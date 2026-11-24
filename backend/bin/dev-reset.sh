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

# Rebuilding a database is DDL, so it runs as the owner role. The API itself
# connects as the least-privilege role in DATABASE_URL (`keelson_app`), which has
# no right to drop or create anything. These defaults are the ones
# docker-compose.yml creates; export OWNER_DATABASE_URL when yours differ (a
# different POSTGRES_PORT, for instance).
OWNER_DATABASE_URL="${OWNER_DATABASE_URL:-postgresql://keelson_owner:keelson_owner@127.0.0.1:5433/keelson_dev?serverVersion=16&charset=utf8}"
OWNER_DB_USER="${OWNER_DB_USER:-keelson_owner}"
export DATABASE_URL="$OWNER_DATABASE_URL"

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

echo "Resetting ${database}@${host} as the owner role (APP_ENV=${EXPECTED_ENVIRONMENT}). Every row in it will be deleted."
echo "Starting from: docker compose up -d db   (Mailpit: docker compose up -d mailpit)"

# The least-privilege role the API serves as. Compose creates it on a fresh volume;
# dropping the database does not remove the role, only the grants that lived in it.
APP_DB_USER="${APP_DB_USER:-keelson_app}"

run_sql() {
    php bin/console dbal:run-sql "$1" --env="${EXPECTED_ENVIRONMENT}" --no-interaction >/dev/null
}

php bin/console doctrine:database:drop --force --if-exists --env="${EXPECTED_ENVIRONMENT}" --no-interaction
php bin/console doctrine:database:create --env="${EXPECTED_ENVIRONMENT}" --no-interaction

# Dropping a database discards its per-database grants and default privileges, so
# they are re-applied here exactly as docker/postgres/10-app-role.sh sets them.
# Without this the API connects and every query fails with "permission denied".
run_sql "GRANT CONNECT ON DATABASE \"${database}\" TO \"${APP_DB_USER}\""
run_sql "GRANT USAGE ON SCHEMA public TO \"${APP_DB_USER}\""
run_sql "ALTER DEFAULT PRIVILEGES FOR ROLE \"${OWNER_DB_USER}\" IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO \"${APP_DB_USER}\""
run_sql "ALTER DEFAULT PRIVILEGES FOR ROLE \"${OWNER_DB_USER}\" IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO \"${APP_DB_USER}\""

php bin/console doctrine:migrations:migrate --no-interaction --env="${EXPECTED_ENVIRONMENT}"

# Belt and braces: the default privileges above already cover the migrated tables,
# and re-granting is idempotent if they somehow did not.
run_sql "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO \"${APP_DB_USER}\""
run_sql "GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO \"${APP_DB_USER}\""

php bin/console app:fixtures:load --force --env="${EXPECTED_ENVIRONMENT}"

echo "Done. Sign in at http://localhost:5173/sign-in with owner@example.test and viewer@example.test."
