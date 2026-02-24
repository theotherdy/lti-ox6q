#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

set_env() {
  local key="$1"
  local value="$2"

  if grep -qE "^${key}=" .env 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" .env
  else
    echo "${key}=${value}" >> .env
  fi
}

# Ensure .env exists (do not overwrite)
if [ ! -f .env ]; then
  if [ -f .env.example ]; then
    cp .env.example .env
  else
    touch .env
  fi
fi

# Configure MariaDB connection
set_env DB_CONNECTION mariadb
set_env DB_HOST "${DB_HOST:-mariadb}"
set_env DB_PORT "${DB_PORT:-3306}"
set_env DB_DATABASE "${DB_DATABASE:-lti_ox6q}"
set_env DB_USERNAME "${DB_USERNAME:-lti_user}"
set_env DB_PASSWORD "${DB_PASSWORD:-secret}"

# Force non-database stores
set_env CACHE_STORE file
set_env SESSION_DRIVER file
set_env QUEUE_CONNECTION sync

# App + CORS config
: "${APP_URL:=http://localhost:8000}"
: "${FRONTEND_ORIGIN:=http://localhost:5173}"
set_env APP_URL "${APP_URL}"
set_env FRONTEND_ORIGIN "${FRONTEND_ORIGIN}"

# Local API token secret
: "${LOCAL_JWT_SECRET:=change-me}"
set_env LOCAL_JWT_SECRET "${LOCAL_JWT_SECRET}"

# Tool Support JWT verification — only write to .env if explicitly provided
# by Docker (non-empty), so manual .env edits survive restarts.
[ -n "${TOOLSUPPORT_JWKS_URL:-}"       ] && set_env TOOLSUPPORT_JWKS_URL       "${TOOLSUPPORT_JWKS_URL}"
[ -n "${TOOLSUPPORT_JWT_ISS:-}"        ] && set_env TOOLSUPPORT_JWT_ISS        "${TOOLSUPPORT_JWT_ISS}"
[ -n "${TOOLSUPPORT_JWT_AUD:-}"        ] && set_env TOOLSUPPORT_JWT_AUD        "${TOOLSUPPORT_JWT_AUD}"
[ -n "${TOOLSUPPORT_SKIP_SIGNATURE:-}" ] && set_env TOOLSUPPORT_SKIP_SIGNATURE "${TOOLSUPPORT_SKIP_SIGNATURE}"

# Install dependencies (only if vendor missing)
if [ ! -d vendor ]; then
  echo "[backend] Installing composer dependencies..."
  composer install --no-interaction
fi

# App key (dev: generate if missing)
if ! grep -q '^APP_KEY=' .env || grep -q '^APP_KEY=$' .env; then
  php artisan key:generate --force
fi

php artisan config:clear

# Wait for MariaDB
echo "[backend] Waiting for MariaDB at ${DB_HOST:-mariadb}:${DB_PORT:-3306}..."
until mysql -h"${DB_HOST:-mariadb}" -P"${DB_PORT:-3306}" \
            -u"${DB_USERNAME:-lti_user}" -p"${DB_PASSWORD:-secret}" \
            -e "SELECT 1" >/dev/null 2>&1; do
  sleep 1
done
echo "[backend] MariaDB ready."

if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php artisan migrate --force
fi

exec php artisan serve --host=0.0.0.0 --port=8000
