#!/usr/bin/env bash
# Membuat role, database simrs + simrs_test, skema domain (PRD 11.1), dan hak akses.
# Dipakai oleh container Postgres (docker-entrypoint-initdb.d) dan oleh CI.
# Koneksi admin diambil dari variabel PG* standar atau POSTGRES_USER.
set -euo pipefail

SQL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/sql"
ADMIN_USER="${POSTGRES_USER:-${PGUSER:-postgres}}"
MIGRATOR_PASSWORD="${SIMRS_MIGRATOR_PASSWORD:-simrs_migrator}"
APP_PASSWORD="${SIMRS_APP_PASSWORD:-simrs_app}"

psql_admin() { psql --quiet -v ON_ERROR_STOP=1 --username "$ADMIN_USER" "$@"; }

psql_admin --dbname postgres \
  -v migrator_password="$MIGRATOR_PASSWORD" \
  -v app_password="$APP_PASSWORD" \
  -f "$SQL_DIR/01-role.sql"

for db in simrs simrs_test; do
  if [ -z "$(psql_admin --dbname postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'")" ]; then
    psql_admin --dbname postgres -c "CREATE DATABASE $db OWNER simrs_migrator"
  fi
  psql_admin --dbname "$db" -f "$SQL_DIR/02-database.sql"
done
