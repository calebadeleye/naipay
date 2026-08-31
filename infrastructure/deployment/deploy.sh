#!/usr/bin/env bash
#
# Every Merchant — release deploy.
#
# Run on each target host (they are separate ISPConfig users):
#
#   everymerchant-deploy         (naitalk2loan)   → api + admin
#   everymerchant-portal-deploy  (naitalk2portal) → portal
#
# Usage:
#   bash infrastructure/deployment/deploy.sh [api|admin|portal|all]
#
# Paths and pm2 process names come from environment variables, which are
# normally set in infrastructure/deployment/deploy.env next to this script
# (gitignored — it is per-host and never committed). See deploy.env.example.
#
#   EM_API_DIR      Laravel application root
#   EM_ADMIN_DIR    apps/admin-web root (its own checkout or a subdir)
#   EM_PORTAL_DIR   apps/merchant-web root
#   EM_ADMIN_PM2    pm2 process name for admin-web   (default: every-merchant-admin)
#   EM_PORTAL_PM2   pm2 process name for merchant-web (default: every-merchant-portal)
#   EM_BRANCH       git branch to pull               (default: main)
#   EM_GIT_PULL     1 to `git pull` in each dir, 0 to skip (default: 1)
#
# A component named explicitly on the command line whose directory is unset or
# missing is a hard error. `all` skips unconfigured components with a warning,
# so the same command works on both hosts.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- Config -----------------------------------------------------------------

ENV_FILE="${DEPLOY_ENV:-$SCRIPT_DIR/deploy.env}"
if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a; source "$ENV_FILE"; set +a
  echo "config: $ENV_FILE"
else
  echo "config: none ($ENV_FILE not found) — relying on environment"
fi

EM_ADMIN_PM2="${EM_ADMIN_PM2:-every-merchant-admin}"
EM_PORTAL_PM2="${EM_PORTAL_PM2:-every-merchant-portal}"
EM_BRANCH="${EM_BRANCH:-main}"
EM_GIT_PULL="${EM_GIT_PULL:-1}"

TARGET="${1:-all}"

# --- Helpers --------------------------------------------------------------

log()  { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# resolve_dir <VAR_NAME> <component> <explicitly_requested>
# Echoes the directory to stdout, or returns 1 to signal "skip".
resolve_dir() {
  local var="$1" component="$2" explicit="$3" dir="${!1:-}"

  if [[ -z "$dir" ]]; then
    if [[ "$explicit" == "1" ]]; then die "$var is not set — cannot deploy '$component'."; fi
    warn "skip '$component' — $var not set"
    return 1
  fi
  if [[ ! -d "$dir" ]]; then
    if [[ "$explicit" == "1" ]]; then die "$var=$dir does not exist — cannot deploy '$component'."; fi
    warn "skip '$component' — $dir does not exist"
    return 1
  fi
  printf '%s' "$dir"
}

git_pull() {
  local dir="$1"
  if [[ "$EM_GIT_PULL" != "1" ]]; then warn "git pull skipped (EM_GIT_PULL=$EM_GIT_PULL)"; return; fi
  if [[ ! -d "$dir/.git" ]]; then warn "$dir is not a git checkout — nothing to pull"; return; fi

  log "git pull ($EM_BRANCH) in $dir"
  git -C "$dir" fetch --prune origin
  git -C "$dir" checkout "$EM_BRANCH"
  git -C "$dir" reset --hard "origin/$EM_BRANCH"
  git -C "$dir" --no-pager log -1 --oneline
}

# --- Components ---------------------------------------------------------------

deploy_api() {
  local dir; dir="$(resolve_dir EM_API_DIR api "$1")" || return 0

  git_pull "$dir"

  log "backend: composer install"
  ( cd "$dir" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction )

  log "backend: migrate"
  ( cd "$dir" && php artisan migrate --force )

  log "backend: rebuild caches"
  ( cd "$dir" \
      && php artisan optimize:clear \
      && php artisan config:cache \
      && php artisan route:cache \
      && php artisan event:cache )

  log "backend: restart queue workers"
  ( cd "$dir" && php artisan queue:restart )

  echo "api ✓"
}

deploy_web() {
  local component="$1" var="$2" pm2_name="$3" explicit="$4"
  local dir; dir="$(resolve_dir "$var" "$component" "$explicit")" || return 0

  git_pull "$dir"

  log "$component: npm ci"
  ( cd "$dir" && npm ci )

  log "$component: npm run build"
  ( cd "$dir" && npm run build )

  log "$component: pm2 restart $pm2_name"
  if pm2 describe "$pm2_name" >/dev/null 2>&1; then
    pm2 restart "$pm2_name" --update-env
  else
    warn "pm2 process '$pm2_name' not found — start it once by hand, then re-run"
  fi
  pm2 save >/dev/null 2>&1 || true

  echo "$component ✓"
}

# --- Dispatch --------------------------------------------------------------

case "$TARGET" in
  api)    deploy_api 1 ;;
  admin)  deploy_web admin  EM_ADMIN_DIR  "$EM_ADMIN_PM2"  1 ;;
  portal) deploy_web portal EM_PORTAL_DIR "$EM_PORTAL_PM2" 1 ;;
  all)
    deploy_api 0
    deploy_web admin  EM_ADMIN_DIR  "$EM_ADMIN_PM2"  0
    deploy_web portal EM_PORTAL_DIR "$EM_PORTAL_PM2" 0
    ;;
  *) die "unknown target '$TARGET' (expected: api | admin | portal | all)" ;;
esac

log "deploy finished"
