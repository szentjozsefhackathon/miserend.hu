<?php

namespace Eloquent;

use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    protected $fillable = ['name','type','uid','timeout'];
    protected $appends = ['isValid'];

    /**
     * #862: token keresése a nevéből — TÍPUSBIZTOSAN.
     *
     * A `tokens.name` `varchar(40)`, a token pedig 32 jegyű hexadecimális szám. Ha az
     * összehasonlítás másik oldalára SZÁM kerül, a MySQL nem a számot alakítja
     * sztringgé, hanem fordítva: a tárolt sztringet konvertálja számmá, a vezető
     * számjegyekből. Mérve:
     *
     *     SELECT '971744af0e83941bffd19c110a5d2b28' = 971744;   ->  1
     *
     * Az API-k JSON-törzsből olvasnak, ahol a `{"token": 971744}` valódi PHP int lesz —
     * tehát egy TÖREDÉK szám érvényes tokenre illeszkedett. A `/api/v4/favorites`
     * végponton ez mérve is teljes azonosítás-megkerülés volt:
     *
     *     {"token": 971744}    ->  {"favorites":[],"error":0}      <-- beengedte
     *     {"token": "971744"}  ->  {"error":"1","text":"Invalid token."}
     *
     * Vagyis bárki, token ismerete nélkül, rövid számokat próbálgatva idegen fiók
     * adataihoz fért. A védelem itt van, a modellben, mert négy hívóhely használja
     * ugyanezt a mintát (api/user.php, api/report.php, api/favorites.php, classes/user.php)
     * — ha mindegyikben külön kellene megcsinálni, egy biztosan kimaradna.
     *
     * A NEM sztring bemenet elutasítás, nem konverzió: aki számot küld, az nem tokent
     * küldött.
     */
    public static function findByName($name): ?self
    {
        if (!is_string($name) || $name === '') {
            return null;
        }

        return self::where('name', $name)->first();
    }

    /**
     * #862: token TÖRLÉSE a nevéből — típusbiztosan.
     *
     * Itt a típus-zavar még súlyosabb lenne, mint a keresésnél: a `where('name', <szám>)`
     * TÖBB sorra is illeszkedhet (minden token, ami ugyanazokkal a számjegyekkel kezdődik),
     * tehát egy törlés idegen felhasználókat is kiléptethetne.
     *
     * A süti értéke mindig sztring, tehát ma nem kiváltható — de a minta legyen egységes.
     *
     * @return int hány sort töröltünk
     */
    public static function deleteByName($name): int
    {
        if (!is_string($name) || $name === '') {
            return 0;
        }

        return (int) self::where('name', $name)->delete();
    }

    public function getIsValidAttribute($value) {
        if($this->timeout < date('Y-m-d H:i:s') ) 
            return false;
        
        return true;
    }
    
    public function extend() {        
        global $config;
        $this->timeout = date('Y-m-d H:i:s', strtotime("+" . $config['token'][$this->type]));
        $this->save();
    }
	
}