<?php
/**
 * #895/#896: mennyire pontos a kategória-felismerés — TEMPLOMONKÉNT.
 *
 * borazslo a #895-ben: „bár jó átnézni, hogy most miből mennyi van, de ez nagyon nem
 * releváns adat. Inkább az, hogy olyan templomnál ahol már mindenféle van, ott melyikből
 * mennyi van. Hiszen ez egy új fícsör és tökre nincs feltöltve adattal."
 *
 * Igaza van: az összesített szám a MAI, alig feltöltött állapotról szól. Ez a szkript
 * ezért templomonként bont, és azokat veszi előre, ahol TÖBBFÉLE kategória is előfordul —
 * ott derül ki, hogy a felismerő tényleg szét tudja-e választani őket.
 *
 * CSAK OLVAS.
 *
 *   docker compose exec miserend php /miserend/webapp/tools/mass-category-audit.php
 *
 * Kapcsolók:
 *   --limit=N      hány templomot írjon ki (alapérték: 25)
 *   --church=ID    csak ez az egy templom, a teljes cím-listájával
 *   --unknown      csak a besorolatlan címeket listázza, templomonként
 */

require __DIR__ . '/../load.php';

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Balra igazított kitöltés MEGJELENÍTÉSI szélesség szerint.
 *
 * A printf `%-34s`-e bájtot számol, az ékezetes templomnevek pedig többájtosak — a
 * táblázat oszlopai enélkül elcsúsznak, pont azoknál a soroknál, amiket olvasni akarunk.
 */
function szelesRe(string $szoveg, int $szelesseg): string {
    $vagott = mb_strimwidth($szoveg, 0, $szelesseg, '…');

    return $vagott . str_repeat(' ', max(0, $szelesseg - mb_strwidth($vagott)));
}

$opciok  = getopt('', ['limit::', 'church::', 'unknown']);
$limit   = isset($opciok['limit']) ? max(1, (int) $opciok['limit']) : 25;
$csakEgy = isset($opciok['church']) ? (int) $opciok['church'] : null;
$csakIsmeretlen = isset($opciok['unknown']);

$md = new \MassDefinitions();
$kategoriak = array_column($md->categories(), 'key');

$query = DB::table('cal_masses')
    ->join('templomok', 'templomok.id', '=', 'cal_masses.church_id')
    ->select('cal_masses.church_id', 'cal_masses.title', 'templomok.nev')
    ->orderBy('cal_masses.church_id');

if ($csakEgy !== null) {
    $query->where('cal_masses.church_id', $csakEgy);
}

$templomok = [];
$osszes = 0;

foreach ($query->get() as $sor) {
    $tid = (int) $sor->church_id;
    $kategoria = $md->categoryForTitle((string) $sor->title) ?? 'NINCS';

    if (!isset($templomok[$tid])) {
        $templomok[$tid] = ['nev' => $sor->nev, 'db' => [], 'ismeretlen' => []];
    }

    $templomok[$tid]['db'][$kategoria] = ($templomok[$tid]['db'][$kategoria] ?? 0) + 1;
    if ($kategoria === 'NINCS') {
        $cim = (string) $sor->title;
        $templomok[$tid]['ismeretlen'][$cim] = ($templomok[$tid]['ismeretlen'][$cim] ?? 0) + 1;
    }
    $osszes++;
}

if ($templomok === []) {
    echo "Nincs egyetlen naptár-esemény sem.\n";
    exit(0);
}

/*
 * A rendezés a lényeg: elöl az a templom, ahol a LEGTÖBBFÉLE kategória van. Az összesített
 * darabszám félrevezet — egy 10 000 misés templom nem mond semmit a felismerő
 * pontosságáról, egy 200 eseményes, ötféle alkalmat tartó viszont igen.
 */
uasort($templomok, static function (array $a, array $b): int {
    $aFele = count(array_diff_key($a['db'], ['NINCS' => 1]));
    $bFele = count(array_diff_key($b['db'], ['NINCS' => 1]));

    return $bFele <=> $aFele ?: array_sum($b['db']) <=> array_sum($a['db']);
});

if ($csakIsmeretlen) {
    $db = 0;
    foreach ($templomok as $tid => $t) {
        if ($t['ismeretlen'] === []) {
            continue;
        }
        printf("#%d %s\n", $tid, $t['nev']);
        arsort($t['ismeretlen']);
        foreach ($t['ismeretlen'] as $cim => $n) {
            printf("   %3d x %s\n", $n, $cim);
            $db++;
        }
        echo "\n";
    }
    printf("Összesen %d különböző besorolatlan cím.\n", $db);
    exit($db > 0 ? 1 : 0);
}

$fejlec = array_merge($kategoriak, ['NINCS']);

printf("Naptár-esemény: %d, templom: %d\n\n", $osszes, count($templomok));
printf("%-6s %s", 'id', szelesRe('templom', 34));
foreach ($fejlec as $k) {
    printf("%12s", $k);
}
printf("%8s\n", 'félék');
echo str_repeat('-', 48 + 12 * count($fejlec) + 8), "\n";

$kiirt = 0;
$osszesites = [];

foreach ($templomok as $tid => $t) {
    foreach ($t['db'] as $k => $n) {
        $osszesites[$k] = ($osszesites[$k] ?? 0) + $n;
    }

    if ($kiirt++ >= $limit) {
        continue;
    }

    printf("%-6d %s", $tid, szelesRe((string) $t['nev'], 34));
    foreach ($fejlec as $k) {
        printf("%12s", $t['db'][$k] ?? '·');
    }
    printf("%8d\n", count(array_diff_key($t['db'], ['NINCS' => 1])));
}

if (count($templomok) > $limit) {
    printf("\n… és még %d templom (--limit=N a többiért).\n", count($templomok) - $limit);
}

printf("\nÖsszesen:\n");
foreach ($fejlec as $k) {
    $n = $osszesites[$k] ?? 0;
    printf("  %-12s %6d (%.1f%%)\n", $k, $n, $osszes > 0 ? 100 * $n / $osszes : 0);
}

$ismeretlenTemplomok = array_filter($templomok, static fn(array $t): bool => $t['ismeretlen'] !== []);
if ($ismeretlenTemplomok !== []) {
    printf("\n%d templomnál van besorolatlan cím. A listájukhoz: --unknown\n", count($ismeretlenTemplomok));
}
