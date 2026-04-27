#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MIGRATIONS_DIR="$ROOT_DIR/migrations"
DATA_DIR="$MIGRATIONS_DIR/data"

DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-unit_dom}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"

if ! command -v "$MYSQL_BIN" >/dev/null 2>&1; then
  echo "Error: mysql client tidak ditemukan. Install mysql-client dulu."
  exit 1
fi

MYSQL_ARGS=(
  "--host=$DB_HOST"
  "--port=$DB_PORT"
  "--user=$DB_USER"
  "--database=$DB_NAME"
  "--default-character-set=utf8mb4"
)

if [[ -n "$DB_PASS" ]]; then
  MYSQL_ARGS+=("--password=$DB_PASS")
fi

run_sql() {
  local file="$1"
  echo "Import: $(basename "$file")"
  "$MYSQL_BIN" "${MYSQL_ARGS[@]}" < "$file"
}

STRUCTURE_FILES=(
  "$MIGRATIONS_DIR/province_structure.sql"
  "$MIGRATIONS_DIR/city_structure.sql"
  "$MIGRATIONS_DIR/district_structure.sql"
  "$MIGRATIONS_DIR/village_structure.sql"
  "$MIGRATIONS_DIR/klasifikasi_instansi_structure.sql"
  "$MIGRATIONS_DIR/users_structure.sql"
  "$MIGRATIONS_DIR/domains_structure.sql"
  "$MIGRATIONS_DIR/websites_structure.sql"
)

RELATIONSHIP_FILE="$MIGRATIONS_DIR/relationship_stucture.sql"

DATA_FILES=(
  "$DATA_DIR/area_data.sql"
  "$DATA_DIR/users_data.sql"
  "$DATA_DIR/domains_data.sql"
)

for file in "${STRUCTURE_FILES[@]}" "${DATA_FILES[@]}" "$RELATIONSHIP_FILE"; do
  if [[ ! -f "$file" ]]; then
    echo "Error: file tidak ditemukan -> $file"
    exit 1
  fi
done

echo "Mulai init DB: $DB_NAME di $DB_HOST:$DB_PORT"
for file in "${STRUCTURE_FILES[@]}"; do
  run_sql "$file"
done

for file in "${DATA_FILES[@]}"; do
  run_sql "$file"
done

# Penting untuk MariaDB: file data area_data.sql berisi city/district dulu, province belakangan.
# Karena itu FK antar tabel area dipasang setelah semua data selesai diimport.
run_sql "$RELATIONSHIP_FILE"

echo "Selesai init DB."
