#!/usr/bin/env bash
# AgriVision VPS install script (testing / advanced self-hosting)
# No Docker. No PowerShell. No web installer.
#
# Example (prefer jsDelivr; raw.githubusercontent.com/main may cache stale files):
#   curl -fsSL https://cdn.jsdelivr.net/gh/Pelessaria/AgriVision-FS25@main/scripts/install.sh -o install.sh
#   chmod +x install.sh
#   ./install.sh --target /var/www/agrivision \
#     --db-host 127.0.0.1 --db-name agrivision --db-user agrivision --db-password 'secret' \
#     --app-url https://agrivision.example.com --migrate
#
# From a git clone:
#   ./scripts/install.sh --skip-download --migrate \
#     --db-host 127.0.0.1 --db-name agrivision --db-user agrivision --db-password 'secret'

set -euo pipefail

OWNER="Pelessaria"
REPO="AgriVision-FS25"
BRANCH="main"
TARGET=""
SKIP_DOWNLOAD=0
MIGRATE=0
BUILD_FRONTEND=0
DB_HOST=""
DB_PORT="3306"
DB_NAME=""
DB_USER=""
DB_PASSWORD=""
DB_CONNECTION="mysql"
APP_URL="http://127.0.0.1:8000"
APP_NAME="AgriVision"
PHP_BIN="${PHP_BIN:-php}"

usage() {
  cat <<'EOF'
AgriVision VPS install (no Docker)

Usage:
  ./scripts/install.sh [options]

Options:
  --target DIR          Install into DIR
  --skip-download       Use existing tree (git clone already present)
  --branch NAME         Git branch (default: main)
  --owner NAME          GitHub owner (default: Pelessaria)
  --repo NAME           GitHub repo (default: AgriVision-FS25)
  --db-host HOST        MySQL/MariaDB host
  --db-port PORT        Database port (default: 3306)
  --db-name NAME        Database name
  --db-user USER        Database user
  --db-password PASS    Database password
  --db-connection NAME  mysql or mariadb (default: mysql)
  --app-url URL         APP_URL (default: http://127.0.0.1:8000)
  --app-name NAME       APP_NAME (default: AgriVision)
  --migrate             Run php artisan migrate --force
  --build-frontend      Run npm ci && npm run build (needs Node 20+)
  -h, --help            Show this help
EOF
}

log() {
  printf '\n==> %s\n' "$*"
}

die() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "Missing command: $1"
}

# Always write KEY="value" (escaped). Uses awk ENVIRON to avoid quote/sed pitfalls.
set_env_key() {
  local file="$1"
  local key="$2"
  local value="$3"
  local escaped line tmp

  escaped=$(printf '%s' "$value" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')
  line="${key}=\"${escaped}\""
  tmp=$(mktemp)

  KEY="$key" LINE="$line" awk '
    BEGIN { k = ENVIRON["KEY"]; line = ENVIRON["LINE"]; done = 0 }
    index($0, k "=") == 1 { print line; done = 1; next }
    { print }
    END { if (!done) print line }
  ' "$file" >"$tmp"

  mv "$tmp" "$file"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --target) TARGET="$2"; shift 2 ;;
    --owner) OWNER="$2"; shift 2 ;;
    --repo) REPO="$2"; shift 2 ;;
    --branch) BRANCH="$2"; shift 2 ;;
    --skip-download) SKIP_DOWNLOAD=1; shift ;;
    --migrate) MIGRATE=1; shift ;;
    --build-frontend) BUILD_FRONTEND=1; shift ;;
    --db-host) DB_HOST="$2"; shift 2 ;;
    --db-port) DB_PORT="$2"; shift 2 ;;
    --db-name) DB_NAME="$2"; shift 2 ;;
    --db-user) DB_USER="$2"; shift 2 ;;
    --db-password) DB_PASSWORD="$2"; shift 2 ;;
    --db-connection) DB_CONNECTION="$2"; shift 2 ;;
    --app-url) APP_URL="$2"; shift 2 ;;
    --app-name) APP_NAME="$2"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) die "Unknown argument: $1 (try --help)" ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-$0}")" && pwd)"
DEFAULT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [[ -n "$TARGET" ]]; then
  ROOT="$TARGET"
elif [[ -f "${DEFAULT_ROOT}/backend/artisan" ]] || [[ -f "${DEFAULT_ROOT}/scripts/install.sh" ]]; then
  ROOT="$DEFAULT_ROOT"
else
  ROOT="$(pwd)/AgriVision-FS25"
fi

BACKEND="${ROOT}/backend"

log "AgriVision install -> ${ROOT}"
need_cmd "$PHP_BIN"
need_cmd curl
need_cmd unzip

PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
"$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || die "PHP 8.3+ required (found ${PHP_VERSION})"

for ext in ctype curl dom fileinfo mbstring openssl pdo_mysql tokenizer xml zip; do
  "$PHP_BIN" -m | grep -qi "^${ext}$" || die "PHP extension missing: ${ext}"
done

