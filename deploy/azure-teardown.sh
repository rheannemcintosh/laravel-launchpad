#!/usr/bin/env bash
#
# Removes everything a Laravel Launchpad app created in Azure, so a test
# environment can be thrown away as quickly as it was set up.
#
# Removes, all named from APP_NAME (see lib.sh, shared with the other scripts):
#
#   budget alert        <app>-monthly, on the resource group. It is deleted
#                       explicitly first, because it is not a resource inside the
#                       group and is not known to go with it
#   resource group      rg-<app> and everything in it: the app, its database and
#                       the Container Apps environment
#   app registrations   <app>-deploy (azure-oidc-setup.sh) and, if present,
#                       <app>-easyauth, in Microsoft Entra
#   repository secrets  AZURE_CLIENT_ID, AZURE_TENANT_ID and AZURE_SUBSCRIPTION_ID,
#                       but only for the app named after the repository, because
#                       the repository has one set of them and they belong to
#                       that app
#
# It lists exactly what it will delete and asks you to type the app name before
# it deletes anything. A resource group that does not contain anything this
# project's scripts create for the app is refused, even with FORCE.
#
# Prerequisites:
#   - az CLI logged in (az login).
#   - gh CLI logged in (gh auth login), to remove the repository secrets. Without
#     it the rest is still removed and the script tells you what to remove by hand.
#
# Settings (environment variables):
#   APP_NAME         default: repository name from `git remote origin`.
#   GITHUB_OWNER     default: owner from `git remote origin`.
#   GITHUB_REPO      default: repository name from `git remote origin`.
#   DRY_RUN          set to 1 to list what would be removed and delete nothing.
#   FORCE            set to 1 to skip the typed confirmation, for automation.
#   NO_WAIT          set to 1 to return without waiting for the resource group
#                    deletion to finish.
#   REMOVE_SECRETS   set to false to leave the repository secrets alone.
#
# Running it again is safe: it only removes what still exists, and does nothing
# when everything is already gone.
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

# True for anything except empty, 0, false and no.
is_set() {
  case "$(lowercase "${1:-}")" in
    "" | 0 | false | no) return 1 ;;
    *) return 0 ;;
  esac
}

TAB="$(printf '\t')"

# ----------------------------------------------------------------------------
echo "==> Azure CLI"
if ! az account show --output none 2>/dev/null; then
  echo "ERROR: Not logged in to Azure. Run: az login" >&2
  exit 1
fi

# ----------------------------------------------------------------------------
# Find out what exists
# ----------------------------------------------------------------------------
echo "==> Looking for what $APP_NAME created"

rg_exists=false
rg_location=""
resource_lines=""
total_resources=0
owned_resources=0

if az group show --name "$RESOURCE_GROUP" --output none 2>/dev/null; then
  rg_exists=true
  rg_location="$(az group show --name "$RESOURCE_GROUP" --query location -o tsv)"
  # Top-level resources only: children such as a database show under their server.
  resource_lines="$(az resource list --resource-group "$RESOURCE_GROUP" --query "[?!contains(name, '/')].[name, type]" -o tsv)"
  while IFS="$TAB" read -r name type; do
    [[ -z "$name" ]] && continue
    total_resources=$((total_resources + 1))
    # What azure-provision.sh creates for this app.
    case "$name" in
      "$APP_NAME" | "$APP_NAME-env" | "$APP_NAME"-sql-*) owned_resources=$((owned_resources + 1)) ;;
    esac
  done <<< "$resource_lines"
fi

# The budget alert sits on the resource group rather than inside it, so it does
# not appear in the resource list. Look for it separately.
budget_exists=false
SUB_ID=""
budget_url=""
if [[ "$rg_exists" == true ]]; then
  SUB_ID="$(az account show --query id -o tsv)"
  budget_url="https://management.azure.com/subscriptions/$SUB_ID/resourceGroups/$RESOURCE_GROUP/providers/Microsoft.Consumption/budgets/$BUDGET_NAME?api-version=2024-08-01"
  if az rest --method get --url "$budget_url" --output none 2>/dev/null; then
    budget_exists=true
  fi
