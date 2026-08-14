# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**miserend.hu** is a Hungarian Catholic mass times search platform. Users find nearby churches and Mass schedules; church admins manage service times. Repository: https://github.com/szentjozsefhackathon/miserend.hu

## Tech Stack

- **Backend**: PHP 8.4, Twig 3.0, Laravel Eloquent ORM, Apache
- **Frontend**: Angular 21 (standalone components), FullCalendar 6, Angular Material 21, Bootstrap 5 + jQuery (legacy)
- **Database**: MySQL 8.0, Elasticsearch 8 (full-text search)
- **Testing**: PHPUnit 10, Symfony Panther 2 (E2E), Karma + Jasmine (Angular)
- **Infrastructure**: Docker Compose, Node.js 18

## Development Setup

```bash
cd webapp && npm ci && cd ..
docker-compose -f docker/compose.yml -f docker/compose.dev.yml up
# App: http://localhost:8000  Login: admin / miserend
# Mailcatcher: http://localhost:11080  Kibana: http://localhost:5601
```

Port is configurable via `APP_PORT` in `.env`.

## Common Commands

### Angular Calendar
```bash
cd calendar
npm ci
npm run build                        # build → dist/mcal
npm run start:integrated             # watch mode with PHP integration
npx ng test --watch=false --browsers=ChromeHeadless  # CI mode
```

### PHP Backend
```bash
cd webapp && composer install
```

### Testing
```bash
./scripts/docker-test.sh                          # PHP unit + integration
./scripts/docker-test.sh --testsuite api          # specific suite
./scripts/docker-test-panther.sh                  # E2E browser tests
./scripts/docker-test-panther.sh -- --filter HomepageTest
./scripts/docker-coverage.sh                      # coverage report → webapp/tests/coverage/html/
```

### Docker Utilities
```bash
docker exec -it miserend bash
docker exec miserend php index.php q=health
docker exec -it mysql mysql -u root -p miserend
docker logs miserend-miserend-1 2>&1 | grep '\[miserend\]'   # alkalmazás-hibák stack trace-szel
```

Logolás: nincs külön logfájl, minden a `docker logs`-ban van — részletek: `docs/logok.md`.

## Architecture

### PHP Routing
`webapp/index.php` → `Path` class → resolves URL to a PHP class → instantiates it. Page handlers live in `Html\*` namespace, REST endpoints in `Api\*`, Eloquent models in `Eloquent\*`. Templates are Twig files in `webapp/templates/`.

### Adding a Page/API Endpoint
- **Page**: create `webapp/classes/html/{Page}.php` extending `Html`, set `$this->template`, create `.twig` in `webapp/templates/`, add routing in `Path` if needed.
- **API**: create `webapp/classes/api/{Feature}.php` extending `Api`, add tests in `webapp/tests/Api/`.

### Angular App
Standalone components under `calendar/src/app/`. State via RxJS — no NgRx. Built output (`dist/mcal`) is copied to `webapp/js/mcal/` by `docker/miserend/calendar_deploy.py`. Use `ng generate component features/my-feature` for new components.

### Elasticsearch
Full-text search for churches and masses. Updated via `ExternalApi\ElasticsearchApi::updateChurches()` / `updateMasses()`. Manual trigger: `/index.php?q=cron&cron_id=38` (churches) or `&cron_id=39` (masses — can take 30+ min for 500k+ events).

### Database Schema Changes
Add SQL to `docker/mysql/initdb.d/`, then `docker-compose down -v && docker-compose up` to reinitialize.

## Conventions

- **Commit messages**: Hungarian (project convention)
- **PHP naming**: PascalCase classes, camelCase methods
- **Angular testing**: use `xdescribe()` for pending tests until TestBed providers are ready
- **PHP tests**: real test database (no mocks), initialized fresh per run
- **UI strings**: translatable via `t()` function and i18n system

## Key Files

| File | Purpose |
|------|---------|
| `webapp/index.php` | Request entry point |
| `webapp/load.php` | Bootstrap: autoloader, timezone, Twig, session, `.env` |
| `webapp/config.php` | Environment-specific config (DB, API keys) |
| `webapp/classes/path.php` | URL routing |
| `webapp/classes/user.php` | Authentication |
| `calendar/src/main.ts` | Angular bootstrap |
| `docker/compose.yml` | Main Compose file |
| `docker/miserend/Dockerfile` | Multi-stage app image (Node → PHP) |
