<?php

class Request {

    // #393: a "mi számít egésznek/dátumnak" tudás a közös \Validate-ben él, ugyanazt
    // használja az \Api validate* is. A hibaüzenetek itt szándékosan a megszokottak
    // maradnak, hogy a hívók viselkedése ne változzon.
    static function Integer($name) {
        $value = self::get($name);
        if ($value <> '' AND \Validate::integerError($value) !== null) {
            throw new Exception("Required '$name' is not an Integer.");
        }
        return $value;
    }

    static function IntegerRequired($name) {
        $value = self::getRequired($name);
        if (\Validate::integerError($value) !== null) {
            throw new Exception("Required '$name' is not an Integer.");
        }
        return $value;
    }

    static function IntegerwDefault($name, $default = false) {
        $value = self::getwDefault($name, $default);
        if (\Validate::integerError($value) !== null) {
            throw new Exception("Required '$name' is not an Integer.");
        }
        return $value;
    }

    static function Text($name) {
        $value = self::get($name);
        $value = sanitize($value);
        return $value;
    }

    static function TextFromGet($name) {
        $value = isset($_GET[$name]) ? $_GET[$name] : false;
        $value = sanitize($value);
        return $value;
    }

    static function TextwDefault($name, $default = false) {
        $value = self::getwDefault($name, $default);
        $value = sanitize($value);
        return $value;
    }

    static function TextRequired($name) {
        $value = self::getRequired($name);
        $value = sanitize($value);
        return $value;
    }
    
    static function InArray($name, $array) {
        $value = self::get($name);
        if(!$value) return false;
        
        if(!in_array($value, $array)) {
            throw new Exception("Array '$name' is not in Array.");
        }
        return $value;
    }
    
    static function InArrayRequired($name, $array) {
        $value = self::get($name);
        if(!in_array($value, $array)) {
            throw new Exception("Required '$name' is not in Array.");
        }
        return $value;
    }

    static function Simpletext($name) {
        $value = self::get($name);
        if ($value != '' AND ! preg_match('/^[0-9a-zA-Z_-]+$/i', $value)) {
            throw new Exception("Variable '$name' is not a SimpleText.");
        }
        return $value;
    }

    static function SimpletextwDefault($name, $default = false) {
        $value = self::getwDefault($name, $default);
        if ($value != '' AND ! preg_match('/^[0-9a-zA-Z_-]+$/i', $value)) {
            throw new Exception("Variable '$name' is not a SimpleText.");
        }
        return $value;
    }

    static function SimpletextRequired($name) {
        $value = self::getRequired($name);
        if (!preg_match('/^[0-9a-zA-Z_-]+$/i', $value)) {
            throw new Exception("Required '$name' is not a SimpleText.");
        }
        return $value;
    }

    static function IntegerArray($name) {
        $value = self::get($name);
        if (!$value) return false;
        
        if (!is_array($value)) {
            throw new Exception("'$name' is not an Array.");
        }
        
        foreach ($value as $item) {
            if (!is_numeric($item)) {
                throw new Exception("Array '$name' contains non-integer values.");
            }
        }
        
        return $value;
    }

    static function IntegerArrayRequired($name) {
        $value = self::get($name);
        
        if (!$value) {
            throw new Exception("Required '$name' is missing.");
        }
        
        if (!is_array($value)) {
            throw new Exception("Required '$name' is not an Array.");
        }
        
        foreach ($value as $item) {
            if (!is_numeric($item)) {
                printr($item);
                throw new Exception("Required Array '$name' contains non-integer values.");
            }
        }
        
        return $value;
    }

