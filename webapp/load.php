<?php

define('PATH', dirname(__FILE__) . "/");

$vars = array();

if (!@include __DIR__ . '/vendor/autoload.php') {
    die('You must set up the project dependencies, run the following commands:
        wget http://getcomposer.org/composer.phar
        php composer.phar install');
}
date_default_timezone_set('Europe/Budapest');

$_tidsToWorkWith = 
[];

$config = array();
$db = false;

include_once('functions.php');

$env = env('MISEREND_WEBAPP_ENVIRONMENT', 'staging'); /* testing, staging, production, vagrant */
configurationSetEnvironment($env);

// #547: nem-produkciós környezet (staging/testing) ne kerüljön be a keresőkbe.
// A layout.twig noindex-metája csak a HTML-oldalakat fedi; ez a header MINDEN
// válaszra rákerül (AJAX/JSON/kép/PDF is), és a crawlerek is tiszteletben tartják.
if ($env !== 'production' && PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
}

error_reporting($config['error_reporting'] ? $config['error_reporting'] : 0);

// #725: a végzetes hibák és az elkapatlan kivételek naplózása. A php.ini-ben
// `display_errors = Off`, tehát a látogató ettől semmit nem lát többet — csak a
// `docker logs` lesz használható. Enélkül egy 500-as oldalról semmi nyom nem maradt.
registerFatalErrorLogger();
set_exception_handler(static function (\Throwable $e): void {
    logThrowable('Uncaught', $e);
});
define('DOMAIN', $config['path']['domain']);

Translator::init('hu'); // vagy autodetect
// Short alias for Translator::translate(). Use as t('key') in PHP or templates when available.
function t($text, $default = null) {
    return Translator::translate($text, $default);
}

//Felhasználó
if (isset($_REQUEST['login'])) {
    try {
        \User::login($_REQUEST['login'], $_REQUEST['passw']);
    } catch (\Exception $ex) {        
        addMessage('Hibás név és/vagy jelszó!<br/><br/>Ha elfelejtetted a jelszavadat, <a href="/user/lostpassword">kérj ITT új jelszót</a>.', 'danger');
    }
}
if (isset($_REQUEST['logout']) AND $_REQUEST['logout'] != 'false') {
    \User::logout();

}
$user = \User::load();

include_once('twig_extras.php');
$loader = new \Twig\Loader\FilesystemLoader(PATH . 'templates');
$twig = new \Twig\Environment($loader);
$twig->addFilter(new \Twig\TwigFilter('miserend_date', 'twig_hungarian_date_format'));
$twig->addFilter(new \Twig\TwigFilter('trans', 'twig_translate'));
$twig->addFilter(new \Twig\TwigFilter('floor', 'floor'));
$twig->addFilter(new \Twig\TwigFilter('phone_links', 'twig_phone_links'));
$twig->addFilter(new \Twig\TwigFilter('strip_protocol', 'twig_strip_protocol'));
$twig->addFilter(new \Twig\TwigFilter('facebook_path', 'twig_facebook_path'));
$twig->addFilter(new \Twig\TwigFilter('readable_rrule', 'twig_readable_rrule'));
// DANGER: a twig declarálva van / meg van hívva a Class/Html/Html.php -ban is. Így ott is módosítani kellhet a filterket
$twig->addGlobal('domain', DOMAIN); // Environment-specific domain for email templates
$twig->addGlobal('mcal_version', mcalVersion()); // naptár-bundle cache-buster, l. mcalVersion()

//
//  Useful CONSTANTS

define("ROLES", serialize(['miserend', 'user']));
?>