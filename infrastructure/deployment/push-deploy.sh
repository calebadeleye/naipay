#!/usr/bin/env bash
#
# Every Merchant — push a release from this machine to a target host.
#
# The hosts are rsync targets, not git checkouts (see deploy.env.example), so a
# release is two steps: copy the tree up, then run the host-side deploy.sh
# (composer install, migrate, cache rebuild, asset build, pm2 restart).
#
# Usage:
#   infrastructure/deployment/push-deploy.sh [options] [target]
#
#   target   passed straight to the host's deploy.sh: api | admin | portal | all
#            (default: all — unconfigured components are skipped with a warning)
#
# Options:
#   -H, --host <ssh-host>    SSH host alias      (default: everymerchant-deploy)
#   -d, --remote-dir <path>  repo root on host   (default: ~/private/naipay)
#   -n, --dry-run            rsync --dry-run and skip the host deploy
#       --no-verify          skip the clean-tree / branch / origin checks
#       --rsync-only         copy the tree, do not run deploy.sh
#       --deploy-only        run deploy.sh, do not rsync
#   -y, --yes                do not prompt for confirmation
#   -h, --help               this text
#
# Safe by construction:
#   * .env, storage/, node_modules, vendor, .git and build output are never
#     copied and (being excluded) are never deleted by --delete either;
#   * the host's own deploy.sh owns migrations and restarts;
#   * on success the deployed commit SHA is recorded at
#     <remote-dir>/.deployed-commit.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

SSH_HOST="everymerchant-deploy"
REMOTE_DIR="~/private/naipay"
TARGET="all"
DRY_RUN=0
VERIFY=1
DO_RSYNC=1
DO_DEPLOY=1
ASSUME_YES=0

log()  { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    -H|--host)       SSH_HOST="$2"; shift 2 ;;
    -d|--remote-dir) REMOTE_DIR="$2"; shift 2 ;;
    -n|--dry-run)    DRY_RUN=1; shift ;;
    --no-verify)     VERIFY=0; shift ;;
    --rsync-only)    DO_DEPLOY=0; shift ;;
    --deploy-only)   DO_RSYNC=0; shift ;;
    -y|--yes)        ASSUME_YES=1; shift ;;
    -h|--help)       sed -n '2,40p' "$0"; exit 0 ;;
    api|admin|portal|all) TARGET="$1"; shift ;;
    *) die "unknown argument '$1' (see --help)" ;;
  esac
done

cd "$REPO_ROOT"

# --- Pre-flight ------------------------------------------------------------

COMMIT="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"

if [[ "$VERIFY" == "1" ]]; then
  [[ -z "$(git status --porcelain)" ]] \
    || die "working tree is dirty — commit or stash first, or pass --no-verify"

  [[ "$BRANCH" == "main" ]] \
    || die "on branch '$BRANCH', not main — pass --no-verify to deploy it anyway"

  git fetch --quiet origin main || warn "could not fetch origin/main"
  if [[ -n "$(git rev-parse --verify --quiet origin/main)" ]]; then
    [[ "$(git rev-parse HEAD)" == "$(git rev-parse origin/main)" ]] \
      || die "HEAD is not level with origin/main — push first, or pass --no-verify"
  fi
fi

log "release plan"
cat <<PLAN
  from    $REPO_ROOT
  commit  $COMMIT ($BRANCH)
  to      $SSH_HOST:$REMOTE_DIR
  target  deploy.sh $TARGET   (composer=host, migrate=host)
  rsync   $([[ $DO_RSYNC == 1 ]] && echo yes || echo skipped)$([[ $DRY_RUN == 1 ]] && echo '  [dry-run]')
  deploy  $([[ $DO_DEPLOY == 1 && $DRY_RUN == 0 ]] && echo yes || echo skipped)
PLAN

if [[ "$ASSUME_YES" != "1" && "$DRY_RUN" != "1" ]]; then
  read -r -p $'\nProceed? [y/N] ' reply
  [[ "$reply" == "y" || "$reply" == "Y" ]] || die "aborted"
fi

# --- Copy ---------------------------------------------------------------------

if [[ "$DO_RSYNC" == "1" ]]; then
  log "rsync → $SSH_HOST:$REMOTE_DIR"

  # Trailing slash on the source: copy the contents of REPO_ROOT into REMOTE_DIR.
  # Excluded paths are also protected from --delete (rsync will not remove an
  # excluded path on the receiver without --delete-excluded, which we never set).
  rsync -az --delete $([[ $DRY_RUN == 1 ]] && echo --dry-run) --info=stats1,del,name0 \
    --exclude='.git/' \
    --exclude='.github/' \
    --exclude='**/node_modules/' \
    --exclude='backend/api/vendor/' \
    --exclude='backend/api/.env' \
    --exclude='backend/api/.env.*' \
    --exclude='backend/api/storage/' \
    --exclude='backend/api/bootstrap/cache/*.php' \
    --exclude='apps/*/.next/' \
    --exclude='apps/*/.env' \
    --exclude='apps/*/.env.*' \
    --exclude='infrastructure/deployment/deploy.env' \
    --exclude='.DS_Store' \
    --exclude='**/.DS_Store' \
    "$REPO_ROOT/" "$SSH_HOST:$REMOTE_DIR/"
fi

# --- Deploy ----------------------------------------------------------------

if [[ "$DO_DEPLOY" == "1" && "$DRY_RUN" == "0" ]]; then
  log "deploy.sh $TARGET on $SSH_HOST"
  # -t so the host-side coloured output and any prompt render normally.
  ssh -t "$SSH_HOST" "cd $REMOTE_DIR && bash infrastructure/deployment/deploy.sh $TARGET && printf %s '$COMMIT' > .deployed-commit && echo && echo 'deployed commit: '\$(cat .deployed-commit)"
fi

log "done"
