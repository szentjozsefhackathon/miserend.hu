<?php

class Translator {
    private static $translations = [];
    private static $inited = false;

    public function __construct($lang = null)
    {
        // backward-compatible: allow creating an instance but delegate to static init
        self::init($lang);
    }

    /**
     * A szótár helye.
     *
     * #751: a `webapp/i18n/` a naptár-build kimenete — a `calendar_deploy.py`
     * másolja oda a `calendar/public/i18n/`-ből, hogy a böngésző HTTP-n el tudja
     * érni. Emiatt már nincs verziókövetve, a PHP viszont ugyanezt a szótárat
     * használja (a kategória-szűrő innen kapja a magyar mise-címeket, #299).
     * Ezért ELŐSZÖR a forrást nézzük — így a fordítás egy helyen él, és friss
     * klónban, build nélkül is működik. A `webapp/i18n/` csak tartalék.
     */
    private static function dictionaryPath(string $lang): ?string
    {
        $candidates = [
            __DIR__ . '/../../calendar/public/i18n/' . $lang . '.json',
            __DIR__ . '/../i18n/' . $lang . '.json',
        ];
        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }
        return null;
    }

    public static function init($lang = null)
    {
        if (self::$inited) {
            return;
        }

        if (!$lang) {
            $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
            if (preg_match('/^([a-zA-Z-]+)/', $accept, $m)) {
                $lang = explode('-', $m[1])[0];
            } else {
                $lang = 'en';
            }
        }

        $lang = preg_replace('/[^a-z]/', '', strtolower($lang)) ?: 'en';
        $path = self::dictionaryPath($lang);

        // fallback to English if requested language file is missing
        if ($path === null) {
            $fallback = self::dictionaryPath('en');
            if ($fallback !== null) {
                $path = $fallback;
            } else {
                // nothing to load, mark initialized to avoid repeated attempts
                self::$inited = true;
                return;
            }
        }

        $json = @file_get_contents($path);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                self::$translations = array_merge(self::$translations, $data);
            }
        }

        self::flattenTranslations();
        self::$inited = true;
    }

    public static function translate($key, $default = null)
    {        
        if(is_array($key)) {
            foreach($key as &$k) {
                $k = self::translate($k, $default);
            }            
            return $key;
        }
        else 
            return self::$translations[$key] ?? ($default ?? $key);
    }

    private static function flattenTranslations() {
        // Flatten nested translation arrays into dot-separated keys
        $flattened = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator(self::$translations));
        foreach ($iterator as $value) {
            $keys = [];
            for ($depth = 0; $depth <= $iterator->getDepth(); $depth++) {
                $keys[] = $iterator->getSubIterator($depth)->key();
            }
            $flatKey = implode('.', $keys);
            $flattened[$flatKey] = $value;
        }
        self::$translations = $flattened;
    }
}