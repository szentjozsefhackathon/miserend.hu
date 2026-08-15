<?php

namespace Html;

class Html {
    public $template;
    public $menu = array();
    public $pageTitle;
    public $templatesPath = 'templates';
    public $extraMeta;
    public $input;
    public $pagination;
    public $environment;
    public $githash;
    public $user;
    public $mychurches;
    public $chat;
    public $messages;
    public $title;
    public $twig;
    public $html;
    public $langs;
    public $alert;
    public $errorMessage;
    public $errorTrace;

    function __construct() {
        // #545: ez a nyers-input GYÖKÉR — minden Html-oldalon elérhető a $this->input
        // szűretlen $_REQUEST-ként. Kiváltása a html/ mappa mind a ~73 ->input[...]
        // használatának átírását + staging-tesztet igényel (form-mentés, kép-feltöltés),
        // ezért külön, tesztelt lépésben megy — nem itt, vakon.
        $this->input = \Request::all();
        $this->initPagination();
    }

    function render() {
        global $user, $config;

        $this->environment = $config['env'];
        $this->githash = $this->getGitHash();
        $this->user = $user;

        $this->loadMenu();
        if (isset($this->user->loggedin) AND $this->user->loggedin AND ! $this->user->checkRole('miserend')) {
            $this->mychurches = feltoltes_block();
        }
        if ($this->user->checkRole('"any"')) {
            $this->chat = new \Chat;
            $this->chat->load();
            $this->chat = collect($this->chat)->toArray();
        }

        $this->messages = \Message::getToShow();

        $this->loadTwig();
        $this->getTemplateFile();
        $this->html = $this->twig->render(strtolower($this->template), (array) $this);
        $this->injectTime();
    }

    function loadTwig() {

		$loader = new \Twig\Loader\FilesystemLoader(PATH . $this->templatesPath);
		$this->twig = new \Twig\Environment($loader); //cache:
        include_once('twig_extras.php');
        $this->twig->addFilter(new \Twig\TwigFilter('miserend_date', 'twig_hungarian_date_format'));
        $this->twig->addFilter(new \Twig\TwigFilter('trans', 'twig_translate'));
        $this->twig->addFilter(new \Twig\TwigFilter('floor', 'floor'));
        $this->twig->addFilter(new \Twig\TwigFilter('phone_links', 'twig_phone_links'));
        $this->twig->addFilter(new \Twig\TwigFilter('strip_protocol', 'twig_strip_protocol'));
        $this->twig->addFilter(new \Twig\TwigFilter('facebook_path', 'twig_facebook_path'));
        $this->twig->addFilter(new \Twig\TwigFilter('readable_rrule', 'twig_readable_rrule'));
        // DANGER: a twig declarálva van / meg van hívva a Load.php -ban is. Így ott is módosítani kellhet a filterket
        $this->twig->addGlobal('domain', DOMAIN); // Environment-specific domain for email templates
        $this->twig->addGlobal('mcal_version', mcalVersion()); // naptár-bundle cache-buster, l. mcalVersion()
        // #766: az Overpass-végpont a böngészőből induló lekérdezésekhez is. A PHP oldal
        // már a config['overpass']['apiUrl']-t használja (#376), a térkép-sablonok viszont
        // beégetve hívták az overpass-api.de-t — ráadásul http-n. Így a mirror-váltás
        // rájuk is érvényes.
        $this->twig->addGlobal('overpass_api_url', $config['overpass']['apiUrl'] ?? 'https://overpass-api.de/api/interpreter');

    }

    function getTemplateFile() {
        if (!isset($this->template)) {
            $className = get_class($this);
            $classPath = preg_replace("/\\\/i", "/", get_class($this));
            $classShortPath = preg_replace("/html\//i", "", $classPath);
            $this->template = $classShortPath . ".twig";
        }
    }

