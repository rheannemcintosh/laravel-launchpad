#!/usr/bin/env bash
#
# Sets up Microsoft sign-in (Easy Auth) for a Laravel Launchpad app, so you can
# open the deployed app with your Microsoft account and nobody else can.
#
# Until this runs, azure-provision.sh leaves the app answering HTTP 403 to
# everyone. This script replaces that with a redirect to the Microsoft sign-in
# page, and lets only one account through.
#
# Creates and configures, all named from APP_NAME (see lib.sh):
#
#   app registration  <app>-easyauth in Microsoft Entra: this directory only, with
#                     the app's own /.auth/login/aad/callback as its redirect
#                     address and ID tokens on, which Easy Auth needs
#   client secret     one secret called "easyauth", valid for two years, stored
#                     as a secret in the container app
#   user assignment   assignment required on the registration's enterprise
#                     application, with only the allowed user assigned, so any
#                     other account is refused by Microsoft before the request
#                     reaches the container (and cannot wake the app or database)
#   container app     the Microsoft identity provider, authentication required,
#                     unauthenticated visitors redirected to sign in (HTTP 302),
#                     HTTPS required, and the app restarted so it picks up the
#                     sign-in secret
#
# The Microsoft provider needs the registration to prove itself with a client
# secret. Container Apps offers no non-expiring alternative, so the secret expires
# in two years: the script shows the date and warns when it is close, and
# ROTATE_SECRET=1 issues a new one and removes the old.
#
# Prerequisites:
#   - az CLI logged in (az login) with permission to create app registrations.
#   - The app already provisioned: run ./deploy/azure-provision.sh first.
#
# Settings (environment variables):
#   APP_NAME          default: repository name from `git remote origin`.
#   ALLOWED_USER_ID   object ID of the one user allowed in. Default: the account
#                     signed in to az.
#   ROTATE_SECRET     set to 1 to issue a new client secret and remove the old.
#   VERIFY            set to false to skip the final check that visitors are
#                     redirected to sign in.
#
# Re-running is safe: an existing registration, assignment and secret are reused,
# and nothing is changed unless it has drifted.
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

ACA_APP="$APP_NAME"
EASYAUTH_APP="$APP_NAME-easyauth"
SECRET_LABEL="easyauth"
# The name Easy Auth's Microsoft provider gives its secret in the container app.
SECRET_NAME="microsoft-provider-authentication-secret"
SECRET_YEARS=2
WARN_DAYS=60
DEFAULT_ACCESS_ROLE="00000000-0000-0000-0000-000000000000"
BROWSER_AGENT="Mozilla/5.0"

# True for anything except empty, 0, false and no.
is_set() {
  case "$(lowercase "${1:-}")" in
    "" | 0 | false | no) return 1 ;;
    *) return 0 ;;
  esac
}

# ----------------------------------------------------------------------------
echo "==> Azure CLI"
if ! az account show --output none 2>/dev/null; then
  echo "ERROR: Not logged in to Azure. Run: az login" >&2
  exit 1
fi
TENANT_ID="$(az account show --query tenantId -o tsv)"

if ! az containerapp show --resource-group "$RESOURCE_GROUP" --name "$ACA_APP" --output none 2>/dev/null; then
  echo "ERROR: The container app $ACA_APP in $RESOURCE_GROUP does not exist." >&2
  echo "       Run ./deploy/azure-provision.sh first." >&2
  exit 1
fi
FQDN="$(az containerapp show --resource-group "$RESOURCE_GROUP" --name "$ACA_APP" --query properties.configuration.ingress.fqdn -o tsv)"
REDIRECT_URI="https://$FQDN/.auth/login/aad/callback"
ISSUER="https://login.microsoftonline.com/$TENANT_ID/v2.0"

ALLOWED_USER_ID="${ALLOWED_USER_ID:-}"
if [[ -z "$ALLOWED_USER_ID" ]]; then
  ALLOWED_USER_ID="$(az ad signed-in-user show --query id -o tsv)"
fi
if [[ ! "$ALLOWED_USER_ID" =~ ^[0-9A-Fa-f-]{36}$ ]]; then
  echo "ERROR: ALLOWED_USER_ID '$ALLOWED_USER_ID' is not an object ID (a GUID)." >&2
  exit 1
fi

