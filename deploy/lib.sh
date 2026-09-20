# shellcheck shell=bash
# shellcheck disable=SC2034 # the variables set here are used by the sourcing scripts
#
# Shared by the scripts in this folder. Source it, do not run it:
#
#   source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
#
# It is the single place that decides what an app is called, so the provisioning
# script, the OIDC setup script and the deploy workflow never drift apart. The
# deploy workflow (.github/workflows/deploy.yml) derives the same names in its
# own "Derive names" step, so a change to the naming here needs the same change
# there.
#
# Works with the stock bash 3.2 that ships with macOS.

# Owner and repository from the git remote, e.g. git@github.com:owner/repo.git
# or https://github.com/owner/repo.git. Prints "owner repo", or nothing.
remote_owner_and_repo() {
  git remote get-url origin 2>/dev/null \
    | sed -E 's#\.git$##; s#^(git@github\.com:|https://github\.com/)([^/]+)/([^/]+)$#\2 \3#' \
    | grep -E '^[^ /]+ [^ /]+$' || true
}

# Sets REMOTE_OWNER and REMOTE_REPO exactly as GitHub spells them, or leaves
# them empty when origin is not a GitHub repository.
load_remote() {
  local remote
  remote="$(remote_owner_and_repo)"
  REMOTE_OWNER=""
  REMOTE_REPO=""
  if [[ -n "$remote" ]]; then
    REMOTE_OWNER="${remote%% *}"
    REMOTE_REPO="${remote#* }"
  fi
}

lowercase() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

# Decides APP_NAME (default: the repository name, lowercased) and the names
# derived from it. Exits with a message when the name cannot be used. Needs
# load_remote to have run first.
#
#   RESOURCE_GROUP  rg-<app>
#   BUDGET_NAME     <app>-monthly, the budget alert on that resource group
resolve_app_name() {
  APP_NAME="${APP_NAME:-}"
  if [[ -z "$APP_NAME" && -n "$REMOTE_REPO" ]]; then
    APP_NAME="$(lowercase "$REMOTE_REPO")"
  fi
  if [[ -z "$APP_NAME" ]]; then
    echo "ERROR: Set APP_NAME, or run this from a clone whose 'origin' is a GitHub repository." >&2
    exit 1
  fi
  if [[ ! "$APP_NAME" =~ ^[a-z]([a-z0-9-]{0,30}[a-z0-9])$ || "$APP_NAME" == *--* ]]; then
    echo "ERROR: APP_NAME '$APP_NAME' is not valid." >&2
    echo "       Use 2-32 lowercase letters, numbers and single hyphens, starting with a" >&2
    echo "       letter and ending with a letter or number." >&2
    exit 1
  fi
  RESOURCE_GROUP="rg-$APP_NAME"
  BUDGET_NAME="$APP_NAME-monthly"
}
