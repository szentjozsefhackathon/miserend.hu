<?php

namespace Html\Ajax;

class DiocesesInBBox extends Ajax {

    public function __construct() {
               
        //echo json_encode(['roman_catholic' => \Html\Map::getGeoJsonDioceses()]);
        //return;

        // #391: a bbox parse+validáció a \Request::Bbox()-ban (ld. churchesinbbox).
        // Hiányzó/rossz alakú bbox → false, ekkor némán kilépünk.
        $bbox = \Request::Bbox('bbox');
        if($bbox === false) return;

        echo json_encode([
            'roman_catholic' => $this->getDioceses($bbox, 'roman_catholic'),
            'greekcatholic' => $this->getDioceses($bbox, 'greek_catholic')
        ]);
            
        exit;
            
    }
     
    function getDioceses($bbox, $rite) {

        // Csak az érintett egyházmegyék azonosítóit kérjük le, hogy gyorsabb legyen a lekérdezés
        // Mert ugyan itt is van cache, de minden térképmozdulatnál történik valami
        $overpass = new \ExternalApi\OverpassApi();
        $filter = "['type'='boundary']['boundary'='religious_administration']['religion'='christian']['denomination'='".$rite."']['admin_level'='6']";
        $filter .= "(".$bbox[0].",".$bbox[1].",".$bbox[2].",".$bbox[3].")";
        $overpass->buildSimpleQuery($filter,"ids");
        $overpass->run();
        //print_r($overpass);

        if (!isset($overpass->jsonData->elements)) {
             return [];
        }

        // #641: az egyházmegyék NEVÉT eddig nem küldtük le, ezért a térképen semmilyen
        // módon nem lehetett megnézni, melyik egyházmegye fölött járunk. A Nominatim
        // csak a geometriát adja vissza, a nevek viszont a saját `boundaries` táblánkban
        // ott vannak (az OSM-sync tölti) — egyetlen lekérdezéssel hozzájuk tesszük.
        $names = \Eloquent\Boundary::whereIn('osmid', array_map(function ($e) { return $e->id; }, $overpass->jsonData->elements))
            ->get(['osmtype', 'osmid', 'name'])
            ->keyBy(function ($boundary) { return $boundary->osmtype . '/' . $boundary->osmid; })
            ->map(function ($boundary) { return $boundary->name; })
            ->toArray();

        // Az egyházmegyék geoJSON adatait lekérjük.
        // Ez sok kérésnek tűnik, de mivel komoly cache van ezért gyorsan fog menni.
        $dioceses = [];

        foreach ($overpass->jsonData->elements as $element) {
                $types = [
                    'node' => 'N',
                    'way' => 'W',
                    'relation' => 'R'
                ];

                $nominatim = new \ExternalApi\NominatimApi();
                $diocese = $nominatim->OSM2GeoJson($types[$element->type], $element->id);
                if ($diocese == false ) continue;
                $key = $element->type . "/" . $element->id;
                $diocese->id = $key;
                $diocese->name = $names[$key] ?? '';
                $dioceses[] = $diocese;


        }

        return $dioceses;

    }

}