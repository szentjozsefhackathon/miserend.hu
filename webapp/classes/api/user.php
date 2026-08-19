<?php

namespace Api;

class User extends Api {

    public $title = 'Felhasználó adatainak lekérése';
    public $requiredVersion = ['>=',4]; // API v4-től érhető el
    
    public $fields = [
        'token' => [
            'required' => true,
            'validation' => 'string',
            'description' => 'Egy érvényes token'      
        ]
    ];

   public function docs() {

        $docs = [];
        
        $docs['description'] = <<<HTML
        <p>A megfelelő url-re JSON formátumban küldött token érvényessége esetén a rendszer JSON formátumban visszaküldi a felhasználó adatait.</p>        
        HTML;

        $docs['response'] = <<<HTML
        <ul>
        <li>„error": <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
        <li>„username”: A felhasználó felhasználó neve. (Soha nem üres.)</li>
        <li>„nickname”: Becenév vagy megszólítás. (Lehetséges, hogy üres.)</li>
        <li>„name”: Teljes név. (Lehetséges, hogy üres.)</li>
        <li>„email”: A felhasználó email címe. (Elvileg nem lehet üres.)</li>
        <li>„favorites": A felhasználó kedvenc misézőhelyeinek azonosítóinak listája.</li>
        <li>„responsibilities": A felhasználó által gondozott misézőhelyek azonosítóinak listája.</li>
        <li>„text” (opcionális): „error:1” esetén a hiba szöveges leírása.</li>
        </ul>
        HTML;

        return $docs;
    }
    
    public function run() {
        parent::run();
        $this->getInputJson();
        $token = \Eloquent\Token::where('name',$this->input['token'])->first();
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
        
        $data = array(
            'username' => $user->username,
            'nickname' => $user->nickname,
            'name' => $user->name,
            'email' => $user->email,
            'favorites' => [],
            'responsibilities' => []
        );


        $user->getFavorites();
        foreach ($user->favorites as $favorite)
            $data['favorites'][] = $favorite['tid'];


        $user->getResponsabilities();
        $responsibilities = [];        
        if (isset($user->responsibilities['church']['allowed']) && is_iterable($user->responsibilities['church']['allowed'])) {
            foreach ($user->responsibilities['church']['allowed'] as $church) {
            $responsibilities[] = $church->church_id;
            }
        }
        $data['responsibilities'] = $responsibilities;
                
        $this->return['user'] = $data;
    }

}
