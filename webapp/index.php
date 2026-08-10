<?php
 //apache_setenv('MISEREND_WEBAPP_ENVIRONMENT', 'development');
include("load.php");

try {
    if (php_sapi_name() == "cli") {
        if (isset($argv)) {
            foreach ($argv as $arg) {
                $e = explode("=", $arg);
                if (count($e) == 2) {
                    $_REQUEST[$e[0]] = $e[1];
                    if ($e[0] == 'env') {
                        configurationSetEnvironment($e[1]);
                    }
                } else
                    $_REQUEST[$e[0]] = 0;
            }
        }
    }

    $action = \Request::Text('q');
    $path = new Path($action);

    if ($path->url == 'home' AND \Request::Integer('templom') > 0) {
        $path = new Path('templom/' . \Request::Integer('templom'));
    }

    // #724: süti- és IP-mentes látogatottság-számláló. A feloldott, normalizált útvonalat
    // számoljuk (`templom/{id}`), nem a nyers URL-t — így a tábla napi néhány tucat sor
    // marad, és a query-stringből semmi nem kerül be. Robotokat kihagyunk: a User-Agentet
    // csak megnézzük, nem tároljuk.
    \Stats::countPageview($path->url, str_starts_with((string) $path->url, 'api/') ? 'api'
        : (str_starts_with((string) $path->url, 'ajax') ? 'ajax' : 'html'));

    $class = $path->className;
	if(!$class) throw new Exception('Az oldal nem található');
		
    if (method_exists($path->className, 'factory')) {
        $html = $class::factory($path->arguments);
    } else {
        $html = new $class($path->arguments);
    }
} catch (\Exception $e) {
    // #182: a nyers hibaüzenet (QueryException esetén a TELJES SQL) és a stack
    // trace eddig környezet-ellenőrzés NÉLKÜL kiment a frontendre — pl. egy emoji
    // beküldésekor a "SQLSTATE... Incorrect string value ... insert into `remarks`
    // ..." + fájlútvonalak/sorszámok. Csak debug módban (dev/testing/staging)
    // mutatjuk a részleteket; prod-ban ($config['debug']=0) generikus üzenet.
    // A részletek MINDIG a szerver-logba kerülnek, hogy debug=0 mellett se vesszenek el.
    $showDetails = !empty($config['debug']);
    error_log('[miserend] Unhandled exception: ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (isset($html)) {
        addMessage($showDetails ? $e->getMessage() : 'Váratlan hiba történt. Kérjük, próbáld újra később.', 'danger');
    } else {

		// Mi lenne, ha a hibaüzenetünket szeben írnánk ki?
		$html = new \Html\Html($path->arguments);

		$html->template = 'Exception.twig';
		$html->errorTrace = '';

        if ($showDetails) {
            $html->errorMessage = $e->getMessage();
            foreach ($e->getTrace() as $trace) {
                if (isset($trace['class']))
                    $html->errorTrace .= $trace['class'] . "::" . $trace['function'] . "()";
                if (isset($trace['file']))
                    $html->errorTrace .= $trace['file'] . ":" . $trace['line'] . " -> " . $trace['function'] . "()";
                $html->errorTrace .= "<br>";
            }
        } else {
            $html->errorMessage = 'Váratlan hiba történt. Kérjük, próbáld újra később.';
        }
    }
}
if (isset($html)) {
    $html->render();
    if (trim($html->html) != '') {  
		//Az API esetén a CORS policy megengedő kell legyen.
		if(isset($html->api)) {			
			header("Access-Control-Allow-Origin: *");
			header('Access-Control-Allow-Credentials: true');
			header('Access-Control-Max-Age: 86400');    // cache for 1 day
			header("HTTP/1.1 200 OK");
			header("Access-Control-Allow-Methods: GET, POST, OPTIONS");         		
			header("Access-Control-Allow-Headers: Content-Type");
		}
        if(
			( isset($html->api->format) AND $html->api->format == 'json' ) OR 
			( isset($html->format) AND $html->format == 'json' ) 
		  )
            header('Content-Type: application/json');

        if($config['env'] == 'development') {
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        echo $html->html;
    }
}
?>
