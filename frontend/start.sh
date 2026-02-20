#!/bin/sh
set -e
cd /app

# Ensure optional rollup native dependency matches container architecture.
ROLLUP_PKG="@rollup/rollup-linux-x64-gnu"
ARCH="$(uname -m)"
if [ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ]; then
  ROLLUP_PKG="@rollup/rollup-linux-arm64-gnu"
fi

if [ ! -x node_modules/.bin/vite ] || [ ! -d "node_modules/$ROLLUP_PKG" ]; then
  echo "[frontend] Reinstalling dependencies..."
  # node_modules is a volume mount; can't remove the directory, only its contents
  find node_modules -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  npm install --include=optional --no-package-lock
fi

exec npm run dev -- --host 0.0.0.0 --port 5173
