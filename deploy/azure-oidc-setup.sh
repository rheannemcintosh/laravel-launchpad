#!/usr/bin/env bash
#
# Sets up the Azure sign-in that the deploy workflow uses, for a Laravel Launchpad
# app, so the workflow can deploy without any stored password (OIDC).
#
# Creates, all named from APP_NAME (see lib.sh, shared with azure-provision.sh):
#
#   app registration    <app>-deploy, with its service principal
#   role                Contributor on the resource group rg-<app> ONLY
#   trust rule          a federated credential that trusts this GitHub repository
#                       on one branch (main by default) and nothing else
#   repository secrets  AZURE_CLIENT_ID, AZURE_TENANT_ID, AZURE_SUBSCRIPTION_ID
#   repository variable DEPLOY_ON_MERGE=true, which turns on the deploy that runs
#                       when a change is merged to main
#
# No client secret is created, so there is nothing to store or rotate. The three
# secrets are identifiers, not passwords.
#
# Prerequisites:
#   - az CLI logged in (az login) with permission to create app registrations and
#     role assignments.
#   - The resource group must already exist: run ./deploy/azure-provision.sh first.
#   - gh CLI logged in (gh auth login) with permission to write repository secrets.
#     Without it the script still does the Azure setup and prints the three values
#     for you to add by hand.
#
# Settings (environment variables):
#   APP_NAME       default: repository name from `git remote origin`.
#   GITHUB_OWNER   default: owner from `git remote origin`.
#   GITHUB_REPO    default: repository name from `git remote origin`, as GitHub
#                  spells it.
#   TRUST_BRANCH   default: main. Only workflow runs on this branch are trusted.
#   ENABLE_DEPLOY_ON_MERGE
#                  default: true. Set to false to leave merges to main deploying
#                  nothing, so releases stay manual. It is only turned on when
#                  TRUST_BRANCH is main, because that is where merges land.
#   OIDC_SUBJECT   default: read from GitHub. Set it to override the trust rule's
#                  subject, for example with the one quoted in an Azure
#                  AADSTS700213 sign-in error.
#
# Re-running is safe: an existing app registration, role and trust rule are
# reused, and the secrets are simply set again.
#
# Works with the stock bash 3.2 that ships with macOS.

set -euo pipefail

# ----------------------------------------------------------------------------
# Settings
# ----------------------------------------------------------------------------

# shellcheck source=deploy/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

load_remote
resolve_app_name

GITHUB_OWNER="${GITHUB_OWNER:-$REMOTE_OWNER}"
GITHUB_REPO="${GITHUB_REPO:-$REMOTE_REPO}"
if [[ -z "$GITHUB_OWNER" || -z "$GITHUB_REPO" ]]; then
  echo "ERROR: Set GITHUB_OWNER and GITHUB_REPO, or run this from a clone whose 'origin' is a GitHub repository." >&2
  exit 1
fi

TRUST_BRANCH="${TRUST_BRANCH:-main}"
if [[ ! "$TRUST_BRANCH" =~ ^[A-Za-z0-9._/-]+$ ]]; then
  echo "ERROR: TRUST_BRANCH '$TRUST_BRANCH' is not a plain branch name." >&2
  exit 1
fi

DEPLOY_APP="$APP_NAME-deploy"
# Credential names allow letters, numbers, hyphens and underscores only.
CREDENTIAL_NAME="github-$(printf '%s' "$TRUST_BRANCH" | tr -c 'A-Za-z0-9_\n-' '-')"

# A new service principal can take a little while to become visible to role
# assignment, so retry a few times before giving up.
retry() {
  local tries="$1" attempt=1
  shift
  until "$@"; do
    if [[ "$attempt" -ge "$tries" ]]; then
      return 1
    fi
    echo "    not ready yet, retrying ($attempt/$tries)"
    attempt=$((attempt + 1))
    sleep "${RETRY_DELAY:-10}"
  done
}

# ----------------------------------------------------------------------------
echo "==> Azure CLI"
if ! az account show --output none 2>/dev/null; then
  echo "ERROR: Not logged in to Azure. Run: az login" >&2
  exit 1
