<?php

$autoloadPath = getenv('FUNCTIONAL_VENDOR_AUTOLOAD');

if (!$autoloadPath) {
    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
}

if (!is_file($autoloadPath)) {
    throw new RuntimeException('Functional test autoload file not found: ' . $autoloadPath);
}

require_once $autoloadPath;

/*
 * A böngészős tesztek közös őse. A PHPUnit a teszt-FÁJLOKAT tölti be közvetlenül, a
 * projektnek pedig nincs PSR-4 leképezése a `Tests\` névtérre (a composer.json
 * autoload/autoload-dev egyaránt üres), ezért az ősosztályt magunknak kell betölteni —
 * enélkül a legelső teszt „Class not found"-dal áll meg.
 */
require_once __DIR__ . '/Functional/FunctionalTestCase.php';