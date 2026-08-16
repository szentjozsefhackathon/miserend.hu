<?php

namespace Api;

class Api {

    public $version;
    public $format = 'json';    
    public $return = array();
    public $fields = array();
    public $input = [];
    public $requiredVersion;
    public $requiredFields;
    public $date;

    public function run() {
        $this->version = \Request::IntegerRequired('v');
        $this->validateVersionMain();
         

        $defaultDate = date('Y-m-d');
        $this->date = \Request::DatewDefault('datum', $defaultDate);
    }

    /**
     * #56: a legmagasabb kiadott API-verzió.
     *
     * Az v5 ugyanaz a boríték és ugyanazok a végpontok, mint a v4 — csak a mise-adat
     * lesz strukturált, és a képek rövid úton jönnek. A régebbi verziók válasza
     * VÁLTOZATLAN, hogy a meglévő kliensek (KAPP) a saját ütemükben állhassanak át.
     */
    const LEGUJABB_VERZIO = 5;

    public function validateVersionMain() {
        // Laza összehasonlítás: a \Request::IntegerRequired() sztringet ad vissza, és a
        // szigorú változat emiatt a meglévő verziókat is elutasítaná.
        if (!in_array($this->version, range(1, self::LEGUJABB_VERZIO))) {
            throw new \Exception("Invalid API version.");
        }

        // Each endpoint can have 'requiredVersion' property to specify the minimum or maximum version required
        if(isset($this->requiredVersion))  {
            if (is_array($this->requiredVersion)) {
                if (!version_compare($this->version, $this->requiredVersion[1], $this->requiredVersion[0] )) {
                    throw new \Exception("API version (".$this->version.") does not match the required version: '".$this->requiredVersion[0]."".$this->requiredVersion[1]."'.");
                }
            } else {            
                throw new \Exception("Invalid requiredVersion for API endpoint.");                
            }

        }

        // Each endpoint can have its own version validation
        if (method_exists($this, 'validateVersion')) {
            $this->validateVersion();
        }
    }

    public function getInputJson() {
        if (!$inputJSONstring = file_get_contents('php://input')) {
            throw new \Exception("There is no JSON input.");
        }
        
        $inputJSONarray = json_decode($inputJSONstring, TRUE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON input. " . json_last_error_msg());
        }

        $this->input = $inputJSONarray;


        
        // Check for unknown fields
        // Be aware that $this->fields can contain 'field/subfield' - that is, hierarchical fields. But we are not checking that here.
        // TODO: We should check hierarchical fields too. Even though "lorawan.php" has a lot of such fields, it does not use this check.
        foreach ($this->input as $key => $value) {
            if (!isset($this->fields[$key])) {
                throw new \Exception("Unknown field '".$key."' in JSON input.");
            }
        }

        if(isset($this->fields)) {
            foreach($this->fields as $field => $details) {
            
                // A $this->fields-ben lehet olyat definiálni hogy 'field/subfield' - azaz alá-fölé rendeltség
                $parts = explode('/', $field);
                $ref = $this->input;
                foreach ($parts as $part) {
                    if (!isset($ref[$part])) {
                        $ref = null;
                        break;
                    }
                    $ref = $ref[$part];
                }
                $inputValue = $ref;
                
                // Check required fields
                if(isset($details['required']) && $details['required'] === true) {
                    $this->requiredInput([$field]);
                }

                // If field is not set, skip validation
                if($inputValue === null) {
                    continue; 
                }

                // Prepare validation rules
                if(!isset($details['validation'])) {
                    $details['validation'] = [];
                }
                if(!is_array($details['validation'])) {
                    $details['validation'] = [$details['validation'] => []];
                }
                foreach($details['validation'] as $key => $value) {
                    if(!is_array($value)) {
                        $details['validation'][$key] = [$value => []];
                    }
                }                
                //Check validation rules
                foreach($details['validation'] as $function => $details) {
                    $this->validateVariable($function, $field, $details, $inputValue);
                }
            
                if(isset($details['validation']) && method_exists($this, 'validateField')) {
                    $this->validateField($field, $details['validation']);
                }                
            }
        }   

        if(isset($this->requiredFields)) {
            $this->requiredInput($this->requiredFields);
        }
        if (method_exists($this, 'validateInput')) {
            $this->validateInput();
        }
    }
    
    public function requiredInput($fields) {
        foreach($fields as $field) {
            $parts = explode('/', $field);
            $ref = $this->input;
            foreach ($parts as $part) {
                if (!isset($ref[$part])) {
                    throw new \Exception("Field '".$field."' is required in JSON.");
                }
                $ref = $ref[$part];
            }
        }
    }