fi

# Entra app registrations, as "name<TAB>appId" lines.
app_lines=""
for registration in "$APP_NAME-deploy" "$APP_NAME-easyauth"; do
  ids="$(az ad app list --filter "displayName eq '$registration'" --query "[].appId" -o tsv)"
  while read -r id; do
    [[ -z "$id" ]] && continue
    app_lines="$app_lines$registration$TAB$id
"
  done <<< "$ids"
done

# Repository secrets. There is one set per repository, so they are only removed
# for the app the deploy workflow targets: the one named after the repository.
secret_names=""
secrets_note=""
secrets_by_hand=false
if ! is_set "${REMOVE_SECRETS:-true}"; then
  secrets_note="left alone (REMOVE_SECRETS is off)"
elif [[ -z "$GITHUB_OWNER" || -z "$GITHUB_REPO" ]]; then
  secrets_note="left alone (no GitHub repository known; set GITHUB_OWNER and GITHUB_REPO)"
elif [[ "$APP_NAME" != "$(lowercase "$GITHUB_REPO")" ]]; then
  secrets_note="left alone: they belong to the app named after the repository ($(lowercase "$GITHUB_REPO")), not $APP_NAME"
elif listed="$(gh secret list --repo "$GITHUB_OWNER/$GITHUB_REPO" --json name --jq '.[].name' 2>/dev/null)"; then
  secret_names="$(printf '%s\n' "$listed" | grep -E '^(AZURE_CLIENT_ID|AZURE_TENANT_ID|AZURE_SUBSCRIPTION_ID)$' || true)"
else
  secrets_by_hand=true
  secrets_note="could not be read (is gh installed and logged in?)"
fi

# ----------------------------------------------------------------------------
# Refuse a resource group that is not this app's
# ----------------------------------------------------------------------------
if [[ "$rg_exists" == true && "$total_resources" -gt 0 && "$owned_resources" -eq 0 ]]; then
  echo "ERROR: $RESOURCE_GROUP exists but contains nothing this project creates for $APP_NAME." >&2
  echo "       It should hold a container app named $APP_NAME, the environment $APP_NAME-env or" >&2
  echo "       a SQL server called $APP_NAME-sql-<number>. It holds:" >&2
  while IFS="$TAB" read -r name type; do
    [[ -z "$name" ]] && continue
    echo "         $name ($type)" >&2
  done <<< "$resource_lines"
  echo "       Nothing was deleted. Check the app name, or delete the group by hand if it is yours." >&2
  exit 1
fi

# ----------------------------------------------------------------------------
# Show the plan
# ----------------------------------------------------------------------------
nothing_to_remove=true
echo
echo "============================================================================"
echo "This will permanently delete:"
echo

if [[ "$rg_exists" == true ]]; then
  nothing_to_remove=false
  echo "  Resource group $RESOURCE_GROUP ($rg_location) and everything in it:"
  if [[ "$budget_exists" == true ]]; then
    echo "    - $BUDGET_NAME  (budget alert, deleted first)"
  fi
  if [[ "$total_resources" -eq 0 && "$budget_exists" == false ]]; then
    echo "    (empty)"
  else
    while IFS="$TAB" read -r name type; do
      [[ -z "$name" ]] && continue
      case "$name" in
        "$APP_NAME" | "$APP_NAME-env" | "$APP_NAME"-sql-*) echo "    - $name  ($type)" ;;
        *) echo "    - $name  ($type)  <- not created by azure-provision.sh, but it is in this group" ;;
      esac
    done <<< "$resource_lines"
  fi
else
  echo "  Resource group $RESOURCE_GROUP: does not exist (already gone)"
fi

echo
if [[ -n "$app_lines" ]]; then
  nothing_to_remove=false
  echo "  Microsoft Entra app registrations:"
  while IFS="$TAB" read -r name id; do
    [[ -z "$name" ]] && continue
    echo "    - $name  ($id)"
  done <<< "$app_lines"
