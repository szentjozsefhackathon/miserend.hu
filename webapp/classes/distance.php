<?php

use Illuminate\Database\Capsule\Manager as DB;

class Distance {

    public function __construct() {
        
    }

    public function updateSome() {
        $this->update(false, 50);
    }

    function update($church_id = false, $limit = false) {
        $counter = 0;
        if (!is_numeric($limit) or $limit == false) {
            $limit = 120;
        }

        // #172: a régi `has('osms')` egy 2018-ban megszűnt relációra hivatkozott (nincs
        // `osms()` metódus / `osms` tábla) → a cron MINDEN futáskor BadMethodCallException-t
        // dobott, ezért a distances tábla halott maradt. Helyette: valós koordinátájú
        // templomok (ezekre van értelme távolságot számolni).
        $query = \Eloquent\Church::whereNotNull('lat')->whereNotNull('lon')
                ->where('lat', '!=', 0)->where('lon', '!=', 0)
                ->take($limit)->orderBy('updated_at', 'desc');
        if ($church_id) {
            if (is_array($church_id)) {
                $query = $query->whereIn('id', $church_id);
            } else {
                $query = $query->where('id', $church_id);
            }
        }

        $churches = $query->get();
        if (!$churches) {
            throw new \Exception('There are no churches to measure the distance from/to');
        }

        foreach ($churches as $churchFrom) {
            $this->MupdateChurch($churchFrom);
        }
    }
    
    /**
     * #748: a pár KANONIKUS sorrendje — a „kisebb" koordináta a `from`.
     *
     * A `distances` tábla eddig ugyanazt a párt kétszer tárolta (A->B ÉS B->A),
     * mert a cron minden templomot külön dolgoz fel `from`-ként, a `Coord` unique
     * kulcs pedig a két tuple-t különbözőnek látja. Innentől egy pár egy sor.
     */
    public static function canonicalPair(array $a, array $b): array {
        if ($a['lat'] < $b['lat'] || ($a['lat'] == $b['lat'] && $a['lon'] <= $b['lon'])) {
            return [$a, $b];
        }
        return [$b, $a];
    }

    /**
     * #748: a párhoz tartozó sor — BÁRMELYIK tárolt irányban.
     *
     * A még nem normalizált (fordított) sorokat így a helyükön frissítjük, nem
     * hozunk mellé újat; új sor viszont már csak kanonikus sorrendben születik.
     * Így a migráció lefutása előtt sem keletkezik több duplikátum.
     */
    public static function findOrNewPair(array $a, array $b): \Eloquent\Distance {
        $existing = \Eloquent\Distance::where(function ($q) use ($a, $b) {
                    $q->where('fromLat', $a['lat'])->where('fromLon', $a['lon'])
                      ->where('toLat', $b['lat'])->where('toLon', $b['lon']);
                })->orWhere(function ($q) use ($a, $b) {
                    $q->where('fromLat', $b['lat'])->where('fromLon', $b['lon'])
                      ->where('toLat', $a['lat'])->where('toLon', $a['lon']);
                })->first();

        if ($existing) {
            return $existing;
        }

        [$from, $to] = self::canonicalPair($a, $b);

        $row = new \Eloquent\Distance();
        $row->fromLat = $from['lat'];
        $row->fromLon = $from['lon'];
        $row->toLat   = $to['lat'];
        $row->toLon   = $to['lon'];
        return $row;
    }

