#!/bin/sh
#
# #673: OSRM útvonal-gráf építése a már letöltött Geofabrik-kivonatból.
#
# A letöltés NEM itt van: az osrm-backend image-ben nincs letöltő eszköz, azt az
# `osrm-fetch` (busybox) intézi ugyanazon a volume-on.
#
# Három lépés kell (MLD algoritmus):
#   extract    a PBF-ből köztes gráf, a profil szabályai szerint  (a leghosszabb)
#   partition  cellákra bontás
#   customize  élsúlyok
#
# Mért idők magyarországi kivonaton: extract 21 s + partition 4 s + customize 2 s.
# Csúcs-RAM az extract alatt 1,9 GB — ezért fut egyszer lefutó service-ként, nem a
# routed konténerben.
#
# Alapból NEM épít újra: ha a kész gráf ott van, kilép. Így a második
# `docker compose --profile osrm up` azonnal indul. Újraépítéshez:
#   OSRM_FORCE_REBUILD=1 docker compose --profile osrm up osrm-init
set -eu

DATA_DIR="${OSRM_DATA_DIR:-/data}"
PROFILE="${OSRM_PROFILE:-/opt/car.lua}"

PBF_FILE="$DATA_DIR/region.osm.pbf"
# Az osrm-routed ezt a fájlt kéri; a többi (.cells, .partition, ...) mellette keletkezik.
GRAPH_FILE="$DATA_DIR/region.osrm.mldgr"

if [ -f "$GRAPH_FILE" ] && [ "${OSRM_FORCE_REBUILD:-0}" != "1" ]; then
    echo "[osrm-init] A gráf már megvan ($GRAPH_FILE), nem építem újra."
    echo "[osrm-init] Kényszerített újraépítés: OSRM_FORCE_REBUILD=1"
    exit 0
fi

if [ ! -f "$PBF_FILE" ]; then
    echo "[osrm-init] HIÁNYZIK a kivonat: $PBF_FILE"
    echo "[osrm-init] Előbb az osrm-fetch service-nek kell lefutnia."
    exit 1
fi

echo "[osrm-init] extract ($PROFILE)"
osrm-extract -p "$PROFILE" "$PBF_FILE"

echo "[osrm-init] partition"
osrm-partition "$DATA_DIR/region.osrm"

echo "[osrm-init] customize"
osrm-customize "$DATA_DIR/region.osrm"

echo "[osrm-init] Kész."