else
  echo "  Microsoft Entra app registrations $APP_NAME-deploy and $APP_NAME-easyauth: none found"
fi

echo
if [[ -n "$secret_names" ]]; then
  nothing_to_remove=false
  echo "  Secrets on the GitHub repository $GITHUB_OWNER/$GITHUB_REPO:"
  while read -r name; do
    [[ -z "$name" ]] && continue
    echo "    - $name"
  done <<< "$secret_names"
elif [[ -n "$secrets_note" ]]; then
  echo "  Repository secrets: $secrets_note"
else
  echo "  Repository secrets: none found"
fi
echo "============================================================================"

if [[ "$nothing_to_remove" == true ]]; then
  echo
  echo "Nothing to remove."
  if [[ "$secrets_by_hand" == true ]]; then
    echo "Check the repository secrets by hand: AZURE_CLIENT_ID, AZURE_TENANT_ID and"
    echo "AZURE_SUBSCRIPTION_ID may still be set."
  fi
  exit 0
fi

if is_set "${DRY_RUN:-}"; then
  echo
  echo "Dry run: nothing was deleted."
  exit 0
fi

# ----------------------------------------------------------------------------
# Confirm
# ----------------------------------------------------------------------------
if ! is_set "${FORCE:-}"; then
  echo
  printf 'Type the app name (%s) to delete everything above: ' "$APP_NAME"
  answer=""
  read -r answer || true
  if [[ "$answer" != "$APP_NAME" ]]; then
    echo "Aborted: nothing was deleted."
    exit 1
  fi
fi

# ----------------------------------------------------------------------------
# Delete
# ----------------------------------------------------------------------------
if [[ "$budget_exists" == true ]]; then
  echo "==> Deleting the budget alert $BUDGET_NAME"
  if az rest --method delete --url "$budget_url" --output none; then
    echo "    deleted"
  else
    echo "WARNING: could not delete the budget alert $BUDGET_NAME. Remove it in the Azure portal." >&2
  fi
fi

if [[ "$rg_exists" == true ]]; then
  if is_set "${NO_WAIT:-}"; then
    echo "==> Deleting resource group $RESOURCE_GROUP (not waiting for it to finish)"
    az group delete --name "$RESOURCE_GROUP" --yes --no-wait
  else
    echo "==> Deleting resource group $RESOURCE_GROUP (this can take several minutes)"
    az group delete --name "$RESOURCE_GROUP" --yes
    if [[ "$(az group exists --name "$RESOURCE_GROUP")" == "true" ]]; then
      echo "WARNING: $RESOURCE_GROUP still exists. Check the Azure portal." >&2
    else
      echo "    gone"
    fi
  fi
fi

if [[ -n "$app_lines" ]]; then
  echo "==> Deleting Microsoft Entra app registrations"
  while IFS="$TAB" read -r name id; do
    [[ -z "$name" ]] && continue
    az ad app delete --id "$id"
    echo "    deleted $name ($id)"
  done <<< "$app_lines"
fi

secrets_failed=false
if [[ -n "$secret_names" ]]; then
  echo "==> Deleting repository secrets on $GITHUB_OWNER/$GITHUB_REPO"
  while read -r name; do
    [[ -z "$name" ]] && continue
    if gh secret delete "$name" --repo "$GITHUB_OWNER/$GITHUB_REPO" >/dev/null 2>&1; then
      echo "    deleted $name"
    else
      secrets_failed=true
      echo "    could not delete $name"
    fi
  done <<< "$secret_names"
fi

echo
echo "============================================================================"
echo "Done."
if [[ "$secrets_by_hand" == true || "$secrets_failed" == true ]]; then
  echo
  echo "Remove these repository secrets by hand under Settings, Secrets and variables,"
  echo "Actions: AZURE_CLIENT_ID, AZURE_TENANT_ID and AZURE_SUBSCRIPTION_ID."
fi
echo "============================================================================"
