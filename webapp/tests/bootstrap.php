<?php

define('PATH', dirname(__DIR__) . '/');

// Load Composer autoloader
if (!@include PATH . 'vendor/autoload.php') {
    die('Composer dependencies not found. Run: composer install');
}

require_once PATH . 'functions.php';
require_once PATH . 'twig_extras.php';

// #873: az űrlapokat beküldő integrációs teszteknek CSRF-tokent kell szerezniük,
// mint a böngészőnek. A közös ügyfél nem a `classes/` alatt van, tehát az app
// autoloadere nem találja meg — itt töltjük be egyszer.
require_once __DIR__ . '/Support/CsrfFormClient.php';

// Load configuration for DB connection
if (!function_exists('env')) {
    function env($key, $default = null) {
        return getenv($key) ?: $default;
    }
}

require_once PATH . 'config.php';
if (!function_exists('configurationSetEnvironment')) {
    function configurationSetEnvironment($env) {
        global $config, $environment;
        $config = $environment['default'];
        if (isset($environment[$env])) {
            $config = array_replace_recursive($config, $environment[$env]);
        }
    }
}

$env = env('MISEREND_WEBAPP_ENVIRONMENT', 'testing');
configurationSetEnvironment($env);

/*
 * #890: a `date_default_timezone_set()` a `dbconnect()` ELŐTT kell, hogy legyen.
 *
 * A load.php ezt a legelső dolgai közt teszi meg (load.php:12), a teszt-bootstrap
 * viszont eddig a `dbconnect()` UTÁN — így a kapcsolat születésekor a PHP még a
 * konténer alapértelmezett UTC-jén állt. A `dbconnect()` most a PHP zónájából
 * származtatja a munkamenet-zónát, tehát ez a sorrend már nem ízlés kérdése:
 * fordítva a tesztek UTC-s kapcsolaton futnának, az alkalmazás meg budapestin.
 *
 * A régi sorrend nyoma az adatbázisban is látszott: a `crons` sorai a `Cron::init()`
 * miatt UTC-s PHP-órával születtek, miközben minden más sor budapestivel.
 */
date_default_timezone_set('Europe/Budapest');

// Set up database connection for integration tests
dbconnect();

// #638: Seed the cron registry — mirrors the deployment step (index.php?q=cron&cron_init=1).
// Without this the crons table stays empty and tests that rely on pre-existing
// cron rows (e.g. testCronInitAddsMissingJobsAndKeepsExistingHistory) would fail.
\Eloquent\Cron::init();

/*
 * A DOMAIN konstanst a load.php definiálja, a tesztek viszont nem azt töltik be. Enélkül
 * minden olyan út elszáll vagy csendben tartalékértékre esik, ami a beállított címre
 * hivatkozik — például az iCal-események UID-je (Html\Church\Ical::uidHost).
 */
if (!defined('DOMAIN')) {
    define('DOMAIN', $GLOBALS['config']['path']['domain'] ?? 'http://localhost');
}


/*
 * A `t()` fordító-rövidítést a load.php definiálja, a tesztek viszont nem azt töltik be.
 * Enélkül minden olyan út elszáll „Call to undefined function t()"-vel, ami felhasználónak
 * szánt szöveget állít elő — például a templom API-tömbje (Church::toAPIArray).
 */
Translator::init('hu');
if (!function_exists('t')) {
    function t($text, $default = null) {
        return Translator::translate($text, $default);
    }
}

/*
 * #824: a globális `$user`-t a load.php állítja be a munkamenetből, a tesztek viszont
 * nem azt töltik be. Enélkül minden jogosultság-vizsgálat „Call to a member function
 * checkRole() on null"-lal hasal el — a `ChurchPrintableScheduleTest` például külön
 * futtatva 12-ből 9 hibával állt meg, a teljes futamban viszont zöld volt, mert egy
 * korábbi teszt véletlenül beállította. Az ilyen sorrendfüggés a legrosszabb fajta
 * hiba: nem a kód romlik el tőle, hanem a mérés hitele.
 *
 * VENDÉG felhasználót adunk, mert az a nyilvános alapállapot: aki bejelentkezettet
 * akar mérni, az állítsa be magának a saját tesztjében.
 */
$GLOBALS['user'] = new \User();

// A levélküldés Twig-sablonból áll elő (\Eloquent\Email::render()), így $twig nélkül
// egyetlen email-út sem tesztelhető. Ugyanaz a környezet, mint amit a load.php épít.
//
// A $GLOBALS azért kell, mert a PHPUnit ezt a fájlt FÜGGVÉNYEN BELÜLRŐL tölti be: a
// sima $twig értékadás ott lokális változó lenne, a render() `global $twig`-je pedig
// továbbra is null-t kapna.
$GLOBALS['twig'] = buildTwigEnvironment();


/**
 * Olyan templom-azonosítót ad, amire SEMMI nem hivatkozik.
 *
 * A tesztek eddig `MAX(templomok.id) + 1`-et használtak. Ez akkor romlik el, ha egy
 * korábbi futás után maradt árva sor egy hivatkozó táblában: az új templom megörökli
 * az idegen maradványt. Nálam ez úgy jött ki, hogy a `LocationNamesFromBoundariesTest`
 * magyar határláncot épített, de „Deutschland"-ot kapott vissza — egy régebbi futás
 * `lookup_boundary_church` sorai ugyanerre az id-re mutattak.
 *
 * A CI-n ez sosem látszik, mert ott minden futás üres adatbázison indul. Helyben
 * viszont — ahol a fejlesztés zajlik — hamis, félrevezető bukást ad.
 *
 * Ezért az összes templomra hivatkozó oszlopot is megnézzük, nem csak a `templomok`-at.
 */
function szabadTemplomId(): int {
    static $kovetkezo = null;

    if ($kovetkezo === null) {
        $db = \Illuminate\Database\Capsule\Manager::connection()->getDatabaseName();
        $hivatkozok = \Illuminate\Database\Capsule\Manager::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.columns
             WHERE TABLE_SCHEMA = ? AND COLUMN_NAME IN (?, ?, ?)',
            [$db, 'church_id', 'templom_id', 'tid']
        );

        $max = (int) \Illuminate\Database\Capsule\Manager::table('templomok')->max('id');
        foreach ($hivatkozok as $oszlop) {
            $ertek = \Illuminate\Database\Capsule\Manager::table($oszlop->t)->max($oszlop->c);
            $max = max($max, (int) $ertek);
        }
        $kovetkezo = $max + 1;
    }

    // Egy futáson belül több teszt is kérhet — ne kapják ugyanazt.
    return $kovetkezo++;
}