fi
SUB_ID="$(az account show --query id -o tsv)"
TENANT_ID="$(az account show --query tenantId -o tsv)"

if ! az group show --name "$RESOURCE_GROUP" --output none 2>/dev/null; then
  echo "ERROR: The resource group $RESOURCE_GROUP does not exist." >&2
  echo "       Run ./deploy/azure-provision.sh first, so the deploy identity can be" >&2
  echo "       limited to that one resource group." >&2
  exit 1
fi

echo "==> Trust rule subject"
if [[ -z "${OIDC_SUBJECT:-}" ]]; then
  # GitHub says exactly how it spells this repository in the tokens it issues,
  # including the numeric IDs it embeds for repositories that use immutable
  # subjects, so ask it instead of assembling the string by hand.
  if ! subject_prefix="$(gh api "repos/$GITHUB_OWNER/$GITHUB_REPO/actions/oidc/customization/sub" --jq '.sub_claim_prefix // empty' 2>/dev/null)"; then
    echo "ERROR: Could not read the OIDC subject for $GITHUB_OWNER/$GITHUB_REPO from GitHub." >&2
    echo "       Install the gh CLI and log in with 'gh auth login', or set OIDC_SUBJECT yourself." >&2
    exit 1
  fi
  if [[ -z "$subject_prefix" ]]; then
    subject_prefix="repo:$GITHUB_OWNER/$GITHUB_REPO"
  fi
  OIDC_SUBJECT="$subject_prefix:ref:refs/heads/$TRUST_BRANCH"
fi
if [[ ! "$OIDC_SUBJECT" =~ ^[A-Za-z0-9_:@/.-]+$ ]]; then
  echo "ERROR: OIDC_SUBJECT '$OIDC_SUBJECT' contains unexpected characters." >&2
  exit 1
fi
echo "    $OIDC_SUBJECT"

echo "==> App registration $DEPLOY_APP"
existing_apps="$(az ad app list --filter "displayName eq '$DEPLOY_APP'" --query "[].appId" -o tsv)"
# Newline-separated rather than an array, for bash 3.2 (see azure-provision.sh).
case "$(printf '%s\n' "$existing_apps" | grep -c . || true)" in
  0)
    echo "    creating"
    APP_ID="$(az ad app create --display-name "$DEPLOY_APP" --query appId -o tsv)"
    ;;
  1)
    echo "    reusing"
    APP_ID="$existing_apps"
    ;;
  *)
    echo "ERROR: Several app registrations are called $DEPLOY_APP ($(printf '%s' "$existing_apps" | tr '\n' ' '))." >&2
    echo "       Delete the extra ones in Microsoft Entra, then re-run." >&2
    exit 1
    ;;
esac

echo "==> Service principal"
if SP_OBJECT_ID="$(az ad sp show --id "$APP_ID" --query id -o tsv 2>/dev/null)"; then
  echo "    reusing"
else
  echo "    creating"
  SP_OBJECT_ID="$(az ad sp create --id "$APP_ID" --query id -o tsv)"
fi

echo "==> Contributor on $RESOURCE_GROUP only"
SCOPE="/subscriptions/$SUB_ID/resourceGroups/$RESOURCE_GROUP"
existing_role="$(az role assignment list --assignee "$SP_OBJECT_ID" --role Contributor --scope "$SCOPE" --query "[0].id" -o tsv)"
if [[ -n "$existing_role" ]]; then
  echo "    reusing"
else
  echo "    assigning"
  # Object ID plus principal type skips a directory lookup that fails while a
  # brand new service principal is still replicating.
  retry 6 az role assignment create \
    --assignee-object-id "$SP_OBJECT_ID" \
    --assignee-principal-type ServicePrincipal \
    --role Contributor \
    --scope "$SCOPE" \
    --output none
fi

