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
			'charset' => 'utf8',
			'collation' => 'utf8_unicode_ci',
			'prefix' => '',
				], 'default');
		// Make this Capsule instance available globally via static methods... (optional)
		$capsule->setAsGlobal();
		$capsule->bootEloquent();
		DB::statement("SET time_zone='+05:00';");
	} catch(PDOException $e) {
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

function getWeekInMonth($date, $order = '+') {
    $num = 0;
    if ($order == '+')
        for ($i = 0; $i < 6; $i++) {
            if (date("m", strtotime($date)) == date('m', strtotime($date . " -" . $i . " week")))
                $num++;
        }
    if ($order == '-')
        for ($i = 0; $i < 6; $i++) {
            if (date("m", strtotime($date)) == date('m', strtotime($date . " +" . $i . " week")))
                $num--;
        }
    return $num;
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

function br2nl($string) {
    return preg_replace('/\<br(\s*)?\/?\>/i', PHP_EOL, $string);
}

function idoszak($i) {
    switch ($i) {
        case 'a': $tmp = 'Ádventi idő';
            break;
        case 'k': $tmp = 'Karácsonyi idő';
            break;
        case 'n': $tmp = 'Nagyböjti idő';
            break;
        case 'h': $tmp = 'Húsvéti idő';
            break;
        case 'e': $tmp = 'Évközi idő';
            break;
        case 's': $tmp = 'Szent ünnepe';
            break;
    }
    return $tmp;
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

function printr($variable) {

    echo"<pre>" . print_r($variable, 1) . "</pre>";
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
