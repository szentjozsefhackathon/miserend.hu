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
            'description' => 'Mód 1 esetén kötelező. Az eszköz ajtó állapotának érzékelése: 1 - nyitva, 0 - zárva. '
                . 'A helyesen írt „Status_Door" alakot is elfogadjuk.',
            'example' => 1
        ],
        /*
         * #866: a helyesen írt alak IS működjön.
         *
         * A mező neve a kódban `Satus_Door` — elgépelés, de az eszközök így küldik, tehát
         * nem lehet csak úgy átnevezni. Aki viszont a DOKUMENTÁCIÓBÓL dolgozik és a
         * helyes `Status_Door`-t kódolja be, annak a kulcsa némán elveszett (a gyökér-szűrő
         * csak a legfelső szintet nézi), és a kérés a „Satus_Door field is required"
         * hibán bukott el — érthetetlenül.
         */
        'object/Status_Door' => [
            'validation' => [
                'enum' => [0,1]
            ],
            'description' => 'A „Satus_Door" helyesen írt alakja; ugyanazt jelenti. Mód 1 esetén '
                . 'a kettő közül az egyik kötelező.',
            'example' => 1
        ],
        /*
         * #866: AZONOSÍTÁS a JSON törzsben.
         *
         * A dokumentáció (l. `docs()`) két utat ígér: `X-Miserend-Token` fejléc VAGY
         * `token` mező a törzsben. A `token` viszont nem szerepelt a mezők közt, a
         * `getInputJson()` gyökér-szűrője pedig a hitelesítés ELŐTT fut — vagyis a
         * törzsben küldött token „Unknown field 'token'" hibát adott, MIELŐTT bárki
         * megnézte volna, hogy jó-e. A dokumentált két út egyike egyáltalán nem működött.
         */
        'token' => [
            'description' => 'Azonosító token. Alternatívája az X-Miserend-Token fejlécnek — '
                . 'elég az egyiket küldeni.',
            'example' => 'kerj-tolunk-egyet'
        ],
        /*
         * #866: a ChirpStack-boríték saját metaadatai.
         *
         * Az átjáró a szenzoradat mellé beleteszi a sajátjait is (címzés, jelerősség,
         * rádió-paraméterek). Ezeket nem használjuk, de a gyökér-szűrő ELUTASÍTOTTA a
         * küldeményt miattuk — beleértve a SAJÁT mintaadatunkat az /apitest oldalon,
         * amire az integrátort ráirányítottuk.
         *
         * Ez szemben állt a szűrő fölött álló saját indoklásunkkal is: „a LoRaWAN-átjárók
         * a saját metaadataikat is beleteszik a küldeménybe … szigorú almező-ellenőrzésnél
         * ezek MIND elutasítást okoznának". Az almezőkre tényleg engedékenyek voltunk, a
         * gyökérre viszont épp nem.
         */
        'devAddr' => ['description' => 'ChirpStack-metaadat; nem használjuk.'],
        'adr' => ['description' => 'ChirpStack-metaadat; nem használjuk.'],
        'dr' => ['description' => 'ChirpStack-metaadat; nem használjuk.'],
        'fCnt' => ['description' => 'ChirpStack-metaadat; nem használjuk.'],
        'fPort' => ['description' => 'ChirpStack-metaadat; nem használjuk.'],
        'confirmed' => ['description' => 'ChirpStack-metaadat; nem használjuk.'],
        'data' => ['description' => 'A nyers hasznos adat base64-ben; nem használjuk (az `object` a feldolgozott alak).'],
        'rxInfo' => ['description' => 'ChirpStack vételi metaadat; nem használjuk.'],
        'txInfo' => ['description' => 'ChirpStack adási metaadat; nem használjuk.'],
        'regionConfigId' => ['description' => 'ChirpStack-metaadat; nem használjuk.']
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


        /*
         * #866: a VÍZSZIVÁRGÁS nem gyóntatás.
         *
         * A végpont két módot ismer (`Mód: 1` ajtó, `Mód: 2` vízszivárgás), de eddig
         * MINDKETTŐ ugyanabba a `confessions.status` mezőbe írt 'ON'/'OFF'-ot. A
         * státusz-olvasó pedig a templom LEGUTOLSÓ sorát veszi (`church.php`,
         * `getConfessionStatusAttribute`) — így egy jelzett szivárgásból „Most van
         * gyóntatás a helyszínen!" lett a templom oldalán, csukott gyóntatófülke mellett.
         *
         * A döntés IMPORTKOR születik meg, nem olvasáskor (borazslo javaslata a #867-ben):
         * ami nem gyóntatás, az nem kerül a `confessions` táblába. Így nem kell se új
         * oszlop, se éles migráció, se szűrés az olvasó oldalon — a tábla azt jelenti,
         * amit a neve mond.
         *
         * A szivárgás-jelzést tehát nem tároljuk. Ma sem használtuk semmire; ha egyszer
         * kell (riasztás a sekrestyésnek), annak úgyis saját tábla és saját életciklus
         * kell — a `confessions` sosem volt jó helye.
         */
        if ((int) $this->input['object']['Mód'] === 2) {
            if (!isset($this->input['object']['Status_Leak'])) {
                throw new \Exception('Status_Leak field is required when Mód is 2.');
            }
            error_log(sprintf(
                '[miserend] LoRaWAN: vízszivárgás-jelzés (templom %s, eszköz %s, Status_Leak=%s) — nem gyóntatás, nem tároljuk.',
                $this->input['deviceInfo']['tags']['templom_id'] ?? '?',
                $this->input['deviceInfo']['tags']['local_id'] ?? '?',
                $this->input['object']['Status_Leak']
            ));
            return;
        }

        $confession = new \Eloquent\Confession();

        /*
         * #890: ez a tábla NEM szorul javításra, és ez nem magától értetődő.
         *
         * A `confessions.timestamp` oszlopon ott a `CURRENT_TIMESTAMP` alapérték, a modellen
         * pedig `$timestamps = false` — ebből az következne, hogy a MySQL órája tölti. Nem
         * az: lentebb a `timestamp` mindig megkapja az ESZKÖZ saját eseményidejét
         * (`$this->input['time']`), ami kötelező és mintára ellenőrzött mező. Az alapérték
         * tehát sosem sül el, és egy „PHP-óra" beállítás itt holt kód lenne — pár sorral
         * lejjebb úgyis felülíródna.
         */

        if ($this->input['object']['Mód'] == 1) {
            // #866: a helyesen írt `Status_Door` alakot is elfogadjuk (l. a mezőknél).
            $ajto = $this->input['object']['Satus_Door'] ?? $this->input['object']['Status_Door'] ?? null;
            if ($ajto === null) {
                throw new \Exception('Satus_Door (or Status_Door) field is required when Mód is 1.');
            }
            $confession->status = ($ajto == 1) ? 'ON' : 'OFF';
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
