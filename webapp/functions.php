<?php

use Illuminate\Database\Capsule\Manager as DB;

function dbconnect() {

    global $config, $capsule;
	
	try {
		$capsule = new DB;
		$capsule->addConnection([
			'driver' => 'mysql',
			'host' => $config['connection']['host'],
			'database' => $config['connection']['database'],
			'username' => $config['connection']['user'],
			'password' => $config['connection']['password'],
			'charset' => 'utf8mb4',
			'collation' => 'utf8mb4_unicode_ci',
			'prefix' => '',
			/*
			 * #890: a munkamenet-zóna ott dől el, ahol a kapcsolat születik.
			 *
			 * Eddig `bootEloquent()` UTÁN ment egy `DB::statement("SET time_zone='+05:00'")`.
			 * Két baja volt. Az egyik, hogy `+05:00` — a PHP `Europe/Budapest`-ben ír
			 * (load.php), tehát a tárolt pillanat 5 órával a falióra mögé került a helyes
			 * 1-2 óra helyett. A másik, hogy EGYSZER futott: a Laravel elveszett kapcsolat
			 * után magától újracsatlakozik (Connection::reconnectIfMissingConnection()), és
			 * az új kapcsolat már NEM kapta meg a beállítást — mérve `SYSTEM` (=UTC) lett
			 * belőle, némán, egy harmadik kódolást hozva be. Tipikus alkalom rá a fél órás
			 * `updateMasses()` újraindexelés.
			 *
			 * A `timezone` konfigkulcsot a MySqlConnector::configureTimezone() a `connect()`
			 * részeként alkalmazza, tehát MINDEN újracsatlakozásnál is lefut.
			 *
			 * A zónát a PHP-tól kérdezzük, nem írjuk le másodszor: két helyen karbantartott
			 * időzónából előbb-utóbb kettő lesz.
			 */
			'timezone' => date_default_timezone_get(),
				], 'default');
		// Make this Capsule instance available globally via static methods... (optional)
		$capsule->setAsGlobal();
		$capsule->bootEloquent();
	} catch(PDOException $e) {
		/*
		 * #890: az időzóna-hibát NEM szabad elnyelni.
		 *
		 * Ha a `mysql.time_zone_*` tábla nincs betöltve, a connector `set time_zone`-ja
		 * ERROR 1298-cal elszáll. A Laravel ezt `QueryException`-be csomagolja, ami
		 * `PDOException`-leszármazott (vendor/illuminate/database/QueryException.php) —
		 * tehát ez a catch eddig lenyelte volna, kiírta volna a hibát a HTML-be, és az
		 * alkalmazás futott volna tovább SYSTEM (=UTC) zónán. Az a legrosszabb kimenet:
		 * egy harmadik, néma időrendszer a PHP falióra-értékei mellett.
		 *
		 * Megoldás nincs a kódban — a tz-táblát be kell tölteni:
		 *   mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb -u root -p mysql
		 */
		if (($e->errorInfo[1] ?? null) === 1298) {
			throw new \RuntimeException(
				'#890: a MySQL nem ismeri a(z) "' . date_default_timezone_get() . '" időzónát. '
				. 'Töltsd be a nevesített időzóna-táblát: '
				. 'mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb -u root -p mysql',
				0, $e
			);
		}
		echo $e->getMessage();		
	}

}

function sanitize($text) {
    if (is_array($text))
        foreach ($text as $k => $i)
            $text[$k] = sanitize($i);
    else {
        $text = preg_replace('/\n/i', '<br/>', $text);
        $text = strip_tags($text, '<a><i><b><strong><br>');
        $text = trim($text);
    }
    return $text;
}

function checkUsername($username) {
    if ($username == '')
        return false;
    if ($username == '*vendeg*')
        return false;
    if (strlen($username) > 20)
        return false;
    if (preg_match("/( |\"|'|;)/i", $username))
        return false;

    /*
     * #829: „én ezt feloldanám” — ez terméki döntés, ezért nem magamtól oldom fel.
     *
     * Ma csak ékezet nélküli betű és szám mehet. A feloldás nem egy regexp átírása:
     *
     *  - a felhasználónév URL-be kerül (`/user/<nev>/edit`), tehát kódolni kellene;
     *  - a `sanitize()` és a levélsablonok is átmennének rajta;
     *  - és a legfontosabb: két név, ami csak ékezetben tér el („Peter” / „Péter”),
     *    a bejelentkezésnél összetéveszthető lenne — a `login` mezőn ma nincs egyedi
     *    index, tehát a védelem kizárólag ezen az ellenőrzésen múlik.
     *
     * A becenév (`becenev`) mező viszont MÁR MOST is szabad szöveg, és a felületen
     * az jelenik meg — az ékezetes megjelenítés tehát adott.
     */
    //TODO: én ezt feloldanám
    if (!preg_match("/^([a-z0-9]{1,20})$/i", $username))
        return false;

    $checkeduser = new User($username);
    if ($checkeduser->uid > 0)
        return false;


    return true;
}