    function loadMenu() {
        if ($this->user->checkRole("'any'")) {
            $this->loadAdminMenu();
        }
        if (isset($this->user->responsible['diocese']) AND count($this->user->responsible['diocese']) > 0 AND ! $this->user->checkRole('miserend')) {
            $this->loadResponsibleMenu();
        }
        $this->menu[] = [
            'title' => 'Térkép',  'mid' => 27,
            'url' => '/terkep',
            'items' => [
                [ 'title' => 'Térképen a misézőhelyek', 'url' => '/terkep' ],
                [ 'title' => 'Térképes plakátkészítő', 'url' => 'https://szentjozsefhackathon.github.io/templom-terkep/' ]
            ]
        ];
        
    }

    function loadAdminMenu() {
        $adminmenuitems = [
            ['title' => 'Miserend', 'url' => '/templom/list', 'permission' => 'miserend', 'mid' => 27,
                'items' => [
                    ['title' => 'teljes lista', 'url' => '/templom/list', 'permission' => ''],
                    ['title' => 'kezelendő észrevételek', 'url' => '/templom/list?status=Rnj&orderBy=updated_at+DESC', 'permission' => ''],

                    ['title' => 'egyházmegyei lista', 'url' => '/egyhazmegye/list', 'permission' => 'miserend'],
                    ['title' => 'időszakok dátumai', 'url' => '/periodyeareditor', 'permission' => 'miserend'],
                    ['title' => 'statisztika', 'url' => '/stat', 'permission' => '"any"'],
                    ['title' => 'gyóntatások', 'url' => '/confessionscatalogue', 'permission' => 'miserend'],
					['title' => 'egészség', 'url' => '/health', 'permission' => 'miserend'],
                    ['title' => 'API tesztelés', 'url' => '/apitest', 'permission' => 'miserend'],
                    ['title' => 'OSM kapcsolat', 'url' => '/josm', 'permission' => 'miserend'],
                ]
            ],
            ['title' => 'Felhasználók', 'url' => '/user/catalogue', 'permission' => 'user'],
        ];
        $adminmenuitems = $this->clearMenu($adminmenuitems);
        $this->menu = array_merge($this->menu, $adminmenuitems);
    }

    function loadResponsibleMenu() {
        $diocesemenuitems = [
            ['title' => 'Templomok', 'url' => '/user/maintainedchurches',
                'items' => [
                    ['title' => 'módosítás', 'url' => '/user/maintainedchurches'],
                ]
            ],
        ];
        $this->menu = array_merge($this->menu, $diocesemenuitems);
    }

    function clearMenu($menuitems) {
        foreach ($menuitems as $key => $item) {
            if (isset($item['permission']) AND ! $this->user->checkRole($item['permission'])) {
                unset($menuitems[$key]);
            } else {
                if (isset($item['items']) AND is_array($item['items'])) {
                    foreach ($item ['items'] as $k => $i) {
                        if (isset($i['permission']) AND ! $this->user->checkRole($i['permission'])) {
                            unset($menuitems[$key][$k]);
                        } else {
                            
                        }
                    }
                }
            }
        }
        return $menuitems;
    }

    function setTitle($title) {
        $this->pageTitle = $title . " | Miserend";
        $this->title = $title;
    }

    function addExtraMeta($name, $content) {
        $this->extraMeta .= "\n<meta name='" . $name . "' content='" . $content . "'>";
        return true;
    }

