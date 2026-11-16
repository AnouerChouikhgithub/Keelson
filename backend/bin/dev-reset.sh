#!/usr/bin/env bash
#
# Rebuild the local development database from scratch.
#
#   bin/dev-reset.sh
#
# Drop, create, migrate and seed `keelson_dev` on a loopback host. It refuses
# anything else, so it can never touch a shared or production database, and it
# asks the application for the *effective* connection (Doctrine rewrites the URL
# and the test environment appends a suffix), not for a subdomain of
# DATABASE_URL.
#
# This deletes every row in keelson_dev. It does not touch the PostgreSQL volume,
# the `keelson` database, or the container.
set -euo pipefail

BACKEND_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$BACKEND_DIR"

EXPECTED_DATABASE="keelson_dev"
EXPECTED_ENVIRONMENT="dev"

abort() {
    echo "error: $1" >&2
    exit 1
}

command -v php >/dev/null 2>&1 || abort 'php is not on PATH.'

# Credential-free: "database@host".
target="$(php bin/console app:db:target --env="${EXPECTED_ENVIRONMENT}" --no-interaction)" \
    || abort 'could not read the effective database target (is DATABASE_URL set?).'
database="${target%@*}"
host="${target#*@}"

[ "$database" = "$EXPECTED_DATABASE" ] \
    || abort "refusing to reset \"$database\": this script only rebuilds \"$EXPECTED_DATABASE\"."

case "$host" in
    127.0.0.1|localhost|::1) ;;
    *) abort "refusing to reset a database on \"$host\": only a loopback host is allowed." ;;
esac

echo "Resetting ${database}@${host} (APP_ENV=${EXPECTED_ENVIRONMENT}). Every row in it will be deleted."
echo "Starting from: docker compose up -d db   (Mailpit: docker compose up -d mailpit)"

php bin/console doctrine:database:drop --force --if-exists --env="${EXPECTED_ENVIRONMENT}" --no-interaction
php bin/console doctrine:database:create --env="${EXPECTED_ENVIRONMENT}" --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction --env="${EXPECTED_ENVIRONMENT}"
php bin/console app:fixtures:load --force --env="${EXPECTED_ENVIRONMENT}"

echo "Done. Sign in at http://localhost:5173/sign-in with owner@example.test and viewer@example.test."
