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

# Ensure .env exists
if [ ! -f .env ]; then
  if [ -f .env.example ]; then
    cp .env.example .env
  else
    touch .env
  fi
fi

# Core app config
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_KEY "${APP_KEY}"
set_env APP_URL "${APP_URL:-http://localhost}"
set_env FRONTEND_ORIGIN "${FRONTEND_ORIGIN:-http://localhost}"

# Database
set_env DB_CONNECTION mariadb
set_env DB_HOST "${DB_HOST:-mariadb}"
set_env DB_PORT "${DB_PORT:-3306}"
set_env DB_DATABASE "${DB_DATABASE:-lti_ox6q}"
set_env DB_USERNAME "${DB_USERNAME:-lti_user}"
set_env DB_PASSWORD "${DB_PASSWORD}"

# Force file-based stores (no Redis needed)
set_env CACHE_STORE file
set_env SESSION_DRIVER file
set_env QUEUE_CONNECTION sync

# Local JWT
if [ -z "${LOCAL_JWT_SECRET:-}" ] || [ "${#LOCAL_JWT_SECRET}" -lt 32 ]; then
  echo "[backend] ERROR: LOCAL_JWT_SECRET must be set and at least 32 characters long for HS256."
  exit 1
fi
set_env LOCAL_JWT_SECRET "${LOCAL_JWT_SECRET}"
set_env LOCAL_JWT_EXPIRES_IN "${LOCAL_JWT_EXPIRES_IN:-1800}"

# Tool Support JWT verification
set_env TOOLSUPPORT_JWKS_URL "${TOOLSUPPORT_JWKS_URL}"
set_env TOOLSUPPORT_JWT_ISS "${TOOLSUPPORT_JWT_ISS}"
set_env TOOLSUPPORT_JWT_AUD "${TOOLSUPPORT_JWT_AUD}"
set_env TOOLSUPPORT_SKIP_SIGNATURE "${TOOLSUPPORT_SKIP_SIGNATURE:-false}"

# OpenAI
set_env OPENAI_API_KEY "${OPENAI_API_KEY}"
set_env OPENAI_MODEL "${OPENAI_MODEL:-gpt-4.1-mini}"
set_env OPENAI_TEMPERATURE "${OPENAI_TEMPERATURE:-0.3}"
set_env OPENAI_TIMEOUT "${OPENAI_TIMEOUT:-180}"

# Clear any stale config cache, then rebuild it
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Wait for MariaDB
echo "[backend] Waiting for MariaDB at ${DB_HOST:-mariadb}:${DB_PORT:-3306}..."
until mysql -h"${DB_HOST:-mariadb}" -P"${DB_PORT:-3306}" \
            -u"${DB_USERNAME:-lti_user}" -p"${DB_PASSWORD}" \
            -e "SELECT 1" >/dev/null 2>&1; do
  sleep 1
done
echo "[backend] MariaDB ready."

if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php artisan migrate --force
fi

php artisan storage:link || true

exec "$@"
