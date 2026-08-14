#!/bin/sh
set -e

# #171: opcionálisan a konténer maga ütemezi a cront. Alapból KI van kapcsolva, mert az
# éles telepítés ma a hosztról ütemez — ha mindkettő menne, kétszer futna minden munka.
# Átállás: előbb a hoszt crontab sorát kell kivenni, és csak utána CRON_ENABLED=1.
if [ "${CRON_ENABLED:-0}" = "1" ]; then
    /cron-loop.sh &
fi

# #751: a fordított naptárcsomag nincs verziókövetve. Élesben az image-ben a
# helyén van, fejlesztésben viszont a `../webapp:/miserend/webapp` bind mount
# eltakarja a hoszt könyvtárával — friss klón után ott nincs semmi, és a naptár
# némán eltűnne. Ilyenkor visszamásoljuk az image-ből. (Az i18n és a cal_images
# verziókövetett marad, azokhoz nem nyúlunk.)
# Csak akkor nyúlunk hozzá, ha tényleg hiányzik: aki a hoszton buildel
# (`npm run start:integrated`), annak a frissebb példányát nem írjuk felül.
if [ -d /opt/mcal-dist ] && [ ! -f /miserend/webapp/js/mcal/main.js ]; then
    echo "[entrypoint] Hiányzik a fordított naptárcsomag — másolom az image-ből."
    ok=1
    cp -a /opt/mcal-dist/js/mcal /miserend/webapp/js/ 2>/dev/null || ok=0
    cp -a /opt/mcal-dist/css/styles.css /miserend/webapp/css/ 2>/dev/null || ok=0
    if [ -d /opt/mcal-dist/fonts ]; then
        cp -a /opt/mcal-dist/fonts /miserend/webapp/ 2>/dev/null || ok=0
    fi
    if [ "$ok" = "0" ]; then
        echo "[entrypoint] FIGYELEM: a naptárcsomag kimásolása nem sikerült (jogosultság?)."
        echo "[entrypoint] Add ki a hoszton: cd calendar && npm ci && npm run build \\"
        echo "[entrypoint]   && python3 ../docker/miserend/calendar_deploy.py dist/mcal/browser ../webapp"
    fi
fi

exec apache2-foreground
