# Docker

A [miserend/Dockerfile](miserend/Dockerfile) egyben tartalmazza az összes fordítási lépést, ebbe beleértve a node modulok telepítését, a calendar fordítását és a PHP modulok fordítását is. Ez gyakori CI/CD környezetbel buildek-hez (ahol nem feltétlenül van cache, vagy korlátozottak a kapacitások) nem praktikus.

CI/CD környezetben (lásd [.github/workflows](.github/workflows)) egy php base image-dszel [php-base](php-base/Dockerfile) dolgozunk, ez [.github/workflows/php-base.yaml](.github/workflows/php-base.yaml) alapján hetente egyszer, ill. a php függőségek változásakor triggerelődik.

Ha bármelyek commit-et tag-eljük a tárolóban, az [.github/workflows/app-release.yaml](.github/workflows/app-release.yaml) munkafolyamat helyben előkészíti a npm függőségeket (ezek cache-elve is vannak), majd futtatja a [miserend/Dockerfile.github](miserend/Dockerfile.github) Dockerfile-t, amelynek alapja a php-base image-ünk, és így jóval egyszerűbb, mint a helyi build-re szolgáló változat.

# Docker Compose

## compose.yml – Alap Compose fájl
Ez a fő Compose fájl, amely a legfontosabb szolgáltatásokat írja le:

- mysql: MariaDB adatbázis, előre beállított jelszóval, inicializáló szkriptekkel és konfigurációval.
- elasticsearch: Keresőmotor, egycsomópontos módban, egyszerűsített beállításokkal.
- miserend: Maga a webalkalmazás, előre elkészített (buildelt) Docker image-ből indul, a szükséges portokkal és ideiglenes fájlok számára külön volume-mal.

A szolgáltatások egy belső hálózaton kommunikálnak egymással. Ez a fájl az alapértelmezett, ha a teljes rendszert szeretnéd futtatni (pl. helyi teszteléshez vagy éles környezethez).

### A `miserend` service tartalma futásidőben (#721, #720)

Ez a fájl **nem** mountolja fölé a hoszt `webapp` könyvtárát: amit a konténer kiszolgál, az az image tartalma. Így a Dockerfile-ban lefutó `ng build --configuration=${NG_CONFIG}` és a `calendar_deploy.py` eredménye tényleg kimegy, és a `composer install` / `npm ci` friss függőségei is.

Korábban ez nem így volt, és két hibát okozott:

- a naptár minden környezetben a **beversenyzett, régi** `webapp/js/mcal/main-*.js`-t szolgálta ki, hiába épült friss az image-ben (#721);
- a `vendor_data` / `node_modules_data` named volume csak az **első** létrehozáskor kapja meg az image tartalmát, a production deploy pedig `up -d`-t hív `down -v` nélkül — így új függőség (pl. a leaflet) sosem jutott ki (#720).

Ami marad a hoszton: **`webapp/kepek`** (a feltöltött képek futásidőben keletkeznek, és a `.dockerignore` ki is hagyja őket az image-ből), valamint a `tmp` és `sqlite` named volume.

**Következmény üzemeltetőnek:** a szerveren a `webapp/` alatti kézi fájlmódosítás többé nem érvényesül. A beállítás az `.env`-ből jön — a `config.php` minden értéke `env()`-en át olvas. Ha valamit tényleg felül kell írni, az `.env` a helye, nem a fájl.

## compose.kibana.yml – Kibana (opcionális)
Ez a fájl egy opcionális Kibana szolgáltatást ad a stack-hez:

- kibana: Grafikus felület az Elasticsearch indexek és adatok böngészéséhez, vizualizációkhoz.
- Az Elasticsearch-hez kapcsolódik, a szükséges portot (5601) kiexportálja.

A Kibana nem kötelező része a rendszernek, de fejlesztéshez, hibakereséshez vagy adatelemzéshez hasznos lehet.

## compose.dev.yml – Fejlesztői környezet
Ez a fájl a fejlesztői workflow-t támogatja:

- mailcatcher: Teszt e-mail szerver, amely elfogja a rendszer által küldött e-maileket, így azok nem kerülnek ki éles címzettekhez.
- miserend: A fejlesztői image-t használja, és a helyi forráskódot (webapp) mountolja, így a kódváltozások azonnal látszanak a konténerben.
- Külön volume-ok a PHP vendor könyvtárnak és a node_modules-nek, hogy ezek ne keveredjenek a forráskóddal.

Ez a fájl akkor hasznos, ha aktívan fejlesztesz, és szeretnéd, hogy a kódmódosítások azonnal érvényesüljenek a futó alkalmazásban.

## compose.test.yml – Tesztkörnyezet
Ez a fájl a tesztelést támogatja, például CI/CD pipeline-okban:

- mailcatcher: Ugyanaz, mint a dev környezetben.
- miserend: Teszteléshez szükséges környezeti változókkal indul (pl. SMTP beállítások).

Ez a fájl akkor hasznos, ha automatizált teszteket futtatsz, és szükséged van egy gyorsan inicializálható, tiszta környezetre.

## Összefoglalás – Mikor melyiket használd?

- Fejlesztéshez:
```
docker compose -f docker/compose.yml -f docker/compose.dev.yml up
```

- Teszteléshez:

```
docker compose -f docker/compose.yml -f docker/compose.test.yml up
```

- Kibana hozzáadása:
Az előbbi parancsokhoz csak add hozzá a kibana compose fájlt az `up` elé:

```
  -f docker/compose.kibana.yml
```

- OSRM hozzáadása:
Az előbbiekhez csak a következő kapcsolót kell hozzáadni az `up` elé:

```
--profile osrm
```

A fájlokat egymásra rétegezve is használhatod, pl. fejlesztés közben Kibana-val:

## Fontosabb tudnivalók
A Compose fájlok egymásra épülnek, így csak a szükséges szolgáltatásokat adod hozzá a fő rendszerhez.

Az inicializáló konténerek (data-init, elastic-init, tmp-init) csak egyszer futnak le, és nem maradnak futva.

A volume-ok biztosítják, hogy az adatbázis, Elasticsearch és ideiglenes fájlok tartósak maradjanak konténer újraindítás után is.

A fejlesztői környezetben a forráskód módosításai azonnal látszanak, nem kell újraépíteni az image-et.
