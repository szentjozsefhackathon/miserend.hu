<?php
/**
 * Nemlétező metódusra mutató $this-> hívásokat keres.
 *
 * Miért kell: a #833-ban a `/templom/:id/edit` oldal minden jogosult
 * felhasználónak végzetes hibával elszállt, mert egy átalakítás egy metódus
 * FEJLÉCÉT elvitte, a TÖRZSÉT nem — a rá mutató hívás ottmaradt. A fájl
 * szintaktikailag ép maradt, tehát a `php -l` nem szólt; a metódusnév csak
 * futásidőben dől el, tehát statikus ellenőrzés sem fogta meg; a lap pedig
 * jogosultság nélkül el sem jut a hibás sorig, tehát próbálgatással sem derült
 * ki. Napokig törött volt élesben.
 *
 * Ez a szkript a valódi öröklődési láncot nézi (ReflectionClass), nem
 * metódusneveket kutat vaktában — így a vendorból örökölt Eloquent-metódusok
 * (`hasMany()`, `getAttribute()` és társaik) nem adnak hamis riasztást.
 *
 * Futtatás:  php scripts/check-undefined-methods.php [webapp-gyökér]
 * Kilépőkód: 0 ha tiszta, 1 ha talált valamit, 2 ha nincs vendor.
 *
 * A gyökér azért megadható, mert a CI a konténerbe másolva futtatja, ahol a
 * szkript nem a repóban lévő helyén van.
 */

define('PATH', rtrim($argv[1] ?? dirname(__DIR__) . '/webapp', '/') . '/');

if (!@include PATH . 'vendor/autoload.php') {
    fwrite(STDERR, "Nincs vendor/autoload.php — előbb `composer install`.\n");
    exit(2);
}

// Ez regisztrálja az osztály-autoloadert (classes/<kisbetűs\névtér>.php).
require_once PATH . 'functions.php';

/** A fájl útvonalából megmondja, milyen osztályt kellene tartalmaznia. */
function osztalynevUtvonalbol(string $utvonal): string {
    $relativ = substr($utvonal, strlen(PATH . 'classes/'));
    return str_replace('/', '\\', substr($relativ, 0, -4));  // .php le
}

/**
 * Kigyűjti a fájlból a `$this->nev(` alakú hívásokat.
 *
 * @return array<int, array{0:string,1:int}> [név, sor] párok
 */
function thisHivasok(array $tokenek): array {
    $talalatok = [];
    $n = count($tokenek);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokenek[$i];
        if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$this') {
            continue;
        }

        $j = $i + 1;
        while ($j < $n && is_array($tokenek[$j]) && $tokenek[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || !is_array($tokenek[$j]) || $tokenek[$j][0] !== T_OBJECT_OPERATOR) {
            continue;
        }

        $j++;
        while ($j < $n && is_array($tokenek[$j]) && $tokenek[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || !is_array($tokenek[$j]) || $tokenek[$j][0] !== T_STRING) {
            continue;   // $this->$dinamikus() vagy sima property
        }

        $nev = $tokenek[$j][1];

        $k = $j + 1;
        while ($k < $n && is_array($tokenek[$k]) && $tokenek[$k][0] === T_WHITESPACE) {
            $k++;
        }
        if ($k < $n && $tokenek[$k] === '(') {
            $talalatok[] = [$nev, $t[2]];
        }
    }

    return $talalatok;
}

$fajlok = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(PATH . 'classes', FilesystemIterator::SKIP_DOTS)
);

$hibak = 0;
$atnezett = 0;

foreach ($fajlok as $fajl) {
    if (!$fajl->isFile() || $fajl->getExtension() !== 'php') {
        continue;
    }

    $utvonal = $fajl->getPathname();
    $tartalom = file_get_contents($utvonal);
    $osztaly = osztalynevUtvonalbol($utvonal);

    if (!class_exists($osztaly) && !trait_exists($osztaly)) {
        continue;   // nem az autoloader névkonvenciója szerinti fájl
    }
    $atnezett++;

    $tukor = new ReflectionClass($osztaly);
    // A __call() bármit elnyel, ott nem tudunk nyilatkozni.
    if ($tukor->hasMethod('__call')) {
        continue;
    }

    foreach (thisHivasok(@token_get_all($tartalom)) as [$nev, $sor]) {
        if (method_exists($osztaly, $nev)) {
            continue;
        }
        // Szándékos, opcionális horog: `if (method_exists($this, 'x')) $this->x();`
        if (str_contains($tartalom, "method_exists(\$this, '$nev')")
            || str_contains($tartalom, "method_exists(\$this, \"$nev\")")) {
            continue;
        }

        fwrite(STDERR, "$utvonal:$sor  \$this->$nev()  — nincs ilyen metódus a(z) $osztaly osztályon\n");
        $hibak++;
    }
}

if ($hibak > 0) {
    fwrite(STDERR, "\n$hibak nemlétező metódusra mutató hívás. Ezek futásidőben végzetes hibát okoznak.\n");
    exit(1);
}

echo "Rendben — $atnezett osztály, nincs nemlétező metódusra mutató \$this-> hívás.\n";
exit(0);
