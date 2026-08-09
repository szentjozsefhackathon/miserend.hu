#!/bin/sh
#
# #673: az OSRM-gráf havi újraépítése friss OSM-adatból.
#
# Havonta elég: az úthálózat lassan változik, és a keresés szempontjából egy-két
# hetes lemaradás nem számít. CPU-ban ~27 másodperc havonta.
#
# Cron (a compose-fájl könyvtárából, hónap első vasárnapján hajnalban):
#   17 3 1-7 * 0  cd /var/www/miserend.hu/docker && ./osrm/rebuild.sh >> /var/log/osrm-rebuild.log 2>&1
#
# Amit tud:
#  - a régi gráf a helyén marad, amíg az új el nem készül (a routed közben kiszolgál),
#  - ha az építés elhasal, NEM cserél, tehát nem marad gráf nélkül a szolgáltatás,
#  - csak a csere pillanatában van rövid újraindítás.
set -eu

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f compose.yml"

echo "[osrm-rebuild] $(date -Is) indul"

# Külön, ideiglenes könyvtárba építünk a volume-on belül, hogy a futó szolgáltatás
# gráfja érintetlen maradjon. Csak sikeres építés után cserélünk.
$COMPOSE --profile osrm run --rm \
    -e OSRM_DATA_DIR=/data/next \
    -e OSRM_FORCE_REBUILD=1 \
    -e OSRM_FORCE_DOWNLOAD=1 \
    osrm-init

echo "[osrm-rebuild] építés kész, csere"

# A csere és a régi takarítása egy rövid, egyszer lefutó konténerben. Azért itt és
# nem a hoszton, mert a volume tartalmához így nem kell hoszt-oldali útvonalat tudni.
$COMPOSE --profile osrm run --rm --entrypoint /bin/sh osrm-init -c '
    set -eu
    test -f /data/next/region.osrm.mldgr || { echo "nincs kész gráf, nem cserélek"; exit 1; }
    rm -rf /data/prev
    mkdir -p /data/prev
    # A régit félretesszük (nem töröljük azonnal) — így kézzel vissza lehet állni.
    for f in /data/region.osrm*; do [ -e "$f" ] && mv "$f" /data/prev/ || true; done
    mv /data/next/region.osrm* /data/
    rmdir /data/next 2>/dev/null || true
    echo "csere kész"
'

$COMPOSE --profile osrm up -d --force-recreate osrm

echo "[osrm-rebuild] $(date -Is) kész"
