<?php

namespace Api;

class Favorites extends Api {

    public $title = 'Felhasználó kedvenc templomai';
    public $requiredVersion = ['>=',4]; // API v4-től érhető el    

    public $fields = [
        'token' => [
            'required' => true, 
            'description' => 'Egy érvényes token'
        ],
        'add' => [
            'validation' => [
                'list' => 'integer'
            ],
            'description' =>  'A kedvencekhez hozzáadni kívánt templomok azonosítójának listája/tömbje.',            
        ],
        'remove' => [
            'validation' => [
                'list' => 'integer'
            ],
            'description' =>  'A kedvencekből törölni kívánt templomok azonosítójának listája/tömbje.'            
        ]
    ];
     public function docs() {

        $docs = [];        

        $docs['description'] = <<<HTML
        <p>A felhasználó kedven templomait le lehet kérdezni, valamint hozzá lehet adni vagy el lehet belőle venni a megfelelő url-re JSON formátumban küldött token érvényessége esetén. A rendszer JSON formátumban válaszol a kedvenc templomok megújult listájával. Először a hozzáadást hajtja végre, majd a törlést. Nem tér vissza hibajelzéssel, ha az adott templomazonosító már szerepel a kedvencek között. És akkor sem, ha olyan törlésére kerül sor, ami nem is szerepelt a kedvencek között.</p>        
        HTML;

        $docs['response'] = <<<HTML
        <ul>
            <li>„error”: <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
            <li>„favorites": A felhasználó kedvenc templomainak azonosítóinak frissült listája/tömbje.</li>
            <li>„text” (opcionális): „error:1” esetén a hiba szöveges leírása</li>
        </ul>
        HTML;

        return $docs;
    }
    
    public function run() {
        parent::run();
        $this->getInputJson();

        // #862: típusbiztos keresés — a JSON-ból szám is jöhet, és a MySQL olyankor a
        // TÁROLT sztringet konvertálja számmá (l. Token::findByName()).
        $token = \Eloquent\Token::findByName($this->input['token'] ?? null);
        if(!$token or !$token->isValid) {
            throw new \Exception("Invalid token.");
        }    

        /*
         * #832: a felhasználó itt LOKÁLIS, nem globális.
         *
         * A `global $user` a munkamenet felhasználóját írta felül az egész kérésre —
         * pedig itt csak a token tulajdonosának adatai kellenek, és utána már senki
         * nem nyúl hozzá. Végigmentem az API-útvonalon: sem a `Html\Api`, sem a
         * `Html\Html`, sem a hívott `User`-metódusok nem olvassák a globált.
         *
         * A távolba ható értékadás azért veszélyes, mert némán megváltoztatja, KI a
         * felhasználó a kérés hátralévő részében — és ha valaha kerül ide egy
         * jogosultság-vizsgálat, az nem a bejelentkezettet fogja nézni.
         */
        $user = new \User($token->uid);

        if (isset($this->input['add'])) {
            if (!$user->addFavorites($this->input['add'])) {
                throw new \Exception("Could not add favorites.");
            }
        }
        if (isset($this->input['remove'])) {
            if (!$user->removeFavorites($this->input['remove'])) {
                throw new \Exception("Could not remove favorites.");
            }
        }

        $favorites = array();
        $user->getFavorites();
        foreach ($user->favorites as $favorite) {
            $favorites[] = $favorite['tid'];
        }

        $this->return['favorites'] = $favorites;
        
    }

}
