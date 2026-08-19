<?php

define('PATH', dirname(__DIR__) . '/');

// Load Composer autoloader
if (!@include PATH . 'vendor/autoload.php') {
    die('Composer dependencies not found. Run: composer install');
}

require_once PATH . 'functions.php';
require_once PATH . 'twig_extras.php';

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

date_default_timezone_set('Europe/Budapest');

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

