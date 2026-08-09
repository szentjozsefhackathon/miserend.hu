#!/usr/bin/env bash

set -euo pipefail

# A HTTP-alapú integrációs tesztek (AutocompleteCombinedTest, LegacyChurchUrlRedirectTest)
# a PANTHER_EXTERNAL_BASE_URI-t hívják, alapértelmezésben a 127.0.0.1:8000-et. A CI-ban ez
# működik, mert ott `docker compose exec`-kel a FUTÓ app-konténerben indul a phpunit — ott
# tényleg a saját 8000-es portján figyel az Apache.
#
# Itt viszont `run --rm`, azaz egy KÜLÖN konténer, amiben nincs webkiszolgáló: a
# 127.0.0.1:8000 halott, és ez a 18 teszt helyben mindig elbukott. A compose-hálózaton
# viszont a futó app a `miserend` néven elérhető, ezért arra irányítjuk őket.
#
# Felülírható: PANTHER_EXTERNAL_BASE_URI=... ./scripts/docker-test.sh
BASE_URI="${PANTHER_EXTERNAL_BASE_URI:-http://miserend:8000}"

docker compose -f docker/compose.yml -f docker/compose.dev.yml run --rm \
  -e "PANTHER_EXTERNAL_BASE_URI=$BASE_URI" \
  --entrypoint sh miserend -lc \
  'if [ ! -f vendor/bin/phpunit ]; then composer install --no-interaction --no-progress; fi; php vendor/bin/phpunit -c tests/phpunit.xml "$@"' -- "$@"
