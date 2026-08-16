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

date_default_timezone_set('Europe/Budapest');

// A levélküldés Twig-sablonból áll elő (\Eloquent\Email::render()), így $twig nélkül
// egyetlen email-út sem tesztelhető. Ugyanaz a környezet, mint amit a load.php épít.
//
// A $GLOBALS azért kell, mert a PHPUnit ezt a fájlt FÜGGVÉNYEN BELÜLRŐL tölti be: a
// sima $twig értékadás ott lokális változó lenne, a render() `global $twig`-je pedig
// továbbra is null-t kapna.
$GLOBALS['twig'] = buildTwigEnvironment();

