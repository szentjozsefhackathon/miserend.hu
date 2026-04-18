# 🙏 miserend.hu

A miserend.hu weboldal teljes forráskódja. Próbáld ki!

Csak [git](gttps://git-scm.com) és [Docker](https://docs.docker.com/engine/install/) legyen nálad és mehet is:

```sh
git clone https://github.com/borazslo/miserend.hu/
cd miserend.hu
docker-compose  -f docker/compose.yml  -f docker/compose.test.yml up
```

Máris elérhető a http://localhost:8000 címen a miserend alkalmazás. Az `admin` felhasználóval be is lehet lépni az alapérelmezett jelszóval: `miserend`.


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


## Miserend web alkalmazás (PHP)

A webalkalmazás fő komponense a `miserend` konténerben indul. A repó `webapp` könyvtárát a dev composer rá-mappeli a konténerre. Így ha bármit változtatsz, rögtön tesztelhető is. 

 A PHP függőségeket `composer` segítségével lehet telepíteni, a JavaScript/CSS függőségeket pedig `nodejs/npm`-el. 

Ha új PHP van NodeJS függőséget építesz be, akkor a dev composer fájlból a két volume-ot ki kell venni és a függőségeket helyben telepíteni. 

A http://localhost:8000 címen érhető el. Az admin / miserend az első felhasználó neve / jelszava.


## Naptár frontend (Angular)

A naptárnézet és naptár szerkesztő felület egy különállóm Angular alap projekt, mely a `miserend` konténerban van szintén.

A `calendar` könyvtárban található Angular alkalmazás forrása.

### 📆 Naptárnézet
- Első alkalommal le kell generálni az időszakokat:
- Admin joggal, az `/periodyeareditor` felületen
- A minta adatok idővel elévülhetnek, fontos az aktualizálásuk!

### Naptárnézet fejlesztése
Amennyiben a naptár alkalmazáson dolgozunk, az npm build után a `docker/miserend/calendar_deploy.py` szkript futtatásával lehet az alkalmazásba integrálni.


A `/calendar` könyvtárban az alábbi parancsokat futtassuk:

Ha még nem volt, akkor:

```sh
npm install
```

```sh
ng build --configuration=localProd
npm run start:integrated
```

- Ezzel egyrészt elérjük, hogy fejlesztői legyen a naptár
- Másrészt elérjük, hogy ha valamit módosítunk, az szinte egyből érvényre jusson
- Ilyenkor egy python script a `/calendar` mappában buildeli az Angularos projektet, majd a megfelelő helyre átmásolja a legenerált fájlokat


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
docker build -t miserend:latest -f docker/miserend/Dockerfile
```

Ha ki szeretnéd próbálni, hogyan működne a valóságban, akkor a [dev composer](docker/compose.dev.yml) fájlban írd ät a `miserend` service `image` attribútumát `localhost/miserend:latest`-re. 

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

### 📦 Composer használata (interaktív módban):

```sh
docker exec miserend composer install|require|update
```

## 🧪 Tesztelés

A projekt PHPUnit alapú unit teszteket használ a kód minőségének biztosítására és a regressziók elkerülésére.

### Unit tesztek futtatása

**Gyors futtatás (minden teszt):**
```sh
bash scripts/docker-test.sh
```

**Csak egy testsuite futtatása:**
```sh
bash scripts/docker-test.sh --testsuite api
bash scripts/docker-test.sh --testsuite simple
```

**Code coverage generálása:**
```sh
bash scripts/docker-coverage.sh
```

A coverage report HTML formátumban a `webapp/tests/coverage/html/index.html` fájlban érhető el.

### Teszt struktúra

A tesztek a `webapp/tests/` könyvtárban találhatók, és tükrözik a `webapp/classes/` struktúráját:

```
webapp/tests/
├── bootstrap.php              # Test környezet inicializálása
├── phpunit.xml                # PHPUnit konfiguráció
├── SimpleFunctionsTest.php    # Helper funkciók tesztjei
├── UtilityFunctionsTest.php   # Utility funkciók tesztjei
└── Api/                       # API osztályok tesztjei
    ├── ApiTest.php            # Api\Api osztály tesztjei
    └── LoginTest.php          # Api\Login osztály tesztjei
```

### Fontos tudnivalók

- **Test naming convention**: A test fájlok neve megegyezik a tesztelt osztály nevével + `Test.php` végződés
- **Test methods**: Minden teszt metódus `test` prefixszel kezdődik, pl. `testValidateVersionMainAcceptsVersion1()`
- **Docker izolált környezet**: A tesztek Docker konténerben futnak, így nem befolyásolják a fejlesztői környezetet
- **Coverage driver**: PCOV extension biztosítja a code coverage-t

### Új tesztek írása

1. **Hozz létre egy új test fájlt** a megfelelő helyen (pl. `webapp/tests/Api/FavoritesTest.php`)
2. **Extend PHPUnit TestCase**: `class FavoritesTest extends TestCase`
3. **Írj teszteket** a kritikus funkcionalitáshoz
4. **Futtasd a teszteket** a fenti parancsokkal
5. **Ellenőrizd a coverage-t** hogy minden fontos ág le legyen fedve

**Példa teszt:**
```php
<?php

use PHPUnit\Framework\TestCase;
use Api\Favorites;

class FavoritesTest extends TestCase {
    
    public function testAddFavoriteAcceptsValidChurchId() {
        $favorites = new Favorites([]);
        
        // Test implementation
        $this->assertTrue(true);
    }
}
```

### Mit érdemes tesztelni?

**Prioritás szerint:**
1. **API validáció és autentikáció** - kritikus biztonsági funkciók
2. **Pure functions** - nincs side effect, könnyen tesztelhető
3. **Üzleti logika** - komplex számítások, döntési fák
4. **Edge case-ek** - határértékek, hibakezelés

**Mit NEM kell tesztelni:**
- Framework/library kód (már tesztelt)
- Triviális getter/setter metódusok
- UI/template renderelés (inkább E2E tesztek)

## 🌳 Branching stratégia

- `master` ➜ staging környezet (`staging.miserend.hu`)
- `production` ➜ éles honlap

## Éles / staging / UAT build
Fejlesztés végén azonban egy megfelelő környezetbe való build kell, például:
```
ng build --configuration=production
python ../docker/miserend/calendar_deploy.py
```