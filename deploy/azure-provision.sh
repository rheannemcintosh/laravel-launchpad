#!/usr/bin/env bash
#
# Provisioning for a Laravel Launchpad app on Azure.
# Creates: resource group, serverless Azure SQL (free offer), a consumption-only
# Container Apps environment, the container app itself (scale-to-zero) and a
# monthly budget alert.
#
# Everything is named from APP_NAME, which defaults to the repository name from
# `git remote origin`. The naming rules live in lib.sh, and the deploy workflow
# derives the same names from the repository name, so they must all agree:
#
#   resource group  rg-<app>
#   container app   <app>
#   environment     <app>-env
#   SQL server      <app>-sql-<random>   (globally unique, found again on re-runs)
#   SQL database    <app> with hyphens changed to underscores
#   SQL admin       <database>_admin
#
# Prerequisites:
#   - az CLI logged in:  az login
#   - A container image already pushed to ghcr.io by the deploy workflow (run it
#     once from the Actions tab first). The script installs the containerapp CLI
#     extension and registers the Microsoft.Sql, Microsoft.App and
#     Microsoft.OperationalInsights resource providers if they are not already
#     registered.
#   - The ghcr.io package can be private — set GHCR_USERNAME/GHCR_PAT (a token with
#     read:packages) and this script gives the container app pull credentials. Leave
#     both unset only if the package is public.
#
# Settings (environment variables):
#   SQL_ADMIN_PASSWORD  required. A strong password for the SQL admin login.
#   APP_NAME            default: repository name from `git remote origin`.
#                       2-32 lowercase letters, numbers and single hyphens; starts
#                       with a letter and ends with a letter or number.
#   GITHUB_OWNER        default: owner from `git remote origin`. Used for the image.
#   LOCATION            default: uksouth
#   IMAGE               default: ghcr.io/<owner>/<app>:latest
#   APP_TITLE           default: APP_NAME title-cased, e.g. "Laravel Launchpad".
#   SQL_SERVER          pin an existing server when the resource group has several.
#   GHCR_USERNAME, GHCR_PAT   pull credentials for a private ghcr.io package.
#   APP_KEY             default: generated on the first provision only.
#   BUDGET_AMOUNT       default: 1, in your subscription's billing currency.
#   BUDGET_EMAIL        default: the signed-in Azure user, when that is an email
#                       address. Without one the budget alert is skipped.
#
# Re-running is safe: existing resources are reused, never replaced. The SQL server
# is discovered from the resource group, the database and container app are left
# alone if they exist, and APP_KEY / secrets / data are preserved. Only the image
# and non-secret env vars are refreshed.
#
# Ingress is created behind Easy Auth in "deny everything" mode, so the URL is never
# publicly readable until an identity provider is configured.
#
# Works with the stock bash 3.2 that ships with macOS: no `mapfile`, no `${var,,}`,
# and empty arrays are expanded in a way that `set -u` tolerates.

set -euo pipefail

# ----------------------------------------------------------------------------
# Settings
# ----------------------------------------------------------------------------

# App naming and validation live in lib.sh, shared with the OIDC setup script.
# shellcheck source=deploy/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

load_remote
resolve_app_name

# Image names must be lowercase, so lowercase the owner however it was given.
GITHUB_OWNER="$(lowercase "${GITHUB_OWNER:-$REMOTE_OWNER}")"

LOCATION="${LOCATION:-uksouth}"

# Leave SQL_SERVER empty to reuse the server already in the resource group, or to
# generate a globally unique name on a first-time provision. Set it explicitly to
# pin a specific server.
SQL_SERVER="${SQL_SERVER:-}"
SQL_DB="${APP_NAME//-/_}"
SQL_ADMIN_USER="${SQL_DB}_admin"
SQL_ADMIN_PASSWORD="${SQL_ADMIN_PASSWORD:?Set SQL_ADMIN_PASSWORD env var to a strong password}"

ACA_ENV="$APP_NAME-env"
ACA_APP="$APP_NAME"

