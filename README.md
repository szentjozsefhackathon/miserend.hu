# 🙏 miserend.hu

A miserend.hu weboldal teljes forráskódja. Próbáld ki!

Csak [git](gttps://git-scm.com) és [Docker](https://docs.docker.com/engine/install/) legyen nálad és mehet is:

```sh
git clone https://github.com/borazslo/miserend.hu/
cd miserend.hu
docker-compose  -f docker/compose.yml  -f docker/compose.dev.yml up
```

Máris elérhető a http://localhost:8000 címen a miserend alkalmazás. Az `admin` felhasználóval be is lehet lépni az alapérelmezett jelszóval: `miserend`.

## 🤝 Közreműködés

Kapcsolódj be a fejlesztésbe! A fork → branch → PR folyamat, a teszt-konvenciók és a komponensenkénti (PHP / Angular) részletek itt:

📖 **[CONTRIBUTING.md](CONTRIBUTING.md)**


# ⚙️ Fejlesztői környezet telepítése

Kapcsolódj be a fejlesztésbe! Ehhez szükséged lesz egy fejlesztői környezetre amit ripsz-ropsz felállíthatsz.

## 📦 Előfeltételek

- __[git](gttps://git-scm.com)__ mindenképp legyen nálad, vannak akik __Github Desktopot__ is használnak mellé.   
- __Docker__ vagy __Podman__ is nélkülözhetetlen.
- __nodejs/npm__ és __python__ a naptár rész fejlesztéséhez kell
- __MySQL vagy MariaDB kliens__ ajánlott az az adatbázisban turkáláshoz.

Elsősorban __linux__ alapú fejlesztésre van minden optimalizálva, de nem lehetetlen windows és osx használata sem. Erről a végén írunk még. 




## 🚀 Indítás

### tl;dr
```sh
git clone https://github.com/borazslo/miserend.hu/
cd miserend.hu/webapp
npm ci
cd ..
docker-compose  -f docker/compose.yml -f docker/compose.dev.yml up
```

### Részletesebben

#### Letöltjük az egész repository-t a saját gépünkre.
```
git clone https://github.com/borazslo/miserend.hu/
```
#### Telepítenünk kell a Javascript/CSS függőségeket

```
cd miserend.hu/webapp
npm ci
```

##### Kezdődjön a móka
A docker compose valamennyi konténert szépen felépíti, bekonfigurálja, feltölti adatokkal, és elindítja:
```
cd ..
docker-compose  -f docker/compose.yml  -f docker/compose.dev.yml up
```

Máris elérhető a http://localhost:8000 címen a miserend alkalmazás. Az `admin` felhasználóval be is lehet lépni az alapérelmezett jelszóval: `miserend`.


# Komponensek és konténerek

Az alkalmazás öt komponensből áll.

> 🌐 A szerver által fut-időben használt **külső és belső hálózati kapcsolatok** (host\:port) teljes listája — hasznos firewall / egress-allow-list beállításhoz — a [Kimenő hálózati kapcsolatok](docs/outgoing-connections.md) dokumentumban található.

## 🛢️ MySQL konténer azaz az adatbázis

Az adatbázis egyszerű MySQL / MariaDB. Megőrzi az adatokat újraindítás esetén is.  

Az adatbázis konténer első futtatáskor a `docker/mysql/initdb.d` könyvtár alapján inicializálja az adatbázist. Valamint a  `data` alkönytár alapján fel is töltjük minta adatokkal az adatbázist.

Ha az adatbázis sémán változtatsz, ebbe a könyvtárba vezesd be a módosításokat! Ha pedig pár committal korábban változtattak az adatbázison, akkor szükséget lehet az adatbázis újrainicializálására amire legjobb megoldás a conatiner törlése, majd -- és ez fontos -- a hozzá tartozó _volume_ törlése is. Így már újraindításnál szépen újra épül az adatbázis, bár minden korábbi saját adatbázis adatot elveszik.

Ha grafikus adatbázis elérésre lenne szükség, az [adminer](https://www.adminer.org/en/) ajánlott, egyszerűen /webapp  valamelyik könyvtárába kell tenni és már megy is. Természetesen ezt a fájl nem kell a git tárolóba elmenteni.

## Elastisearch és Kibana

Az alkalmazás keresőmotorját az Elasticsearch adja. A fent leírt standard konténer alapú telepítés során szépen elindul ez is. Sőt az elasticache-init konténer gondoskodik az inicializálásról. Azaz egy jó nagy fájlt lehúzva feltölti rögtön adatokkal is. 

#### Ezeket kézileg is lehet frissíteni:

A templom kereső frissítéséhez az `Externalapi\ElasticsearchApi::updateChurches()` függvényt, a szentmisék keresésének frissítéséhez az `Externalapi\ElasticsearchApi::updateMasses()` függvényt kell futtatni. Legkönnyebb a [/index.php?q=cron&cron_id=38](http:/localhost:8000/index.php?q=cron&cron_id=38) és [/index.php?q=cron&cron_id=39](http:/localhost:8000/index.php?q=cron&cron_id=39) cron oldalak betöltése révén.

Vigyázat! Az 5000 misézőhelyhez évente több mint 500 ezer (!) konkrét liturgikus esemény tartozik, így az updateMass() eltarthat fél óráig is! 

### 📊 kibana

Elasticsearch web interfész fejlesztéshez, teszteléshez.  
Beizzítása kis varázslást igényelhet.

## Mailcatcher

Fejlesztői környezetben indul egy Mailcatcher is, ami a levelezés szimulálásában és tesztelésében segít. A mailcatcher működése esetén bármit csinálhatunk a honlapon, a keletkező emaileket sosem küldi el, hanem a mailcatcher kapja el. Így nem kell ideiglenes smtp beállításokkal nyüglődni.

Alapértelmezetten a http://localhost:11080/ oldalon lehet nyomon követni a kiküldött emaileket. 

Éles (`production`) és `staging` környezetben viszont a mailcatcher **nem** alapértelmezés: ott az `SMTP_HOST` /
`SMTP_PORT` / `SMTP_USER` / `SMTP_PASSWORD` / `SMTP_SECURE` env-változókat kötelező megadni (`docker/.env`, l.
[docs/outgoing-connections.md](docs/outgoing-connections.md)). Ha nincs beállítva SMTP kiszolgáló, a rendszer nem
küld ki levelet, és a `/health` oldal „Levelezőrendszer egészsége” blokkja pirosan jelzi az okát. (#610)


## Miserend web alkalmazás (PHP)

A webalkalmazás fő komponense a `miserend` konténerben indul. A repó `webapp` könyvtárát a dev composer rá-mappeli a konténerre. Így ha bármit változtatsz, rögtön tesztelhető is. 

 A PHP függőségeket `composer` segítségével lehet telepíteni, a JavaScript/CSS függőségeket pedig `nodejs/npm`-el. 

Ha új PHP van NodeJS függőséget építesz be, akkor a dev composer fájlból a két volume-ot ki kell venni és a függőségeket helyben telepíteni. 

A http://localhost:8000 címen érhető el. Az admin / miserend az első felhasználó neve / jelszava.


## Naptár frontend (Angular)

A naptárnézet és naptár szerkesztő felület egy különálló Angular alap projekt, mely a `miserend` konténerben van szintén. A forrása a `calendar/` könyvtárban.

### 📆 Naptárnézet

- Első alkalommal le kell generálni az időszakokat:
- Admin joggal, az `/periodyeareditor` felületen
- A minta adatok idővel elévülhetnek, fontos az aktualizálásuk!

### Naptár fejlesztése, build / deploy, tesztek

A részletek (build / `start:integrated` / Karma teszt / CI workflow / `xdescribe` konvenció) a [CONTRIBUTING.md Angular fejezetében](CONTRIBUTING.md#-angular-naptár-calendar) találhatók.


# 📅 RRULE Definícióik dokumentációja

Az Angular naptár és a PHP backend különféle **RRULE** (Recurrence Rule) definíciókat támogat az ismétlődő eseményekhez. Az összes támogatott RRULE tulajdonság, frekvencia típus, és implementációs mintázat részletes dokumentációja megtalálható itt:

📖 **[RRULE_DEFINITIONS.md](RRULE_DEFINITIONS.md)** - Teljes RRULE dokumentáció (RFC 5545 alapú)

**Támogatott RRULE tulajdonságok:**
- `dtstart` - Ismétlődés kezdete (SZÜKSÉGES)
- `freq` - Frekvencia: daily, weekly, monthly, yearly (SZÜKSÉGES)
- `until` - Ismétlődés vége (opcionális)
- `count` - Előfordulások száma (opcionális)
- `interval` - Lépések közötti távolság
- `bymonth` - Hónap(ok) [1-12]
- `bymonthday` - Hónap napja(i) [1-31]
- `byweekday` - Hét napja(i) [MO, TU, WE, TH, FR, SA, SU]
- `bysetpos` - Pozíció a halmazban (pl. 1=első, -1=utolsó)
- `byweekno` - Hét szám(ai) [1-53]
- `exdate` - Kizárt dátumok
- Páros/páratlan hét szűrés (`byweekno` tömbökkel)

# Fejlesztői megjegyzések

## 🌍 Környezeti változók

Egyes beállításokat, pl. portokat, az `.env.example` fájl tartalmának átmásolásával az `.env` fájlban lehet módosítani.

- Ha a `docker up` hibát generál, mondván hogy egy port már foglalt, akkor ez lehet a megoldás. Egyébként opcionális.
- `MISEREND_WEBAPP_ENVIRONMENT` = `development` | `staging` | `production`

## Helyi build

Az alkamazásból helyben is lehet container image-t készíteni, ehhez a következő parancsot kell lefuttatni:

```sh
docker build -t localhost/miserend:latest -f docker/miserend/Dockerfile .
```

A [compose.yml](docker/compose.yml) a `miserend` service-t a `localhost/miserend:latest` image-ből indítja, ezért a fenti build után a szokásos `docker compose -f docker/compose.yml -f docker/compose.dev.yml up` a **helyben épített** image-et használja — nem kell semmit átírni.

## 🗃️ Dump készítés

Ha dump-ot szeretnénk készíteni az adatbázisról fejlesztési célra, a kényes adatok eltávolításáról gondoskodni kell, erre a `docker/mysql/dump.sh` szkript szolgál. A fájl elején lévő változóktat környezeti változóként lehet felülbírálni.


## Windows 

Lehetséges Windows Subsystem for Linux nélkül is felépíteni egy miserend fejlesztői környezetet, de mivel az alkalmazás komponensei alapvetően natív linuxos eszközök, a windowsos futtatás mindig extra odafigyelést igényel.   

Mindenesetre, a szükséges eszközök winget-tel is telepíthetőek.

```
winget install --id=Git.Git -e
winget install --id=Python.Python.3.14 -e
winget install --id=Docker.DockerCLI -e
winget install --id=Docker.DockerCompose -e
winget install --id=OpenJS.NodeJS.LTS -e
```

De szinte biztos, hogy a végén valami extra masszírozás kell.



## 🛠️ További parancsok

### 🧭 Konténerekbe belépés

```sh
docker exec -it [mysql|mailcatcher|miserend] bash
```

### 🪵 Logok

Nincs külön logfájl, minden a `docker logs`-ban van. Az alkalmazás hibái `[miserend]` előtaggal mennek ki, stack trace-szel:

```sh
docker logs miserend-miserend-1 2>&1 | grep '\[miserend\]'
```

Részletek, éles példák és további szűrők: [docs/logok.md](docs/logok.md).

### 📦 Composer használata (interaktív módban):

```sh
docker exec miserend composer install|require|update
```

## 🧪 Tesztelés

A projekt háromféle tesztet használ: **ng-test** (Angular), **PHPUnit** (PHP unit/integration), és **Panther** (funkcionális/E2E). A GitHub Actions automatikusan futtatja őket, a részletes dokumentáció:

📖 **[tests/README.md](webapp/tests/README.md)** — Teszt típusok, futtatás, és új tesztek írása

## 🌳 Branching stratégia

- `master` ➜ staging környezet (`staging.miserend.hu`)
- `production` ➜ éles honlap

## 🌐 Domainek / környezetek

| Domain | Szerep | Környezet (`webapp/config.php`) |
|--------|--------|----------------------------------|
| `miserend.hu` (+ `www.miserend.hu`) | Éles honlap (production) | `default` / `production` — `https://miserend.hu` |
| `staging.miserend.hu` | Staging / deploy-target (a `master` ág ide megy) | `staging` — `http://staging.miserend.hu` |
| `dev.miserend.hu` | Karbantartási tartalék-oldal (best-effort, a `webapp/0index.html` ide irányít leállás alatt; működése nem garantált) | — (nincs saját config-bejegyzés) |
| `http://localhost:8000` | Helyi fejlesztés (Docker, `docker/compose.dev.yml`) | `development` |

A környezetet a `MISEREND_WEBAPP_ENVIRONMENT` env-változó választja ki (alapértelmezés: `staging`). A `development` env a `domain`-t `http://localhost:8000`-re állítja (hogy a mailcatcher-levelekben is jó legyen a domain teszteléskor); helyi dev-en ez már *secure context*, l. [CONTRIBUTING.md](CONTRIBUTING.md).

## Éles / staging / UAT build
Fejlesztés végén azonban egy megfelelő környezetbe való build kell, például:
```
ng build --configuration=production
python ../docker/miserend/calendar_deploy.py
```
