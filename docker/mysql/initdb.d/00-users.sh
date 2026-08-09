#!/usr/bin/env bash
#
# #668: a MySQL-hozzáférés eddig be volt égetve (root/pw, user/pw). Innentől
# env-változókból jön, az alapértelmezés a régi érték — aki nem állít semmit,
# annak minden ugyanúgy működik.
#
#   MYSQL_ROOT_PASSWORD  — a root jelszava (backup/karbantartás)   default: pw
#   MYSQL_USER           — korlátozott jogú fiók, csak a miserend  default: user
#   MYSQL_PASSWORD       — ennek a jelszava                        default: pw
#
# A MYSQL_USER/MYSQL_PASSWORD párost maga a mariadb image hozza létre (MYSQL_DATABASE
# mellett), ezért itt csak a root@'%' hozzáférést kell beállítanunk: az image csak a
# MARIADB_ROOT_HOST szerinti hostra ad jogot, a compose-hálózatról viszont bármelyik
# konténer IP-jéről jöhet a kapcsolat.
#
# FONTOS: ez CSAK üres adatkönyvtárnál fut le (docker-entrypoint-initdb.d). Meglévő
# telepítésen a jelszavakat kézzel kell átállítani, l. .env.example.
set -euo pipefail

ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-pw}"

if command -v mariadb >/dev/null 2>&1; then
  DB_CLIENT="mariadb"
elif command -v mysql >/dev/null 2>&1; then
  DB_CLIENT="mysql"
else
  echo "00-users.sh: sem 'mariadb', sem 'mysql' kliens nincs a képben!" >&2
  exit 1
fi

# A jelszó SQL-stringbe kerül, ezért az aposztrófot és a backslasht escape-elni kell —
# enélkül egy O'Brien-szerű jelszó szintaktikai hibát okozna, és a root@'%' némán
# beállítatlan maradna.
ROOT_PASSWORD_SQL="$(printf '%s' "$ROOT_PASSWORD" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g")"

"$DB_CLIENT" --user=root --password="$ROOT_PASSWORD" <<SQL
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' IDENTIFIED BY '${ROOT_PASSWORD_SQL}' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

echo "00-users.sh: root@'%' beállítva."