# ----------------------------------------------------------------------------
echo "==> App registration $EASYAUTH_APP"
existing_apps="$(az ad app list --filter "displayName eq '$EASYAUTH_APP'" --query "[].appId" -o tsv)"
# Newline-separated rather than an array, for bash 3.2 (see azure-provision.sh).
case "$(printf '%s\n' "$existing_apps" | grep -c . || true)" in
  0)
    echo "    creating"
    APP_ID="$(az ad app create \
      --display-name "$EASYAUTH_APP" \
      --sign-in-audience AzureADMyOrg \
      --web-redirect-uris "$REDIRECT_URI" \
      --enable-id-token-issuance true \
      --query appId -o tsv)"
    ;;
  1)
    APP_ID="$existing_apps"
    # Put right only what has drifted, so anything added by hand is kept.
    current_redirects="$(az ad app show --id "$APP_ID" --query "web.redirectUris" -o tsv)"
    current_id_tokens="$(az ad app show --id "$APP_ID" --query "web.implicitGrantSettings.enableIdTokenIssuance" -o tsv)"
    current_audience="$(az ad app show --id "$APP_ID" --query "signInAudience" -o tsv)"
    if ! grep -qxF "$REDIRECT_URI" <<< "$current_redirects"; then
      echo "    adding the redirect address $REDIRECT_URI"
      # shellcheck disable=SC2086 # the addresses contain no spaces; one argument each
      az ad app update --id "$APP_ID" --web-redirect-uris $current_redirects "$REDIRECT_URI"
    fi
    if [[ "$(lowercase "$current_id_tokens")" != "true" ]]; then
      echo "    turning ID tokens on"
      az ad app update --id "$APP_ID" --enable-id-token-issuance true
    fi
    if [[ "$current_audience" != "AzureADMyOrg" ]]; then
      echo "    limiting it to this directory (was $current_audience)"
      az ad app update --id "$APP_ID" --sign-in-audience AzureADMyOrg
    fi
    echo "    reusing"
    ;;
  *)
    echo "ERROR: Several app registrations are called $EASYAUTH_APP ($(printf '%s' "$existing_apps" | tr '\n' ' '))." >&2
    echo "       Delete the extra ones in Microsoft Entra, then re-run." >&2
    exit 1
    ;;
esac

echo "==> Enterprise application (service principal)"
if SP_OBJECT_ID="$(az ad sp show --id "$APP_ID" --query id -o tsv 2>/dev/null)"; then
  echo "    reusing"
else
  echo "    creating"
  SP_OBJECT_ID="$(az ad sp create --id "$APP_ID" --query id -o tsv)"
fi

# The order matters from here. Access is restricted to one user BEFORE the app
# starts redirecting to sign-in, so there is never a moment when any account in
# the directory could get in.
echo "==> Only assigned users may sign in"
if [[ "$(lowercase "$(az ad sp show --id "$APP_ID" --query appRoleAssignmentRequired -o tsv)")" == "true" ]]; then
  echo "    reusing"
else
  echo "    turning assignment required on"
  az ad sp update --id "$APP_ID" --set appRoleAssignmentRequired=true
fi

assignments_url="https://graph.microsoft.com/v1.0/servicePrincipals/$SP_OBJECT_ID/appRoleAssignedTo"
assigned="$(az rest --method get --url "$assignments_url" --query "value[].principalId" -o tsv)"
if grep -qxF "$ALLOWED_USER_ID" <<< "$assigned"; then
  echo "    $ALLOWED_USER_ID is already assigned"
else
  echo "    assigning $ALLOWED_USER_ID"
  az rest --method post --url "$assignments_url" \
    --body "{\"principalId\":\"$ALLOWED_USER_ID\",\"resourceId\":\"$SP_OBJECT_ID\",\"appRoleId\":\"$DEFAULT_ACCESS_ROLE\"}" \
    --output none
fi
others="$(grep -vxF "$ALLOWED_USER_ID" <<< "$assigned" | grep -c . || true)"
if [[ "$others" -gt 0 ]]; then
  echo "    NOTE: $others other user(s) or group(s) are also assigned and can sign in. Remove them"
  echo "    in Microsoft Entra if only $ALLOWED_USER_ID should have access."
fi

# ----------------------------------------------------------------------------
echo "==> Client secret"
secret_lines="$(az ad app credential list --id "$APP_ID" --query "[?displayName=='$SECRET_LABEL'].[keyId, endDateTime]" -o tsv)"
old_key_ids="$(printf '%s\n' "$secret_lines" | cut -f1 | grep . || true)"
app_has_secret="$(az containerapp secret list --resource-group "$RESOURCE_GROUP" --name "$ACA_APP" --query "[?name=='$SECRET_NAME'].name" -o tsv)"

need_secret=false
if is_set "${ROTATE_SECRET:-}"; then
  echo "    rotating (ROTATE_SECRET is set)"
  need_secret=true
elif [[ -z "$old_key_ids" ]]; then
  echo "    creating"
  need_secret=true
elif [[ -z "$app_has_secret" ]]; then
  # The value cannot be read back from Entra, so a lost copy needs a new secret.
  echo "    the container app has no copy of the secret — issuing a new one"
  need_secret=true
else
  echo "    reusing"
fi

# ----------------------------------------------------------------------------
echo "==> Microsoft identity provider on $ACA_APP"
if [[ "$need_secret" == true ]]; then
  # Held in a variable only: never printed, and never written to disk.
  SECRET_VALUE="$(az ad app credential reset \
    --id "$APP_ID" \
    --append \
    --display-name "$SECRET_LABEL" \
    --years "$SECRET_YEARS" \
    --query password -o tsv)"
  az containerapp auth microsoft update \
    --resource-group "$RESOURCE_GROUP" \
    --name "$ACA_APP" \
    --client-id "$APP_ID" \
    --client-secret "$SECRET_VALUE" \
    --issuer "$ISSUER" \
    --yes \
    --output none
  # A rotation removes the secrets it replaced, once the new one is in place.
  while read -r key_id; do
    [[ -z "$key_id" ]] && continue
    az ad app credential delete --id "$APP_ID" --key-id "$key_id"
    echo "    removed the previous secret $key_id"
  done <<< "$old_key_ids"
