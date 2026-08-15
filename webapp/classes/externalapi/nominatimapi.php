<?php

namespace ExternalApi;

# https://operations.osmfoundation.org/policies/nominatim/

class NominatimApi extends \ExternalApi\ExternalApi {

    public $name = 'nominatim';
    public $apiUrl = "https://nominatim.openstreetmap.org/" ;    
    /*
     * #706: a /health-en ez a lekérdezés pirosan bukott ("ResponseCode: 400"),
     * miközben a Nominatim maga rendben volt. A hiba nálunk volt: az ékezetes
     * betűk NYERSEN álltak az URL-ben, azokat a Nominatim 400-zal utasítja el.
     * Kimérve, ugyanazzal a User-Agenttel:
     *   ...q=Szent%20József%20jezsuita   -> 400
     *   ...q=Szent%20J%C3%B3zsef%20...   -> 200
     * Ezért teljesen url-kódolva tesszük be.
     */
    public $testQuery = 'search?q=Szent%20J%C3%B3zsef%20jezsuita&format=json';

    function OSM2GeoJson($osmtype, $osmid) {
        
        $this->cache = '2 weeks';  // Nem számolunnk azzal, hogy a boundary-k sűrűn változnának.
        $this->query = "details.php?addressdetails=1&hierarchy=0&group_hierarchy=1";
        $this->query .= "&osmtype=".$osmtype."&osmid=".$osmid;
        $this->query .= "&polygon_geojson=1&format=json";

        if ($this->runQuery() && isset($this->jsonData->geometry)) {            
            return $this->jsonData->geometry;
        } else {
            return false;
        }
    }

    /**
     * #89: helynév -> koordináta (geokódolás).
     *
     * Az osztály eddig csak az OSM2GeoJson-t tudta (osmtype+osmid -> geometria), pedig a
     * kereső épp ezt igényelte: a felhasználó beír egy településnevet, mi meg nem tudtuk
     * hova tenni — a `hely` és `tavolsag` paraméter ezért volt néma no-op.
     *
     * Két hetes cache: a településnevek koordinátája nem változik, a Nominatim
     * használati irányelve pedig kifejezetten kéri a gyorsítótárazást
     * (https://operations.osmfoundation.org/policies/nominatim/).
     *
     * @param  string $place  a keresett hely neve
     * @return array|false    ['lat' => float, 'lon' => float, 'name' => string] vagy false
     */
    function geocode($place) {
        $place = trim((string) $place);
        if ($place === '') {
            return false;
        }

        $this->cache = '2 weeks';
        // A runQuery() csak akkor hívja a buildQuery()-t, ha a rawQuery MÉG nincs
        // beállítva — egy második hívás ugyanazon az objektumon tehát az ELSŐ lekérdezést
        // ismételné meg (és annak a cache-fájlját olvasná). Ugyanaz a csapda, mint a
        // #627-nél az Elasticsearch-kérés törzsével.
        unset($this->rawQuery);
        unset($this->rawData);

        // Magyarország-központú találat: a miserend túlnyomórészt hazai helyeket keres,
        // e nélkül a „Szentendre" simán lehetne egy amerikai utcanév is.
        $this->query = 'search?format=json&limit=1&accept-language=hu'
            . '&countrycodes=hu,sk,ro,rs,ua,at,si,hr'
            . '&q=' . urlencode($place);

        if (!$this->runQuery()) {
            return false;
        }
        if (!isset($this->jsonData[0]->lat) || !isset($this->jsonData[0]->lon)) {
            return false;
        }

        return [
            'lat'  => (float) $this->jsonData[0]->lat,
            'lon'  => (float) $this->jsonData[0]->lon,
            'name' => (string) ($this->jsonData[0]->display_name ?? $place),
        ];
    }

    function buildQuery() {
        global $config;
        $this->rawQuery = $this->query;        
    }

}

