#!/usr/bin/env bash
#
# Takes a repository made from the Laravel Launchpad template to a running app on
# Azure, with its own name, tagline and icon. It asks for what it cannot work out
# and runs the other scripts in this folder in order, so none of the steps below
# is done by hand:
#
#   1. Branding         npm run brand, which also gives the app its own Composer
#                       package name and README title, then commits and pushes it
#   2. First image      starts the deploy workflow and waits for it to push the
#                       image (it finishes green, skipping its Azure steps with a notice
#                       until the deploy sign-in exists)
#   3. Provisioning     deploy/azure-provision.sh
#   4. Sign-in          deploy/azure-easyauth-setup.sh
#   5. Protect main     requires the `ci` check, where GitHub allows it
#   6. Deploy sign-in   deploy/azure-oidc-setup.sh, which also turns on deploying
#                       after every merge (left off when main cannot be protected)
#
# Usage, from the main branch of a clone with nothing uncommitted:
#
#   ./deploy/bootstrap.sh [--name "Acme Tracker"] [--tagline "Track everything."]
#                         [--icon brain] [--yes]
#
# --name, --tagline and --icon are asked for when they are not given and the
# terminal is interactive, and otherwise default to what .env.example has now (the
# repository name in title case, for the name). --yes skips the final "go ahead?"
# question. The icon is a Lucide name from https://lucide.dev/icons.
#
# Prerequisites:
#   - git, node 18.3 or newer, npm, the gh CLI 2.25 or newer (gh auth login) and the
#     az CLI 2.79 or newer (az login). The versions are checked before anything is
#     changed. rsvg-convert is optional, for the favicon.ico and apple icon.
#   - A clone whose 'origin' is a GitHub repository, on the main branch.
#
# Settings (environment variables):
#   SQL_ADMIN_PASSWORD  a strong password for the SQL admin login. Asked for when
#                       it is not set and the terminal is interactive.
#   GHCR_USERNAME, GHCR_PAT
#                       pull credentials for the image, when its ghcr.io package is
#                       private (a token with read:packages). Asked for when the
#                       token is not set and the terminal is interactive. Leave it
#                       empty if the package is public.
#   ENABLE_DEPLOY_ON_MERGE
#                       set to false to keep releases manual. By default it is left
#                       on only when main can be protected with the `ci` check.
#   POLL_SECONDS        default: 15. How often to look at the first deploy.
#   WAIT_MINUTES        default: 30. How long to wait for it before giving up.
#
# Every setting of the scripts it runs works too, such as LOCATION, BUDGET_AMOUNT
# and BUDGET_EMAIL. The display name and tagline come from --name and --tagline,
# not from APP_TITLE and APP_TAGLINE.
#
# Running it again is safe, whether a step failed or you want to change a value:
# the branding and the other scripts only change what differs, and the first image
# is not built again once the app exists.
#
# Works with the stock bash 3.2 that ships with macOS.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "$SCRIPT_DIR/lib.sh"

# The oldest versions that the steps below work with, and why. Node 18.3 is where
# util.parseArgs, which `npm run brand` uses, was added. gh 2.25 is where
# `gh variable` was added, which the OIDC and teardown scripts use. az 2.79 is what
# the containerapp extension asks for; an older one installs an older extension that
# this has not been tried with.
MIN_NODE="18.3.0"
MIN_GH="2.25.0"
MIN_AZ="2.79.0"

POLL_SECONDS="${POLL_SECONDS:-15}"
WAIT_MINUTES="${WAIT_MINUTES:-30}"
CURRENT_STEP=""

say() { printf '%s\n' "$*"; }
fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

# Say which step stopped, so the way back in is clear.
on_exit() {
  local status=$?
  if [[ $status -ne 0 && -n "$CURRENT_STEP" ]]; then
    printf '\nStopped during: %s\n' "$CURRENT_STEP" >&2
    printf 'Fix the problem and run this again. Steps that are finished are skipped or repeated safely.\n' >&2
  fi
}
trap on_exit EXIT

usage() {
  sed -n '3,/^# Works with/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' | sed '$d'
}

# ----------------------------------------------------------------------------
# Options
# ----------------------------------------------------------------------------

