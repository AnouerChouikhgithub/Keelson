#!/usr/bin/env bash
# Prints the current test counts for both suites.
#
# The documentation quotes test counts; quoting them by hand means they drift the
# first time someone adds a test. Run this instead of copying a number:
#
#   bash bin/test-counts.sh
#
# The backend suite needs its disposable PostgreSQL. This script starts it if it
# is not already running and stops it again when it started it, so it leaves no
# container or .env.test.local behind.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

started_db=0
cleanup() {
  if [ "$started_db" -eq 1 ]; then
    (cd backend && bash bin/test-db.sh stop >/dev/null 2>&1)
  fi
}
trap cleanup EXIT

echo "== backend =="
if [ ! -f backend/.env.test.local ]; then
  (cd backend && bash bin/test-db.sh start >/dev/null 2>&1) && started_db=1
fi
backend_output="$(cd backend && php bin/phpunit 2>&1)"
backend_status=$?
backend_summary="$(printf '%s\n' "$backend_output" | grep -aoE "OK \([0-9]+ tests?, [0-9]+ assertions?\)|Tests: [0-9]+.*" | tail -1)"
if [ "$backend_status" -ne 0 ]; then
  echo "backend suite FAILED (exit $backend_status) - no count reported." >&2
  printf '%s\n' "$backend_output" | tail -15 >&2
else
  echo "backend: ${backend_summary:-no summary line found} (exit 0)"
fi

echo "== frontend =="
frontend_output="$(cd frontend && pnpm test 2>&1)"
frontend_status=$?
# Vitest colours its summary, so strip the escape sequences before matching.
frontend_plain="$(printf '%s\n' "$frontend_output" | sed -E 's/\x1B\[[0-9;]*[A-Za-z]//g')"
frontend_files="$(printf '%s\n' "$frontend_plain" | grep -aoE "Test Files .*[0-9]+ passed" | grep -aoE "[0-9]+" | head -1)"
frontend_tests="$(printf '%s\n' "$frontend_plain" | grep -aoE "Tests .*[0-9]+ passed" | grep -aoE "[0-9]+" | head -1)"
if [ "$frontend_status" -ne 0 ]; then
  echo "frontend suite FAILED (exit $frontend_status) - no count reported." >&2
  printf '%s\n' "$frontend_output" | tail -15 >&2
else
  echo "frontend: ${frontend_tests:-?} tests in ${frontend_files:-?} files (exit 0)"
fi

[ "$backend_status" -eq 0 ] && [ "$frontend_status" -eq 0 ]