    public function validateVariable($type, $name, $details, $input = null) {
        // #182-szerű leak eltávolítva: egy null lista-elem (pl. add:[1,null,2]) eddig
        // a printr($this->input)-on át a TELJES request-payloadot a válaszba echózta
        // (JSON-szennyezés + belső adat szivárgás), és a null-t az egész tömbre cserélte
        // (rossz hiba-címke). Mindkét hívó (getInputJson, list-rekurzió) átadja a 4.
        // argumentumot, így a valódi null egyszerűen a lenti validateInteger-en akad fenn,
        // tiszta "Field ... should be an integer." hibával, szivárgás nélkül.

        if($type == 'integer') {
            $this->validateInteger($name, $details, $input);
        } elseif($type == 'boolean') {
            $this->throwIf($name, \Validate::booleanError($input));
        } elseif($type == 'date') {
            // #393: eddig puszta reguláris kifejezés volt, ezért az API elfogadta a
            // 2023-02-29-et, a 2023-02-31-et és a 2026-04-31-et is — miközben ugyanezeket
            // a \Request naptár-tudatos ellenőrzése helyesen visszautasította.
            $this->throwIf($name, \Validate::dateError($input));
        } elseif($type == 'timestamp') {
            $this->throwIf($name, \Validate::timestampError($input));
        } elseif($type == 'email') {
            $this->throwIf($name, \Validate::emailError($input));
        } elseif($type == 'float') {
            $this->validateFloat($name, $details, $input);
        } elseif($type == 'string') {
            $this->validateString($name, $details, $input);
        } elseif($type == 'enum') {
            $this->validateEnum($name, $details, $input);
        
        } elseif($type == 'list') {                        
            if(!is_array($input)) {
                throw new \Exception("Field '".$name."' should be a list/array.");
            }                        
            foreach($input as $item) {                            
                foreach($details as $function => $detail) {
                    $this->validateVariable($function, $name, $detail, $item);                                
                }                
            }       
        } else {
            throw new \Exception("Unknown validation type '".$type."' for field '".$name."'.");
        }
    }

    /**
     * #393: a tényleges ellenőrzés a közös \Validate-ben él (ugyanazt a tudást a
     * \Request is használja). Itt már csak a hibaüzenet formája marad, hogy az API
     * válaszai változatlanok legyenek.
     */
    private function throwIf($field, ?string $error) {
        if ($error !== null) {
            throw new \Exception("Field '".$field."' ".$error);
        }
    }

    public function validateFloat($field, $details, $input) {
        $this->throwIf($field, \Validate::floatError($input, (array) $details));
    }

    public function validateInteger($fieldName, $details, $input) {
        $this->throwIf($fieldName, \Validate::integerError($input, (array) $details));
    }

    public function validateString($field, $details, $input) {
        $this->throwIf($field, \Validate::stringError($input, (array) $details));
    }

    public function validateEnum($field, $details, $input) {
        $return = false;
        foreach($details as $key => $value) {
            // Simple value
            if(!is_array($value) && $input === $value) {
                $return = true;
                break;
            }
            // Value with validation rules
            if(is_array($value)) {
                foreach($value as $fieldType => $validationRule) {
                    $details[$key] = json_encode($value);
                    try {
                        if($fieldType == 'integer') {
                            // #374: hiányzott a 3. ($input) argumentum -> ArgumentCountError
                            // (\Error, amit a catch(\Exception) nem fog el), így minden
                            // típusos-szabályú enum elszállt volna. Az $input a validateEnum
                            // paramétere, itt átadjuk.
                            $this->validateInteger($field, $validationRule, $input);
                        } elseif($fieldType == 'float') {
                            $this->validateFloat($field, $validationRule, $input);
                        } elseif($fieldType == 'string') {
                            if(!is_string($input)) {
                                throw new \Exception("Field '".$field."' should be a string.");
                            }                            
                        } elseif($fieldType == 'date') {
                            if(!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $input)) {
                                throw new \Exception("Field '".$field."' should be a date (yyyy-mm-dd).");
                            }
                        } elseif($fieldType == 'boolean') {
                            if(!is_bool($input)) {
                                throw new \Exception("Field '".$field."' should be a boolean.");
                            }       
                        } else {
                            throw new \Exception("Unknown enum validation type '".$fieldType."' for field '".$field."'.");
                        }
                        $return = true;                        
                        break;
                    } catch (\Exception $e) {
                        // Do nothing, try next
                    }
                }                 
                
            }

        }

        if(!$return) {            
            throw new \Exception("Field '".$field."' should be one of: ".implode(", ", $details).".");
        }
    }

    /**
     * Összegyűjti az összes API endpoint osztály nevét.
     * Azaz visszaadja a classes/api könyvtárban található összes PHP fájl által ténylegesen definiált osztály nevét (kivéve api.php).
     *
     * @return array Az endpoint osztályok nevei (string)
     */
    public static function collectApiEndpoints(?string $dir = null) {
        $dir = $dir ?? (__DIR__ . '/');
        $dir = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $files = scandir($dir);
        $result = [];
        $filesIncluded = get_included_files();
        
        foreach ($files as $file) { 
            if(in_array($dir . $file, $filesIncluded)) {
                continue; // Skip files that are already included
            }
            if (substr($file, -4) === '.php' && $file !== 'api.php') {
                $before = get_declared_classes();
                try {
                  include_once($dir . $file);
                } catch (\Throwable $e) {                    
                    throw new \Exception("Error including API endpoint file '$file'.");
                } 
                $after = get_declared_classes();
                $new = array_diff($after, $before);
                if(count($new) == 0) {
                    throw new \Exception("No new class found in API endpoint file '$file'.");
                } else if(count($new) > 1) {
                    throw new \Exception("Multiple new classes found in API endpoint file '$file'. This is not allowed.");
                } else {  
                    $class = preg_replace('/^Api\\\/', '', reset($new));                  
                    if (strtolower($class).".php" != $file)
                    {
                        throw new \Exception("The class name '$class' in file '$file' does not match the expected format. The class name should be the same as the file name (without .php).");
                    }
                    
                    $result[] = $class;
                }
            }
        }
                
        sort($result);
        return $result;
    }

}
