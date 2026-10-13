#!/usr/bin/env bash
# Run as the Plesk subscription user, never through a browser.
set +x
set -Eeuo pipefail
umask 077

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
NON_INTERACTIVE=0
SKIP_COMPOSER=0
SKIP_HTTP=0
INSTALL_CRON=0
CREATE_CLIENT=0
DRY_RUN=0

usage() {
  cat <<'HELP'
Usage: bash bin/post-deploy.sh [options]
  --php PATH          Plesk PHP CLI, e.g. /opt/plesk/php/8.3/bin/php
  --composer PATH     Composer executable or composer.phar
  --non-interactive   Use .env and DEPLOY_* environment values; never prompt
  --skip-composer     Verify existing vendor dependencies instead of installing
  --skip-http         Skip public HTTPS probes (configuration checks still run)
  --install-cron      Install/update an hourly task in THIS user's crontab
  --create-client     Save static ChatGPT OAuth credentials to private storage
  --dry-run           Check runtime and describe actions without changing files
  --help              Show help

Optional environment inputs (credentials are never command-line arguments):
  DEPLOY_APP_URL, DEPLOY_STORAGE_PATH, DEPLOY_ALLOWED_EMAILS,
  DEPLOY_GOOGLE_CLIENT_ID, DEPLOY_GOOGLE_CLIENT_SECRET,
  DEPLOY_OAUTH_REDIRECT_URIS, DEPLOY_MUSIC_CLIENT_VERSION, DEPLOY_MUSIC_LOCATION

Existing keys and credentials are preserved unless an explicit input replaces
credentials. APP_KEY is generated only for a genuinely new installation.
Google Cloud registration and browser consent remain manual.
HELP
}
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
trap 'printf "Post-deployment stopped at line %s. Review the error before rerunning.\n" "$LINENO" >&2' ERR
while (($#)); do
  case "$1" in
    --php|--composer)
      (($# >= 2)) || fail "$1 requires a path"
      if [[ "$1" == --php ]]; then PHP_BIN="$2"; else COMPOSER_BIN="$2"; fi
      shift 2 ;;
    --non-interactive) NON_INTERACTIVE=1; shift ;;
    --skip-composer) SKIP_COMPOSER=1; shift ;;
    --skip-http) SKIP_HTTP=1; shift ;;
    --install-cron) INSTALL_CRON=1; shift ;;
    --create-client) CREATE_CLIENT=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    --help|-h) usage; exit 0 ;;
    *) fail "Unknown option: $1" ;;
  esac
done
[[ "$EUID" -ne 0 ]] || fail 'Run as the Plesk subscription user, not root.'
command -v "$PHP_BIN" >/dev/null || fail 'Selected PHP executable was not found.'
PHP_BIN="$(command -v "$PHP_BIN")"
cd -- "$ROOT"
"$PHP_BIN" "$ROOT/bin/deploy-config.php" runtime
if ((DRY_RUN)); then
  printf 'Dry run: project %s; PHP %s\n' "$ROOT" "$PHP_BIN"
  printf 'Would verify/install dependencies, prepare private configuration, initialize storage, and run cleanup.\n'
  printf 'HTTP probes: %s; cron installation: %s; static client creation: %s\n' "$((1-SKIP_HTTP))" "$INSTALL_CRON" "$CREATE_CLIENT"
  exit 0
fi
command -v flock >/dev/null || fail 'flock is required to serialize deployment runs.'
exec 9> "$ROOT/.post-deploy.lock"
flock -n 9 || fail 'Another post-deployment run is active.'

if (( ! SKIP_COMPOSER )); then
  command -v "$COMPOSER_BIN" >/dev/null || [[ -f "$COMPOSER_BIN" ]] || fail 'Composer missing; supply --composer or upload vendor and use --skip-composer.'
  if [[ "$COMPOSER_BIN" == *.phar ]]; then
    "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
  else
    # Composer launchers usually invoke php from PATH. Select the matching CLI.
    PATH="$(dirname -- "$PHP_BIN"):$PATH" "$COMPOSER_BIN" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
  fi