NAME=""
TAGLINE=""
ICON=""
ASSUME_YES=false
HAVE_NAME=false
HAVE_TAGLINE=false
HAVE_ICON=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --name | --tagline | --icon)
      [[ $# -ge 2 ]] || fail "$1 needs a value."
      case "$1" in
        --name) NAME="$2"; HAVE_NAME=true ;;
        --tagline) TAGLINE="$2"; HAVE_TAGLINE=true ;;
        --icon) ICON="$2"; HAVE_ICON=true ;;
      esac
      shift 2
      ;;
    --yes | -y)
      ASSUME_YES=true
      shift
      ;;
    --help | -h)
      usage
      exit 0
      ;;
    *)
      fail "Unknown option '$1'. Run with --help to see the options."
      ;;
  esac
done

# ----------------------------------------------------------------------------
# Checks before anything is changed
# ----------------------------------------------------------------------------

CAN_PROMPT=false
if [[ -t 0 ]]; then
  CAN_PROMPT=true
fi

for tool in git node npm gh az; do
  command -v "$tool" >/dev/null 2>&1 || fail "'$tool' is not installed or not on the PATH."
done

# Stops when a tool is older than the version the steps need. A version that cannot
# be read is reported but does not stop the run, so an unusual build is not refused.
check_version() {
  local tool="$1" output="$2" minimum="$3" reason="$4" version
  version="$(printf '%s' "$output" | grep -oE '[0-9]+(\.[0-9]+)*' | head -1 || true)"
  if [[ -z "$version" ]]; then
    say "Could not read the version of $tool, so it was not checked. It needs $minimum or newer."
  elif ! version_at_least "$version" "$minimum"; then
    fail "$tool $version is too old: $tool $minimum or newer is needed ($reason). Update $tool and run this again."
  fi
}

check_version node "$(node --version 2>/dev/null || true)" "$MIN_NODE" "util.parseArgs, used by npm run brand"
check_version gh "$(gh --version 2>/dev/null | head -1 || true)" "$MIN_GH" "gh variable, used to turn on deploying after a merge"
check_version az "$(az version --query '"azure-cli"' --output tsv 2>/dev/null || true)" "$MIN_AZ" "what the containerapp extension needs"

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || fail "Run this from inside a clone of the repository."
cd "$REPO_ROOT"

load_remote
resolve_app_name
[[ -n "$REMOTE_OWNER" && -n "$REMOTE_REPO" ]] || fail "The 'origin' remote is not a GitHub repository."
# The Composer package name is the owner and repository, in lowercase.
PACKAGE="$(lowercase "$REMOTE_OWNER")/$APP_NAME"

branch="$(git rev-parse --abbrev-ref HEAD)"
[[ "$branch" == "main" ]] || fail "Run this from the main branch (you are on '$branch'), because that is where the app deploys from."
[[ -z "$(git status --porcelain)" ]] || fail "There are uncommitted changes. Commit or stash them first, so only the branding is committed."

gh auth status >/dev/null 2>&1 || fail "Not logged in to GitHub. Run: gh auth login"
az account show --output none 2>/dev/null || fail "Not logged in to Azure. Run: az login"

# ----------------------------------------------------------------------------
# What to set
# ----------------------------------------------------------------------------

# Ask a question with a default. Prints the answer.
ask() {
  local question="$1" default="$2" reply=""
  if [[ "$CAN_PROMPT" == true ]]; then
    if [[ -n "$default" ]]; then
      read -r -p "$question [$default]: " reply
    else
      read -r -p "$question: " reply
    fi
  fi
  printf '%s' "${reply:-$default}"
}

# Ask for a secret without showing it. Prints the answer.
ask_secret() {
  local question="$1" reply=""
  if [[ "$CAN_PROMPT" == true ]]; then
    read -r -s -p "$question: " reply
    printf '\n' >&2
  fi
  printf '%s' "$reply"
}

# The defaults are what the app has now.
APP_TITLE=""
resolve_app_display
default_name="$APP_TITLE"
default_tagline="$APP_TAGLINE"
default_icon="$(sed -n "s/^import { \\([A-Za-z0-9]*\\) } from '@lucide\\/vue';.*/\\1/p" resources/js/components/AppLogoIcon.vue 2>/dev/null | head -1 | sed -E 's/([a-z0-9])([A-Z])/\1-\2/g' | tr '[:upper:]' '[:lower:]')"
default_icon="${default_icon:-rocket}"
# They are set with --name and --tagline, and the scripts below read them back from
# .env.example, so a stray setting cannot override them.
unset APP_TITLE APP_TAGLINE

say "Setting up $REMOTE_OWNER/$REMOTE_REPO as the Azure app '$APP_NAME'."
say ""

