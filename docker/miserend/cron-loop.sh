#!/bin/sh
#
# #171: a cron-futtatás a konténeren belülről.
#
# Eddig a hosztról kellett ütemezni (`*/5 * * * * php …/index.php q=cron`), tehát a
# konténer önmagában nem volt teljes: aki felhúzta, annak külön kellett tudnia róla.
#
# Szándékosan NEM a cron démon: a konténer www-data-ként fut (l. compose.yml), a cron
# démonhoz viszont root kell, és crontab-fájlt sem kell szerkeszteni benne (ezért nem
# kell szövegszerkesztő sem az image-be). Az ütemezést amúgy is az alkalmazás végzi —
# a crons tábla frequency/from/until mezői —, ide csak egy rendszeres kopogás kell.
#
# A futások szándékosan sorosak: a következő kör csak az előző után indul, tehát egy
# hosszú munka (mise-újraindexelés) nem torlódik önmagára.
#
#   CRON_ENABLED   1 = fusson (alapból KI, l. lentebb)
#   CRON_INTERVAL  két kopogás között ennyi másodperc (default 300, mint a régi */5)
set -u

INTERVAL="${CRON_INTERVAL:-300}"
ENVIRONMENT="${MISEREND_WEBAPP_ENVIRONMENT:-production}"

echo "[cron] indul, ${INTERVAL} másodpercenként, env=${ENVIRONMENT}"

while true; do
    # A kimenetet a konténer naplójába írjuk (docker logs / docker compose logs),
    # hogy ne kelljen külön logfájlt forgatni. A cron-oldal HTML-t ír (a böngészőből is
    # futtatható), ezért a tageket és az entitásokat kiszedjük — különben
    # "\Api\Sqlite-&gt;cron()" alakban olvasnánk a naplót. Az &amp; szándékosan utolsó.
    php /miserend/webapp/index.php q=cron "env=${ENVIRONMENT}" 2>&1 \
        | sed -e 's|<[^>]*>||g' \
              -e 's/&gt;/>/g' -e 's/&lt;/</g' -e 's/&quot;/"/g' -e "s/&#039;/'/g" \
              -e 's/&amp;/\&/g' \
        | grep -v '^[[:space:]]*$' \
        | sed -e 's/^/[cron] /'

    sleep "$INTERVAL"
done
