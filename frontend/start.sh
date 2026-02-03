#!/bin/sh
set -e

[ -d node_modules ] || npm ci
exec npm run dev -- --host 0.0.0.0 --port 5173
