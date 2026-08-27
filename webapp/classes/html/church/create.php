<?php

namespace Html\Church;

class Create extends \Html\Html {

    public function __construct($path) {
        global $user;
        if (!$user->checkRole('miserend')) {
            throw new \Exception('Nincs jogosultságod a templomot létrehozni.');
        }

        $this->title = 'Új misézőhely létrehozása';


        $isForm = \Request::Text('submit');
        // #873: új templom felvétele — POST + token.
        if ($isForm) { \Csrf::guard(); }
        if ($isForm) {
            $tid = $this->create();
            if($tid) {
                $this->redirect("/templom/".$tid."/edit");
                return;
            }
            throw new \Exception('Nem sikerült a templomot létrehozni.');
        }


        return;

    }

    function create() {

        $lat = self::koordinata('church[lat]', 'szélességi fok', -90, 90);
        $lon = self::koordinata('church[lon]', 'hosszúsági fok', -180, 180);
        $name = \Request::TextRequired('church[nev]');
        $osm_id = \Request::Integer('church[osmid]');
        $osm_type = \Request::InArray('church[osmtype]', ['node', 'way', 'relation']);

        /*
         * #898: a két OSM-mező csak EGYÜTT ér valamit.
         *
         * Eddig fél azonosítót is el lehetett menteni (típus id nélkül vagy fordítva),
         * és az a templom onnantól úgy nézett ki, mintha OSM-hez lenne kötve — miközben
         * az `OSM::updateChurch()` az `empty($osmtype) OR empty($osmid)` ágon úgyis
         * kihagyja. Csendes fél-adat helyett inkább szóljunk.
         */
        if (($osm_id && !$osm_type) || (!$osm_id && $osm_type)) {
            throw new \Exception('Az OSM azonosítóhoz a típus és az azonosító is kell — '
                . 'vagy hagyd üresen mind a kettőt.');
        }

        $church = \Eloquent\Church::create([
            'nev' => $name,
            'ok' => 'n',
            'frissites' => date('Y-m-d'),
            'lat' => $lat,
            'lon' => $lon,
            'osmid' => $osm_id,
            'osmtype' => $osm_type,
        ]);

        /*
         * #898: az űrlapon ott van a megjegyzés-mező, de a mentés eddig nem olvasta —
         * amit a szerkesztő beírt, az némán elveszett.
         *
         * És nem elég a fenti tömbbe betenni: az `adminmegj` nincs a `Church::$fillable`
         * listáján, tehát a tömeges értékadás CSENDBEN eldobná. Kimértem — a sor létrejön,
         * a megjegyzés helye üres marad. Ezért közvetlen értékadás.
         */
        $megjegyzes = \Request::Text('church[adminmegj]');
        if ($megjegyzes !== false && trim((string) $megjegyzes) !== '') {
            $church->adminmegj = $megjegyzes;
        }

        $church->save();

        return $church->id;

    }

    /**
     * #898: koordináta az űrlapról, tizedesVESSZŐVEL is.
     *
     * A magyar billentyűzeten és a magyar számformátumban a tizedesjel a vessző, és a
     * felhasználók így is írják be. A `Request::FloatRequired()` az `is_numeric()`-re
     * épül, ami a „47,4979"-et elutasítja — a bejelentő pedig ezt kapta:
     * `Required 'church[lat]' is not a Float.` Ebből nem derül ki, mit rontott el.
     *
     * A vessző cseréje itt, a koordinátánál történik, nem a `Request`-ben: ott az
     * ezres elválasztó vessző („1,000") miatt a csere értelmet változtatna.
     *
     * A tartomány-ellenőrzés is idevaló. Felcserélt szélesség/hosszúság esetén (ami
     * gyakori hiba) a 90 fölötti szélességet így elkapjuk, ahelyett hogy a templom a
     * térkép túloldalán landolna.
     */
    private static function koordinata(string $mezo, string $nev, float $min, float $max): float {
        $nyers = \Request::Text($mezo);

        if ($nyers === false || trim((string) $nyers) === '') {
            throw new \Exception('Hiányzik a ' . $nev . '. A térképen a jelölőt mozgatva is megadható.');
        }

        $ertek = str_replace(',', '.', trim((string) $nyers));

        if (!is_numeric($ertek)) {
            throw new \Exception('A ' . $nev . ' nem szám: „' . $nyers . '". '
                . 'Tizedesjelnek pont és vessző is jó, de más nem kerülhet bele.');
        }

        $ertek = (float) $ertek;

        if ($ertek < $min || $ertek > $max) {
            throw new \Exception('A ' . $nev . ' ' . $min . ' és ' . $max . ' közé eshet, '
                . 'ez viszont ' . $ertek . '. (Nincs felcserélve a két mező?)');
        }

        return $ertek;
    }

}
