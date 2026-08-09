<?php

$environment['default'] = [
    'connection' => [
        'host' => env('MYSQL_MISEREND_HOST', 'mysql'),
        'user' => env('MYSQL_MISEREND_USER', 'root'),
        'password' => env('MYSQL_MISEREND_PASSWORD', 'pw'),
        'database' => env('MYSQL_MISEREND_DATABASE', 'miserend')
    ],
    'path' => [
        'domain' => 'https://miserend.hu'
    ],
    'mapquest' => [
        'appkey' => env('MAPQUEST_CONSUMERKEY', '***'),
        'useitforsearch' => false
    ],
	'openstreetmap' => [
		'apiUrl' => 'https://api.openstreetmap.org/'
    ],
    // #376: konfigurálható Overpass-endpoint. A fő overpass-api.de instabil lehet;
    // prod átállhat egy stabil, EU-s mirrorra az OVERPASS_API_URL env-vel. Ajánlott:
    //   https://overpass.kumi.systems/api/interpreter  (Kumi Systems, Ausztria — EU, GDPR-OK)
    // NE orosz mirror (openstreetmap.ru): nem EU, GDPR/megbízhatósági kockázat.
    'overpass' => [
        'apiUrl' => env('OVERPASS_API_URL', 'https://overpass-api.de/api/interpreter')
    ],
    'token' => [
        'web' => "2 weeks",
		'API' => "15 minutes"
    ],
	// #610: az SMTP kizárólag env-ből jön, és a `default` ág SZÁNDÉKOSAN üres Host-tal
	// indul. Korábban itt 'mailcatcher' volt az alapérték, így production-ben — ahol a
	// compose sosem adta át az SMTP_HOST-ot — minden levél a dev mailcatcher-be ment és
	// némán elnyelődött (a /health közben "OK"-ot mutatott). Üres Host esetén inkább
	// hangosan hibázunk, l. \Eloquent\Email::send().
	'smtp' => [
		'Host' => env('SMTP_HOST', ''),
		// Üres env-változó esetén az env() üres sztringet ad vissza (nem az alapértéket),
		// abból pedig 0-s port lenne — ezért az ?: fallback.
		'Port' => ((int) env('SMTP_PORT', 25)) ?: 25,
		'SMTPAuth' => (bool) env('SMTP_USER', false),
		'Username' => env('SMTP_USER', ''),
		'Password' => env('SMTP_PASSWORD', ''),
		'SMTPSecure' => env('SMTP_SECURE', '')
	],
    'mail' => [
        'sender' => ['info@miserend.hu','miserend.hu'],
        'debug' => 0, /* 0,1,2,3,5 */
        'debugger' => 'eleklaszlosj@gmail.com'
    ],
    'debug' => 0,
    'error_reporting' => false
];

$environment['testing'] = [
    'debug' => 1,
    'mail' => [
        'debug' => 5
    ],
	// A mailcatcher-alapérték csak itt és development-ben él (l. #610).
	'smtp' => [
		'Host' => env('SMTP_HOST', 'mailcatcher'),
		'Port' => ((int) env('SMTP_PORT', 1025)) ?: 1025
	],
    'error_reporting' => E_ERROR | E_WARNING | E_PARSE
];

$environment['staging'] = [
    'debug' => 1,
    'mail' => [
        'debug' => 2
    ],
    'path' => [
        'domain' => 'http://staging.miserend.hu'
    ],
	'openstreetmap' => [
		'client_id' => '',
		'client_secret' => '',
		'application_code' => '',		
		'access_token' => '',
		'apiUrl' => 'https://master.apis.dev.openstreetmap.org/'	
    ],	
	
	
	
    'error_reporting' => E_ERROR | E_WARNING | E_PARSE
];

$environment['development'] = [
    'debug' => 1,
    'mail' => [
        'debug' => 0
    ],
	'smtp' => [
		'Host' => env('SMTP_HOST', 'mailcatcher'),
		'Port' => ((int) env('SMTP_PORT', 1025)) ?: 1025
	],
    'path' => [
        'domain' => 'http://localhost:8000'
    ],
	'openstreetmap' => [], # => false: semmit nem használ ami OMS ; [] => adatot letölt, de nem nyúl hozzá
    'error_reporting' => E_ALL
];

$environment['production'] = [];