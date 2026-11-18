#!/usr/bin/env bash
#
# Logical PostgreSQL backup of the database named by PGDATABASE.
#
#   PGHOST=127.0.0.1 PGPORT=5433 PGUSER=app PGPASSWORD=app PGDATABASE=keelson_dev \
#     bash backend/bin/db-backup.sh [output-directory]
#
#   # from a Compose stack, using the database container's own client tools
#   KEELSON_PG_CONTAINER=keelson-db-1 PGUSER=app PGPASSWORD=app PGDATABASE=keelson \
#     bash backend/bin/db-backup.sh /var/backups/keelson
#
# Why these choices:
#   - custom format (`-Fc`) is compressed, restorable selectively and portable
#     across PostgreSQL 16 minor versions; a plain SQL file is neither;
#   - `--no-owner --no-privileges` keeps the dump restorable by a different role,
#     which is what a restore into a fresh database needs;
#   - the dump is verified with `pg_restore --list` before it is reported as
#     successful, because `pg_dump` exiting 0 does not prove the archive is
#     readable;
#   - retention deletes only files this script names (`keelson-*.dump`) in the
#     output directory it was given.
#
# Credentials are read from the standard libpq variables, so no password appears
# in the process list. Nothing here prints a password.
set -euo pipefail

OUT_DIR="${1:-${KEELSON_BACKUP_DIR:-./var/backups}}"
KEEP_DAYS="${KEELSON_BACKUP_KEEP_DAYS:-14}"
CONTAINER="${KEELSON_PG_CONTAINER:-}"

fail() {
    echo "error: $*" >&2
    exit 1
}

[ -n "${PGDATABASE:-}" ] || fail "PGDATABASE must name the database to back up."
[ -n "${PGUSER:-}" ] || fail "PGUSER must name the role that may read it."

if [ -n "${CONTAINER}" ]; then
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
    command -v pg_dump >/dev/null 2>&1 || fail "pg_dump is not on PATH (install the PostgreSQL client, or set KEELSON_PG_CONTAINER)."
    pg() {
        "$@"
    }
fi

mkdir -p "$OUT_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$OUT_DIR/keelson-$STAMP.dump"
TMP="$FILE.partial"

cleanup() {
    rm -f "$TMP"
}
trap cleanup EXIT

echo "Dumping $PGDATABASE as $PGUSER…"
if ! pg pg_dump --format=custom --no-owner --no-privileges --dbname="$PGDATABASE" >"$TMP"; then
    fail "pg_dump failed; nothing was kept."
fi

# Readable archive, not just a zero exit status.
if ! pg pg_restore --list <"$TMP" >/dev/null; then
    fail "the dump could not be listed; refusing to report it as a backup."
fi

mv "$TMP" "$FILE"

SIZE="$(wc -c <"$FILE" | tr -d ' ')"
echo "Wrote $FILE ($SIZE bytes)."
if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$FILE"
elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$FILE"
fi

if [ "$KEEP_DAYS" -gt 0 ] 2>/dev/null; then
    find "$OUT_DIR" -maxdepth 1 -type f -name 'keelson-*.dump' -mtime "+$KEEP_DAYS" -print -delete \
        | sed 's/^/Removed expired backup: /'
fi

echo "Restore with: bash backend/bin/db-restore.sh $FILE <new-database>"
