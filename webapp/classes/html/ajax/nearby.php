<?php

namespace Html\Ajax;

/**
 * #854: a főoldali „mi van a közelemben" doboz saját végpontja.
 *
 * borazslo: „A /home használja az api/v4/nearby-t a közeli templomok kiírására. És ez
 * egyáltalán nem elegáns. A statisztikát is torzítja, és másképp kell így figyelni az
 * API alakításra is."
 *
 * Két külön baj volt vele:
 *
 * 1. A STATISZTIKA. Az `index.php` minden `api/` kezdetű útvonalat `kind = 'api'`-ként
 *    számol (`Stats::countPageview`). A saját főoldalunk minden helymeghatározása tehát
 *    API-használatnak látszott — a szám nem azt mérte, amire való: hogy mennyire
 *    használják a mobilalkalmazást és a külső integrációkat.
 *
 * 2. A SZERZŐDÉS. Amíg a saját oldalunk a nyilvános API-n függ, annak az alakját nem
 *    lehet szabadon alakítani: minden változás egyszerre kliens-változás is. Az `Api\NearBy`
 *    ráadásul `requiredVersion = ['>=', 4]` — a főoldal így egy verziózott, kifelé
 *    ígért felülethez volt kötve.
 *
 * A LEKÉRDEZÉS ettől függetlenül KÖZÖS marad (`Church::nearestQuery()`): a
 * távolságszámítás és a kizárások pontosan ugyanazok, és nem szabad, hogy szétcsússzanak.
 * Csak a felület vált szét, nem a logika.
 *
 * A válasz szándékosan SZŰK: pontosan az az öt mező, amit a doboz kiír. Nem `toAPIArray()`,
 * mert az a nyilvános szerződés — épp attól szakadunk el.
 */
class NearBy extends Ajax {

    /** Ennél többet a doboz úgysem ír ki; a felső korlát a védelem. */
    const ALAP_LIMIT = 10;
    const MAX_LIMIT = 50;

    public function __construct() {
        header('Content-Type: application/json; charset=utf-8');

        /*
         * A doboz JSON-törzsben POST-ol (így hívta az API-t is), de a GET-es alak is
         * működjön: így böngészőből, kézzel is kipróbálható, és egy jövőbeli hívónak
         * nem kell JSON-t gyártania.
         */
        $bemenet = self::bemenet();

        if ($bemenet === false) {
            echo json_encode([
                'error' => 1,
                'text' => 'Hiányzó vagy érvénytelen koordináta.',
                'templomok' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        [$lat, $lon, $limit] = $bemenet;

        if (!\Eloquent\Church::isUsablePosition($lat, $lon)) {
            echo json_encode([
                'error' => 1,
                'text' => 'Érvénytelen helyzet (0,0) — nem sikerült meghatározni a pozíciót.',
                'templomok' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $templomok = \Eloquent\Church::nearestQuery($lat, $lon, $limit)->get()->map(function ($church) {
            return [
                'id' => (int) $church->id,
                'nev' => (string) $church->nev,
                'ismertnev' => (string) ($church->ismertnev ?? ''),
                'varos' => (string) $church->locationCityName(),
                // Méterben, ahogy a doboz várja (ott dől el a m/km megjelenítés).
                'tavolsag' => (int) $church->distance,
            ];
        })->values();

        echo json_encode([
            'error' => 0,
            'templomok' => $templomok,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * A koordináta kiolvasása — JSON-törzsből vagy kérés-paraméterből.
     *
     * @return array{0:float,1:float,2:int}|false
     */
    private static function bemenet() {
        /*
         * SZÁNDÉKOSAN nem a `Request::FloatRequired()`-et hívjuk: az kivételt dob, és egy
         * ajax-végponton a hiányzó paraméterre nem HTML hibaoldal jár, hanem JSON.
         */
        $lat = self::szam(\Request::get('lat'));
        $lon = self::szam(\Request::get('lon'));
        $limit = self::szam(\Request::get('limit'));

        // JSON-törzs: a doboz így küldi.
        if ($lat === null || $lon === null) {
            $nyers = file_get_contents('php://input');
            $json = $nyers === false ? null : json_decode($nyers, true);
            if (is_array($json)) {
                $lat = $lat ?? self::szam($json['lat'] ?? null);
                $lon = $lon ?? self::szam($json['lon'] ?? null);
                $limit = $limit ?? self::szam($json['limit'] ?? null);
            }
        }

        if ($lat === null || $lon === null) {
            return false;
        }

        $limit = $limit === null ? self::ALAP_LIMIT : $limit;

        // A Föld szélein túli koordinátára nincs értelmes válasz.
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return false;
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) $limit));

        return [(float) $lat, (float) $lon, $limit];
    }

    /** Szám vagy null — üres sztringből, false-ból, szemétből NE legyen 0.0. */
    private static function szam($ertek): ?float {
        if ($ertek === null || $ertek === false || $ertek === '' || !is_numeric($ertek)) {
            return null;
        }

        return (float) $ertek;
    }
}
