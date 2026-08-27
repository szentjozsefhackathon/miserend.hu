<?php

namespace Api;

class Upload extends Api {

    public $title = 'Fénykép feltöltése templomokhoz';
    public $photo; // Photo object
    public $requiredVersion = ['>=',4]; // API v4-től érhető el

    public $fields = [
        'tid' => [
            'required' => true, 
            'validation' => 'integer', 
            'description' => 'A templom azonosítója, ahová a fényképet feltöltjük.',
            'example' => 7
        ],
        'photo' => [
            'required' => true, 
            'validation' => 'string', 
            'description' => 'A fénykép fájl streamje base64 kódolású stringben a megfelelő <code>data:image/jpeg;base64,</code> előtaggal. PHP-ban ez így állítható elő: <code>$photo = \'data:\'.$_FILES[\'fileToUpload\'][\'type\'].\';base64,\'.base64_encode(file_get_contents($_FILES[\'fileToUpload\'][\'tmp_name\']));</code>',
            'example' => 'data:image/jpeg;base64,...'            
        ]
    ];
        
     public function docs() {

        $docs = [];
               
        $docs['description'] = <<<HTML
        <p>Lehetséges fénykép beküldése bármelyik templomhoz. A beküldött képek jelenleg azonnal megjelennek a honlapon. Ez változhat majd, hogy nem regisztrált felhasználóknak csak jóváhagyás után jelenik meg a fényképük. JSON formátumba kell küldeni az adatokat és JSON formátumban válaszol az API.</p>
        <p><strong>Fontos</strong> felhívni a beküldő figyelmét, hogy a fénykép feltöltésével <strong>a)</strong> a fotó jogos tulajdonosának jelenti ki magát <strong>b)</strong> a kép felhasználói jogait teljesen (de nem kizárólagosan) átadja a miserend.hu-nak (és a hozzá tartozó alkalmazásoknak).</p>

        <p>A kép formátuma lehet <code>image/jpg</code>, <code>image/jpeg</code>, <code>image/gif</code>, <code>image/png</code>. Van felső méret korlát is.</p>
        HTML;

        $docs['response'] = <<<HTML
        <ul>
            <li>„error”: <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
            <li>„text” (opcionális): „error:1” esetén a hiba szöveges leírása</li>
        </ul>
        HTML;

        return $docs;
    }


    public function run() {
        try {
            parent::run();
            $this->getInputJson();
            
            //Decoda and Validate ImageData
            $image = $this->decodeImage($this->input['photo']);
            
            $imagesize = getimagesize($image['tmp_name']);
            if(!$imagesize) {
                throw new \Exception("File is not a valid image.");
            }
            
            $this->photo = new \Eloquent\Photo();
            $_SERVER['HTTP_X_REQUESTED_WITH'] = true; //I do not know why I need it. See Eloquent/photo.php
            $this->photo->church_id = $this->input['tid'];
            $this->photo->flag = 'n'; // Set default flag as 'n' = normal (not featured) 
            $this->photo->weight = 0; // Set default weight
            $this->photo->uploadFile($image);
            
            // Additional debug info in response
            $this->return['text'] = 'Köszönjük a képet. Elmentettük.';
            $this->return['photo_id'] = $this->photo->id;
            $this->return['church_id'] = $this->photo->church_id;
            $this->return['filename'] = $this->photo->filename;
            
        } catch (\Exception $e) {
            // Set error response with detailed message
            // #893: nincs `debug_info` a válaszban — a fájlnév, a sorszám és a hívási
            // lánc a naplóba való, nem a hitelesítés nélkül hívható végpont válaszába.
            // (L. #860: ugyanez a szivárgás már egyszer kikerült ebből a fájlból.)
            $this->return['error'] = '1';
            $this->return['text'] = $e->getMessage();

            // Set HTTP status code to 400 (Bad Request) instead of 500
            http_response_code(400);
            
            // Log the error for debugging
            error_log("API Upload Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }
    
    private function decodeImage($data) {
        if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
            $data = substr($data, strpos($data, ',') + 1);
            $imgtype = strtolower($type[1]); // jpg, png, gif

            if (!in_array($imgtype, [ 'jpg', 'jpeg', 'gif', 'png' ])) {
                throw new \Exception('invalid image type: ' . $imgtype);
            }

            $data = base64_decode($data);

            if ($data === false) {
                throw new \Exception('base64_decode failed');
            }
            
            try {
                $tmp_name = tempnam(sys_get_temp_dir(), 'FOO'); 
                file_put_contents($tmp_name, $data);
            }
            catch(\Exception $e){
                throw new \Exception("Temporary file cannot saved.");
            }
            
            // Convert the short image type to MIME type for compatibility
            $mime_type = 'image/' . ($imgtype === 'jpg' ? 'jpeg' : $imgtype);
            
            return array(
                'name' => 'temporaryImageFile.' . $imgtype,
                'type' => $mime_type,
                'tmp_name' => $tmp_name,
                'error' => 0,
                'size' => filesize($tmp_name)
            );
        } else {
            throw new \Exception('did not match data with image data');
        }        
    }

}
