#!/usr/bin/env bash
#
# Every Merchant — release deploy.
#
# Run on each target host (they are separate ISPConfig users on millions.naitalk.com):
#
#   everymerchant-deploy         (naitalk2loan / web67)   → api + admin
#     repo:  ~/private/naipay                 (npm workspaces monorepo)
#     pm2:   every-merchant-web, every-merchant-queue-worker
#
#   everymerchant-portal-deploy  (naitalk2portal / web68) → portal
#     repo:  ~/private/naipay-merchant-web
#     pm2:   every-merchant-portal
#
# Usage:
#   bash infrastructure/deployment/deploy.sh [api|admin|portal|all]
#
# Config comes from environment variables, normally set in
# infrastructure/deployment/deploy.env next to this script (gitignored,
# per-host — see deploy.env.example):
#
#   EM_API_DIR      Laravel application root (contains artisan)
#   EM_ADMIN_DIR    apps/admin-web directory
#   EM_PORTAL_DIR   apps/merchant-web directory
#   EM_ADMIN_PM2    pm2 process name for admin-web    (default: every-merchant-web)
#   EM_PORTAL_PM2   pm2 process name for merchant-web (default: every-merchant-portal)
#   EM_BRANCH       git branch to pull, if a checkout (default: main)
#   EM_GIT_PULL     1 to `git pull` where a .git exists, 0 to skip (default: 1)
#   EM_NPM_INSTALL  1 to run `npm ci` at the workspace root, 0 to skip  (default: 1)
#   EM_COMPOSER     1 to run `composer install --no-dev`, 0 to skip     (default: 1)
#   EM_MIGRATE      1 to run `php artisan migrate --force`, 0 to skip   (default: 1)
#
# A component named explicitly whose directory is unset/missing is a hard
# error. `all` skips unconfigured components with a warning, so the same
# command runs on both hosts.

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

EM_ADMIN_PM2="${EM_ADMIN_PM2:-every-merchant-web}"
EM_PORTAL_PM2="${EM_PORTAL_PM2:-every-merchant-portal}"
EM_BRANCH="${EM_BRANCH:-main}"
EM_GIT_PULL="${EM_GIT_PULL:-1}"
EM_NPM_INSTALL="${EM_NPM_INSTALL:-1}"
EM_COMPOSER="${EM_COMPOSER:-1}"
EM_MIGRATE="${EM_MIGRATE:-1}"

TARGET="${1:-all}"

# --- Helpers --------------------------------------------------------------

log()  { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

resolve_dir() {
  local var="$1" component="$2" explicit="$3" dir="${!1:-}"
  if [[ -z "$dir" ]]; then
    [[ "$explicit" == "1" ]] && die "$var is not set — cannot deploy '$component'."
    warn "skip '$component' — $var not set"; return 1
  fi
  dir="${dir/#\~/$HOME}"
  if [[ ! -d "$dir" ]]; then
    [[ "$explicit" == "1" ]] && die "$var=$dir does not exist — cannot deploy '$component'."
    warn "skip '$component' — $dir does not exist"; return 1
  fi
  printf '%s' "$dir"
}

# Walks up from $1 until it finds a package.json declaring "workspaces";
# falls back to $1 if there is none.
workspace_root() {
  local dir; dir="$(cd "$1" && pwd)"
  while [[ "$dir" != "/" ]]; do
    if [[ -f "$dir/package.json" ]] && grep -q '"workspaces"' "$dir/package.json"; then
      printf '%s' "$dir"; return 0
    fi
    dir="$(dirname "$dir")"
  done
  printf '%s' "$1"
}

git_pull() {
  local dir="$1"
  [[ "$EM_GIT_PULL" == "1" ]] || { warn "git pull skipped (EM_GIT_PULL=$EM_GIT_PULL)"; return; }
  local top; top="$(git -C "$dir" rev-parse --show-toplevel 2>/dev/null || true)"
  [[ -n "$top" ]] || { warn "$dir is not a git checkout — code must already be in place (rsync)"; return; }
  log "git pull ($EM_BRANCH) in $top"
  git -C "$top" fetch --prune origin
  git -C "$top" checkout "$EM_BRANCH"
  git -C "$top" reset --hard "origin/$EM_BRANCH"
  git -C "$top" --no-pager log -1 --oneline
}

# --- Components ---------------------------------------------------------------

deploy_api() {
  local dir; dir="$(resolve_dir EM_API_DIR api "$1")" || return 0
  [[ -f "$dir/artisan" ]] || die "$dir has no artisan — not a Laravel root."

  git_pull "$dir"

  if [[ "$EM_COMPOSER" == "1" ]]; then
    log "backend: composer install"
    ( cd "$dir" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction )
  fi

  if [[ "$EM_MIGRATE" == "1" ]]; then
    log "backend: migrate"
    ( cd "$dir" && php artisan migrate --force )
  fi

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

  local root; root="$(workspace_root "$dir")"

  git_pull "$root"

  if [[ "$EM_NPM_INSTALL" == "1" ]]; then
    log "$component: npm ci ($root)"
    ( cd "$root" && npm ci )
  else
    warn "$component: npm ci skipped (EM_NPM_INSTALL=$EM_NPM_INSTALL)"
  fi

  log "$component: npm run build ($dir)"
  ( cd "$dir" && npm run build )

  log "$component: pm2 restart $pm2_name"
  if pm2 describe "$pm2_name" >/dev/null 2>&1; then
    pm2 restart "$pm2_name" --update-env
    pm2 save >/dev/null 2>&1 || true
  else
    warn "pm2 process '$pm2_name' not found — start it once by hand, then re-run"
  fi

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
