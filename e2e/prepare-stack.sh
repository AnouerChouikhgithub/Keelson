#!/usr/bin/env bash
#
# Prepares the real stack the end-to-end journeys run against.
#
#   bash e2e/prepare-stack.sh
#   pnpm --dir e2e test
#
# It starts the Compose database and Mailpit, then rebuilds `keelson_dev` with
# the documented fixture accounts. It never touches the Compose volume and never
# points at another database: `bin/dev-reset.sh` refuses anything that is not a
# loopback `keelson_dev`.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "Starting PostgreSQL and Mailpit…"
docker compose up -d db mailpit

echo "Waiting for PostgreSQL to report healthy…"
for _ in $(seq 1 60); do
    status="$(docker compose ps --format json db 2>/dev/null | grep -o '"Health":"[a-z]*"' | head -1 || true)"
    case "$status" in
        *healthy*) break ;;
    esac
    sleep 1
done

echo "Rebuilding keelson_dev (migrations + fixture accounts)…"
bash backend/bin/dev-reset.sh

echo
echo "Stack ready."
echo "  API:      http://127.0.0.1:8000 (started by Playwright)"
echo "  SPA:      http://localhost:5173 (started by Playwright)"
echo "  Mailpit:  http://localhost:8026"
echo
echo "Run the journeys with: pnpm --dir e2e test"