IMAGE="${IMAGE:-}"
if [[ -z "$IMAGE" ]]; then
  if [[ -z "$GITHUB_OWNER" ]]; then
    echo "ERROR: Set GITHUB_OWNER (or IMAGE), or run this from a clone whose 'origin' is a GitHub repository." >&2
    exit 1
  fi
  IMAGE="ghcr.io/$GITHUB_OWNER/$APP_NAME:latest"
fi

# Display name for APP_NAME in the app, e.g. laravel-launchpad -> Laravel Launchpad.
APP_TITLE="${APP_TITLE:-$(printf '%s' "$APP_NAME" | tr '-' ' ' | awk '{for (i = 1; i <= NF; i++) $i = toupper(substr($i, 1, 1)) substr($i, 2)} 1')}"

# Only needed if the ghcr.io package is private.
GHCR_USERNAME="${GHCR_USERNAME:-}"
GHCR_PAT="${GHCR_PAT:-}"

BUDGET_AMOUNT="${BUDGET_AMOUNT:-1}"
if [[ ! "$BUDGET_AMOUNT" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
  echo "ERROR: BUDGET_AMOUNT '$BUDGET_AMOUNT' is not a number." >&2
  exit 1
fi

# ----------------------------------------------------------------------------
echo "==> Azure CLI"
if ! az account show --output none 2>/dev/null; then
  echo "ERROR: Not logged in to Azure. Run: az login" >&2
  exit 1
fi
az extension add --name containerapp --upgrade --yes --only-show-errors --output none

# Resource providers are off by default on a fresh subscription and creating a
# resource fails until they are registered.
for provider in Microsoft.Sql Microsoft.App Microsoft.OperationalInsights; do
  state="$(az provider show --namespace "$provider" --query registrationState -o tsv 2>/dev/null || true)"
  if [[ "$state" != "Registered" ]]; then
    echo "    registering $provider (this can take a few minutes)"
    az provider register --namespace "$provider" --wait --output none
  fi
done

echo "==> Resource group"
az group create --name "$RESOURCE_GROUP" --location "$LOCATION" --output none

echo "==> Azure SQL logical server"
if [[ -z "$SQL_SERVER" ]]; then
  # Newline-separated rather than an array: macOS ships bash 3.2, where `mapfile`
  # does not exist and `${#arr[@]}` on an empty array trips `set -u`.
  existing_servers="$(az sql server list -g "$RESOURCE_GROUP" --query "[].name" -o tsv)"
  case "$(printf '%s\n' "$existing_servers" | grep -c . || true)" in
    0) SQL_SERVER="$APP_NAME-sql-$RANDOM" ;;
    1) SQL_SERVER="$existing_servers" ;;
    *)
      echo "ERROR: $RESOURCE_GROUP contains several SQL servers ($(printf '%s' "$existing_servers" | tr '\n' ' '))." >&2
      echo "       Re-run with SQL_SERVER=<name> so the right one is reused." >&2
      exit 1
      ;;
  esac
fi

if az sql server show -g "$RESOURCE_GROUP" -n "$SQL_SERVER" --output none 2>/dev/null; then
  echo "    reusing $SQL_SERVER"
else
  echo "    creating $SQL_SERVER — record this name, reruns need it"
  az sql server create \
    --name "$SQL_SERVER" \
    --resource-group "$RESOURCE_GROUP" \
    --location "$LOCATION" \
    --admin-user "$SQL_ADMIN_USER" \
    --admin-password "$SQL_ADMIN_PASSWORD" \
    --output none
fi

echo "==> Allow other Azure services (incl. Container Apps) to reach the server"
az sql server firewall-rule create \
  --resource-group "$RESOURCE_GROUP" \
  --server "$SQL_SERVER" \
  --name AllowAzureServices \
  --start-ip-address 0.0.0.0 --end-ip-address 0.0.0.0 \
  --output none

echo "==> Serverless database on the free offer (auto-pauses, stops if free limit hit)"
if az sql db show -g "$RESOURCE_GROUP" -s "$SQL_SERVER" -n "$SQL_DB" --output none 2>/dev/null; then
  echo "    reusing $SQL_DB — existing data left untouched"
