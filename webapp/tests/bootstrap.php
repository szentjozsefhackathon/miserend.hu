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

date_default_timezone_set('Europe/Budapest');

$_honapok = [
    1 => ['jan', 'január'],
    2 => ['feb', 'február'],
    3 => ['márc', 'március'],
    4 => ['ápr', 'április'],
    5 => ['máj', 'május'],
    6 => ['jún', 'június'],
    7 => ['júl', 'július'],
    8 => ['aug', 'augusztus'],
    9 => ['szept', 'szeptember'],
    10 => ['okt', 'október'],
    11 => ['nov', 'november'],
    12 => ['dec', 'december'],
];
