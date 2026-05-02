<?php

namespace Api;

class Api {

    public $version;
    public $format = 'json';    
    public $return = array();
    public $fields = array();

    public function run() {
        $this->version = \Request::IntegerRequired('v');
        $this->validateVersionMain();
         

        $defaultDate = date('Y-m-d');
        $this->date = \Request::DatewDefault('datum', $defaultDate);
    }

    public function validateVersionMain() {
        if (!in_array($this->version, array(1, 2, 3, 4))) {
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
        if($input === null) {
            printr($this->input);
            $input = $this->input[$name];        
        }

        if($type == 'integer') {
            $this->validateInteger($name, $details, $input);
        } elseif($type == 'boolean') {
            if(!is_bool($input)) {
                throw new \Exception("Field '".$name."' should be a boolean.");
            }
        } elseif($type == 'date') {
            if(!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $input)) {
                throw new \Exception("Field '".$name."' should be a date (yyyy-mm-dd).");
            }
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

    public function validateFloat($field, $details, $input) {        
        if(!is_numeric($input) || floatval($input) != $input) {
            throw new \Exception("Field '".$field."' should be a float.");
        }
        if(isset($details['minimum']) && $input < $details['minimum']) {
            throw new \Exception("Field '".$field."' should be at least ".$details['minimum'].".");
        }
        if(isset($details['maximum']) && $input > $details['maximum']) {
            throw new \Exception("Field '".$field."' should be at most ".$details['maximum'].".");
        }
    }   

    public function validateInteger($fieldName, $details, $input) {        
        if(!is_numeric($input) || intval($input) != $input) {
            throw new \Exception("Field '".$fieldName."' should be an integer.");
        }
        if(isset($details['minimum']) && $input < $details['minimum']) {
            throw new \Exception("Field '".$fieldName."' should be at least ".$details['minimum'].".");
        }
        if(isset($details['maximum']) && $input > $details['maximum']) {
            throw new \Exception("Field '".$fieldName."' should be at most ".$details['maximum'].".");
        }
    }   

    public function validateString($field, $details, $input) {
        if(!is_string($input)) {
            throw new \Exception("Field '".$field."' should be a string.");
        }
        if(isset($details['minLength']) && strlen($input) < $details['minLength']) {
            throw new \Exception("Field '".$field."' should be at least ".$details['minLength']." characters long.");
        }
        if(isset($details['maxLength']) && strlen($input) > $details['maxLength']) {
            throw new \Exception("Field '".$field."' should be at most ".$details['maxLength']." characters long.");
        }
        if(isset($details['pattern']) && !preg_match('/'.$details['pattern'].'/', $input)) {
            throw new \Exception("Field '".$field."' does not match the required pattern.");
        }
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
                            $this->validateInteger($field, $validationRule);
                        } elseif($fieldType == 'float') {
                            $this->validateFloat($field, $validationRule);                            
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