function mapquestGeocode($location) {
    global $config;
    $url = "http://www.mapquestapi.com/geocoding/v1/address?key=" . $config['mapquest']['appkey'];
    $url .= "&location=" . urlencode($location);
    $url .= "&outFormat=json&maxResults=1";

    $file = file_get_contents($url);
    $mapquest = json_decode($file, true);
    //print_r($mapquest);
    //echo "<a href='".$mapquest['results'][0]['locations'][0]['mapUrl']."'>map</a>";
    return array_merge($mapquest['results'][0]['locations'][0]['latLng'], array('mapUrl' => $mapquest['results'][0]['locations'][0]['mapUrl']));
}


function feltoltes_block() {
    global $user;
    
    if(!isset($user->responsibilities['church']['allowed']))
        return false;
    
    $allowed = $user->responsibilities['church']['allowed'];
    $ids = [];
    foreach($allowed as $church) {
        $ids[] = $church->church_id;
    }
    $churches = \Eloquent\Church::whereIn('id',$ids)->get();
    
    if(count($churches) == 0) return;
    
    $kod_tartalom = '<ul>';
    foreach( $churches as $church) { 
        $jelzes = '';        
        if ($church->eszrevetel == 'u')
            $jelzes.="<a href=\"javascript:OpenScrollWindow('/templom/".$church->id."/eszrevetelek',550,500);\"><img src=/img/csomag.gif title='Új észrevételt írtak hozzá!' align=absmiddle border=0></a> ";
        elseif ($church->eszrevetel == 'i')
            $jelzes.="<a href=\"javascript:OpenScrollWindow('/templom/".$church->id."/eszrevetelek',550,500);\"><img src=/img/csomag1.gif title='Észrevételek!' align=absmiddle border=0></a> ";
        elseif ($church->eszrevetel == 'f')
            $jelzes.="<a href=\"javascript:OpenScrollWindow('/templom/".$church->id."/eszrevetelek',550,500);\"><img src=/img/csomagf.gif title='Észrevétel javítása folyamatban!' align=absmiddle border=0></a> ";
        else
            $jelzes = '';

        $kod_tartalom.="\n<li>$jelzes<a href='/templom/".$church->id."/edit' class=link_kek title='".$church->varos."'>".$church->names[0]."</a></li>";
    }

    $kod_tartalom.="\n<li><a href='/user/maintainedchurches' class=felsomenulink>Teljes lista...</a></li>";
    $kod_tartalom .= '</ul>';

    return $kod_tartalom;
    
}

function addMessage($text, $severity = false) {
    return \Message::add($text,$severity);    
}

function copyArrayToObject($array, &$object) {
    foreach ($array as $key => $value) {
        $object->$key = $value;
    }
}

function dumpToFile(mixed ...$values): void {
    $file = '/tmp/api-endpoints-test-debug.log';

    $chunks = [
        '[' . date('c') . ']',
    ];

    foreach ($values as $index => $value) {
        $chunks[] = 'value_' . $index . ':';
        $chunks[] = var_export($value, true);
    }

    file_put_contents(
        $file,
        implode(PHP_EOL, $chunks) . PHP_EOL . str_repeat('-', 60) . PHP_EOL,
        FILE_APPEND
    );
}


function callPageFake($uri, $post, $phpinput = array()) {
    dumpToFile('callPageFake start', [
        'uri' => $uri,
        'file_exists' => file_exists($uri),
        'cwd' => getcwd(),
        'request_before' => $_REQUEST,
        'post' => $post,
        'phpinput' => $phpinput,
    ]);

    ini_set('display_errors', '1');
    error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);

    register_shutdown_function(function () use ($uri) {
        $lastError = error_get_last();
        dumpToFile('shutdown', [
            'uri' => $uri,
            'last_error' => $lastError,
            'buffer' => ob_get_contents(),
        ]);
    });

    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'MockPhpStream');
    file_put_contents('php://input', json_encode($phpinput));
    $_REQUEST = array_merge($_REQUEST, $post);

    ob_start();

    try {
        include $uri;
        $page = ob_get_contents();
        dumpToFile('include returned', $page);
    } catch (\Throwable $e) {
        dumpToFile('include threw', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'buffer' => ob_get_contents(),
        ]);
        throw $e;
    } finally {
        if (in_array('php', stream_get_wrappers(), true)) {
            stream_wrapper_restore('php');
        }
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    return $page;
}

spl_autoload_register(function ($class) {
    $classpath = PATH . '/classes/' . str_replace('\\', '/', strtolower($class)) . '.php';
    if ($file = file_exists_ci($classpath)) {
        require_once($file);
    }
});

if (!function_exists("env")) {

    function env($name, $default = false) {
        if (!getenv($name))
            return $default;
        else
            return getenv($name);
    }

}

function file_exists_ci($fileName) {
    // exact match first, but on case-insensitive filesystems file_exists may be true with wrong case
    $dir = dirname($fileName);
    $base = basename($fileName);
    if (file_exists($fileName)) {
        // try to return the real filesystem-cased filename from the same directory
        if (is_dir($dir)) {
            foreach (scandir($dir) as $entry) {
                if (strcasecmp($entry, $base) === 0) {
                    return $dir . DIRECTORY_SEPARATOR . $entry;
                }
            }
        }
        // fallback to given filename
        return $fileName;
    }
   
    $pattern = dirname(__FILE__) . "/classes";
    $files = array();
    for ($i = 0; $i < 5; $i++) {
        $pattern .= '/*';
        $files = array_merge($files, glob($pattern));
    }
    $fileNameLowerCase = strtolower($fileName);
    foreach ($files as $file) {
        if (strtolower($file) == $fileNameLowerCase) {
            return $file;
        }
    }
    return false;
}