    function MupdateChurch($churchFrom, $maxDistance = 5000) { //maxDistance in meter
            set_time_limit('600');
            $counter = 0;
            if($churchFrom->location->lat == '' OR $churchFrom->location->lon == '') 
                return false;
               
            $point = ['lon' => $churchFrom->location->lon, 'lat' => $churchFrom->location->lat];
            

            //TODO: Delete BBOX-on belüli távolságok. Vagy minden távolság?                        
            
            for($i=1;$i<10;$i++) {
                $bbox = $this->getBBox($point, $maxDistance);
                $churchesInBBox = \Eloquent\Church::inBBox($bbox)->where('id', '!=', $churchFrom->id)->get();
                if(count($churchesInBBox) > 12) break;
                $maxDistance = $maxDistance * ( 120 / 100 );
            }
            
            $highestDistance = 0;
            foreach ($churchesInBBox as $churchTo) {  
                // #748: kanonikus sorrend, és a fordított irányú sort is megtaláljuk.
                $processingDistance = self::findOrNewPair(
                    ['lat' => $churchFrom->lat, 'lon' => $churchFrom->lon],
                    ['lat' => $churchTo->lat,   'lon' => $churchTo->lon]
                );
                if ($churchFrom->updated_at > $processingDistance->updated_at
                        OR $churchTo->updated_at > $processingDistance->updated_at) {

                    $pointFrom = ['lat' => $churchFrom->location->lat, 'lon' => $churchFrom->location->lon];
                    $pointTo = ['lat' => $churchTo->location->lat, 'lon' => $churchTo->location->lon];
                    $rawDistance = $this->getRawDistance($pointFrom, $pointTo);
                    if ($rawDistance < $maxDistance AND $rawDistance > 0) {
                        // #172: nem dőlünk el a Mapquesten - road-distance ha van,
                        // egyébként a légvonalbeli (haversine) rawDistance.
                        $resolved = $this->resolveDistance($pointFrom, $pointTo, $rawDistance);
                        $processingDistance->distance = $resolved['distance'];
                        // #526: légvonal-fallback -> toupdate=1 (később útvonalra frissítendő); road -> 0.
                        $processingDistance->toupdate = $resolved['road'] ? 0 : 1;
                        if ($resolved['distance'] > $highestDistance)
                            $highestDistance = $resolved['distance'];
                        $processingDistance->save();
                    } else {
                        //Pontatlant inkább soha senem mentünk el.
                        //$processingDistance->distance = $rawDistance;
                    }        

                    $counter++;                    
                }
            }
            /*
             * Ha találtunk olyat, hogy útvonalon annyival hosszabb, akkor
             * lehetséges, hogy van annál közelebbi is, ezért ki kell tágítani 
             * a kört. 
             */
            if($highestDistance > $maxDistance) {
                //echo "Van nagyobb kör is. Bocsesz.";
                
                //TODO: duplicated code
                $bbox = $this->getBBox($point, $highestDistance);
                $churchesInBBox = \Eloquent\Church::inBBox($bbox)->where('id', '!=', $churchFrom->id);
                
                foreach ($churchesInBBox as $churchTo) {  

                        // #748: kanonikus sorrend, és a fordított irányú sort is megtaláljuk.
                        $processingDistance = self::findOrNewPair(
                            ['lat' => $churchFrom->lat, 'lon' => $churchFrom->lon],
                            ['lat' => $churchTo->lat,   'lon' => $churchTo->lon]
                        );
                        $highestDistance = 0;
                        if ($churchFrom->updated_at > $processingDistance->updated_at
                                OR $churchTo->updated_at > $processingDistance->updated_at) {

                            $pointFrom = ['lat' => $churchFrom->location->lat, 'lon' => $churchFrom->location->lon];
                            $pointTo = ['lat' => $churchTo->location->lat, 'lon' => $churchTo->location->lon];
                            $rawDistance = $this->getRawDistance($pointFrom, $pointTo);

                            if ($rawDistance < $maxDistance AND $rawDistance > 0) {
                                // #172: road-distance ha elérhető, egyébként légvonal.
                                $resolved = $this->resolveDistance($pointFrom, $pointTo, $rawDistance);
                                $processingDistance->distance = $resolved['distance'];
                                $processingDistance->toupdate = $resolved['road'] ? 0 : 1; // #526
                                $processingDistance->save();
                            } else {
                                //Pontatlant inkább soha senem mentünk el.
                                //$processingDistance->distance = $rawDistance;
                            }        

                            $counter++;                    
                        }
                    }
                
            }
            return $counter;
    }

    // #172: a szomszédság-számítás ne dőljön el a Mapquesten. Ha a road-distance
    // elérhető (van kulcs, nincs rate-limit), azt adjuk vissza; egyébként a
    // légvonalbeli (haversine) rawDistance-re esünk vissza. Így a feature mindig
    // frissül, a Mapquest csak opcionális felminősítés.
    // #526: visszaadja a távolságot ÉS a minőségét ('road' => true, ha Mapquest
    // útvonal-távolság; false, ha csak légvonal). A hívó ez alapján állítja a
    // distances.toupdate flaget (1 = később útvonalra frissítendő).
    function resolveDistance($pointFrom, $pointTo, $rawDistance) {
        try {
            $mapquest = new \ExternalApi\MapquestApi();
            $mapquestDistance = $mapquest->distance($pointFrom, $pointTo);
            if ($mapquestDistance > 0) {
                return ['distance' => $mapquestDistance, 'road' => true];
            }
        } catch (\Throwable $e) {
            // nincs Mapquest-kulcs vagy bármilyen hiba -> marad a légvonal
        }
        return ['distance' => $rawDistance, 'road' => false];
    }

    function getRawDistance($pointFrom, $pointTo) {
        $this->validatePoint($pointFrom);
        $this->validatePoint($pointTo);

        $lat1 = $pointFrom['lat'] * M_PI / 180;
        $lat2 = $pointTo['lat'] * M_PI / 180;
        $long1 = $pointFrom['lon'] * M_PI / 180;
        $long2 = $pointTo['lon'] * M_PI / 180;

        if ($lat1 == $lat2 AND $long1 == $long2)
            return 0;

        $R = 6371; // km
        $d = $R * acos(sin($lat1) * sin($lat2) + cos($lat1) * cos($lat2) * cos($long2 - $long1)) * 1000;
        return $d;
    }

    function getBBox($point, $distanceInM) {
        $this->validatePoint($point);

        $distanceInKm = $distanceInM / 1000;
        // earth's radius in km = ~6371
        $radius = 6371;

        // latitude boundaries
        $bbox['latMax'] = $point['lat'] + rad2deg($distanceInKm / $radius);
        $bbox['latMin'] = $point['lat'] - rad2deg($distanceInKm / $radius);

        // longitude boundaries (longitude gets smaller when latitude increases)
        $bbox['lonMax'] = $point['lon'] + rad2deg($distanceInKm / $radius / cos(deg2rad($point['lat'])));
        $bbox['lonMin'] = $point['lon'] - rad2deg($distanceInKm / $radius / cos(deg2rad($point['lat'])));

        return $bbox;
    }

    function validatePoint($point) {
        if (!$this->isPoint($point)) {
            throw new \Exception('$point has wrong format: ' . print_r($point, 1));
        } else {
            return true;
        }
    }

    function isPoint($point) {
        if (!isset($point['lat']) or ! isset($point['lon']))
            return false;
        if ($point['lat'] == '' or $point['lon'] == '')
            return false;
        if (!is_numeric($point['lat']) or ! is_numeric($point['lon']))
            return false;
        if ($point['lat'] < -90 or $point['lat'] > 90)
            return false;
        if ($point['lon'] < -180 or $point['lon'] > 180)
            return false;

        return true;
    }

}
