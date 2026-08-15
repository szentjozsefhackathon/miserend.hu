#!/usr/bin/env bash

set -euo pipefail

COMPOSE=(docker compose -f docker/compose.yml -f docker/compose.dev.yml)

PHPUNIT_CMD='if [ ! -f vendor/bin/phpunit ]; then composer install --no-interaction --no-progress; fi; php vendor/bin/phpunit -c tests/phpunit.xml "$@"'

# Néhány integrációs teszt (AutocompleteCombinedTest, LegacyChurchUrlRedirectTest, …) valódi
# HTTP-hívást intéz az apphoz, tehát futó webszerver kell hozzá. Hogy hol van a webszerver, az
# attól függ, hogyan indítjuk a phpunitot — ezért nem elég egyetlen fix cím:
#
#   - FUTÓ stack esetén `exec`-elünk a meglévő app-konténerbe, ugyanúgy, ahogy a CI teszi.
#     Ott az Apache a saját 127.0.0.1:8000-én figyel.
#   - Ha a stack áll, `run --rm`-mel egy KÜLÖN konténer indul, amiben nincs webkiszolgáló:
#     ott a 127.0.0.1:8000 halott. A compose-hálózaton viszont a futó app `miserend` néven
#     elérhető, ezért arra irányítjuk a teszteket.
#
# Felülírható: PANTHER_EXTERNAL_BASE_URI=... ./scripts/docker-test.sh
if [ -n "$("${COMPOSE[@]}" ps --status running -q miserend 2>/dev/null)" ]; then
  "${COMPOSE[@]}" exec -T \
    -e "PANTHER_EXTERNAL_BASE_URI=${PANTHER_EXTERNAL_BASE_URI:-http://127.0.0.1:8000}" \
    miserend sh -lc "$PHPUNIT_CMD" -- "$@"
else
  "${COMPOSE[@]}" run --rm \
    -e "PANTHER_EXTERNAL_BASE_URI=${PANTHER_EXTERNAL_BASE_URI:-http://miserend:8000}" \
    --entrypoint sh miserend -lc "$PHPUNIT_CMD" -- "$@"
fi
