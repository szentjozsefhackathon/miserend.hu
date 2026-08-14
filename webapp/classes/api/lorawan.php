<?php

namespace Api;

class LoRaWAN extends Api {

    public $requiredVersion = ['>=',4]; // API v4-től érhető el
    public $title = 'Gyóntatás jelentése LoRaWAN eszközről';
    public $fields = [
        'deduplicationId' => [
            'validation' => ['string'
                => ['pattern' => '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$']
            ],  
            'description' => 'UUID formátumú egyedi azonosító minden egyes adatküldéshez. Fontos, hogy minden külön adat külön UUID-vel érkezzen. Kétszer azonos adatot nem fogadunk.',
            'example' => '123e4567-e89b-12d3-a456-426614174000',
            'required' => true
        ],
        'time' => [
            'validation' => ['string' => [
                'pattern' => '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}\+\d{2}:\d{2}$'
            ]],
            'description' => 'Az esemény időbélyege YYYY-MM-DDTHH:MM:SS.sss+00:00 formátumban',
            'example' => '2023-10-05T14:48:00.000+00:00',
            'required' => true  
        ],
        'deviceInfo' => [
            'validation' => 'list',
            'description' => 'Az eszköz információi, beleértve a devEui-t és a címkéket.',
            'example' => [],
            'required' => true
        ],
        'deviceInfo/devEui' => [
            'validation' => [
                'string' => [
                    'pattern' => '^[a-f0-9]{16}$'
                ]                
            ],  
            'description' => 'A konkrét eszköz egyedi azonosítója, hexadecimális formátumban (16 karakter).',
            'example' => '70b3d57ed00001a1',
            'required' => true
        ],
        'deviceInfo/tags/templom_id' => [
            'validation' => 'integer', 
            'description' => 'A misézőhely azonosítója.',
            'example' => 7,
            'required' => true
        ],
        'deviceInfo/tags/local_id' => [
            'validation' => 'integer', 
            'description' => 'Egy misézőhelyen több eszköz is lehet, ezért szükséges a helyi azonosító.',
            'example' => 1,
            'required' => true
        ],
        'object' => [
            'validation' => 'list',
            'description' => 'Az eszközről itt jönnek a státuszt érintő adatok.',
            'example' => [],
            'required' => true
        ],
        'object/Mód' => [
            'validation' => [
                'enum' => [1,2]
            ],  
            'description' => 'Az eszköz működési módja: 1 - ajtó állapot, 2 - vízszivárgás érzékelés.',
            'example' => 1,
            'required' => true
        ],
        'object/Status_Leak' => [
            'validation' => [
                'enum' => [0,1]
            ],  
            'description' => 'Mód 2 esetén kötelező. Az eszköz vízszivárgás érzékelésének állapota: 1 - vízszivárgás, 0 - nincs vízszivárgás.',
            'example' => 0
        ],
        'object/Satus_Door' => [
            'validation' => [
                'enum' => [0,1]
            ],  
            'description' => 'Mód 1 esetén kötelező. Az eszköz ajtó állapotának érzékelése: 1 - nyitva, 0 - zárva.',
            'example' => 1
        ] 
    ];
        