[[ "$HAVE_NAME" == true ]] || NAME="$(ask "Name of the app" "$default_name")"
[[ "$HAVE_TAGLINE" == true ]] || TAGLINE="$(ask "Tagline" "$default_tagline")"
[[ "$HAVE_ICON" == true ]] || ICON="$(ask "Icon (a Lucide name, see lucide.dev/icons)" "$default_icon")"
[[ -n "$NAME" ]] || fail "The app needs a name. Give one with --name."
[[ -n "$TAGLINE" ]] || fail "The app needs a tagline. Give one with --tagline."

if [[ -z "${SQL_ADMIN_PASSWORD:-}" ]]; then
  SQL_ADMIN_PASSWORD="$(ask_secret "SQL admin password (a strong one)")"
fi
[[ -n "$SQL_ADMIN_PASSWORD" ]] || fail "Set SQL_ADMIN_PASSWORD to a strong password."

GHCR_USERNAME="${GHCR_USERNAME:-}"
GHCR_PAT="${GHCR_PAT:-}"
if [[ -z "$GHCR_PAT" ]]; then
  GHCR_PAT="$(ask_secret "Token with read:packages to pull the image (empty if the package is public)")"
fi
if [[ -n "$GHCR_PAT" && -z "$GHCR_USERNAME" ]]; then
  GHCR_USERNAME="$REMOTE_OWNER"
fi

say ""
say "This will:"
say "  1. Set the name to \"$NAME\", the tagline to \"$TAGLINE\" and the icon to $ICON, name the Composer"
say "     package $PACKAGE and the README after the app, then commit and push."
say "  2. Build the first image with the deploy workflow, unless the app already exists."
say "  3. Create the Azure resources in $RESOURCE_GROUP."
say "  4. Set up Microsoft sign-in for the app."
say "  5. Require the ci check on main, where GitHub allows it."
say "  6. Set up the deploy sign-in, and deploying after every merge."
say ""
if [[ "$ASSUME_YES" != true && "$CAN_PROMPT" == true ]]; then
  answer="$(ask "Go ahead?" "y")"
  case "$(lowercase "$answer")" in
    y | yes) ;;
    *) fail "Cancelled. Nothing was changed." ;;
  esac
fi

# ----------------------------------------------------------------------------
# 1. Branding
# ----------------------------------------------------------------------------
CURRENT_STEP="1/6 Branding"
say "==> $CURRENT_STEP"
if [[ ! -d node_modules ]]; then
  npm ci
fi
npm run brand -- --name "$NAME" --tagline "$TAGLINE" --icon "$ICON" --package "$PACKAGE"
if [[ -n "$(git status --porcelain)" ]]; then
  git add -A
  git commit -q -m "Set the app name, tagline and icon" \
    -m "Sets the name to $NAME, the tagline, the icon, the Composer package name $PACKAGE and the README title, with npm run brand, before the first image is built so the app is published with them."
  git push origin main
else
  say "    Nothing changed, so nothing to commit."
fi

# ----------------------------------------------------------------------------
# 2. First image
# ----------------------------------------------------------------------------
CURRENT_STEP="2/6 First image"
say "==> $CURRENT_STEP"

# Waits until the deploy run has built and pushed the image. The run itself fails
# later, at the Azure login, because the deploy sign-in does not exist yet.
wait_for_image() {
  local run_id="$1" waited=0 limit=$((WAIT_MINUTES * 60)) conclusion status
  while :; do
    conclusion="$(gh run view "$run_id" --json jobs --jq '[.jobs[].steps[] | select(.name == "Build and push image") | .conclusion] | .[0] // empty' 2>/dev/null || true)"
    case "$conclusion" in
      success) return 0 ;;
      failure | cancelled | timed_out) return 1 ;;
    esac
    status="$(gh run view "$run_id" --json status --jq '.status' 2>/dev/null || true)"
    [[ "$status" == "completed" ]] && return 1
    [[ $waited -ge $limit ]] && return 2
    sleep "$POLL_SECONDS"
    waited=$((waited + POLL_SECONDS))
  done
}

if az containerapp show -g "$RESOURCE_GROUP" -n "$APP_NAME" --output none 2>/dev/null; then
  say "    The container app already exists, so the first image is not built again."