else
  az containerapp auth microsoft update \
    --resource-group "$RESOURCE_GROUP" \
    --name "$ACA_APP" \
    --client-id "$APP_ID" \
    --client-secret-name "$SECRET_NAME" \
    --issuer "$ISSUER" \
    --yes \
    --output none
fi

echo "==> Redirect visitors who are not signed in to Microsoft sign-in"
# Azure says the app "must be restarted in order for secret changes to take
# effect", so restart it when the secret is new. Also restart when this run is
# the one that switches the redirect on: that finishes a run that stopped part
# way, after the secret was stored but before the restart.
restart_needed="$need_secret"
current_action="$(az containerapp auth show --resource-group "$RESOURCE_GROUP" --name "$ACA_APP" --query globalValidation.unauthenticatedClientAction -o tsv)"
if [[ "$current_action" != "RedirectToLoginPage" ]]; then
  restart_needed=true
fi
# No --token-store: Container Apps only supports one backed by blob storage,
# which this app does not have and plain sign-in does not need.
az containerapp auth update \
  --resource-group "$RESOURCE_GROUP" \
  --name "$ACA_APP" \
  --enabled true \
  --unauthenticated-client-action RedirectToLoginPage \
  --redirect-provider azureactivedirectory \
  --require-https true \
  --proxy-convention Standard \
  --yes \
  --output none

if [[ "$restart_needed" == true ]]; then
  echo "==> Restarting the app so it picks up the sign-in secret"
  latest_revision="$(az containerapp show --resource-group "$RESOURCE_GROUP" --name "$ACA_APP" --query properties.latestRevisionName -o tsv)"
  if ! az containerapp revision restart --resource-group "$RESOURCE_GROUP" --name "$ACA_APP" --revision "$latest_revision" --output none; then
    echo "WARNING: Could not restart $latest_revision. Restart it so it picks up the sign-in secret:" >&2
    echo "         az containerapp revision restart -g $RESOURCE_GROUP -n $ACA_APP --revision $latest_revision" >&2
  fi
fi

# When does the secret run out? Compare ISO dates as text: the first ten characters
# are YYYY-MM-DD.
secret_end="$(az ad app credential list --id "$APP_ID" --query "[?displayName=='$SECRET_LABEL'].endDateTime | [0]" -o tsv)"
secret_end_date="${secret_end:0:10}"
warn_after="$(date -u -v+${WARN_DAYS}d +%Y-%m-%d 2>/dev/null || date -u -d "+${WARN_DAYS} days" +%Y-%m-%d)"

# ----------------------------------------------------------------------------
# Check that it worked
# ----------------------------------------------------------------------------
verified="skipped"
if is_set "${VERIFY:-true}"; then
  echo "==> Checking that visitors are sent to Microsoft sign-in"
  verified="no"
  for attempt in 1 2 3 4 5 6; do
    # Ask the way a browser does. Easy Auth answers a client that does not look like
    # a browser, such as plain curl, with 401 and a Bearer challenge instead of the
    # redirect a person gets.
    result="$(curl -s -o /dev/null --max-time 30 -A "$BROWSER_AGENT" -H "Accept: text/html,application/xhtml+xml" -w '%{http_code} %{redirect_url}' "https://$FQDN/" || true)"
    case "$result" in
      "302 https://login.microsoftonline.com/"*)
        verified="yes"
        break
        ;;
    esac
    echo "    not yet (got: ${result:-nothing}), waiting ($attempt/6)"
    sleep "${VERIFY_DELAY:-10}"
  done
fi

cat <<EOF

============================================================================
Done.

  App URL          : https://$FQDN
  Sign-in          : $EASYAUTH_APP ($APP_ID), this directory only
  Allowed user     : $ALLOWED_USER_ID
  Client secret    : expires ${secret_end_date:-unknown}
EOF

case "$verified" in
  yes) echo "  Redirect check   : visitors are sent to Microsoft sign-in" ;;
  no) echo "  Redirect check   : NOT confirmed yet. Settings can take a few minutes to apply, so try:  curl -sI -A Mozilla/5.0 -H 'Accept: text/html' https://$FQDN/" ;;
esac

if [[ -n "$secret_end_date" && ! "$secret_end_date" > "$warn_after" ]]; then
  echo
  echo "WARNING: the client secret expires on $secret_end_date, within $WARN_DAYS days."
  echo "         Run this script with ROTATE_SECRET=1 to issue a new one."
fi

cat <<EOF

NEXT STEPS
  1. Open https://$FQDN in a browser and sign in with the allowed Microsoft account.
  2. Register your account on the app's own register page, once. The app keeps its
     own login behind Microsoft sign-in.
  3. To check that nobody else gets in, open the address in a private window and
     sign in with a different Microsoft account. It should be refused.
============================================================================
EOF
