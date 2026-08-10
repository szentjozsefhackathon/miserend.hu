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

    $class = $path->className;
	if(!$class) throw new Exception('Az oldal nem található');
		
    if (method_exists($path->className, 'factory')) {
        $html = $class::factory($path->arguments);
    } else {
        $html = new $class($path->arguments);
    }
} catch (\Throwable $e) {
    // #182: a nyers hibaüzenet (QueryException esetén a TELJES SQL) és a stack
    // trace eddig környezet-ellenőrzés NÉLKÜL kiment a frontendre — pl. egy emoji
    // beküldésekor a "SQLSTATE... Incorrect string value ... insert into `remarks`
    // ..." + fájlútvonalak/sorszámok. Csak debug módban (dev/testing/staging)
    // mutatjuk a részleteket; prod-ban ($config['debug']=0) generikus üzenet.
    // A részletek MINDIG a szerver-logba kerülnek, hogy debug=0 mellett se vesszenek el.
    //
    // #725: \Throwable, nem \Exception. A TypeError/Error a PHP 8-ban NEM \Exception,
    // ezért az eddig átment ezen az ágon: 500 lett belőle, napló nélkül.
    $showDetails = !empty($config['debug']);
    logThrowable('Unhandled exception', $e);

    if (isset($html)) {
        addMessage($showDetails ? $e->getMessage() : 'Váratlan hiba történt. Kérjük, próbáld újra később.', 'danger');
    } else {
		// Mi lenne, ha a hibaüzenetünket szeben írnánk ki?
		$html = buildExceptionPage($e, $showDetails, $path->arguments ?? false);
    }
}
if (isset($html)) {
    // #725: a renderelés eddig védtelen volt. Márpedig a Twig-sablonok a lusta Eloquent-
    // accessorokat (church.location, church.holders, …) csak itt hívják meg — ha ott szállt
    // el valami, abból 500 lett, a hibáról pedig SEMMI nem került a naplóba.
    try {
        $html->render();
    } catch (\Throwable $e) {
        logThrowable('Render failed', $e);
        $html = buildExceptionPage($e, !empty($config['debug']), $path->arguments ?? false);
        $html->render();
    }
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