if [[ "$SKIP_DOWNLOAD" -eq 0 ]]; then
  log "Downloading ${OWNER}/${REPO}@${BRANCH}"
  WORK="$(mktemp -d)"
  trap 'rm -rf "$WORK"' EXIT
  ZIP="${WORK}/source.zip"
  URL="https://codeload.github.com/${OWNER}/${REPO}/zip/refs/heads/${BRANCH}"
  curl -fsSL "$URL" -o "$ZIP"
  unzip -q "$ZIP" -d "$WORK"
  EXTRACTED="$(find "$WORK" -maxdepth 1 -type d -name "${REPO}-*" | head -n1)"
  [[ -n "$EXTRACTED" ]] || die "Extracted repo folder not found"
  mkdir -p "$ROOT"
  if [[ -f "${BACKEND}/.env" ]]; then
    cp "${BACKEND}/.env" "${WORK}/.env.preserve"
  fi
  cp -a "${EXTRACTED}/." "${ROOT}/"
  if [[ -f "${WORK}/.env.preserve" ]]; then
    mkdir -p "$BACKEND"
    cp "${WORK}/.env.preserve" "${BACKEND}/.env"
  fi
  trap - EXIT
  rm -rf "$WORK"
else
  log "Using existing tree (--skip-download)"
fi

[[ -f "${BACKEND}/artisan" ]] || die "backend/artisan missing under ${ROOT}"

cd "$BACKEND"

log "Composer dependencies"
if [[ ! -f vendor/autoload.php ]]; then
  if [[ -f composer.phar ]]; then
    "$PHP_BIN" composer.phar install --no-dev --optimize-autoloader --no-interaction
  elif command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader --no-interaction
  else
    curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o composer.phar
    "$PHP_BIN" composer.phar install --no-dev --optimize-autoloader --no-interaction
  fi
else
  echo "vendor/ already present - skipping composer install"
fi

if [[ ! -f .env ]]; then
  cp .env.example .env
  log "Created .env from .env.example"
fi

set_env_key .env APP_NAME "$APP_NAME"
set_env_key .env APP_URL "$APP_URL"
set_env_key .env APP_ENV "production"
set_env_key .env APP_DEBUG "false"
set_env_key .env APP_LOCALE "de"

if [[ -n "$DB_HOST" ]]; then
  set_env_key .env DB_CONNECTION "$DB_CONNECTION"
  set_env_key .env DB_HOST "$DB_HOST"
  set_env_key .env DB_PORT "$DB_PORT"
  set_env_key .env DB_DATABASE "$DB_NAME"
  set_env_key .env DB_USERNAME "$DB_USER"
  set_env_key .env DB_PASSWORD "$DB_PASSWORD"
fi

log "Application key"
"$PHP_BIN" artisan key:generate --force

log "Writable dirs"
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views
mkdir -p storage/logs storage/app/public bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

if [[ "$BUILD_FRONTEND" -eq 1 ]]; then
  need_cmd npm
  log "Building frontend"
  (
    cd "${ROOT}/frontend"
    npm ci
    npm run build
  )
  mkdir -p "${BACKEND}/public/app"
  cp -a "${ROOT}/frontend/dist/." "${BACKEND}/public/app/"
fi

if [[ -d "${ROOT}/frontend/dist" && ! -f "${BACKEND}/public/app/index.html" ]]; then
  log "Copying frontend/dist -> backend/public/app"
  mkdir -p "${BACKEND}/public/app"
  cp -a "${ROOT}/frontend/dist/." "${BACKEND}/public/app/"
fi

if [[ "$MIGRATE" -eq 1 ]]; then
  [[ -n "$DB_HOST" && -n "$DB_NAME" && -n "$DB_USER" ]] \
    || die "--migrate requires --db-host, --db-name, --db-user"
  log "Running migrations"
  "$PHP_BIN" artisan migrate --force
fi

if [[ -d "${ROOT}/.git" ]] && command -v git >/dev/null 2>&1; then
  SHA="$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || true)"
else
  SHA=""
fi
mkdir -p storage/app
if [[ -n "$SHA" ]]; then
  printf '{\n  "sha": "%s",\n  "installedAt": "%s",\n  "via": "install.sh",\n  "version": "0.3.0"\n}\n' \
    "$SHA" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > storage/app/update-state.json
else
  printf '{\n  "sha": null,\n  "installedAt": "%s",\n  "via": "install.sh",\n  "version": "0.3.0"\n}\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > storage/app/update-state.json
fi

cat <<EOF

--------------------------------------------------
AgriVision install finished
--------------------------------------------------
Root:     ${ROOT}
Backend:  ${BACKEND}
Public:   ${BACKEND}/public
APP_URL:  ${APP_URL}

Quick test:
  cd ${BACKEND}
  ${PHP_BIN} artisan serve --host=0.0.0.0 --port=8000

Production document root:
  ${BACKEND}/public

Updates later:
  cd ${BACKEND} && ${PHP_BIN} artisan agrivision:update --check
  cd ${BACKEND} && ${PHP_BIN} artisan agrivision:update --apply

Repo: https://github.com/${OWNER}/${REPO}
--------------------------------------------------
EOF
