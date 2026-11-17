#!/bin/sh
# Creates the least-privilege application role the API runs as.
#
# Runs once, on an empty data directory, as the bootstrap superuser. The owner
# role (POSTGRES_USER) keeps the schema: it runs migrations and owns the tables.
# The application role gets no DDL rights at all — it can read and write rows and
# nothing else.
#
# Default privileges matter: the tables do not exist yet when this runs, so the
# grants are set for future objects created by the owner (the migrate job).
set -eu

: "${APP_DB_USER:?APP_DB_USER must be set for the database to create the application role}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD must be set for the database to create the application role}"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
CREATE ROLE "${APP_DB_USER}" LOGIN PASSWORD '${APP_DB_PASSWORD}';
GRANT CONNECT ON DATABASE "${POSTGRES_DB}" TO "${APP_DB_USER}";
GRANT USAGE ON SCHEMA public TO "${APP_DB_USER}";
ALTER DEFAULT PRIVILEGES FOR ROLE "${POSTGRES_USER}" IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "${APP_DB_USER}";
ALTER DEFAULT PRIVILEGES FOR ROLE "${POSTGRES_USER}" IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO "${APP_DB_USER}";
SQL

echo "created application role ${APP_DB_USER}"
