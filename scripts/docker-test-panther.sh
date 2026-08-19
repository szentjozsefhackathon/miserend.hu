#!/usr/bin/env bash

set -euo pipefail

# Build the test image with Chromium
docker compose -f docker/compose.yml -f docker/compose.dev.yml -f docker/compose.test.yml build miserend

# Run functional tests
docker compose -f docker/compose.yml -f docker/compose.dev.yml -f docker/compose.test.yml run --rm --entrypoint sh miserend -lc '
  # Install functional test dependencies
  if grep -q "\"symfony/panther\"" composer.lock; then
    composer install --no-interaction --no-progress
  else
    composer update --no-interaction --no-progress symfony/panther --with-all-dependencies
  fi

  # Run functional tests
  export PANTHER_EXTERNAL_BASE_URI=http://miserend:8000
  export PANTHER_NO_SANDBOX=1
  export PANTHER_CHROME_BINARY=/usr/bin/chromium
  php vendor/bin/phpunit -c tests/phpunit.functional.xml "$@"
' -- "$@"
