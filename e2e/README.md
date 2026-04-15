# Miserend.hu E2E Tests (Playwright)

End-to-end tesztek Playwright használatával.

## Futtatás Docker-ben (ajánlott)

### Lokálisan
```bash
# Indítsd el az alkalmazást
docker compose -f docker/compose.yml -f docker/compose.dev.yml up -d

# Futtasd a teszteket
docker compose -f docker/compose.yml -f docker/compose.dev.yml -f docker/compose.e2e.yml run --rm playwright

# Leállítás
docker compose -f docker/compose.yml -f docker/compose.dev.yml down
```

### Környezeti változók (opcionális)
Hozz létre egy `e2e/.env` fájlt (lásd `.env.example`):
```env
E2E_ADMIN_USER=admin
E2E_ADMIN_PASS=miserend
```

Vagy add meg futtatáskor:
```bash
E2E_ADMIN_USER=admin E2E_ADMIN_PASS=miserend docker compose -f docker/compose.yml -f docker/compose.dev.yml -f docker/compose.e2e.yml run --rm playwright
```

## Futtatás lokálisan (npm)

Ha Docker nélkül szeretnéd futtatni (fejlesztéshez):

### Telepítés
```bash
cd e2e
npm install
npx playwright install chromium
```

### Tesztek futtatása
```bash
npm test                  # headless
npm run test:headed       # böngésző látható
npm run test:ui           # interaktív UI
npm run test:debug        # debug mód
npm run report            # HTML riport
```

## GitHub Actions (CI)

A tesztek automatikusan futnak GitHub Actions-ben minden push/PR esetén.
A workflow: `.github/workflows/playwright.yml`

Secrets beállítása (opcionális):
- `E2E_ADMIN_USER`
- `E2E_ADMIN_PASS`

## Megjegyzések

- **Docker módban**: a tesztek a `miserend` konténerhez csatlakoznak az `inner` hálózaton
- **Lokál npm módban**: a tesztek automatikusan elindítják a Docker compose környezetet
- CI környezetben 2 retry van beállítva
- Screenshot és trace csak hiba esetén készül