/**
 * #860: hibakereső kiírás — HTML-be ESCAPE-elve.
 *
 * A függvény korábban nyersen echózta a `print_r()` kimenetét. Ez addig ártalmatlan,
 * amíg saját adatot ír ki, de a `Request::IntegerArrayRequired()`-ben ottfelejtett
 * hívás a FELHASZNÁLÓ bemenetét adta vissza — reflected XSS lett belőle, bejelentkezés
 * nélkül, sima GET-tel.
 *
 * A hívásokat kivettük, de a függvény maga is legyen védett: hibakereséskor az ember
 * pont nem arra figyel, honnan jött az adat. Escape-elve ugyanúgy olvasható, csak nem
 * futtatható.
 */
function printr($variable) {

    echo "<pre>" . htmlspecialchars(print_r($variable, 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</pre>";
}

function configurationSetEnvironment($env) {
    global $config;
    include('config.php');
    if (!array_key_exists($env, $environment)) {
        $env = 'default';
    }
    $config = $environment['default'];
    $config['env'] = $env;
    if ($env != 'default') {
        overrideArray($config, $environment[$env]);
    }
    putenv('MISEREND_WEBAPP_ENVIRONMENT=' . $env);
    dbconnect();
}

function overrideArray(&$orig, $new) {
    foreach ($new as $k => $n) {
        if (!is_array($n)) {
            $orig[$k] = $n;
        } else {
            overrideArray($orig[$k], $n);
        }
    }
}

/**
 * #725: egységes hibanaplózás, stack trace-szel.
 *
 * A jegy kiindulópontja az volt, hogy a /templom/5444/edit 500-at ad, a
 * `docker logs` viszont csak az access-log sorát mutatja, hibaüzenetet nem. Ennek
 * három oka volt együtt:
 *
 *  1. élesben `error_reporting(0)` futott (config.php `default`), ami nem csak a
 *     kijelzést, a NAPLÓZÁST is kikapcsolja — a PHP saját "Fatal error: Uncaught …"
 *     üzenete sehova nem került ki;
 *  2. az index.php csak `\Exception`-t fogott, a PHP 8-as `\Error`/`TypeError` nem az;
 *  3. a `render()` a try/catch-en kívül volt.
 *
 * Az `error_log()` hívást az `error_reporting` maszk NEM szűri, ezért ez akkor is
 * megbízhatóan ír, ha a maszk szűk. A cél a `docker logs` — a php:8.4-apache image
 * az Apache error logját a stderr-re szimlinkeli.
 */
function logThrowable(string $context, \Throwable $e): void {
    $uri = $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : '?');
    error_log(sprintf(
        '[miserend] %s: %s: %s @ %s:%d | URI: %s',
        $context, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $uri
    ));
    // A trace külön sorban: a stack nélkül egy Twig-hiba üzenete önmagában
    // ("An exception has been thrown during the rendering of a template") használhatatlan.
    error_log('[miserend] trace: ' . $e->getTraceAsString());

    if ($previous = $e->getPrevious()) {
        error_log(sprintf('[miserend] %s (previous): %s: %s @ %s:%d',
            $context, get_class($previous), $previous->getMessage(),
            $previous->getFile(), $previous->getLine()));
    }
}

/**
 * #725: a hibaoldal összeállítása. Két helyről kell (oldal-építés és renderelés),
 * ezért került külön.
 */
function buildExceptionPage(\Throwable $e, bool $showDetails, $arguments = false): \Html\Html {
    $html = new \Html\Html($arguments);
    $html->template = 'Exception.twig';
    $html->errorTrace = '';

    if ($showDetails) {
        $html->errorMessage = $e->getMessage();
        foreach ($e->getTrace() as $trace) {
            if (isset($trace['class'])) {
                $html->errorTrace .= $trace['class'] . "::" . $trace['function'] . "()";
            }
            if (isset($trace['file'])) {
                $html->errorTrace .= $trace['file'] . ":" . $trace['line'] . " -> " . $trace['function'] . "()";
            }
            $html->errorTrace .= "<br>";
        }
    } else {
        $html->errorMessage = 'Váratlan hiba történt. Kérjük, próbáld újra később.';
    }

    return $html;
}

/**
 * #725: a végzetes hibák (memória, max_execution_time, parse error) nem dobnak
 * kivételt, tehát semmilyen catch nem fogja meg őket — csak a shutdown handler.
 * Éppen ezek adják a néma 500-akat.
 */
function registerFatalErrorLogger(): void {
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli' : '?');
        error_log(sprintf('[miserend] Fatal error: %s @ %s:%d | URI: %s',
            $error['message'], $error['file'], $error['line'], $uri));
    });
}
