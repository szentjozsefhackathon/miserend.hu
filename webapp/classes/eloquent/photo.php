<?php

namespace Eloquent;

class Photo extends \Illuminate\Database\Eloquent\Model {

    protected $table = 'photos';
    public $timestamps = true; // Enable automatic timestamp handling
    
    protected $fillable = ['church_id', 'filename', 'title', 'weight', 'flag', 'height', 'width'];
    
    protected $urlToPhotos = '/kepek/templomok';
    protected $appends = array('pathToPhoto', 'url', 'smallUrl');

    public function church() {
        return $this->belongsTo('\Eloquent\Church');
    }

    public function scopeOrdered($query) {

        return $query->orderBy('flag')
                        ->orderByRaw("CASE WHEN height/width > 1 THEN 1 ELSE 0 END desc")
                        ->orderBy("weight")
                        ->orderBy("id");
    }

    public function scopeBig($query) {
        return $query->where(function ($query) {
                    $query->where('width', '>', 100)
                            ->where('height', '>=', 600);
                });
    }

    public function scopeVertical($query) {
        return $query->where('height', '>', 'width');
    }

    public function getUrlAttribute($value) {
        return $this->urlToPhotos . "/" . $this->church_id . "/" . $this->filename;
    }

    public function getPathToPhotosAttribute($value) {
        return PATH . 'kepek/templomok';
    }

    public function getSmallUrlAttribute($value) {
        return $this->urlToPhotos . "/" . $this->church_id . "/kicsi/" . $this->filename;
    }

    public function delete() {
        if ($this->attributes['filename']) {
            $file = $this->pathToPhotos . "/" . $this->church_id . "/" . $this->filename;
            if (file_exists($file)) {
                unset($file);
            }
            $file = $this->pathToPhotos . "/" . $this->church_id . "/kicsi/" . $this->filename;
            if (file_exists($file)) {
                unset($file);
            }
        }
        parent::delete();
    }

    /*/
     * Ha nincs a kép méretéről adat, akkor kitaláljuk és elmentjük.
     * @return boolean
     */
    function updateSize() {
        $file = "kepek/templomok/" . $this->church_id . "/" . $this->filename;

        if (file_exists($file)) {
            if (preg_match('/(jpg|jpeg)$/i', $file)) {
                $src_img = @\imagecreatefromjpeg($file);
                if ($src_img !== false) {
                    $this->height = @\imagesy($src_img);  # original height
                    $this->width = @\imagesx($src_img);  # original width
                    \imagedestroy($src_img); // Free memory
                    if ($this->height != '' AND $this->width != '') {
                        $this->save();
                        return true;
                    } else {
                        echo "A képnek nincs mérete: ". $file . "<br>\n";
                    }
                } else {
                    echo "A kép nem olvasható: " . $file . "<br>\n";
                }
            } else {            
                    echo "A kép nem jpg: " . $file . "<br>\n";
            }
        } else {            
                echo "Hiányzó kép: " . $file . "<br>\n";
        }
        return false;        
    }
    
