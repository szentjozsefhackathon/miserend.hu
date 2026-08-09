#!/usr/bin/env bash
# FONTOS: szándékosan NINCS `set -e`. Egy hibás seed-fájl NE szakítsa meg a többi
# tábla betöltését. Korábban a `set -e` miatt egyetlen hibás fájl (pl. a
# church_relationships.sql egy NULL @OLD_AUTOCOMMIT miatt ERROR 1231-et dobott)
# CSENDBEN félbehagyta az EGÉSZ seedet, üresen hagyva a betűrendben utána jövő
# táblákat (templomok, user, varosok, ...) → tömeges, félrevezető teszt-bukás. (#597)
set -uo pipefail

DB_NAME="${MYSQL_DATABASE:-miserend}"
DATA_DIR="/docker-entrypoint-initdb.d/data"

# Detect client binary: prefer mariadb, fallback to mysql
if command -v mariadb >/dev/null 2>&1; then
  DB_CLIENT="mariadb"
elif command -v mysql >/dev/null 2>&1; then
  DB_CLIENT="mysql"
else
  echo "Error: Neither 'mariadb' nor 'mysql' client found!" >&2
  exit 1
fi

# #668: a root jelszava env-ből jön; az alapértelmezés a régi beégetett érték.
MYSQL_CMD="$DB_CLIENT --user=root --password=${MYSQL_ROOT_PASSWORD:-pw} ${DB_NAME}"

echo "Using client: $DB_CLIENT"

failed=()
for file in "$DATA_DIR"/*.sql; do
  table=$(basename "$file" .sql)

  # Skip if table does not exist yet
  exists=$($MYSQL_CMD -N -s -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='${table}';")
  if [ "$exists" -eq 0 ]; then
    echo "Skipping $table: table does not exist yet"
    continue
  fi

  # Skip if table already has rows
  rows=$($MYSQL_CMD -N -s -e "SELECT COUNT(*) FROM \`${table}\`;")
  if [ "$rows" -ne 0 ]; then
    echo "Skipping $table: already has $rows rows"
    continue
  fi

  echo "Importing data for table $table..."
  # A betöltést külön kezeljük: ha EZ a fájl hibázik, hangosan jelezzük, de a
  # ciklus MEGY tovább a következő táblára — egy rossz seed ne bénítsa az egészet.
  #
  # #669/#706: idegenkulcs-ellenőrzés KIKAPCSOLVA a betöltés idejére. A ciklus
  # BETŰRENDBEN halad, a hivatkozási irány viszont nem betűrend szerinti: az
  # `external_calendars` a `templomok`-ra hivatkozik, de jóval előtte jön, ezért
  # a betöltése "Cannot add or update a child row" hibával elhasalt. Mivel a
  # szkript a végén exit 1-gyel zár, a MariaDB entrypoint fatálisnak vette, a
  # konténer meghalt, és a staging-deploy "mysql is unhealthy"-vel megállt.
  #
  # Sorrendezés helyett kapcsoljuk ki az ellenőrzést: a seed egy önmagában
  # konzisztens dump, és minden új idegen kulcs újra elrontaná a kézzel tákolt
  # sorrendet. Ugyanezt teszi egy mysqldump-visszatöltés is.
  if ! import_err=$( { echo "SET foreign_key_checks=0;"; cat "$file"; } | $MYSQL_CMD 2>&1); then
    echo "############################################################" >&2
    echo "SEED HIBA: '$table' ($file) betöltése MEGHIÚSULT." >&2
    echo "A TÖBBI tábla betöltése FOLYTATÓDIK (nem szakítjuk meg az egészet)." >&2
    echo "$import_err" | sed 's/^/    /' >&2
    echo "############################################################" >&2
    failed+=("$table")
  fi
done

if [ "${#failed[@]}" -ne 0 ]; then
  echo "############################################################" >&2
  echo "SEED ÖSSZEGZÉS: ${#failed[@]} tábla betöltése HIBÁZOTT: ${failed[*]}" >&2
  echo "A DB részlegesen felseedelve indul — javítsd a fenti seed-fájl(oka)t." >&2
  echo "############################################################" >&2
  exit 1
fi

echo "Seed complete: minden meglévő, üres tábla betöltve."