     public function docs() {

        $docs = [];
     
        $docs['description'] = <<<HTML
        <strong><i>Ez még egy kísérleti API, használata csak saját felelősségre!</i></strong>
        <p>Ez az API lehetővé teszi a LoRaWAN eszközök által küldött gyóntatási adatok jelentését. A rendszer ellenőrzi a bemeneti adatokat, és ha minden rendben van, elmenti az adatokat az adatbázisba.</p>
        <p>A jelenleg használt eszközök egyedi kommunikációs gyakorlata miatt van szükség ilyen részletes és szokatlan bemeneti adatokra.</p>

        <h4>Azonosítás — kérj tőlünk tokent</h4>
        <p><strong>Az adatküldéshez token szükséges.</strong> Kérj tőlünk egyet: szívesen adunk, csak biztonsági okból kell.
        Enélkül bárki írhatna gyóntatási állapotot bármelyik templomhoz, ezért kötöttük azonosításhoz.</p>
        <p>A kapott értéket kétféleképpen adhatod át — válaszd, amelyik az eszközödnek egyszerűbb:</p>
        <ul>
            <li><code>X-Miserend-Token</code> HTTP-fejlécként, vagy</li>
            <li><code>token</code> mezőként a JSON törzsben.</li>
        </ul>
        <p>Hibás vagy hiányzó token esetén a válasz <code>„error”: 1</code>, a <code>„text”</code> pedig <code>Invalid or missing token.</code></p>

        <p>További információ a gyóntatásokról és a LoRaWAN eszközökről a <a href="/staticpage/confessions">dokumentációban</a> található.</p>
        HTML;

        $docs['response'] = <<<HTML
        <ul>
            <li>„error”: <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
            <li>„text” (opcionális): „error:1” esetén a hiba szöveges leírása</li>
        </ul>
        HTML;

        return $docs;
    }
    
    /**
     * Megosztott titok a LoRaWAN-hálózatszerverrel (ChirpStack).
     *
     * A végpont EDDIG teljesen azonosítás nélkül fogadott adatot: bárki beírhatott
     * „gyóntatás folyamatban" állapotot BÁRMELYIK templomhoz, és korlátlanul
     * szaporíthatta a sorokat. Kipróbálva: sima curl, HTTP 200, a sor bekerült.
     *
     * A titkot az `.env`-ből olvassuk. Ha NINCS beállítva, a végpont a régi módon
     * viselkedik (nyitva marad) — különben a merge pillanatában elnémulnának az éles
     * eszközök, amíg a küldő oldal nincs átállítva. A naplóba viszont minden ilyen
     * kérésnél figyelmeztetés kerül, hogy ez az állapot ne maradjon észrevétlen.
     *
     * Beállítás után a küldő oldalon `X-Miserend-Token` fejlécként vagy `token`
     * mezőként kell átadni ugyanezt az értéket.
     */
    private function checkSharedSecret(): void {
        $elvart = trim((string) env('LORAWAN_TOKEN', ''));

        if ($elvart === '') {
            error_log('[miserend] LoRaWAN: nincs beállítva LORAWAN_TOKEN, a végpont azonosítás nélkül fogad adatot.');
            return;
        }

        $kapott = $_SERVER['HTTP_X_MISEREND_TOKEN'] ?? ($this->input['token'] ?? '');

        if (!is_string($kapott) || !hash_equals($elvart, trim($kapott))) {
            throw new \Exception('Invalid or missing token.');
        }
    }

    public function run() {
        parent::run();

        $this->getInputJson();

        $this->checkSharedSecret();


        $confession = new \Eloquent\Confession();
        
        if ($this->input['object']['Mód'] == 1) {
            if (!isset($this->input['object']['Satus_Door'])) {
                throw new \Exception('Satus_Door field is required when Mód is 1.');
            }
            $confession->status = ($this->input['object']['Satus_Door'] == 1) ? 'ON' : 'OFF';
        }
        if ($this->input['object']['Mód'] == 2) {
            if (!isset($this->input['object']['Status_Leak'])) {
                throw new \Exception('Status_Leak field is required when Mód is 2.');
            }
            $confession->status = ($this->input['object']['Status_Leak'] == 1) ? 'ON' : 'OFF';
        }

        
        $confession->local_id = $this->input['deviceInfo']['tags']['local_id'];
        $confession->church_id = $this->input['deviceInfo']['tags']['templom_id'];
        $confession->deduplicationId = $this->input['deduplicationId'];
        $confession->timestamp = date('Y-m-d H:i:s', strtotime($this->input['time']));
        $confession->fulldata = json_encode($this->input['object']);
        //$confession->fulldata = json_encode($this->input);
        $confession->save();
        
    }

}
