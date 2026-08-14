# Adatbázis-hozzáférés élesben (#750)

## Rövid válasz

- **Alapeset: nincs felület.** Amit lehet, `docker exec`-kel old meg.
- **Ha tényleg felület kell:** opt-in Adminer-profil, csak `127.0.0.1`-re kötve, SSH-tunnellel.

Nincs kézi fájlmásolgatás, és nincs állandóan kint lógó belépő.

## 1. Parancssor — ez az alapértelmezett

```bash
docker exec -it miserend-prod-mysql-1 mariadb -u root -p miserend
```

Szkriptelhető lekérdezéshez:

```bash
docker exec -i miserend-prod-mysql-1 mariadb -u root -p miserend < lekerdezes.sql
```

Nem nyit új portot, nem hagy nyomot, nincs mit elfelejteni letörölni.

## 2. Adminer, ha tényleg felület kell

```bash
docker compose -f docker/compose.yml -f docker/compose.adminer.yml \
  --profile adminer up adminer
```

Fontos részletek:

- **`--profile adminer` nélkül el sem indul.** A sima `docker compose up` nem hozza fel.
- **Nincs `-d`.** Ha kilépsz a shellből, a konténer leáll. Ha mégis bentmarad:
  `docker compose -f docker/compose.yml -f docker/compose.adminer.yml --profile adminer down adminer`
- **A port csak a loopbackre kötődik** (`127.0.0.1:8080`), tehát tűzfal-hiba mellett sem érhető el kívülről.

A saját géped felől SSH-tunnellel:

```bash
ssh -N -L 8080:127.0.0.1:8080 <user>@<szerver>
# utána: http://127.0.0.1:8080
```

Belépés: szerver `mysql`, felhasználó/jelszó a szerver `.env`-jéből
(`MYSQL_USER`/`MYSQL_PASSWORD`, vagy `root` + `MYSQL_ROOT_PASSWORD`).

## Amit NE csinálj

**Ne másold be az `adminer.php`-t a `webapp/` alá.** A docroot `Require all granted`,
tehát a fájl attól a pillanattól publikusan kiszolgálódik — bárki eléri a
`/adminer.php` címen, és az Adminer „server" mezője tetszőleges hosztra engedi
csatlakozni. Ez eddig így is volt: a fájl be volt commitolva, és bekerült az
image-be is.

Két réteg akadályozza meg, hogy visszacsússzon:

- `docker/miserend/apache/apache.conf`: a docrootban minden `adminer*.php` /
  `phpmyadmin*.php` tiltott, akkor is, ha valaki odamásolja.
- `.dockerignore`: ilyen fájl nem kerül be az image-be.
