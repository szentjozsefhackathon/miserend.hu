<?php

class Path {
    public $url;
    public $arguments;
    public $className;

    public function __construct($path) {
        $this->url = strtolower($path);
        $this->convertAliases();
        $this->prepare();
    }

    public function prepare() {
        $this->arguments = array();
        $path = explode('/', $this->url);
        array_unshift($path, "html");
        for ($i = count($path) - 1; $i >= 0; $i--) {

            $file = implode("/", $path) . "/" . $path[$i];
            if (file_exists(PATH . 'classes/' . $file . ".php") AND $file != 'html/html') {
                $this->className = '\\' . preg_replace("/\//", "\\", $file);
                return;
            }
            $file = implode("/", $path);
            if (file_exists(PATH . 'classes/' . $file . ".php") AND $file != 'html/html') {
                $this->className = '\\' . preg_replace("/\//", "\\", $file);
                return;
            }
            array_unshift($this->arguments, $path[$i]);
            unset($path[$i]);
        }
        return false;
    }

    function convertAliases() {

        $replacementPatterns = [
            // #316: a régi /calendar/X URL-ek (Angular naptár-frontend) most már
            // az ajax/calendar/ namespace alá kerültek. Backward-compat alias,
            // hogy a kliens-oldali kód ne törjön a refactor után.
            ["^calendar\/(.+)$", "ajax/calendar/$1"],
            ["^templom\/([0-9]{1,5})\/widget$", "church/widget/$1"],
            // #36: nyomtatható miserend a templom ajtajára / plébániai sokszorosításra.
            ["^templom\/([0-9]{1,5})\/nyomtat$", "church/nyomtat/$1"],
            ["^templom\/([0-9]{1,5})\/javaslatok$", "church/suggestionpackages/$1"],
            ["^templom\/([0-9]{1,5})\/eszrevetelek$", "remark/list/$1"],
            ["^templom\/([0-9]{1,5})\/ujeszrevetel$", "remark/addform/$1"],
            ["^templom\/([0-9]{1,5})\/ujkep$", "uploadimage/$1"],
            ["^templom\/([0-9]{1,5})\/hierarchia$", "church/hierarchia/$1"],
            /*
             * #830: a plébánia-család közös miserendje saját útvonalon.
             *
             * Ugyanaz a lap, mint a `/templom/:id` — csak a naptár a fíliák miséit is
             * mutatja. borazslo kérése volt, hogy ne query-paraméter legyen belőle
             * („kéne egy szó hogy miserend.hu/[valami ügyes szó]/:id"), mert a
             * `?csalad=1` nem osztható meg jól és nem is beszédes.
             *
             * A SZÓ a `Church::CSALAD_UTVONAL` konstansban áll: ha a `kozosseg` vagy a
             * `hierarchy` nyer, ott egyetlen sort kell átírni, és a régi linkek sem
             * törnek el, mert a `?csalad=1` továbbra is működik.
             *
             * A gyökér nem mindig plébánia (olykor fília vagy oldallagosan ellátott),
             * de az URL nem adatmodell: a `/templom/:id` sem attól helyes, hogy minden
             * misézőhely templom.
             */
            ["^" . \Html\Church\Church::CSALAD_UTVONAL . "\/([0-9]{1,5})$", "church/$1"],
            ["^templom\/([0-9]{1,5})", "church/$1"],
            ["^templom\/list$", "church/catalogue"],
            ["^templom\/new", "church/edit"],
            ["^remark\/([0-9]{1,5})\/feedback", "email/remarkfeedback/$1"],
            ["^egyhazmegye\/list", "diocesecatalogue"],
            ["^impresszum$", "staticpage/impressum"],
            ["^gdpr$", "staticpage/gdpr"],
            ["^hazirend$", "staticpage/termsandconditions"],
            ["^terkep$", "map"],
            ["^$", "home"]
        ];
        foreach ($replacementPatterns as $replacementPattern) {
            $patterns[] = "/" . $replacementPattern[0] . "/i";
            $replacements[] = $replacementPattern[1];
        }
        $this->url = preg_replace($patterns, $replacements, $this->url);
    }

}
