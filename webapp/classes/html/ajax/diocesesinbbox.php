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

        // #842: rácsra igazítjuk, mielőtt bármit kérdeznénk — l. snapToGrid().
        $bbox = self::snapToGrid($bbox);

        echo json_encode([
            'roman_catholic' => $this->getDioceses($bbox, 'roman_catholic'),
            'greekcatholic' => $this->getDioceses($bbox, 'greek_catholic')
        ]);
            
        exit;
            
    }
     
    /**
     * #842: a lekérdezés doboza rácsra igazítva.
     *
     * A cache-kulcs a lekérdezés SZÖVEGÉBŐL képződik (`ExternalApi::loadCacheFilePath()`,
     * `md5($this->query)`), a lekérdezés pedig a nyers bbox-lebegőpontokat tartalmazza.
     * Két szomszédos térképnézet így két külön kulcs, tehát a cache — bár be van
     * kapcsolva — gyakorlatilag sosem talált. borazslo pontosan ezt írta a #842-ben:
     * „hiába nyomjuk a cache-t, azért pontosan ugyan akkor bbox ritkán van".
     *
     * A rácsra igazítás KIFELÉ kerekít: a visszaadott doboz mindig LEFEDI a kértet, sose
     * kisebb nála. Több egyházmegye jöhet vissza a kelleténél — az viszont nem hiba, a
     * kliens úgyis kiszűri a már ismerteket (`addDioceses`, #641).
     *
     * A 0,5 fokos rács SZÁNDÉKOSAN durva. Mérve: egy budapesti nézet 3 egyházmegyét ad
     * (0,6 mp), a teljes Kárpát-medence 37-et (2,1 mp) — vagyis a nagyobb doboz alig
     * drágább, viszont a szomszédos nézetek EGY kulcsra esnek, és onnantól a cache tényleg
     * dolgozik. Ez szerveroldalon van, nem a kliensben: a végpont publikus, tehát a
     * védelemnek is itt a helye.
     *
     * @param  float[] $bbox [latMin, lonMin, latMax, lonMax]
     * @return float[]
     */
    const GRID = 0.5;

    public static function snapToGrid(array $bbox): array {
        return [
            floor($bbox[0] / self::GRID) * self::GRID,
            floor($bbox[1] / self::GRID) * self::GRID,
            ceil($bbox[2] / self::GRID) * self::GRID,
            ceil($bbox[3] / self::GRID) * self::GRID,
        ];
    }

    function getDioceses($bbox, $rite) {

        // Csak az érintett egyházmegyék azonosítóit kérjük le, hogy gyorsabb legyen a lekérdezés
        // Mert ugyan itt is van cache, de minden térképmozdulatnál történik valami
        $overpass = new \ExternalApi\OverpassApi();
        /*
         * #842: LÁTOGATÓI kérésben vagyunk, nem cronban. Az alapértelmezett 30 másodperc
         * itt azt jelenti, hogy egy akadozó Overpass fél percre megállítja a térképet.
         * Az egyházmegye-réteg kiegészítés: ha nem jön meg, inkább ne jöjjön meg gyorsan.
         */
        $overpass->queryTimeout = 15;
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