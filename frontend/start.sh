#!/bin/sh
set -e
cd /app

if [ ! -x node_modules/.bin/vite ] || [ ! -d node_modules/@rollup/rollup-linux-x64-gnu ]; then
  echo "[frontend] Reinstalling dependencies..."
  # node_modules is a volume mount; can't remove the directory, only its contents
  find node_modules -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  npm install --include=optional --no-package-lock
fi

exec npm run dev -- --host 0.0.0.0 --port 5173
