#!/usr/bin/env bash
set -euo pipefail

# ------------------------------------------------------------------
# Full safe dump: schema + per-table data, templomok sanitization
# ------------------------------------------------------------------

HOST="${DB_HOST:-127.0.0.1}"
USER="${DB_USER:-root}"
PASS="${DB_PASSWORD:-pw}"
DB="${DB:-miserend}"

# Output files
SCHEMA_OUT="initdb.d/02-schema.sql"
DATA_DIR="initdb.d/data"
mkdir -p "$DATA_DIR"

# Tables whose DATA must be skipped entirely
ignore_tables=(
  chat
  church_holders
  favorites
  emails
  remarks
  user
  distances
  lookup_boundary_church
  lookup_church_osm
  osm
  messages
  osmtags
  stats_externalapi
  tokens
)

MYSQL="mysql -h $HOST -u $USER -p$PASS $DB"
DUMP="mysqldump -h $HOST -u $USER -p$PASS"

# ------------------------------------------------------------------
# 0) TEMP: handle templomok
# ------------------------------------------------------------------
echo "Backing up templomok..."
$MYSQL <<'SQL'
DROP TABLE IF EXISTS templomok_full;
CREATE TABLE templomok_full LIKE templomok;
INSERT INTO templomok_full SELECT * FROM templomok;

UPDATE templomok
SET
  kontakt      = '',
  kontaktmail  = '',
  adminmegj    = '',
  letrehozta   = '',
  modositotta  = '',
  log          = '';
SQL

# ------------------------------------------------------------------
# 1) SCHEMA-ONLY DUMP (no DROP TABLE)
# ------------------------------------------------------------------
echo "Dumping schema..."
$DUMP \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --no-tablespaces \
  --no-data \
  --skip-add-drop-table \
  "$DB" > "$SCHEMA_OUT"

sed -i 's/CREATE TABLE `/CREATE TABLE IF NOT EXISTS `/g' $SCHEMA_OUT

echo "✅ Schema dump complete: $SCHEMA_OUT"

# ------------------------------------------------------------------
# 2) DATA-ONLY DUMP (per table)
# ------------------------------------------------------------------
# Get all tables
TABLES=($($MYSQL -N -s -e "SELECT table_name FROM information_schema.tables WHERE table_schema='${DB}';"))

for t in "${TABLES[@]}"; do
  # Skip ignored tables
  if [[ " ${ignore_tables[*]} " == *" $t "* ]]; then
    echo "Skipping data dump for ignored table $t"
    continue
  fi

  # Skip templomok_full
  if [[ "$t" == "templomok_full" ]]; then
    continue
  fi

  echo "Dumping data for table $t..."
  $DUMP \
    --single-transaction \
    --no-create-info \
    --skip-triggers \
    --no-tablespaces \
    "$DB" "$t" > "$DATA_DIR/$t.sql"
done

# ------------------------------------------------------------------
# 3) RESTORE ORIGINAL templomok DATA
# ------------------------------------------------------------------
$MYSQL <<'SQL'
UPDATE templomok t
JOIN templomok_full f USING (id)
SET
  t.kontakt      = f.kontakt,
  t.kontaktmail  = f.kontaktmail,
  t.adminmegj    = f.adminmegj,
  t.letrehozta   = f.letrehozta,
  t.modositotta  = f.modositotta,
  t.log          = f.log;

DROP TABLE templomok_full;
SQL

echo "✅ Data dumps complete in $DATA_DIR/"