else
  before="$(gh run list --workflow deploy.yml --event workflow_dispatch --limit 20 --json databaseId --jq '.[].databaseId' 2>/dev/null || true)"
  gh workflow run deploy.yml --ref main
  run_id=""
  for ((attempt = 0; attempt < 40; attempt++)); do
    while read -r candidate; do
      if [[ -n "$candidate" ]] && ! printf '%s\n' "$before" | grep -qx "$candidate"; then
        run_id="$candidate"
        break
      fi
    done < <(gh run list --workflow deploy.yml --event workflow_dispatch --limit 20 --json databaseId --jq '.[].databaseId' 2>/dev/null || true)
    [[ -n "$run_id" ]] && break
    sleep "$POLL_SECONDS"
  done
  [[ -n "$run_id" ]] || fail "The deploy workflow did not start. Check the Actions tab, then run this again."
  say "    Waiting for the image to be built (run $run_id)..."
  result=0
  wait_for_image "$run_id" || result=$?
  case "$result" in
    0) say "    The image is published." ;;
    1) fail "The image build failed. See https://github.com/$REMOTE_OWNER/$REMOTE_REPO/actions/runs/$run_id" ;;
    *) fail "Gave up waiting for the image build after $WAIT_MINUTES minutes. See https://github.com/$REMOTE_OWNER/$REMOTE_REPO/actions/runs/$run_id" ;;
  esac
fi

# ----------------------------------------------------------------------------
# 3. Provisioning
# ----------------------------------------------------------------------------
CURRENT_STEP="3/6 Provisioning Azure"
say "==> $CURRENT_STEP"
SQL_ADMIN_PASSWORD="$SQL_ADMIN_PASSWORD" GHCR_USERNAME="$GHCR_USERNAME" GHCR_PAT="$GHCR_PAT" \
  "$SCRIPT_DIR/azure-provision.sh"

# ----------------------------------------------------------------------------
# 4. Sign-in to the app
# ----------------------------------------------------------------------------
CURRENT_STEP="4/6 Microsoft sign-in (Easy Auth)"
say "==> $CURRENT_STEP"
"$SCRIPT_DIR/azure-easyauth-setup.sh"

# ----------------------------------------------------------------------------
# 5. Protect main
# ----------------------------------------------------------------------------
CURRENT_STEP="5/6 Protecting main"
say "==> $CURRENT_STEP"
PROTECTION_API="repos/$REMOTE_OWNER/$REMOTE_REPO/branches/main/protection"
PROTECTED=false
protection_note=""
if existing="$(gh api "$PROTECTION_API" --jq '.required_status_checks.contexts // [] | index("ci") | tostring' 2>/dev/null)"; then
  # Someone already set rules. They are left exactly as they are.
  if [[ "$existing" != "null" ]]; then
    PROTECTED=true
    say "    main already requires the ci check."
  else
    protection_note="main already has protection that does not require the ci check, so it was left as it is."
  fi
else
  if output="$(printf '%s' '{"required_status_checks":{"strict":false,"contexts":["ci"]},"enforce_admins":false,"required_pull_request_reviews":null,"restrictions":null}' \
    | gh api --method PUT "$PROTECTION_API" --input - 2>&1)"; then
    PROTECTED=true
    say "    main now requires the ci check."
  else
    protection_note="main could not be protected: $(printf '%s' "$output" | head -1)"
  fi
fi
[[ -z "$protection_note" ]] || say "    $protection_note"

# ----------------------------------------------------------------------------
# 6. Deploy sign-in
# ----------------------------------------------------------------------------
CURRENT_STEP="6/6 Deploy sign-in (OIDC)"
say "==> $CURRENT_STEP"
deploy_on_merge="${ENABLE_DEPLOY_ON_MERGE:-}"
if [[ -z "$deploy_on_merge" ]]; then
  if [[ "$PROTECTED" == true ]]; then
    deploy_on_merge=true
  else
    deploy_on_merge=false
    say "    Deploying after a merge is left off, because main does not require the ci check."
    say "    Turn it on later with: gh variable set DEPLOY_ON_MERGE --body true"
  fi
fi
ENABLE_DEPLOY_ON_MERGE="$deploy_on_merge" "$SCRIPT_DIR/azure-oidc-setup.sh"

# ----------------------------------------------------------------------------
CURRENT_STEP=""
FQDN="$(az containerapp show -g "$RESOURCE_GROUP" -n "$APP_NAME" --query properties.configuration.ingress.fqdn -o tsv 2>/dev/null || true)"

say ""
say "Done. $NAME is set up."
if [[ -n "$FQDN" ]]; then
  say ""
  say "  Open:  https://$FQDN"
fi
say ""
say "Next:"
say "  - Sign in with your Microsoft account, then register your account on the app's own"
say "    register page, once."
if [[ "$(lowercase "$deploy_on_merge")" == "true" || "$(lowercase "$deploy_on_merge")" == "1" ]]; then
  say "  - Merging to main now deploys the app."
else
  say "  - Merging to main does not deploy. Start the deploy workflow by hand to release."
fi
