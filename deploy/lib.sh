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

# Reads KEY from the app's .env.example, where `npm run brand` writes the name and
# tagline, without the quotes around the value. Prints nothing when there is none.
env_example_value() {
  local root
  root="$(git rev-parse --show-toplevel 2>/dev/null || true)"
  if [[ -n "$root" && -f "$root/.env.example" ]]; then
    sed -n "s/^$1=//p" "$root/.env.example" | head -1 | sed 's/^"\(.*\)"$/\1/'
  fi
}

lowercase() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

# True for anything except empty, 0, false and no.
is_set() {
  case "$(lowercase "${1:-}")" in
    "" | 0 | false | no) return 1 ;;
    *) return 0 ;;
  esac
}

# Decides what the deployed app shows, from the settings or else .env.example, where
# `npm run brand` writes them, so the live app matches the one you set. Needs
# resolve_app_name to have run first.
#
#   APP_TITLE    the name shown by the app. Default: the APP_NAME in .env.example.
#                Without one, or with the template's placeholder "My App", the
#                repository name in title case, e.g. laravel-launchpad ->
#                Laravel Launchpad.
#   APP_TAGLINE  the line shown on the home page and dashboard. Default: the
#                APP_TAGLINE in .env.example, or empty.
resolve_app_display() {
  if [[ -z "${APP_TITLE:-}" ]]; then
    APP_TITLE="$(env_example_value APP_NAME)"
    if [[ -z "$APP_TITLE" || "$APP_TITLE" == "My App" ]]; then
      APP_TITLE="$(printf '%s' "$APP_NAME" | tr '-' ' ' | awk '{for (i = 1; i <= NF; i++) $i = toupper(substr($i, 1, 1)) substr($i, 2)} 1')"
    fi
  fi
  APP_TAGLINE="${APP_TAGLINE:-$(env_example_value APP_TAGLINE)}"
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
