#!/bin/sh
#
# #673: az OSM-kivonat letöltése az OSRM-gráfhoz.
#
# Miért külön konténer: a hivatalos `osrm-backend` image-ben NINCS letöltő eszköz —
# se wget, se curl, se nc (kimértem). Ezért a letöltés busybox-ban fut, ugyanazon a
# named volume-on, mint amit utána a gráfépítő olvas. Ugyanaz a minta, mint a
# `data-init`-nél az Elasticsearch-adatnál.
set -eu

DATA_DIR="${OSRM_DATA_DIR:-/data}"
PBF_URL="${OSRM_PBF_URL:-https://download.geofabrik.de/europe/hungary-latest.osm.pbf}"
PBF_FILE="$DATA_DIR/region.osm.pbf"

mkdir -p "$DATA_DIR"

# A kész gráf mellé nem kell újra letölteni. Ha csak a gráfot építjük újra (elrontott
# build), a 306 MB-ot ne töltsük le megint.
if [ -f "$PBF_FILE" ] && [ "${OSRM_FORCE_DOWNLOAD:-0}" != "1" ]; then
    echo "[osrm-fetch] A PBF már megvan ($PBF_FILE), nem töltöm le újra."
    echo "[osrm-fetch] Friss adathoz: OSRM_FORCE_DOWNLOAD=1"
    exit 0
fi

echo "[osrm-fetch] Letöltés: $PBF_URL"

# .part-ra töltünk, és csak sikeres letöltés után nevezzük át — egy megszakadt
# letöltés így nem hagy féllábú PBF-et, amit a következő futás késznek hinne.
rm -f "$PBF_FILE.part"
wget -q -O "$PBF_FILE.part" "$PBF_URL"
mv "$PBF_FILE.part" "$PBF_FILE"

echo "[osrm-fetch] Kész: $(wc -c < "$PBF_FILE") bájt"