echo "==> Trust rule for branch $TRUST_BRANCH"
credential_body="{\"name\":\"$CREDENTIAL_NAME\",\"issuer\":\"https://token.actions.githubusercontent.com\",\"subject\":\"$OIDC_SUBJECT\",\"audiences\":[\"api://AzureADTokenExchange\"]}"
existing_subject="$(az ad app federated-credential list --id "$APP_ID" --query "[?name=='$CREDENTIAL_NAME'].subject | [0]" -o tsv)"
if [[ -z "$existing_subject" ]]; then
  echo "    creating $CREDENTIAL_NAME"
  az ad app federated-credential create --id "$APP_ID" --parameters "$credential_body" --output none
elif [[ "$existing_subject" == "$OIDC_SUBJECT" ]]; then
  echo "    reusing $CREDENTIAL_NAME"
else
  echo "    $CREDENTIAL_NAME trusted a different subject ($existing_subject) — updating it"
  az ad app federated-credential update --id "$APP_ID" --federated-credential-id "$CREDENTIAL_NAME" --parameters "$credential_body" --output none
fi

echo "==> GitHub repository secrets on $GITHUB_OWNER/$GITHUB_REPO"
secrets_set=true
for pair in "AZURE_CLIENT_ID=$APP_ID" "AZURE_TENANT_ID=$TENANT_ID" "AZURE_SUBSCRIPTION_ID=$SUB_ID"; do
  name="${pair%%=*}"
  value="${pair#*=}"
  if gh secret set "$name" --repo "$GITHUB_OWNER/$GITHUB_REPO" --body "$value" >/dev/null 2>&1; then
    echo "    set $name"
  else
    secrets_set=false
    echo "    could not set $name"
  fi
done

# Merges to main deploy only for a repository that has opted in with this
# variable, so an app that has not been through this setup never tries to deploy.
deploy_on_merge="off"
deploy_on_merge_note=""
echo "==> Deploy on merge"
if ! is_set "${ENABLE_DEPLOY_ON_MERGE:-true}"; then
  deploy_on_merge_note="left off (ENABLE_DEPLOY_ON_MERGE is off)"
elif [[ "$TRUST_BRANCH" != "main" ]]; then
  deploy_on_merge_note="left off: only $TRUST_BRANCH is trusted, and merges land on main"
elif gh variable set DEPLOY_ON_MERGE --repo "$GITHUB_OWNER/$GITHUB_REPO" --body true >/dev/null 2>&1; then
  deploy_on_merge="on"
else
  deploy_on_merge_note="could not be set (is gh logged in, with permission to write variables?)"
fi
if [[ "$deploy_on_merge" == "on" ]]; then
  echo "    set DEPLOY_ON_MERGE=true"
else
  echo "    $deploy_on_merge_note"
fi

cat <<EOF

============================================================================
Done.

  Deploy identity : $DEPLOY_APP  ($APP_ID)
  Role            : Contributor on $RESOURCE_GROUP only
  Trusted         : $OIDC_SUBJECT
  Deploy on merge : $deploy_on_merge
EOF

if [[ "$secrets_set" == false ]]; then
  cat <<EOF

The repository secrets could not be set automatically (is gh logged in, with
permission to write secrets?). Add them by hand under Settings, Secrets and
variables, Actions:

  AZURE_CLIENT_ID       = $APP_ID
  AZURE_TENANT_ID       = $TENANT_ID
  AZURE_SUBSCRIPTION_ID = $SUB_ID
EOF
fi

cat <<EOF

NEXT STEPS
  1. Deploy from the main branch, by hand:
       gh workflow run deploy.yml --repo $GITHUB_OWNER/$GITHUB_REPO --ref $TRUST_BRANCH
     It builds the image, signs in to Azure and updates the app to that commit.
EOF

if [[ "$deploy_on_merge" == "on" ]]; then
  cat <<EOF
     From now on, merging to main also deploys. To turn that off:  gh variable delete DEPLOY_ON_MERGE --repo $GITHUB_OWNER/$GITHUB_REPO
EOF
fi

cat <<EOF
  2. If the sign-in fails with AADSTS700213, Azure names the subject it received.
     Re-run this script with OIDC_SUBJECT set to exactly that value.
============================================================================
EOF
