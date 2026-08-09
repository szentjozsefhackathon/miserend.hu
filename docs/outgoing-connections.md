# Kimenő hálózati kapcsolatok

> Ez a lista azokat a külső host:port-okat foglalja össze, amikre a miserend.hu szerver fut-időben rácsatlakozik.
> Hasznos szerver-firewall, kimenő-allow-list, container egress-policy beállítások előtt — egyenként engedélyezős környezetben ezek hiányában a telepítés ripityára esik.
>
> Tracker: #214

## Külső API-k

| Cél | Host | Port | Mire kell | Forrás (osztály) |
|---|---|---|---|---|
| **OSM Nominatim** | `nominatim.openstreetmap.org` | 443 | Cím vagy terület neve → koordináta keresés (geocoding) | `\ExternalApi\NominatimApi` |
| **OSM Overpass** | `overpass-api.de` | 443 | Templom-elemek és határok lekérése OSM-ből (`url:miserend` tag) | `\ExternalApi\OverpassApi` |
| **OSM opening_hours evaluator** | `openingh.openstreetmap.de` | 443 | `opening_hours` mező parse / evaluate | `\ExternalApi\OpeninghApi` |
| **OSM OAuth** | fejlesztéshez `master.apis.dev.openstreetmap.org`, élesen:  `api.openstreetmap.org` | 443 | OSM adatok módosítása | `\ExternalApi\OpenStreetMapApi` |
| **Mapquest Directions** | `open.mapquestapi.com` | 80 | Útvonal-távolság számítás templomok között (cron) | `\ExternalApi\MapquestApi` |
| **Közösségek API** | `kozossegek.hu` | 443 | Templomhoz tartozó közösségek lekérése | `\ExternalApi\KozossegekApi` |
| **Szentségimádások** | `szentsegimadas.hu` | 443 | Szentségimádás-időpontok (elavulóban) | `\ExternalApi\SzentsegimadasApi` |
| **Napi lelki batyu** | `szentjozsefhackathon.github.io` | 443 | Napi lelki batyu: a liturgikus naptár érkezik innen | `\ExternalApi\NapiLelkibatyuApi` |

## Belső szolgáltatások (Docker compose network)

| Cél | Host | Port | Mire kell |
|---|---|---|---|
| **Elasticsearch** | `elasticsearch` | 9200 | Mise / templom kereső index |
| **MariaDB** | `mysql` | 3306 | Fő adatbázis |
| **Mailcatcher** | `mailcatcher` | 1025 (SMTP) / 1080 (web) | Dev környezetben email-elfogás |

## SMTP (production)

Production-ben a levélküldés az alábbi env-változókból konfigurálódik (`webapp/config.php`, `smtp` ág):

| Változó | Alapérték | Megjegyzés |
|---|---|---|
| `SMTP_HOST` | *(üres)* | **Kötelező.** Üresen a rendszer egyetlen levelet sem küld ki, a `/health` pirosan jelzi. |
| `SMTP_PORT` | `25` | |
| `SMTP_USER` | *(üres)* | Ha meg van adva, bekapcsolja az SMTP-autentikációt. |
| `SMTP_PASSWORD` | *(üres)* | |
| `SMTP_SECURE` | *(üres)* | `tls` vagy `ssl` |

A `.env` a compose-fájl mellé kerül (`docker/.env`), és a `docker/compose.yml` `environment` blokkja adja tovább a
konténernek. **A `.env` önmagában nem elég**: a compose csak a saját interpolációjához olvassa, a PHP-hez nem jut el
belőle semmi, ha a service nem sorolja fel a változókat (ez volt a #610 gyökere — az SMTP így a dev `mailcatcher`
alapértéken maradt és minden levél elveszett).

Engedélyezni kell:

| Cél | Host | Port |
|---|---|---|
| SMTP relay | (config-tól függ) | 25 / 465 / 587 |

### Kézbesíthetőség

Az SMTP `250` válasz csak azt igazolja, hogy a relay átvette a levelet, a címzetti
kézbesítést nem. A feladó domain SPF rekordjának engedélyeznie kell a relay tényleges
kimenő IP-jét, és a relay naplójában kell ellenőrizni az esetleges későbbi visszapattanást.

