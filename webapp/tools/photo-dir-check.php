<?php
/**
 * #893: mely templomok képkönyvtárába nem tudunk írni?
 *
 * Egy gondnok azt jelezte, hogy nem tud képet feltölteni („Upload directory is not
 * writable"). A `webapp/kepek` az egyetlen hoszt-bind mount, a konténer pedig
 * `www-data`-ként fut: a jogosultság a hoszton dől el, és a kód nem tudja megjavítani.
 * Ez a szkript megmondja, HÁNY templomot érint — mert az dönti el, hogy egyetlen
 * elrontott könyvtárról van szó, vagy az egész, dockerizálás előttről maradt képfáról.
 *
 * Két bajt keres:
 *   - a templom könyvtára létezik, de nem írható  -> a feltöltés hibával elszáll;
 *   - hiányzik a `kicsi/` alkönyvtár              -> a feltöltés SIKERT jelent,
 *     de a bélyegkép némán elmarad (l. #893).
 *
 * CSAK OLVAS. A javítás a végén kiírt parancs, amit kézzel kell lefuttatni.
 *
 *   docker compose exec miserend php /miserend/webapp/tools/photo-dir-check.php
 *
 * Kilépési kód: 0 ha minden rendben, 1 ha talált hibát — így cronból is figyelhető.
 */

require __DIR__ . '/../load.php';

$photo     = new \Eloquent\Photo();
$photosDir = $photo->pathToPhotos;

if (!is_dir($photosDir)) {
    fwrite(STDERR, "Nincs meg a képkönyvtár: $photosDir\n");
    exit(2);
}

$uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
$gid = function_exists('posix_getegid') ? posix_getegid() : null;

printf("Képkönyvtár: %s\n", $photosDir);
printf("Ez a folyamat: uid=%s gid=%s\n", $uid ?? '?', $gid ?? '?');
printf("A szülő írható: %s\n\n", is_writable($photosDir) ? 'igen' : 'NEM');

$nemIrhato   = [];
$nincsKicsi  = [];
$osszes      = 0;

foreach (new DirectoryIterator($photosDir) as $bejegyzes) {
    if ($bejegyzes->isDot() || !$bejegyzes->isDir()) {
        continue;
    }

    $osszes++;
    $ut    = $bejegyzes->getPathname();
    $kicsi = $ut . '/kicsi';

    $allapot = sprintf(
        '%s (tulajdonos %d:%d, mód %s)',
        $bejegyzes->getFilename(),
        fileowner($ut),
        filegroup($ut),
        substr(sprintf('%o', fileperms($ut)), -4)
    );

    if (!is_writable($ut)) {
        $nemIrhato[] = $allapot;
        continue; // ha a szülő sem írható, a `kicsi/` hiánya már nem külön hír
    }

    if (!is_dir($kicsi)) {
        $nincsKicsi[] = $allapot;
    } elseif (!is_writable($kicsi)) {
        $nemIrhato[] = $allapot . ' -> kicsi/';
    }
}

printf("Átnézett templom-könyvtár: %d\n\n", $osszes);

if ($nemIrhato) {
    printf("NEM ÍRHATÓ (%d) — ezeknél a feltöltés hibával elszáll:\n", count($nemIrhato));
    foreach ($nemIrhato as $sor) {
        printf("  %s\n", $sor);
    }
    printf("\n");
}

if ($nincsKicsi) {
    printf("HIÁNYZIK A kicsi/ (%d) — ezeknél a feltöltés sikert jelent, de bélyegkép nélkül:\n", count($nincsKicsi));
    foreach ($nincsKicsi as $sor) {
        printf("  %s\n", $sor);
    }
    printf("\n");
}

if (!$nemIrhato && !$nincsKicsi) {
    printf("Minden könyvtár írható, és mindegyikben van kicsi/.\n");
    exit(0);
}

printf(<<<SZOVEG
Javítás a HOSZTON (nem a konténerben — ott nincs jogunk a chown-hoz), a
docker-compose.yml könyvtárából:

    sudo chown -R %s:%s ../webapp/kepek
    sudo find ../webapp/kepek -type d -exec chmod 2775 {} +

A `2775` a setgid: az ezután keletkező alkönyvtárak is a jó csoportot öröklik.
A hiányzó `kicsi/` alkönyvtárakat a feltöltés a #893 után magától létrehozza,
kézzel nem kell.

SZOVEG, $uid ?? 33, $gid ?? 33);

exit(1);
