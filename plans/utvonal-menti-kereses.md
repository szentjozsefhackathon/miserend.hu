# Útvonal menti misekeresés — terv és erőforrásbecslés

> Kapcsolódó: #608 (Környező, útba eső templomok miséi), #636 (közeli misék keresése idő és távolság alapján).
>
> borazslo kérdése a #636-on: *„Az útvonal menti kereséshez lehet valami külön tervet ill. számítást
> készíteni hogy az mekkora erőforrást / limitet jelentene? Lehet hogy akár beruháznánk rá, de még
> ötletem sincs mekkora az összeg."*
>
> **Rövid válasz: nulla forint, ha saját OSRM-et teszünk a compose-ba. Mért adat: 389 MB RAM,
> 10–20 ms/útvonal, havi ~27 másodperc újraépítés, ~0,9 GB lemez. Fizetős szolgáltatónál 1000
> keresés/nap még belefér a díjmentes keretbe, 5000 keresés/nap már 100–700 USD/hó.**
>
> A dokumentum minden „mért" jelölésű száma ezen a gépen, a jelenlegi adatokon készült
> (mass_index: 1 528 968 dokumentum, 5050 engedélyezett templom). Ami becslés, az becslésként van jelölve.

## 1. Mit csinálna a funkció

A felhasználó megad egy honnan–hovát (és egy időablakot), mi pedig kilistázzuk az útvonal
mentén, egy megadott sávon belül eső miséket, indulási időhöz igazítva.

Adatfolyam egyetlen keresésre:

```
1. honnan/hova szöveg  ──► geokódolás (Nominatim, MÁR MEGVAN)          2 külső hívás
2. két koordináta      ──► routing: útvonal-geometria                  1 külső hívás
3. útvonal-geometria   ──► ritkítás N mintapontra                      helyben, PHP
4. N mintapont         ──► Elasticsearch: folyosó-szűrés + időablak    1 belső lekérdezés
```

## 2. A kulcsszám: keresésenként EGY routing-hívás

Ez az egész számítás lényege, és ez az, ami miatt a funkció olcsó.

Kézenfekvő lenne azt hinni, hogy templomonként kell útvonalat számolni (5050 templom × N keresés
— az tényleg megfizethetetlen volna). **Nem kell.** A routing szolgáltatótól egyetlen dolgot kérünk:
az A→B útvonal geometriáját. Onnantól a „mely templomok esnek az útvonal mellé" kérdés tisztán
geometria, amit az Elasticsearch a saját indexéből válaszol meg, külső hívás nélkül.

Tehát: **routing-hívás/nap = útvonal-keresés/nap.** Nem szorzódik se a templomok, se a misék számával.

(A mai `\Distance` cron templom–templom közúti távolságot számol Mapquesttel; az egy másik,
tőle független felhasználás, ezt nem érinti.)

## 3. Elasticsearch-oldal — mért számok

A folyosó-szűrés úgy készül, hogy az útvonal geometriáját ritkítjuk (pl. 5 km-enként egy pont),
és a pontokra `geo_distance` feltételeket teszünk `bool.should`-ba. A sáv szélessége = a
`geo_distance` sugara.

Mért helyzet: a valós OSRM-útvonal Sopron→Debrecen **5159 pontból** áll — ezt kötelező ritkítani,
nyersen nem mehet lekérdezésbe. 5 km-es ritkítás mellett 76 pont marad.

Mérés a jelenlegi indexen (1 528 968 dokumentum, 7 futás mediánja, egyhetes időablakkal):

| Lekérdezés | Ma (runtime geo_point) | Indexelt `geo_point`-tal |
|---|---:|---:|
| Bp→Szeged (162 km, 33 pont, 10 km sáv) | 56 ms | **17 ms** |
| ugyanaz + bounding box előszűrő | 19 ms | **11 ms** |
| Sopron→Debrecen (378 km, 76 pont, 10 km sáv) | 147 ms | **36 ms** |
| ugyanaz + bounding box előszűrő | 113 ms | **35 ms** |
| *referencia:* mai pontszerű közeli keresés (1 pont, 20 km) | 3 ms | – |
| *referencia:* csak időablak, geo nélkül | 0 ms | – |

A ritkítás finomsága lineárisan viszi az árat (mért, runtime mezővel, Bp→Szeged):
10 km/17 pont → 28 ms, 5 km/33 pont → 53 ms, 2,5 km/65 pont → 123 ms.

**Két olcsó, nagy hatású tanulság:**

1. **Bounding box előszűrő.** Egy `geo_bounding_box` az útvonal befoglaló téglalapjára, a
   `should`-lista ELÉ téve, a tipikus lekérdezést 56 ms-ról 19 ms-ra viszi. Ingyen van, egy
   feltétel az egész.
2. **Indexelt `geo_point`.** A mai közeli keresés runtime mezőből állítja elő a koordinátát
   (`church.lat`/`church.lon` float-okból, dokumentumonként script). Egy pontra ez még olcsó
   (3 ms), 76 pontra már nem: 147 ms. Ha a `mass_index` mappingjébe bekerül egy valódi
   `church.location` geo_point, a legrosszabb eset 147 ms-ról **36 ms-ra** esik.

Az indexelt geo_point ára (mért):

| Tétel | Mért érték |
|---|---|
| Egyszeri újraindexelés | **130 s** (1 528 968 dokumentum, 0 hiba) |
| Index mérete utána | 1,0 GB (a mai 1,2 GB helyett — force merge után **nem nő**) |
| Folyamatos többletköltség | nincs; a `updateMasses` cron úgyis kiírja |