else
  az sql db create \
    --resource-group "$RESOURCE_GROUP" \
    --server "$SQL_SERVER" \
    --name "$SQL_DB" \
    --edition GeneralPurpose \
    --compute-model Serverless \
    --family Gen5 \
    --capacity 2 \
    --min-capacity 0.5 \
    --auto-pause-delay 60 \
    --backup-storage-redundancy Local \
    --use-free-limit \
    --free-limit-exhaustion-behavior AutoPause \
    --output none
fi

SQL_FQDN="$(az sql server show -g "$RESOURCE_GROUP" -n "$SQL_SERVER" --query fullyQualifiedDomainName -o tsv)"

echo "==> Container Apps environment (consumption-only, no Log Analytics = no log cost)"
if az containerapp env show -g "$RESOURCE_GROUP" -n "$ACA_ENV" --output none 2>/dev/null; then
  echo "    reusing $ACA_ENV"
else
  az containerapp env create \
    --name "$ACA_ENV" \
    --resource-group "$RESOURCE_GROUP" \
    --location "$LOCATION" \
    --logs-destination none \
    --output none
fi

# Sessions, cache and queue avoid the database so a paused serverless database is
# only woken by real data access. DB_LOGIN_TIMEOUT gives it time to resume.
ENV_VARS=(
  APP_NAME="$APP_TITLE"
  APP_ENV=production
  APP_DEBUG=false
  LOG_CHANNEL=stderr
  APP_KEY=secretref:app-key
  DB_CONNECTION=sqlsrv
  DB_HOST="$SQL_FQDN"
  DB_PORT=1433
  DB_DATABASE="$SQL_DB"
  DB_USERNAME="$SQL_ADMIN_USER"
  DB_PASSWORD=secretref:db-password
  DB_ENCRYPT=yes
  DB_TRUST_SERVER_CERTIFICATE=false
  DB_LOGIN_TIMEOUT=60
  SESSION_DRIVER=cookie
  SESSION_SECURE_COOKIE=true
  CACHE_STORE=file
  QUEUE_CONNECTION=sync
)

if az containerapp show -g "$RESOURCE_GROUP" -n "$ACA_APP" --output none 2>/dev/null; then
  FIRST_PROVISION=false
  echo "==> Container app exists — refreshing image and env vars (APP_KEY secret kept)"
  az containerapp update \
    --name "$ACA_APP" \
    --resource-group "$RESOURCE_GROUP" \
    --image "$IMAGE" \
    --set-env-vars "${ENV_VARS[@]}" \
    --output none
  az containerapp secret set \
    --name "$ACA_APP" \
    --resource-group "$RESOURCE_GROUP" \
    --secrets "db-password=$SQL_ADMIN_PASSWORD" \
    --output none
  if [[ -n "$GHCR_PAT" ]]; then
    echo "==> Refreshing ghcr.io pull credential"
    az containerapp registry set \
      --name "$ACA_APP" \
      --resource-group "$RESOURCE_GROUP" \
      --server ghcr.io \
      --username "$GHCR_USERNAME" \
      --password "$GHCR_PAT" \
      --output none
  fi
else
  FIRST_PROVISION=true
  # Generated once, on the very first provision. A rerun never reaches this branch,
  # so sessions and encrypted payloads survive.
  APP_KEY="${APP_KEY:-base64:$(openssl rand -base64 32)}"

  REGISTRY_ARGS=()
  if [[ -n "$GHCR_PAT" ]]; then
    REGISTRY_ARGS=(--registry-server ghcr.io --registry-username "$GHCR_USERNAME" --registry-password "$GHCR_PAT")
  fi

  echo "==> Container app (scale-to-zero)"
  # ${arr[@]+"${arr[@]}"} expands an empty array to nothing under bash 3.2's `set -u`.
  az containerapp create \
    --name "$ACA_APP" \
    --resource-group "$RESOURCE_GROUP" \
    --environment "$ACA_ENV" \
    --image "$IMAGE" \
    --target-port 8080 \
    --ingress external \
    --min-replicas 0 \
    --max-replicas 1 \
    --cpu 0.5 --memory 1.0Gi \
    --secrets "app-key=$APP_KEY" "db-password=$SQL_ADMIN_PASSWORD" \
    --env-vars "${ENV_VARS[@]}" \
    ${REGISTRY_ARGS[@]+"${REGISTRY_ARGS[@]}"} \
    --output none

  echo "==> Deny all unauthenticated traffic until an identity provider is configured"
  az containerapp auth update \
    --name "$ACA_APP" \
    --resource-group "$RESOURCE_GROUP" \
    --enabled true \
    --unauthenticated-client-action Return403 \
    --require-https true \
    --proxy-convention Standard \
    --output none
