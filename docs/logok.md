# Hol vannak a logok?

Rövid válasz: **minden a `docker logs`-ban van**, az alkalmazás nem ír külön logfájlt.

A `php:8.4-apache` image az Apache naplóit a konténer kimenetére szimlinkeli:

| fájl a konténerben | hova megy |
|---|---|
| `/var/log/apache2/access.log` | stdout |
| `/var/log/apache2/error.log` | stderr |

A PHP-nek nincs saját `error_log` beállítása, ezért az is az Apache error logjába, vagyis a stderr-re megy. A `docker logs` mindkettőt mutatja.

## A leggyakoribb parancsok

```bash
# Élesben (a konténer neve környezetenként más, ellenőrizd: docker ps)
docker logs -f miserend-prod-miserend-1

# Csak az alkalmazás hibái, az access-log zaja nélkül
docker logs miserend-prod-miserend-1 2>&1 | grep '\[miserend\]'

# Az utolsó óra
docker logs --since 1h miserend-prod-miserend-1 2>&1 | grep '\[miserend\]'

# Egy konkrét oldal hibái
docker logs miserend-prod-miserend-1 2>&1 | grep 'URI: /templom/5444/edit'

# Élő követés, amíg reprodukálod a hibát egy másik ablakban
docker logs -f --tail 0 miserend-prod-miserend-1 2>&1 | grep '\[miserend\]'

# Az 500-as válaszok az access-logból
docker logs miserend-prod-miserend-1 2>&1 | grep '" 500 '

# Cron-futások
docker logs miserend-prod-miserend-1 2>&1 | grep '\[cron\]'
```

Helyben ugyanez, csak `miserend-miserend-1` néven.

## Hogyan néz ki egy alkalmazás-hiba

Minden alkalmazás-hiba `[miserend]` előtaggal megy ki, két sorban — a második a stack trace:

```
[miserend] Render failed: TypeError: Cannot access offset of type string @ /miserend/webapp/classes/eloquent/church.php:1355 | URI: /templom/5444/edit
[miserend] trace: #0 /miserend/webapp/index.php(61): Html\Church\Edit->render() …
```

Az előtag utáni szó a hiba helyét jelzi:

| előtag | mikor |
|---|---|
| `Unhandled exception` | az oldal felépítése közben |
| `Render failed` | a Twig-renderelés közben (a lusta Eloquent-accessorok itt futnak) |
| `Uncaught` | bárhol máshol, elkapatlanul |
| `Fatal error` | memória- vagy időtúllépés, parse error — ezek nem dobnak kivételt |

## Amit tudni érdemes (#725)

Ez a naplózás **2026 augusztusa előtt nem működött**. Három dolog volt együtt:

1. Élesben `error_reporting(0)` futott (`config.php` `default` ág, amit a `production` örököl). Ez nem csak a kijelzést kapcsolja ki, hanem a **naplózást is** — a PHP saját `Fatal error: Uncaught …` üzenete sehova nem került ki.
2. Az `index.php` csak `\Exception`-t fogott. A PHP 8-as `\Error` és `TypeError` nem `\Exception`, tehát átment rajta.
3. A `render()` a `try`/`catch`-en kívül volt — márpedig a Twig-sablonok a lusta Eloquent-accessorokat (`church.location`, `church.holders`) csak ott hívják meg.

Együtt ez azt jelentette, hogy egy hibás oldalról **pontosan annyi látszott, amennyit a jegy panaszolt**:

```
"GET /templom/5446/edit HTTP/1.1" 500 236
```

…és semmi több. Mindhármat javítottuk.

## Mit NEM naplózunk

A warning és a notice szint élesben szándékosan kimarad (`error_reporting` maszk), hogy a napló ne fulladjon zajba. Ha egy hibakereséshez kellenek, `.env`-ben át lehet állítani a környezetet `staging`-re, vagy ideiglenesen bővíteni a maszkot a `config.php`-ban.

A napló **nem tartós**: alapértelmezett `json-file` driverrel megy, és `docker compose down` után elvész. Ha egy ritka hibát kell elkapni, érdemes a fenti `docker logs -f … | grep` sort egy fájlba irányítani.

## Kapcsolódó

- [`/health`](https://miserend.hu/index.php?q=health) — cron-elakadások, ES-index frissesség, séma-eltérések (belépés kell hozzá)
- `docs/outgoing-connections.md` — kimenő hálózati kapcsolatok
