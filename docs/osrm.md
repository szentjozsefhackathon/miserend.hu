# OSRM útvonaltervező (#673)

Saját útvonaltervező a compose-ban. Opcionális: az `osrm` profil mögött van, tehát a
sima `docker compose up` nem indítja el.

## Miért saját

A szomszédos templomok távolságát eddig a Mapquest adta. Az kvótás és fizetős (#129),
ezért a `Distance` osztály hiba esetén légvonalra esik vissza — a „5 km-re innen" tehát
néha közút szerinti, néha madárrepülés szerinti volt, és nem lehetett tudni, melyik.
Saját OSRM-mel nincs kvóta, nincs külső függés, és a válasz 10–20 ms.

## Mennyibe kerül

Magyarországi kivonaton mérve:

| | érték |
|---|---|
| memória futás közben | **389 MB** |
| útvonal-válaszidő | **10–20 ms** (Bp→Szeged 173 km, Sopron→Debrecen 449 km) |
| teljes gráf-újraépítés | **~27 s** (extract 21 s + partition 4 s + customize 2 s) |
| csúcs-RAM építéskor | 1,9 GB |
| lemez | 306 MB PBF + 588 MB gráf |

Új szerver nem kell — ugyanabba a compose-ba fér, mint az Elasticsearch.

## Indítás

```bash
docker compose --profile osrm up -d
```

Három szolgáltatás indul egymás után:

1. **`osrm-fetch`** — letölti a térkép-kivonatot. Külön, busybox-ban: az
   `osrm-backend` image-ben **nincs letöltő eszköz** (se wget, se curl, se nc).
2. **`osrm-init`** — felépíti a gráfot (`extract` → `partition` → `customize`).
   Ha a gráf már kész, kihagyja.
3. **`osrm`** — a futó `osrm-routed`, `mld` algoritmussal.

A gráf named volume-ban (`osrm_data`) marad, tehát újraindításnál nem épül újra.

## Beállítások

Mind opcionális, l. `.env.example`:

| változó | alapérték | mire jó |
|---|---|---|
| `OSRM_IMAGE` | `ghcr.io/project-osrm/osrm-backend:v5.27.1` | verzió rögzítése |
| `OSRM_PBF_URL` | Geofabrik: Magyarország | melyik területből épüljön a gráf |
| `OSRM_PORT` | `5000` | hoszt-oldali port |
| `OSRM_FORCE_DOWNLOAD` | `0` | `1`: a kivonat akkor is újratöltődik, ha megvan |
| `OSRM_FORCE_REBUILD` | `0` | `1`: a gráf akkor is újraépül, ha kész |
| `OSRM_URL` | *(üres)* | ha be van állítva, az alkalmazás is használja |

Kipróbáláshoz érdemes kisebb kivonatot megadni — pl. Andorra —, akkor a letöltés és az
építés is töredék idő.

## Térkép frissítése

```bash
OSRM_FORCE_DOWNLOAD=1 OSRM_FORCE_REBUILD=1 docker compose --profile osrm up osrm-fetch osrm-init
docker compose --profile osrm up -d osrm
```

Vagy egyben: `docker/osrm/rebuild.sh`.

## Egészség

A `/health` külső API táblájában akkor jelenik meg, ha az `OSRM_URL` be van állítva.
Ekkor egy valódi útvonalkérdéssel ellenőrizzük, hogy a szolgáltatás fut-e és betöltötte-e
a gráfot.

Beállítás nélkül **nem hibát** mutat, hanem azt, hogy nincs bekapcsolva. Ez szándékos: az
állandó piros pont azt öli meg, amiért az oldal van — a piros maradjon a valódi bajnak.

Maga a konténer is rendelkezik healthcheckkel. Az OSRM-nek nincs `/health` végpontja, és
letöltő eszköz sincs a képben, ezért a `bash /dev/tcp`-vel kérünk egy valódi útvonalat.
Nem konkrét `"code":"Ok"`-ot várunk — az a gráf területétől függ (kis kivonaton egy
budapesti pont `NoSegment`) —, hanem azt, hogy értelmes JSON jön vissza.

## Kimenő kapcsolat

A kivonat letöltése a Geofabrikra megy. Ez az egyetlen kifelé menő forgalma, és csak
építéskor. Futás közben az OSRM semmit nem hív. L. [outgoing-connections.md](outgoing-connections.md).