    function injectTime() {
        global $config;
        if ($config['debug'] > 0) {
            $this->html = str_replace('<!--xxx-->', ( microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"] ) . " s", $this->html);
        }
    }

    function array2this($array) {
        copyArrayToObject($array, $this);
    }

    function redirect($url) {
        # http_redirect ($url,$params,$session,$status);
        header("Location: " . $url);
        exit;
    }

    function initPagination() {
        $this->pagination = new \Pagination();

        // #391: a lapozó eddig NYERSEN vette át a `page`/`take` értéket, tehát bármit.
        // A hívók viszont szoroznak vele (`take * active`), ami PHP 8-ban TypeError
        // nem-számra: egy `?page=abc` vagy `?page[]=1` HTTP 500-at adott MINDEN
        // keresőoldalon.
        //
        // A \Request::Integer* kivételt dob nem-számra — az szintén 500 lenne. Egy
        // elrontott lapszám viszont nem hiba, amiért az egész oldalt el kell dobni:
        // csendben az első lapra esünk vissza, ahogy a felhasználó várná.
        $page = \Request::get('page');
        if (is_numeric($page)) {
            $this->pagination->active = max(0, (int) $page);
        }

        $take = \Request::get('take');
        if (is_numeric($take) && (int) $take > 0) {
            $this->pagination->take = (int) $take;
        }
    }

    function getGitHash() {
        //GIT version        ;
        // exec('git rev-parse --verify --short HEAD 2> /dev/null', $v);
        // #652: abszolút út — relatívként csak akkor talált célba, ha a futó folyamat
        // munkakönyvtára épp a webapp volt (a webkiszolgálónál igen, CLI-ból nem).
        $gitHashFile = PATH . 'fajlok/git_hash';
        // Ellenőrizni, hogy a fájl létezik-e
        if (!file_exists($gitHashFile)) {
            return false;
        }   
        $v = file_get_contents($gitHashFile); // See: (.)git/hooks/post-checkout        
        // Csak az alfanumerikus karaktereket tartjuk meg a fájl tartalmából
        $v = preg_replace('/[^a-zA-Z0-9]/', '', $v);        
        //Validate short of git_hash
        if( preg_match('/^[a-zA-Z0-9]{7,8}$/i',$v,$match) ) {
            return $v;
        }
        return false;
    }
    
    static function printExceptionVerbose($e, $toString = false) {
        
        $return = "<strong>".$e->getMessage()."</strong>\n";        
        foreach ($e->getTrace() as $trace) {
            if (isset($trace['class']))
                $return .= $trace['class'] . "::" . $trace['function'] . "()";
            if (isset($trace['file']))
                $return .= $trace['file'] . ":" . $trace['line'] . " -> " . $trace['function'] . "()";
            $return .= "\n";
        }

        if(!$toString)
            echo "<pre>".$return."</pre>";
       
        return $return;
    }
    
    /**
     * Inline CSS files found in the HTML content.
     *
     * @param string $html The HTML content to process.
     * @return string The HTML content with CSS files inlined.
     */     	
    function inlineCssFiles($html) {
        // Keresd meg az összes <link> elemet, amely CSS fájlokat hivatkozik
        preg_match_all('/<link.*?href=["\'](.*?)["\'].*?rel=["\']stylesheet["\'].*?>/i', $html, $matches);

        // Az összes megtalált CSS fájl URL-je
        $cssFiles = $matches[1];

        // A CSS tartalmakat ide gyűjtjük
        $inlinedCss = '';

        foreach ($cssFiles as $cssFile) {
            // Teljes URL generálása, ha relatív útvonal
            $cssFilePath = $cssFile;
            if (strpos($cssFile, 'http') !== 0) {
                $cssFilePath = $_SERVER['DOCUMENT_ROOT'] . $cssFile;
            }

            // Ellenőrizzük, hogy a fájl létezik-e
            if (file_exists($cssFilePath)) {
                // A CSS fájl tartalmának beolvasása
                $cssContent = file_get_contents($cssFilePath);
                $inlinedCss .= "<style>\n" . $cssContent . "\n</style>\n";
            }
        }

        // Az összes <link> elem eltávolítása a HTML-ből
        $html = preg_replace('/<link.*?rel=["\']stylesheet["\'].*?>/i', '', $html);

        // Az inlined CSS hozzáadása a <head> részhez
        $html = preg_replace('/<\/head>/', $inlinedCss . '</head>', $html);

        return $html;
    }	
}
