<?php

namespace Html;
use Illuminate\Database\Capsule\Manager as DB;

class Map extends Html {
    public $center;
    public $dioceseslayer;
    public $location;
    public $church_id;
    public $boundary;

    public function __construct() {
        $this->setTitle("OSM Térkép");

        $tid = \Request::Integer('tid');
        if ($tid) {
            $church = \Eloquent\Church::find($tid);

            $this->location = $church->location;
            $this->church_id = $tid;
        }
        
        // #545: közvetlen $_REQUEST helyett a \Request:: olvasás. A `map` egy
        // '/'-tagolt numerikus lista (zoom/lat/lon vagy lat/lon); a Text() üres
        // sztringet ad hiányzáskor, a lenti is_numeric-őr változatlanul kezeli.
        $mapParam = \Request::Text('map');
        if($mapParam !== '') {
            $parts = explode('/', $mapParam);
            foreach($parts as $part) {
                if(!is_numeric($part)) return;
            }

            if(count($parts) == 3) {
                $this->center = [
                    'zoom' => $parts[0],
                    'lat' => $parts[1],
                    'lon' => $parts[2]
                ];
            }

            if(count($parts) == 2) {
                $this->center = [
                    'lat' => $parts[0],
                    'lon' => $parts[1]
                ];
            }

        }

        // #545: boundary olvasása \Request::Text-tel; csak akkor állítjuk, ha van
        // (hogy a property null maradjon hiányzáskor, mint eddig).
        $boundaryParam = \Request::Text('boundary');
        if($boundaryParam !== '') {
            $this->boundary = $boundaryParam;
        }
        
        $data = $this->getGeoJsonDioceses();                
        $this->dioceseslayer = [];
        $this->dioceseslayer['geoJson'] = json_encode($data);        
    }
    
    
    
    static function getGeoJsonDioceses() {
        
            $cacheTime = '1 week';

			//Az általunk rögzített egyházmegyék osm azonosítói
			// A jegyzet, ami itt állt („sajna mindet veszi"), időközben elavult: a `(gk)`
			// szűrő pontosan a görögkatolikus egyházmegyéket hagyja ki — az adatbázisban
			// mindhárom ilyen (Hajdúdorogi metropólia, Miskolci, Nyíregyházi) a nevében
			// viseli a jelölést, és minden más, OSM-azonosítóval rendelkező egyházmegye
			// latin szertartású. A réteg felirata tehát fedi a tartalmát.
            $results = DB::table('egyhazmegye')
                ->where('nev', 'not like', '%(gk)%')
                ->whereNotNull('osm_relation')
                ->select("osm_relation")
                ->pluck('osm_relation')
				->toArray();
			
			//És letöltjük ezeknek a területeknek a határait
			// No nem minden alkalommal, hiszen létezik minden externalapi-hoz cache. Itt is van.
            $geoJsons = [];
            foreach($results as $osmid) {
                $nominatim = new \ExternalApi\NominatimApi();
                $geoJsons[] = json_encode($nominatim->OSM2GeoJson('R', $osmid));                
            }

            if(count($geoJsons) < 1) $json = "{}";
            else $json = "[".implode(',',$geoJsons)."]";

            $cacheDir = PATH . 'fajlok/tmp/'; // Vigyázz! Egyezzen: geoJsonDiocesesFromCache();
            $cacheFilePath = $cacheDir . 'GeojsonDioceses';  // Vigyázz! Egyezzen: geoJsonDiocesesFromCache();
            if (!file_put_contents($cacheFilePath,$json)) {
                throw new \Exception("We could not save the cacheFile to " . $cacheFilePath);
            }
            return json_decode($json);
            
    }

}
