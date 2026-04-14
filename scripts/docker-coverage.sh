#!/usr/bin/env bash

set -euo pipefail

# Some WSL2 / Docker Desktop setups do not have the buildx plugin available.
# Force the legacy builder to avoid requiring buildx.
export DOCKER_BUILDKIT=0
export COMPOSE_DOCKER_CLI_BUILD=0

docker compose -f docker/compose.yml -f docker/compose.dev.yml -f docker/compose.coverage.yml build miserend

# MySQL readiness is guaranteed by depends_on service_healthy in compose.coverage.yml
docker compose -f docker/compose.yml -f docker/compose.dev.yml -f docker/compose.coverage.yml run --rm --entrypoint sh -u root miserend -lc \
  'mkdir -p tests/coverage/html \
    && : > tests/junit.xml \
    && chown -R www-data:www-data tests/coverage tests/junit.xml 2>/dev/null || true \
    && su -s /bin/sh www-data -c "if [ ! -f vendor/bin/phpunit ]; then composer install --no-interaction --no-progress; fi; php vendor/bin/phpunit -c tests/phpunit.xml --coverage-html tests/coverage/html --log-junit tests/junit.xml --coverage-cobertura tests/coverage/cobertura.xml"'