    function uploadFile($inputFile) {
        // Check for upload errors, but be more flexible for API uploads
        if (isset($inputFile["error"]) && $inputFile["error"] != UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
            ];
            $error_msg = isset($error_messages[$inputFile["error"]]) ? 
                $error_messages[$inputFile["error"]] : 
                "Unknown upload error: " . $inputFile["error"];
            throw new \Exception($error_msg);
        }
        if (!isset($this->church_id)) {
            throw new \Exception("There is no `church_id` yet.");
        }
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            throw new \Exception('Missing AJAX header. Invalid request.');
        }
        
        // Debug: log file information for troubleshooting
        error_log("Photo upload debug: " . json_encode([
            'name' => $inputFile['name'] ?? 'N/A',
            'size' => $inputFile['size'] ?? 'N/A',
            'type' => $inputFile['type'] ?? 'N/A',
            'error' => $inputFile['error'] ?? 'N/A',
            'tmp_name_exists' => isset($inputFile['tmp_name']) ? file_exists($inputFile['tmp_name']) : false
        ]));
        
        // Get dynamic file size limit
        $maxFileSize = $this->getMaxFileSize();
        if ($inputFile["size"] > $maxFileSize) {
            $maxSizeMB = round($maxFileSize / 1024 / 1024, 2);
            $actualSizeMB = round($inputFile["size"] / 1024 / 1024, 2);
            throw new \Exception("File size is too big! Maximum allowed: {$maxSizeMB} MB, uploaded: {$actualSizeMB} MB");
        }
        /*
         * #709: a típust a FÁJL TARTALMÁBÓL állapítjuk meg, nem abból, amit a
         * kliens állít magáról.
         *
         * Eddig két hiba ért össze, és távoli kódfuttatás lett belőle:
         *   1. az ellenőrzés az $inputFile['type']-ot nézte, ami a kliens által
         *      küldött Content-Type — bármit be lehet írni oda;
         *   2. a kiterjesztés a kliens FÁJLNEVÉBŐL jött, változtatás nélkül,
         *      tehát a .php megmaradt.
         * A fájl így a web által kiszolgált kepek/ könyvtárba került .php néven,
         * ahol az Apache lefuttatta. Kimértem: a feltöltött PHP tényleg lefutott.
         *
         * Mostantól a getimagesize() dönt (a MOZGATÁS ELŐTT), és a kiterjesztést
         * is a felismert képtípusból képezzük — a kliens fájlnevéből semmit nem
         * veszünk át.
         *
         * FIGYELEM: a getimagesize() önmagában nem elég. Egy „polyglot" fájl
         * lehet érvényes GIF-fejlécű ÉS futtatható PHP egyszerre. Ezért a
         * kiszolgálás oldalán is van védelem (webapp/kepek/.htaccess és a vhost):
         * a képkönyvtárból csak kép-kiterjesztésű fájl adható ki. A kettő együtt
         * véd; egyik sem hagyható el.
         */
        $safeExtension = self::safeExtensionFor($inputFile['tmp_name']);
        $konyvtar = $this->pathToPhotos . "/" . $this->church_id;
        if (!is_dir("$konyvtar")) {
            if (!mkdir("$konyvtar", 0775)) {
                throw new \Exception("Could not create the folder.");
            }
            if (!mkdir("$konyvtar/kicsi", 0775)) {
                throw new \Exception("Could not create the folder.");
            }
        }
        if (!is_writable($konyvtar)) {
            throw new \Exception("Upload directory is not writable.");            
        }
        // #709: a kiterjesztés a FELISMERT képtípusból jön, nem a kliens fájlnevéből.
        $Random_Number = rand(0, 9999999999); //Random number to be added to name.
        $this->filename = $Random_Number . $safeExtension; //new file name
                
        // Handle both regular uploads and API uploads (temporary files)
        $target_path = $konyvtar . "/" . $this->filename;
        $move_success = false;
        
        // Check if this is a real uploaded file or a temporary file (from API)
        if (\is_uploaded_file($inputFile['tmp_name'])) {
            // This is a real uploaded file, use move_uploaded_file
            $move_success = \move_uploaded_file($inputFile['tmp_name'], $target_path);
        } else {
            // This is a temporary file (from API), use rename or copy
            $move_success = \rename($inputFile['tmp_name'], $target_path);
        }
        
        if (!$move_success) {
            $exception = "Could not move the file to its new place.";
            if(!file_exists($inputFile['tmp_name'])) 
                $exception .= " Because ".$inputFile['tmp_name']." does not exists";
            if(file_exists($target_path))
                    $exception .= " Because ".$target_path." already exists.";
            if(!is_writable(dirname($target_path)))
                    $exception .= " Because ".dirname($target_path)." is not writable.";
            // #860: itt egy `\printr($inputFile)` állt, ami a nyers $_FILES bejegyzést —
            // benne a szerver ideiglenes útvonalával — a válaszba echózta. A konkrét okot
            // az $exception fentebb amúgy is tartalmazza, ez csak szivárgás volt.
            throw new \Exception($exception);
        }

        $kimenet = $target_path;
        $kimenet1 = $konyvtar . "/kicsi/" . $this->filename;

        /*
         * #709: ha a feldolgozás elhasal, NE maradjon ott a fájl.
         *
         * A getimagesize() átenged olyat is, amit a GD utána már nem tud
         * megnyitni — tipikusan a „polyglot" fájlokat, amik érvényes képfejlécet
         * hordoznak, de mögötte más van. Eddig ilyenkor a kicsinyítés dobott egy
         * belső GD-hibát, a fájl viszont már kint volt a lemezen, adatbázis-sor
         * nélkül. Kimértem: pontosan ez történt.
         *
         * Kép-kiterjesztéssel ez önmagában nem futtatható, de akkor sem hagyjuk
         * ott: takarítunk, és érthető hibát adunk.
         */
        try {
            $info = \getimagesize($kimenet);
            if ($info === false) {
                throw new \Exception("A feltöltött fájl mégsem olvasható képként.");
            }
            $this->width = $info[0];
            $this->height = $info[1];

            if ($this->width > 1200 or $this->height > 800)
                $this->kicsinyites($kimenet, $kimenet, 1200);
            $this->kicsinyites($kimenet, $kimenet1, 120);

            // Save the photo record to the database
            $this->save();
        } catch (\Throwable $e) {
            @\unlink($kimenet);
            @\unlink($kimenet1);
            throw new \Exception("A képet nem sikerült feldolgozni, ezért nem mentettük el. "
                . "Valószínűleg sérült vagy nem valódi képfájl.");
        }
    }
    
    private function getMaxFileSize() {
        // Helper function to convert PHP ini values to bytes
        $convertToBytes = function($val) {
            $val = trim($val);
            $last = strtolower($val[strlen($val)-1]);
            $val = (int) $val;
            switch($last) {
                case 'g':
                    $val *= 1024;
                case 'm':
                    $val *= 1024;
                case 'k':
                    $val *= 1024;
            }
            return $val;
        };
        
        // Get PHP upload limits
        $upload_max_bytes = $convertToBytes(ini_get('upload_max_filesize'));
        $post_max_bytes = $convertToBytes(ini_get('post_max_size'));
        
        // The effective limit is the smallest of PHP limits
        $php_limit = min($upload_max_bytes, $post_max_bytes);
        
        // Return the smallest limit
        return $php_limit;
    }

    /**
     * #709: milyen kiterjesztéssel menthető el biztonságosan ez a fájl?
     *
     * A döntés KIZÁRÓLAG a fájl tartalmán alapul — se a kliens fájlnevét, se az
     * általa küldött Content-Type-ot nem vesszük figyelembe. Ez a védelem lelke:
     * a támadás pontosan azon múlt, hogy a `.php` kiterjesztés a kliens
     * fájlnevéből átjött, a típusellenőrzés pedig a kliens állítását hitte el.
     *
     * Külön, statikus függvény, mert így adatbázis és képkönyvtár nélkül,
     * önmagában tesztelhető — a biztonsági állítások ne múljanak azon, hogy a
     * futtató környezetben írható-e a kepek/ könyvtár.
     *
     * @return string a pontot is tartalmazó kiterjesztés, pl. ".jpg"
     * @throws \Exception ha a fájl nem kép, vagy nem engedett formátum
     */
    static function safeExtensionFor(string $path): string {
        $imageInfo = @\getimagesize($path);
        if ($imageInfo === false) {
            throw new \Exception("A feltöltött fájl nem kép.");
        }

        $allowedTypes = [
            IMAGETYPE_JPEG => '.jpg',
            IMAGETYPE_PNG  => '.png',
            IMAGETYPE_GIF  => '.gif',
        ];

        if (!isset($allowedTypes[$imageInfo[2]])) {
            throw new \Exception("Nem támogatott képformátum. Csak JPEG, PNG és GIF tölthető fel.");
        }

        return $allowedTypes[$imageInfo[2]];
    }

    /**
     * #709 mellékága: ez a függvény FIXEN imagecreatefromjpeg()-et hívott.
     *
     * Vagyis a dokumentáltan engedett négy formátumból csak a JPEG működött: a
     * GIF- és PNG-feltöltés idáig eljutott, majd itt elhasalt egy belső
     * GD-hibával. Meg is látszik az adaton: 29314 jpg mellett 9 gif és 0 png.
     *
     * Mostantól a forrás TÉNYLEGES típusa dönti el az olvasót, és ugyanabban a
     * formátumban írunk vissza — különben JPEG-tartalom kerülne .gif nevű
     * fájlba. A PNG átlátszóságát megtartjuk.
     */
    static function kicsinyites($forras, $kimenet, $max) {

        if (!isset($max))
            $max = 120;# maximum size of 1 side of the picture.

        $info = @\getimagesize($forras);
        if ($info === false) {
            throw new \Exception("A kép nem olvasható: " . basename($forras));
        }

        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src_img = @\imagecreatefromjpeg($forras); break;
            case IMAGETYPE_PNG:  $src_img = @\imagecreatefrompng($forras);  break;
            case IMAGETYPE_GIF:  $src_img = @\imagecreatefromgif($forras);  break;
            default:
                throw new \Exception("Nem támogatott képformátum a kicsinyítéshez.");
        }

        if (!$src_img) {
            throw new \Exception("A képet nem sikerült megnyitni: " . basename($forras));
        }

        $oh = \imagesy($src_img);  # original height
        $ow = \imagesx($src_img);  # original width

        $new_h = $oh;
        $new_w = $ow;

        if ($oh > $max || $ow > $max) {
            $r = $oh / $ow;
            $new_h = ($oh > $ow) ? $max : $max * $r;
            $new_w = $new_h / $r;
        }

        // note TrueColor does 256 and not.. 8
        $dst_img = \imagecreatetruecolor($new_w, $new_h);

        // PNG/GIF: az átlátszóság elveszne, ha nem jelezzük külön a GD-nek.
        if ($info[2] === IMAGETYPE_PNG || $info[2] === IMAGETYPE_GIF) {
            \imagealphablending($dst_img, false);
            \imagesavealpha($dst_img, true);
        }

        \imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $new_w, $new_h, \imagesx($src_img), \imagesy($src_img));

        // Ugyanabban a formátumban írunk vissza, amilyen a forrás volt.
        switch ($info[2]) {
            case IMAGETYPE_PNG: \imagepng($dst_img, "$kimenet");  break;
            case IMAGETYPE_GIF: \imagegif($dst_img, "$kimenet");  break;
            default:            \imagejpeg($dst_img, "$kimenet"); break;
        }

        // Free up memory
        \imagedestroy($src_img);
        \imagedestroy($dst_img);
    }

}