Vagyis ez nem „beruházás", hanem egy mapping-sor plusz egy újraindexelés — és mellékesen a **mai**
közeli keresést is gyorsítja.

## 4. Routing-szolgáltató: mibe kerül

Napi *r* útvonal-keresés = havi ~30×*r* directions-hívás.

| Megoldás | Díjmentes keret | Utána | 1000 keresés/nap (30 e/hó) | 5000 keresés/nap (150 e/hó) |
|---|---|---|---:|---:|
| **Saját OSRM** (compose-ban) | korlátlan | – | **0 USD** | **0 USD** |
| openrouteservice (HeiGIT) | ~2000 directions/nap ([FAQ](https://giscience.github.io/openrouteservice/frequently-asked-questions.html)) | fizetős csomag, ár nem publikus | 0 USD (épphogy belefér) | nem fér bele |
| Mapbox Directions | 100 000 hívás/hó | 2,00 USD / 1000 | 0 USD | ~100 USD/hó |
| Google Directions | 10 000 esemény/hó (Essentials) | 5,00 USD / 1000 | ~100 USD/hó | ~700 USD/hó |

Megjegyzések:
- Az ORS napi keretére két, egymásnak ellentmondó szám kering (2000/nap az official FAQ-ban,
  2500/nap + 40 000/hó másodlagos forrásokban). Regisztrációkor a dashboardon ellenőrizendő.
  A fizetős ORS-csomagok ára nem publikus, ajánlatot kell kérni.
- A Google 2025 márciusától megszüntette a havi 200 USD kreditet, helyette SKU-nkénti díjmentes
  keret van; a Directions „legacy" besorolású.
- **Nincs éles forgalmi adatunk**, amiből az *r*-t meg tudnám mondani — a `stats_externalapi`
  tábla itt csak a fejlesztői gép pár napját tartalmazza. Ezért adtam két forgatókönyvet:
  a döntéshez ezt a számot kell megsaccolni (vagy egy hónapig mérni).

## 5. Saját OSRM — mért erőforrás

Magyarországra, natív arm64 image-dzsel (nem emulált), a hivatalos
`ghcr.io/project-osrm/osrm-backend` konténerrel, `car` profillal:

| Lépés | Idő | Csúcs-RAM |
|---|---:|---:|
| `osrm-extract` | 21,2 s | 1,89 GB |
| `osrm-partition` | 4,3 s | 0,97 GB |
| `osrm-customize` | 1,6 s | 0,48 GB |
| **Teljes újraépítés** | **~27 s** | **~1,9 GB** |

Futás közben (`osrm-routed --algorithm mld`):

| Tétel | Mért érték |
|---|---|
| Memória nyugalomban | **389 MB** |
| Útvonal-válaszidő | **10–20 ms** (Bp→Szeged 172,8 km; Sopron→Debrecen 448,6 km) |
| Lemez: `hungary-latest.osm.pbf` | 306 MB |
| Lemez: felépített gráf | 588 MB |

Tehát a teljes költség: **~400 MB RAM + ~0,9 GB lemez + havi fél perc CPU** a meglévő gépen.
Új szerver nem kell — ugyanabba a compose-ba fér, mint az Elasticsearch. Az adat frissen tartása
egy havi cron: PBF letöltés + a három lépés + konténer újraindítás.

Kockázat, amivel számolni kell: az OSRM-et nekünk kell felügyelni (memória, újraépítés, upgrade),
és a `docs/outgoing-connections.md`-be bekerül egy új kimenő cél (`download.geofabrik.de:443`)
a havi frissítéshez.

## 6. Javaslat

1. **Saját OSRM a compose-ba.** A mért 389 MB / 10–20 ms mellett nincs értelme szolgáltatót
   fizetni, és a napi limit kérdése egyszerűen megszűnik. Ez a válasz a „mekkora az összeg"-re: 0.
   Ha ez üzemeltetési okból nem járható, a Mapbox a következő legjobb (100 e/hó díjmentes,
   utána 2 USD/1000).
2. **Indexelt `church.location` geo_point** a `mass_index`-be. 130 s egyszeri újraindexelés,
   nem nő az index, cserébe 4× gyorsabb folyosó-lekérdezés — és a mai közeli keresés is gyorsul.
3. **Bounding box előszűrő** minden folyosó-lekérdezésbe.
4. **Ritkítás 5 km-re, 10 km-es alapsáv**, felhasználó által állítható 5–20 km között.
   Az 5159 pontos nyers geometria sosem mehet be lekérdezésbe.
5. **Az útvonal-geometriát cache-eljük** (honnan+hova kerekítve → geometria). Ugyanazokat a
   nagyvárosi párokat sokan keresik; ez a routing-hívások számát a fizetős ágon is levinné.

## 7. Amit el kell dönteni

- Hány útvonal-keresést várunk naponta? (Ez az egyetlen szám, ami a fizetős ágon számít.)
- Vállaljuk-e egy saját OSRM üzemeltetését, vagy inkább fizetnénk a nyugalomért?
- Csak autós profil kell, vagy gyalogos/kerékpáros is? (Profilonként külön gráf: +588 MB lemez,
  a RAM nagyjából duplázódik profilonként.)
- Az indulási időt hogyan vetítjük az útvonalra: az egész sávra egy időablak, vagy az útvonal
  menti haladás szerint csúszó ablak (utóbbi szebb, de a szakaszonkénti menetidő kell hozzá —
  az OSRM úgyis adja).
