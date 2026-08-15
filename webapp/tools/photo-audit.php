<?php
/**
 * #709: a képkönyvtárban lévő NEM-kép fájlok felderítése.
 *
 * A feltöltés hibája miatt kép helyett futtatható fájl is bekerülhetett a
 * kepek/ alá. A kiszolgálást a webapp/kepek/.htaccess már blokkolja, de a
 * fájlokat meg is kell találni és el kell takarítani.
 *
 * Ez a szkript CSAK OLVAS — nem töröl semmit. A törléshez a végén kiírt
 * parancsokat kell kézzel lefuttatni, miután átnézted a listát.
 *
 *   docker compose exec miserend php /miserend/webapp/tools/photo-audit.php
 *
 * Kilépési kód: 0 ha tiszta, 1 ha talált gyanúsat — így cronból is figyelhető.
 */

require __DIR__ . '/../load.php';

use Illuminate\Database\Capsule\Manager as DB;

/* Amit képként kiszolgálunk. Egyeznie kell a kepek/.htaccess fehérlistájával. */
const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];

$photo    = new \Eloquent\Photo();
$photosDir = $photo->pathToPhotos;

if (!is_dir($photosDir)) {
    fwrite(STDERR, "Nincs meg a képkönyvtár: $photosDir\n");
    exit(2);
}

printf("Képkönyvtár: %s\n\n", $photosDir);

$suspicious = [];
$checked = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($photosDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;

    $path = $file->getPathname();
    $name = $file->getFilename();

    // A rejtett fájlok (.htaccess, .gitignore) nem képek, de nem is gyanúsak:
    // a kiszolgálásukat az Apache eleve tiltja, és a repóhoz tartoznak.
    if (str_starts_with($name, '.')) continue;

    $checked++;

    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $reasons = [];

    if ($extension === '' || !in_array($extension, ALLOWED_EXTENSIONS, true)) {
        $reasons[] = 'nem kép-kiterjesztés (' . ($extension === '' ? 'nincs' : $extension) . ')';
    }

    // A tartalom is számít: a kiterjesztés hazudhat.
    if (@getimagesize($path) === false) {
        $reasons[] = 'a tartalma nem olvasható képként';
    }

    // PHP-nyitótag bárhol a fájlban — polyglot vagy elrejtett kód jele.
    $head = (string) @file_get_contents($path, false, null, 0, 4096);
    if (stripos($head, '<?php') !== false || stripos($head, '<?=') !== false) {
        $reasons[] = 'PHP-nyitótagot tartalmaz';
    }

    if ($reasons) {
        $suspicious[] = ['path' => $path, 'reasons' => $reasons];
    }
}

printf("Ellenőrzött fájl: %d\n", $checked);
printf("Gyanús:           %d\n\n", count($suspicious));

foreach ($suspicious as $item) {
    printf("  %s\n      -> %s\n", $item['path'], implode('; ', $item['reasons']));
}

/*
 * Az adatbázisban is nézzük meg: lehet olyan sor, aminek a fájlneve eleve
 * futtatható kiterjesztésű, akkor is, ha a fájl már nincs meg.
 */
$badRows = DB::table('photos')
    ->whereRaw('LOWER(filename) NOT REGEXP "\\\\.(' . implode('|', ALLOWED_EXTENSIONS) . ')$"')
    ->select('id', 'church_id', 'filename', 'created_at')
    ->get();

if (count($badRows)) {
    printf("\nAdatbázis-sorok nem kép-kiterjesztéssel: %d\n", count($badRows));
    foreach ($badRows as $row) {
        printf("  photos.id=%-8s templom=%-8s %-28s %s\n",
            $row->id, $row->church_id, $row->filename, $row->created_at ?? '-');
    }
    printf("\nTörlés (ELŐBB NÉZD ÁT a listát):\n");
    printf("  DELETE FROM photos WHERE id IN (%s);\n",
        implode(',', array_map(fn($r) => $r->id, iterator_to_array($badRows))));
}

exit($suspicious || count($badRows) ? 1 : 0);
