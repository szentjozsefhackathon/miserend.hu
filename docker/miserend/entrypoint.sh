#!/bin/sh
set -e

# #171: opcionálisan a konténer maga ütemezi a cront. Alapból KI van kapcsolva, mert az
# éles telepítés ma a hosztról ütemez — ha mindkettő menne, kétszer futna minden munka.
# Átállás: előbb a hoszt crontab sorát kell kivenni, és csak utána CRON_ENABLED=1.
if [ "${CRON_ENABLED:-0}" = "1" ]; then
    /cron-loop.sh &
fi

exec apache2-foreground
