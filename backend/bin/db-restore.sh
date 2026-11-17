#!/usr/bin/env bash
#
# Restore a custom-format dump into a SEPARATE database.
#
#   PGHOST=127.0.0.1 PGPORT=5433 PGUSER=app PGPASSWORD=app PGDATABASE=keelson_dev \
#     bash backend/bin/db-restore.sh /backups/keelson-20261117T020000Z.dump keelson_restore_check
#
#   # inside a Compose stack, using the database container's client tools
#   KEELSON_PG_CONTAINER=keelson-db-1 PGUSER=app PGPASSWORD=app PGDATABASE=keelson \
#     bash backend/bin/db-restore.sh ./keelson-....dump keelson_restore_check
#
# Safety rules, because a restore is destructive by nature:
#   - the target must be given explicitly and must differ from PGDATABASE, so a
#     copy-pasted command cannot overwrite the database it is connected to;
#   - an existing target is refused unless `--replace` is passed, and `--replace`
#     still refuses `postgres`, `template0` and `template1`;
#   - the restore runs `--single-transaction --exit-on-error`, so a failure
#     leaves the target as it was instead of half-populated;
#   - nothing here deletes the dump or the source database.
#
# The intended production flow is: restore into a fresh database, verify it, then
# point DATABASE_URL at it (or rename). Restoring in place is a decision the
# operator makes with `--replace`, never a default.
set -euo pipefail

usage() {
    sed -n '2,22p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

DUMP="${1:-}"
TARGET="${2:-}"
[ -n "$DUMP" ] && [ -n "$TARGET" ] || usage 1
shift 2

REPLACE=0
for arg in "$@"; do
    case "$arg" in
        --replace) REPLACE=1 ;;
        -h|--help) usage 0 ;;
        *) echo "error: unknown argument '$arg'" >&2; usage 1 ;;
    esac
done

fail() {
    echo "error: $*" >&2
    exit 1
}

[ -f "$DUMP" ] || fail "dump '$DUMP' does not exist."
[ -n "${PGDATABASE:-}" ] || fail "PGDATABASE must name the connection database (used for the safety check and the client connection)."
[ -n "${PGUSER:-}" ] || fail "PGUSER must name a role that may create databases."
[ "$TARGET" != "$PGDATABASE" ] || fail "refusing to restore over '$PGDATABASE', the database this shell is pointed at. Name a separate target."
case "$TARGET" in
    postgres|template0|template1) fail "refusing to restore over the system database '$TARGET'." ;;
esac

CONTAINER="${KEELSON_PG_CONTAINER:-}"
if [ -n "$CONTAINER" ]; then
    command -v docker >/dev/null 2>&1 || fail "docker is not on PATH."
    docker inspect "$CONTAINER" >/dev/null 2>&1 || fail "container '$CONTAINER' does not exist."
    # libpq reads PG* from the environment, and `docker exec` only forwards the
    # variables named with -e, so each one is passed explicitly.
    pg() {
        docker exec -i \
            -e PGPASSWORD="${PGPASSWORD:-}" \
            -e PGUSER="${PGUSER:-}" \
            -e PGDATABASE="${PGDATABASE:-}" \
            -e PGHOST="${PGHOST:-}" \
            -e PGPORT="${PGPORT:-}" \
            "$CONTAINER" "$@"
    }
else
    command -v pg_restore >/dev/null 2>&1 || fail "pg_restore is not on PATH (install the PostgreSQL client, or set KEELSON_PG_CONTAINER)."
    pg() {
        "$@"
    }
fi

EXISTS="$(pg psql -d "$PGDATABASE" -tAc "SELECT 1 FROM pg_database WHERE datname = '$TARGET'" | tr -d '[:space:]')"
if [ "$EXISTS" = "1" ]; then
    [ "$REPLACE" -eq 1 ] || fail "database '$TARGET' already exists. Pass --replace to drop and recreate it."
    echo "Dropping existing '$TARGET'…"
    pg dropdb --if-exists --force "$TARGET"
fi

echo "Creating '$TARGET'…"
pg createdb "$TARGET"

echo "Restoring $DUMP into $TARGET…"
if ! pg pg_restore --single-transaction --exit-on-error --no-owner --no-privileges \
        --dbname="$TARGET" <"$DUMP"; then
    fail "pg_restore failed; the target was left as it was (single transaction)."
fi

TABLES="$(pg psql -d "$TARGET" -tAc "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'" | tr -d '[:space:]')"
MIGRATIONS="$(pg psql -d "$TARGET" -tAc "SELECT count(*) FROM doctrine_migration_versions" | tr -d '[:space:]')"
echo "Restored $TABLES tables, $MIGRATIONS recorded migrations."

cat <<REPORT

Next steps:
  1. Point the application at '$TARGET' (DATABASE_URL) or rename it into place.
  2. Run the migrations again — they are idempotent and apply anything the dump
     predates:
       php bin/console doctrine:migrations:migrate --no-interaction
  3. Start the API and check GET /readyz.
REPORT
