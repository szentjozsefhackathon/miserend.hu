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

    function buildQuery() {
        global $config;
        $this->rawQuery = $this->query;        
    }

}