A #610 vizsgálatakor az `epistola.hcbc.hu` relay nem szerepelt a `miserend.hu` SPF
rekordjában. Ha továbbra is ez a szolgáltató küld, az SPF-hez a szolgáltató által megadott
`include:` mechanizmust (jelenleg `include:epistola.hcbc.hu`) kell hozzáadni úgy, hogy
egyetlen SPF rekord maradjon. Ne a relay fogadó A rekordját vegyük fel találomra: a kimenő
IP eltérhet tőle. A módosítás után külső címre küldött tesztlevél fejlécében ellenőrizni
kell az SPF eredményt; ha nem `pass`, a relay naplója alapján kell azonosítani a tényleges
kimenő címet.

## A BÖNGÉSZŐ által betöltött külső erőforrások

> Ezek nem a szerverről mennek ki, hanem a látogató böngészőjéből — tehát egy szerver-oldali
> egress-allowlist NEM fedi le őket. Akkor számítanak, ha zárt hálózatban (vagy szigorú CSP
> mellett) kell működnie az oldalnak: ha ezek nem érhetők el, a térkép egyszerűen nem jelenik meg.
> Ezért kerültek ide. (#653)

| Cél | Host | Mire kell | Hol |
|---|---|---|---|
| **CARTO Voyager csempék** | `{a,b,c,d}.basemaps.cartocdn.com` | a térkép alaprétege | `_map_leaflet.twig` |
| html5shiv, respond.js | `oss.maxcdn.com` | régi IE-polyfillek | `layout.twig` |
| OpenLayers | `openlayers.org` | régi térkép-kód | (legacy) |

Amit érdemes tudni róluk:

- **A Leaflet és bővítményei már NEM külső forrásból jönnek** (#661): a `webapp/package.json`-ból
  telepítjük és magunk szolgáljuk ki őket (`/node_modules/leaflet/...`), egységesen **1.9.4**
  verzióban. Korábban három sablon háromféle verziót töltött be az unpkg-ról (1.7.1 / 1.9.3 /
  1.9.4), a Leaflet.TextPath pedig egy GitHub Pages-ről (`makinacorpus.github.io`) — az nem is CDN.
  Ezzel öt külső kérés és két idegen hoszt esett ki a térképes oldalak kritikus útjáról.
- A `leaflet.polylinedecorator.css` hivatkozás **HTTP 404** volt (nem létezik az unpkg-n); a #653-ban
  töröltük.
- A **Stamen csempeszerver** (`stamen-tiles-*.a.ssl.fastly.net`) mérve **HTTP 503**-at adott: a Stamen
  2023-ban a Stadiához költözött. A réteg soha nem is volt kiválasztható (definiálva volt, de sehol
  nem használtuk), ezért a #653-ban töröltük. Az utód (`tiles.stadiamaps.com`) kulcs nélkül **HTTP
  401** — ha valaha kell terep-alapréteg, az regisztrációt és API-kulcsot igényel.

A #653 arról szól, hogy ezek nagy részét saját kiszolgálásra váltsuk (a jQuery, a Bootstrap és a
FontAwesome már ma is a `webapp/package.json`-ból jön) — akkor ez a szakasz a csempeszerverre
zsugorodik.

## Container image registry-k

Build / pull időben kellhet:

| Cél | Host | Port | Mire kell |
|---|---|---|---|
| GitHub Container Registry | `ghcr.io` | 443 | A `miserend.hu` image pull |
| Docker Hub | `registry-1.docker.io`, `auth.docker.io` | 443 | `mariadb`, `node`, `busybox`, `dockage/mailcatcher` |
| Elastic Registry | `docker.elastic.co` | 443 | ES image pull |

## Build-time (csak fejlesztői gépen)

| Cél | Host | Port | Mire kell |
|---|---|---|---|
| npm registry | `registry.npmjs.org` | 443 | Angular dep (`calendar/`) |
| Packagist | `packagist.org`, `repo.packagist.org` | 443 | Composer dep |
| nodejs.org | `nodejs.org` | 443 | Node binary (Docker build esetén) |
| GitHub | `github.com`, `api.github.com`, `codeload.github.com` | 443 | git clone / composer github source |

---

## Karbantartás

Ha új külső API integrációt vezetsz be:
1. Vedd fel ide a host-ot és a célt
2. A `\ExternalApi\...` osztály implementációja a `webapp/classes/externalapi/` alatt szokott lenni
3. Production-allow-list frissítéséhez küldj jelzést a szerver-üzemeltetőnek

Hiányzik valami? Nézd át a `webapp/classes/externalapi/` mappa fájljait, a `$apiUrl` mezőket — ez a lista azokból generálódott.