fi

FQDN="$(az containerapp show -g "$RESOURCE_GROUP" -n "$ACA_APP" --query properties.configuration.ingress.fqdn -o tsv)"

echo "==> Set APP_URL to the real FQDN"
az containerapp update \
  --name "$ACA_APP" \
  --resource-group "$RESOURCE_GROUP" \
  --set-env-vars "APP_URL=https://$FQDN" \
  --output none

# `az consumption budget create` cannot set who to notify, so the budget goes
# through the REST API instead: a PUT is create-or-update, so re-runs are safe.
# The budget sits on the resource group, so it tracks only this app.
echo "==> Monthly budget alert ($BUDGET_AMOUNT in your billing currency)"
BUDGET_EMAIL="${BUDGET_EMAIL:-$(az account show --query user.name -o tsv 2>/dev/null || true)}"
if [[ "$BUDGET_EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+$ ]]; then
  SUB_ID="$(az account show --query id -o tsv)"
  budget_body="$(cat <<JSON
{
  "properties": {
    "category": "Cost",
    "amount": $BUDGET_AMOUNT,
    "timeGrain": "Monthly",
    "timePeriod": { "startDate": "$(date -u +%Y-%m-01)T00:00:00Z" },
    "notifications": {
      "Actual_GreaterThan_80_Percent": {
        "enabled": true,
        "operator": "GreaterThan",
        "threshold": 80,
        "thresholdType": "Actual",
        "contactEmails": ["$BUDGET_EMAIL"],
        "locale": "en-us"
      },
      "Actual_GreaterThan_100_Percent": {
        "enabled": true,
        "operator": "GreaterThan",
        "threshold": 100,
        "thresholdType": "Actual",
        "contactEmails": ["$BUDGET_EMAIL"],
        "locale": "en-us"
      }
    }
  }
}
JSON
)"
  az rest \
    --method put \
    --url "https://management.azure.com/subscriptions/$SUB_ID/resourceGroups/$RESOURCE_GROUP/providers/Microsoft.Consumption/budgets/$BUDGET_NAME?api-version=2024-08-01" \
    --body "$budget_body" \
    --output none \
    || echo "   (budget create failed — set it in the portal instead)"
  echo "    alerts go to $BUDGET_EMAIL at 80% and 100% of the budget"
else
  echo "    skipped: no email address to notify (got '${BUDGET_EMAIL}'). Set BUDGET_EMAIL and re-run."
fi

cat <<EOF

============================================================================
Done.

  App URL      : https://$FQDN
  SQL server   : $SQL_SERVER  ($SQL_FQDN)
  SQL DB       : $SQL_DB  (admin: $SQL_ADMIN_USER)
EOF

if [[ "$FIRST_PROVISION" == true ]]; then
  cat <<EOF

Every request currently gets HTTP 403 — the app is not readable by anyone.

NEXT STEPS
  1. Set up the deploy workflow's Azure sign-in with ./deploy/azure-oidc-setup.sh,
     then run the deploy workflow so the app runs a commit-tagged image.
  2. Set up Microsoft sign-in for your account only with
     ./deploy/azure-easyauth-setup.sh. That switches the app from 403 to the
     sign-in redirect.
  3. Open the URL, sign in with Microsoft, register your Laravel account once.
EOF
fi

echo "============================================================================"