fi
[[ -f "$ROOT/vendor/autoload.php" ]] || fail 'vendor/autoload.php missing.'
"$PHP_BIN" "$ROOT/bin/deploy-config.php" dependencies

prompt_value() {
  local name="$1" label="$2" current value
  [[ -z "${!name:-}" ]] || return 0
  current="$("$PHP_BIN" "$ROOT/bin/deploy-config.php" read "${name#DEPLOY_}")"
  if ((NON_INTERACTIVE)); then return 0; fi
  read -r -p "$label [$current]: " value
  if [[ -n "$value" ]]; then printf -v "$name" '%s' "$value"; export "$name"; fi
}
prompt_secret() {
  local name="$1" label="$2" present value
  [[ -z "${!name:-}" ]] || return 0
  present="$("$PHP_BIN" "$ROOT/bin/deploy-config.php" has "${name#DEPLOY_}")"
  if [[ "$present" == yes ]] || ((NON_INTERACTIVE)); then return 0; fi
  read -r -s -p "$label: " value; printf '\n'
  if [[ -n "$value" ]]; then printf -v "$name" '%s' "$value"; export "$name"; fi
}
prompt_value DEPLOY_APP_URL 'Public HTTPS origin'
prompt_value DEPLOY_STORAGE_PATH 'Private local storage directory'
prompt_value DEPLOY_ALLOWED_EMAILS 'Invited Google email addresses (comma separated)'
prompt_secret DEPLOY_GOOGLE_CLIENT_ID 'Google OAuth client ID'
prompt_secret DEPLOY_GOOGLE_CLIENT_SECRET 'Google OAuth client secret'
prompt_value DEPLOY_OAUTH_REDIRECT_URIS 'Exact permitted ChatGPT callback URL(s)'
"$PHP_BIN" "$ROOT/bin/deploy-config.php" prepare
unset DEPLOY_GOOGLE_CLIENT_ID DEPLOY_GOOGLE_CLIENT_SECRET
"$PHP_BIN" "$ROOT/bin/console.php" init
"$PHP_BIN" "$ROOT/bin/console.php" cleanup
"$PHP_BIN" "$ROOT/bin/deploy-config.php" verify
"$PHP_BIN" "$ROOT/bin/console.php" music:check

STORAGE="$("$PHP_BIN" "$ROOT/bin/deploy-config.php" storage)"
if ((CREATE_CLIENT)); then "$PHP_BIN" "$ROOT/bin/deploy-config.php" client; fi

# Quote each path for the shell used by cron; do not embed secrets in the task.
shell_quote() { local v="$1"; v="${v//\'/\'\\\'\'}"; printf "'%s'" "$v"; }
CRON_COMMAND="$(shell_quote "$PHP_BIN") $(shell_quote "$ROOT/bin/console.php") cleanup >> $(shell_quote "$STORAGE/cleanup.log") 2>&1"
CRON_COMMAND="${CRON_COMMAND//%/\\%}"
CRON_LINE="17 * * * * $CRON_COMMAND"
if ((INSTALL_CRON)); then
  command -v crontab >/dev/null || fail 'crontab unavailable; add the displayed command in Plesk Scheduled Tasks.'
  "$PHP_BIN" "$ROOT/bin/deploy-config.php" cron "$CRON_LINE"
else
  printf '\nAdd an hourly Plesk scheduled task as the subscription user:\n%s\n' "$CRON_COMMAND"
fi

if (( ! SKIP_HTTP )); then "$PHP_BIN" "$ROOT/bin/deploy-config.php" http; fi
cat <<'NEXT'

Server-side setup completed. Manual actions remaining:
  1. Confirm the domain document root is public/, TLS and PHP-FPM settings.
  2. Complete Google Cloud API/consent/client registration if not already done.
  3. Sign in at the dashboard, grant YouTube access, and confirm your channel.
  4. Add the MCP endpoint to ChatGPT with OAuth and complete browser consent.
  5. Run docs/ACCEPTANCE.md using disposable playlists and two accounts.
  6. Configure consistent backups and review actual Cloud quota usage.
See docs/PLESK.md, docs/CONNECTIONS.md, and docs/OPERATIONS.md.
NEXT
