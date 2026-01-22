#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

set_env() {
  local key="$1"
  local value="$2"

  if grep -qE "^${key}=" .env; then
    sed -i "s|^${key}=.*|${key}=${value}|" .env
  else
    echo "${key}=${value}" >> .env
  fi
}

# 2) Ensure .env exists
if [ ! -f .env ]; then
  cp .env.example .env
fi

# 3) Configure SQLite
mkdir -p database
if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
fi

set_env DB_CONNECTION sqlite
set_env DB_DATABASE /var/www/html/database/database.sqlite

# App + CORS config
: "${APP_URL:=http://localhost:8000}"
: "${FRONTEND_ORIGIN:=http://localhost:5173}"
set_env APP_URL "${APP_URL}"
set_env FRONTEND_ORIGIN "${FRONTEND_ORIGIN}"

# Local API token secret
: "${LOCAL_JWT_SECRET:=change-me}"
set_env LOCAL_JWT_SECRET "${LOCAL_JWT_SECRET}"

# Tool Support JWT verification (optional)
: "${TOOLSUPPORT_JWKS_URL:=}"
: "${TOOLSUPPORT_JWT_ISS:=}"
: "${TOOLSUPPORT_JWT_AUD:=}"
: "${TOOLSUPPORT_SKIP_SIGNATURE:=false}"
set_env TOOLSUPPORT_JWKS_URL "${TOOLSUPPORT_JWKS_URL}"
set_env TOOLSUPPORT_JWT_ISS "${TOOLSUPPORT_JWT_ISS}"
set_env TOOLSUPPORT_JWT_AUD "${TOOLSUPPORT_JWT_AUD}"
set_env TOOLSUPPORT_SKIP_SIGNATURE "${TOOLSUPPORT_SKIP_SIGNATURE}"

# 4) Install dependencies (only if vendor missing)
if [ ! -d vendor ]; then
  echo "[backend] Installing composer dependencies..."
  composer install --no-interaction
fi

# 6) App key, migrations
if ! grep -q '^APP_KEY=' .env || grep -q '^APP_KEY=$' .env; then
  php artisan key:generate --force
fi
#php artisan key:generate --force
php artisan optimize:clear
php artisan migrate --force

# 7) Run
exec php artisan serve --host=0.0.0.0 --port=8000
