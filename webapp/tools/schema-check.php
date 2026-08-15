<?php
/**
 * #706: a futó adatbázis szerkezetének összevetése a referenciával.
 *
 * Ugyanazt mutatja, mint a /health „Adatbázis-struktúra" pontja, csak CLI-ból —
 * így éles szerveren belépés nélkül is lefuttatható:
 *
 *   docker compose exec miserend php /miserend/webapp/tools/schema-check.php
 *
 * Egy másik adatbázis is vizsgálható (pl. egy oda betöltött dump):
 *
 *   php /miserend/webapp/tools/schema-check.php prodschema
 *
 * Kilépési kód: 0 ha nincs danger-szintű eltérés, 1 ha van — így CI-ból is hívható.
 */

require __DIR__ . '/../load.php';

$result = \SchemaCheck::check($argv[1] ?? null);

if (!$result['available']) {
    fwrite(STDERR, "Nem futtatható: " . $result['reason'] . "\n");
    exit(2);
}

$counts = $result['counts'];

printf("Adatbázis: %s\n", $result['schema']);
printf("Eltérés: %d hiba, %d figyelmeztetés, %d megjegyzés\n\n",
    $counts[\SchemaCheck::DANGER], $counts[\SchemaCheck::WARNING], $counts[\SchemaCheck::INFO]);

if (!$result['findings']) {
    echo "A szerkezet megegyezik a referenciával.\n";
    exit(0);
}

$label = [
    \SchemaCheck::DANGER  => 'HIBA ',
    \SchemaCheck::WARNING => 'FIGY ',
    \SchemaCheck::INFO    => 'megj ',
];

$currentTable = null;
foreach ($result['findings'] as $finding) {
    if ($finding['table'] !== $currentTable) {
        $currentTable = $finding['table'];
        printf("\n%s\n", $currentTable);
    }
    printf("  %s %s\n", $label[$finding['severity']] ?? '     ', $finding['message']);
}

echo "\n";

exit($counts[\SchemaCheck::DANGER] > 0 ? 1 : 0);
