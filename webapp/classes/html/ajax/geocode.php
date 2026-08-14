<?php

namespace Html\Ajax;

/**
 * #722: helynév → koordináta, hogy a keresőűrlap ki tudja tölteni a szélesség/hosszúság
 * mezőket még beküldés előtt.
 *
 * A geokódolást eddig csak a szerver végezte, beküldés UTÁN (searchresultschurches.php,
 * searchresultsmasses.php) — a felhasználó tehát nem látta, mire fordítottuk a beírt
 * helyet, és nem is tudta korrigálni. Ugyanazt a NominatimApi-t hívjuk, amit ők, tehát
 * a kliens- és a szerveroldali értelmezés nem tud elcsúszni egymástól.
 *
 * A Nominatim-válasz 2 hétig cache-elt (l. ExternalApi\NominatimApi), ezért ez a végpont
 * a gyakori helyneveknél nem terheli a külső szolgáltatást.
 */
class Geocode extends Ajax {

    public $format = "json";
    public $content;

    public function __construct() {
        $place = trim(\Request::Text('hely'));
        if (mb_strlen($place) < 3) {
            $this->content = json_encode([
                'found' => false,
                'message' => 'Írj be legalább három karaktert.',
            ]);
            return;
        }

        $point = \Html\SearchResultsChurches::geocodePlace($place);

        if ($point === false || !isset($point['lat'], $point['lon'])) {
            $this->content = json_encode([
                'found' => false,
                'message' => 'Nem találtuk meg ezt a helyet: ' . $place,
            ]);
            return;
        }

        $this->content = json_encode([
            'found' => true,
            'lat' => round((float) $point['lat'], 6),
            'lon' => round((float) $point['lon'], 6),
            'name' => $point['name'] ?? $place,
        ]);
    }
}