    static function StringArray($name) {
        $value = self::get($name);
        if (!$value) return false;
        
        if (!is_array($value)) {
            throw new Exception("'$name' is not an Array.");
        }
        
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new Exception("Array '$name' contains non-string values.");
            }
        }
        
        return $value;
    }

    static function StringArrayRequired($name) {
         $value = self::get($name);
         
         if (!$value) {
             throw new Exception("Required '$name' is missing.");
         }
         
         if (!is_array($value)) {
             throw new Exception("Required '$name' is not an Array.");
         }
         
         foreach ($value as $item) {
             if (!is_string($item)) {
                 throw new Exception("Required Array '$name' contains non-string values.");
             }
         }
         
         return $value;
     }

    static function ArrayArray($name) {
        $value = self::get($name);
        if ($value === false) return false;
        
        if (!is_array($value)) {
            throw new Exception("'$name' is not an Array.");
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new Exception("Array '$name' contains non-array values.");
            }
        }
        
        return $value;
    }

    static function ArrayArraywDefault($name, $default = []) {
        $value = self::getwDefault($name, $default);
        
        if (!is_array($value)) {
            throw new Exception("'$name' is not an Array.");
        }
        
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new Exception("Array '$name' contains non-array values.");
            }
        }
        
        return $value;
    }

    static function Boolean($name) {
        $value = self::get($name);
        if (!$value) return false;
        
        if (!is_bool($value) && $value !== '1' && $value !== '0' && $value !== 1 && $value !== 0 && $value !== 'true' && $value !== 'false' && $value !== '') {
            throw new Exception("'$name' is not a Boolean.");
        }
        
        return (bool)$value;
    }

    /**
     * #391: pontosvesszővel (vagy megadott elválasztóval) tagolt float-lista.
     * Hiányzó/üres bemenetre `false` (nem hiba — a hívó csendben kilép).
     * Ha $count meg van adva, a darabszámot is ellenőrzi. Minden elemnek
     * számnak kell lennie, egyébként Exception. Visszatérés: float-ök tömbje.
     */
    static function FloatList($name, $count = null, $separator = ';') {
        $value = self::get($name);
        if ($value === false || $value === '' || $value === null) {
            return false;
        }
        $parts = array_map('trim', explode($separator, $value));
        if ($count !== null && count($parts) != $count) {
            throw new Exception("'$name' must be a list of $count numbers separated by '$separator'.");
        }
        $floats = [];
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                throw new Exception("List '$name' contains a non-numeric value.");
            }
            $floats[] = (float) $part;
        }
        return $floats;
    }

    /**
     * #391: bounding box — pontosvesszős 4-float lista (a térkép-ajaxokhoz).
     * Hiányzó vagy rossz alakú bbox → `false`, hogy a hívó némán kiléphessen
     * (a korábbi inline `count()!=4` / `is_numeric` őrökkel egyező viselkedés).
     */
    static function Bbox($name) {
        try {
            return self::FloatList($name, 4);
        } catch (Exception $e) {
            return false;
        }
    }

     static function validateDateFormat($value) {
        // #393: a naptár-tudatos ellenőrzés a közös \Validate-be került, hogy az API is
        // ugyanazt használja (az ott korábban puszta reguláris kifejezés volt).
        return \Validate::isDate($value);
    }

    static function Date($name) {
        $value = self::get($name);
        if ($value != '' && !self::validateDateFormat($value)) {
            throw new Exception("Required '$name' is not a Date in YYYY-mm-dd format.");
        }
        return $value;
    }

    static function DateRequired($name) {
        $value = self::getRequired($name);
        if (!self::validateDateFormat($value)) {
            throw new Exception("Required '$name' is not a Date in YYYY-mm-dd format.");
        }
        return $value;
    }

    static function DatewDefault($name, $default = false) {
        $value = self::getwDefault($name, $default);
        if ($value !== false && !self::validateDateFormat($value)) {
            throw new Exception("Required '$name' is not a Date in YYYY-mm-dd format.");
        }
        return $value;
    }

    static function getwDefault($name, $default = false) {
        if ($value = self::get($name)) {
            return $value;
        } else {
            return $default;
        }
    }

    static function getRequired($name) {
        if (!$value = self::get($name)) {
            throw new Exception("Required '$name' is required.");
        } else {
            return $value;
        }
    }

    /**
     * #391: egy űrlap-mezőcsoport (többdimenziós $_REQUEST-tömb) beolvasása.
     *
     * A `church[...]`, `edituser[...]`, `relationship[...]` alakú mezőcsoportokat a
     * feldolgozó kód egyben fogyasztja (`submit($vars)`), ezért nem bontható mezőnkénti
     * \Request:: hívásokra. Az viszont kiváltható, hogy nyersen a szuperglobálishoz
     * nyúljunk: itt egyetlen belépési pont van, ami ELLENŐRZI, hogy tömböt kaptunk-e.
     *
     * Az értékeket SZÁNDÉKOSAN nem alakítjuk át: a mezőnkénti validáció a hívóé
     * (User::submit() -> presave()), és egy vak sanitize() elrontaná például a
     * jelszót, ami tartalmazhat `<` karaktert.
     *
     * @return array|false  a mezőcsoport MÁSOLATA, vagy false ha nincs / nem tömb
     */
    static function Fields($name) {
        $value = self::get($name);
        if ($value === false || !is_array($value)) {
            return false;
        }
        return $value;
    }

    /**
     * #391: a teljes kérés. A html/ réteg `$this->input`-ja épül rá.
     *
     * Nem szanál — pontosan azt adja, amit eddig a nyers $_REQUEST —, de egyetlen
     * helyre gyűjti az olvasást: innentől statikusan kereshető, és ha valaha
     * szűrni/naplózni akarjuk a bejövő kérést, egy helyen kell megtenni.
     */
    static function all(): array {
        return $_REQUEST;
    }

    static function get($name) {
         // Ellenőrizzük, hogy a kulcs tömbszerű-e (pl. church[lat])
        if (strpos($name, '[') !== false && strpos($name, ']') !== false) {
            // A kulcs feldarabolása tömbszerű kulcsokra
            $keys = explode('[', str_replace(']', '', $name));
            $value = $_REQUEST;

            // Bejárjuk a kulcsokat, hogy elérjük a megfelelő értéket
            foreach ($keys as $key) {
                if (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return false; // Ha bármelyik kulcs nem létezik, false-t adunk vissza
                }
            }
            return $value;
        } else {
            // Egyszerű kulcsok kezelése
            return isset($_REQUEST[$name]) ? $_REQUEST[$name] : false;
        }     
    }

}
