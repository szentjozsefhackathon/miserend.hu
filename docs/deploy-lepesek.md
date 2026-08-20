# Deploy-lépések

A `docker/mysql/migrations/` alatt hat SQL-fájl áll, és eddig **semmi nem mondta meg,
melyik futott már le**. Ez élesben veszélyes: aki deployol, vagy kihagy egy szükséges
lépést (és valami némán elromlik), vagy újrafuttat egy megtörténtet (és nem tudja, hogy
az baj-e).

Ez a jegyzet ezt pótolja. **Minden lépéshez tartozik egy ellenőrző lekérdezés**, ami
megmondja, kell-e még — így nem kell emlékezni arra, mi történt korábban.

A migrációk **nem futnak le maguktól**. Az `docker/mysql/initdb.d/` csak ÚJ adatbázisnál
fut (fejlesztői környezet); az éles adatbázis a `migrations/` fájlokat kézzel kapja meg.

## Hogyan használd

```bash
# ellenőrzés
docker exec -i mysql mysql -u root -p miserend < ellenorzo.sql

# futtatás
docker exec -i mysql mysql -u root -p miserend < docker/mysql/migrations/<fájl>.sql
```

A cronok viszont **maguktól** szinkronizálódnak: a `\Eloquent\Cron::init()` minden
cron-futásnál felveszi a `webapp/fajlok/crons.php`-ból hiányzó sorokat, és a
`pruneRemoved()` kitakarítja a onnan kivetteket (#638). Új cronhoz tehát nincs teendő —
legfeljebb megvárni a következő futást, vagy meglökni: `/index.php?q=cron&cron_init=1`.

---

## Séma-migrációk

### `174-b-nullable-dates.sql`

Dátum-oszlopokat enged NULL-ra (`boundaries.created_at`, `church_holders.updated_at`,
és társaik). A régi séma `0000-00-00`-t tárolt, amit a mai MySQL nem fogad el.

**Kell-e még?**
```sql
SELECT IS_NULLABLE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA='miserend' AND TABLE_NAME='boundaries' AND COLUMN_NAME='created_at';
-- 'NO'  -> még kell
-- 'YES' -> megvolt
```

### `javaslat-kezeloje.sql`

Két oszlop a javaslat-csomagokra: ki és mikor bírálta el. Enélkül az adminfelület
„vendég"-et mutat kezelőként.

**Kell-e még?**
```sql
SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA='miserend' AND TABLE_NAME='cal_suggestion_packages'
   AND COLUMN_NAME='handled_by_user_id';
-- 0 -> még kell
```

### `706-sema-elteresek.sql`

Eldobja a megszűnt `misek` táblát, és pár oszlop-definíciót a séma-referenciához igazít.
**Adatot dob el** (`misek`) — előtte érdemes meggyőződni róla, hogy tényleg nem használjuk.

**Kell-e még?**
```sql
SELECT COUNT(*) FROM information_schema.TABLES
 WHERE TABLE_SCHEMA='miserend' AND TABLE_NAME='misek';
-- 1 -> még kell
```

### `431-alkalom-sajat-helyszin.sql`

Három oszlop a `cal_masses`-re: az alkalom saját helyszíne, ha nem a templomban van
(#431). A #813-mal együtt kell kimennie — **enélkül a naptár mentése elszáll**.

**Kell-e még?**
```sql
SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA='miserend' AND TABLE_NAME='cal_masses' AND COLUMN_NAME='location_lat';
-- 0 -> még kell
```

### `748-distances-normalizalas.sql`

A `distances` tábla oda-vissza duplikátumait szedi ki. Nem sürgős, csak helyet és
számolást spórol.

**Kell-e még?**
```sql
SELECT COUNT(*) FROM distances d JOIN distances m
  ON m.fromLat=d.toLat AND m.fromLon=d.toLon
 AND m.toLat=d.fromLat AND m.toLon=d.fromLon;
-- >0 -> van még duplikátum
```

### `496-497-498-oszlopok-eldobasa.sql` — **ezt olvasd el, mielőtt futtatod**

Eldobja a `templomok.orszag`, `.megye`, `.varos` oszlopokat és az `orszagok`, `megye`,
`varosok` táblákat. A helynevek ezután kizárólag az OSM-határokból jönnek.

**Visszafordíthatatlan.** Előtte a fájl átmenti a koordináta nélküli templomok helyadatát
a `megjegyzes` mezőbe — az az egyetlen hely, ahol utána megmarad.

**Előfeltétel:** minden fogyasztónak a származtatott neveken kell mennie. A kódban ez kész
(#805 óta), de a **határ-adatnak is késznek kell lennie**: ha egy templomnak nincs
boundary-ja, a neve a drop után üres lesz.

```sql
-- Hány aktív templomnak NINCS közigazgatási határa? Ennyien veszítik el a helynevüket.
SELECT COUNT(*) FROM templomok t
 WHERE t.ok='i' AND NOT EXISTS (
   SELECT 1 FROM lookup_boundary_church l
     JOIN boundaries b ON b.id=l.boundary_id AND b.boundary='administrative'
    WHERE l.church_id=t.id);
```

A /health `Van közigazgatási határa` sora ugyanezt mutatja, felületről (#827).
**Ne a fölötte lévő sort nézd**: az bármilyen határt beszámít — az OSM sokféle határt ad
(`postal_code`, `judicial`, `wine_community`), a `religious_administration` határokat
pedig mi hozzuk létre minden templomhoz. Emiatt ott 100% állhat úgy is, hogy közben
templomok százainak nincs közigazgatási határa.

> A **fejlesztői** adatbázisban ez a szám ijesztően alacsony (nálam 6 az 5035-ből),
> mert a seed nem tartalmazza a határ-szinkron eredményét, és az Overpass-lekérdezés
> nem futott le. Ezt a lépést **csak élesen** van értelme megítélni.

**Kell-e még?**
```sql
SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA='miserend' AND TABLE_NAME='templomok' AND COLUMN_NAME='varos';
-- 1 -> még nem futott le
```

---

## Nem SQL, de deploy után kell

### Séma-referencia újragenerálása

A `webapp/schema-reference.json` a `docker/mysql/initdb.d` pillanatképe. Ha az változik és
ez nem, a /health „a referencia elavult"-at mond, és az összevetés eredménye
**se pozitív, se negatív irányban nem megbízható**.

**FRISSEN ÉPÍTETT konténerben** kell futtatni, különben az ujjlenyomat megint nem egyezik
(az a régi image initdb.d-jéből készül):

```bash
docker exec <konténer> php /miserend/webapp/tools/schema-reference.php
```

### `157-mise-kulso-naptar.sql`

A `cal_masses` új oszlopot kap (`external_calendar_id`), és a meglévő importált misék
visszamenőleg megkapják a templomuk naptárát.

Eddig a `comment` mező pontos értéke jelölte az importált misét. Ez foglalta a mezőt (a
naptár leírása nem fért bele), és templomonként EGY naptárra korlátozott: az import a
templom összes jelölt miséjét törölte, tehát a második naptár kitörölte az elsőét.

A visszamenőleges hozzárendelés csak ott fut, ahol a templomnak pontosan egy naptára van.
Ahol több, ott a régi sorokat a `comment`-es tartalék jelölés viszi tovább az első
importig — nem vesznek el, és nem is válnak szerkeszthetővé.

**Kell-e még?**
```sql
SELECT COUNT(*) AS hianyzik
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'cal_masses'
  AND COLUMN_NAME = 'external_calendar_id';
-- 0 → kell futtatni
```

Adatvesztés nincs: csak hozzáad. A futtatás után az első naptár-szinkron a leírást és a
helyszínt is átveszi, tehát a meglévő importált misék a következő cron-futáskor
gazdagodnak — külön teendő nélkül.

---

### `LORAWAN_TOKEN`

A szenzoros végpont (gyóntatás-érzékelő) enélkül nem fogad adatot. Környezeti változó,
nem migráció.

### Elasticsearch

Ezekhez **nincs teendő**: a `reindexMissingMasses` és — a #826 óta — a
`reindexChurchesWithoutLocation` cron magától pótolja a kimaradt dokumentumokat.

Teljes újraépítés csak akkor kell, ha a **mapping** változik:

```
/index.php?q=cron&cron_id=38   # templomok
/index.php?q=cron&cron_id=39   # misék — 30+ perc, 500e+ esemény
```

Vigyázat: a „templomok `location` nélkül" szám **nem** feltétlenül újraindexelést kíván.
Akinek nincs koordinátája az adatbázisban, azon az újraindexelés nem segít — a /health
a #826 óta külön is megmondja, melyikből mennyi van.

### Határok újraszinkronja

Ha a határ-logika változik (admin_level-kezelés, ISO-kód), a meglévő kapcsolatok
elavulnak. Ilyenkor a sorbaállítás:

```sql
UPDATE templomok SET boundaries_checked_at = NULL;
```

A `\OSM::checkBoundaries()` cron ezután fokozatosan újrakérdezi őket az Overpass-tól.
**Nem gyors**: több ezer templom, ütemezetten. Csak akkor csináld, ha tényleg változott a
logika — egyébként fölöslegesen terheli az Overpass-t.
