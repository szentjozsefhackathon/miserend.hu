<?php

namespace Html;

use Illuminate\Database\Capsule\Manager as DB;

class SearchResultsChurches extends Html {

    /** #722: a hely szerinti szűrés kiindulópontja, hogy a sidebar tovább tudja vinni. */
    public $nearbyOrigin;
    public $nearbyRadius;

    /**
     * #722: koordináta-hármas ellenőrzése. Tiszta függvény (se DB, se HTTP), hogy
     * tesztelhető legyen, és hogy a templom- és a misekereső ugyanazt a határt húzza meg.
     *
     * @return array{lat: float, lon: float, radius: float}|null
     */
    public static function validNearbyPoint($lat, $lon, $radiusKm): ?array {
        if ($lat === false || $lat === '' || $lon === false || $lon === '') {
            return null;
        }
        if (!is_numeric($lat) || !is_numeric($lon) || !is_numeric($radiusKm)) {
            return null;
        }
        $lat = (float) $lat;
        $lon = (float) $lon;
        $radiusKm = (float) $radiusKm;

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return null;
        }
        if ($radiusKm <= 0 || $radiusKm > 200) {
            return null;
        }

        return ['lat' => $lat, 'lon' => $lon, 'radius' => $radiusKm];
    }

    /**
     * #89: helynév -> koordináta. Külön metódus, hogy a mise-kereső is ezt használja,
     * és egy helyen dőljön el, mi történik, ha a geokóder nem érhető el.
     *
     * @return array{lat:float,lon:float}|false
     */
    public static function geocodePlace(string $place) {
        try {
            $nominatim = new \ExternalApi\NominatimApi();
            return $nominatim->geocode($place);
        } catch (\Throwable $e) {
            // A geokóder kiesése ne döntse össze a keresést — a többi szűrő maradjon.
            return false;
        }
    }


    public $template = 'search/resultsChurches.twig';
    public $form = [];
    public $filters;
    public $churches;
    public $boundaryDataJson;
    public $churchDataJson;

    public function __construct() {
        parent::__construct();
        global $user, $config;

        $this->setTitle('Templom keresése');

        //Data for pagination        
		$params = [
		    'q' => 'SearchResultsChurches',
		    'kulcsszo' => \Request::Text('kulcsszo'),
		    'boundaries' => \Request::StringArray('boundaries', []),
		    'church_ids' => \Request::IntegerArray('church_ids') ?: [],
		    'lang' => \Request::StringArray('lang'),
		    // #89: hely + sugár. A mise-kereső eddig is beolvasta ezeket, de SOHA nem
		    // alkalmazta; a templomkereső pedig nem is ismerte őket.
		    'hely' => \Request::Text('hely'),
		    'tavolsag' => \Request::IntegerwDefault('tavolsag', 0),
		    // #722: a koordináta-mezők a címlapi űrlapon eddig is elmentek a templomkereséssel
		    // (egy formban vannak), de ez az oldal nem olvasta be őket — az „Egyéni közeli
		    // keresés" tehát a templomkeresésre teljesen hatástalan volt.
		    'nearby_lat' => \Request::Text('nearby_lat'),
		    'nearby_lon' => \Request::Text('nearby_lon'),
		    'nearby_radius' => \Request::Text('nearby_radius'),
		    // #667: a keresőűrlap rítus-gombjai (zöld/piros/semleges) eddig csak a
		    // mise-keresőre hatottak; a templomkereső némán figyelmen kívül hagyta őket.
		    'rites' => \Request::StringArray('rites'),
		    'ehm' => \Request::IntegerwDefault('ehm', 0),
		    // #666: az „Adottságok" szűrő eddig csak a misekeresésre hatott, pedig a
		    // címlap ugyanabból az űrlapból indítja a templomkeresést is — a paraméterek
		    // tehát megérkeztek, csak nem használtuk fel őket.
		    'wheelchair' => \Request::Text('wheelchair'),
		    'gluten_free' => \Request::Text('gluten_free')
		];
                        
        $search = new \Search('churches');
        
        // Main keyword search
        if (isset($params['kulcsszo'])) {
            $search->keyword($params['kulcsszo']);
            $this->form['kulcsszo']['value'] = $params['kulcsszo'];
        } else {
             $this->form['kulcsszo']['value'] = '';
        }
        
        // Boundaries' based search
        if (!empty($params['boundaries'])) {
            $search->boundaries($params['boundaries']);
            $this->boundaryDataJson = json_encode(\Eloquent\Boundary::whereIn('id', $params['boundaries'])->get()->map->toSimpleArray());
        }

        // Church ID filter (one or more specific churches)
        if (!empty($params['church_ids'])) {
            $search->churchIds($params['church_ids']);
            $this->churchDataJson = json_encode(
                \Eloquent\Church::select('id', 'nev', 'varos')
                    ->whereIn('id', $params['church_ids'])
                    ->get()
                    ->map(fn($c) => ['id' => $c->id, 'name' => $c->nev, 'city' => $c->varos])
            );
        }
        
        // Diocese filter
        if ($params['ehm'] > 0) {
            $ehmnev = DB::table('egyhazmegye')->where('id',$params['ehm'])->pluck('nev')[0];
            $search->addMust(["wildcard" => ['egyhazmegye.keyword' => $ehmnev ]]);
            $search->filters[] = "Egyházmegye: <b>" . htmlspecialchars($ehmnev) ." egyházmegye</b>";
        }

        // nyelvek filter
        $lang = $params['lang'];
        if (!empty($lang)) {
            $langsShould = isset($lang['should']) ? array_filter(array_map('trim', explode(',', $lang['should']))) : [];
            $langsMustNot = isset($lang['must_not']) ? array_filter(array_map('trim', explode(',', $lang['must_not']))) : [];

            if (!empty($langsShould)) {
                $search->addMust([ 'terms' => ['nyelvek' => $langsShould] ]);
                $translated = array_map(function($l){ return t('LANGUAGES.'.$l); }, $langsShould);
                $search->filters[] = "Amelyik templomban van liturgia <b>" . implode('</b> vagy <b>', $translated) . "</b> nyelven.";                              
            }

            if (!empty($langsMustNot)) {
                $search->addMustNot([ 'terms' => ['nyelvek' => $langsMustNot] ]);
                $translated = array_map(function($l){ return t('LANGUAGES.'.$l); }, $langsMustNot);
                $search->filters[] = "Amelyik templomban nincs liturgia <b>" . implode('</b> se <b>', $translated) . "</b> nyelven.";                              
            }
        }
        
        // #667: rítus-szűrő. A `ritusok` a templom származtatott mezője az indexben:
        // mely rítusokban van (bármikor) liturgia nála. Ugyanaz a should/must_not
        // hármas-állapot, mint a nyelveknél.
        //
        // A `.keyword` alváltozatra szűrünk, nem a mezőre magára: az elemzett `text`
        // mezőben a "GREEK_CATHOLIC" kisbetűsen áll, tehát a terms-lekérdezés nulla
        // találatot adna. (A nyelveknél ez csak azért nem gond, mert a kódok eleve
        // kisbetűsek.)
        $rites = $params['rites'];
        if (!empty($rites)) {
            $ritesShould = isset($rites['should']) ? array_filter(array_map('trim', explode(',', $rites['should']))) : [];
            $ritesMustNot = isset($rites['must_not']) ? array_filter(array_map('trim', explode(',', $rites['must_not']))) : [];

            if (!empty($ritesShould)) {
                $search->addMust([ 'terms' => ['ritusok.keyword' => $ritesShould] ]);
                $translated = array_map(function($r){ return t($r); }, $ritesShould);
                $search->filters[] = "Amelyik templomban van <b>" . implode('</b> vagy <b>', $translated) . "</b> liturgia.";
            }

            if (!empty($ritesMustNot)) {
                $search->addMustNot([ 'terms' => ['ritusok.keyword' => $ritesMustNot] ]);
                $translated = array_map(function($r){ return t($r); }, $ritesMustNot);
                $search->filters[] = "Amelyik templomban nincs <b>" . implode('</b> se <b>', $translated) . "</b> liturgia.";
            }
        }

        // #666: adottságok (akadálymentesség, gluténmentes áldozás). A Search metódusai
        // index-függetlenek — a misekeresőben `church.` prefixszel, itt anélkül szűrnek.
        $wheelchairFilter = array_filter(array_map('trim', explode(',', (string) $params['wheelchair'])));
        $glutenFreeFilter = array_filter(array_map('trim', explode(',', (string) $params['gluten_free'])));
        if ($wheelchairFilter) {
            $search->wheelchair($wheelchairFilter);
        }
        if ($glutenFreeFilter) {
            $search->glutenFree($glutenFreeFilter);
        }

        // #671: az adottság-adat még nagyon hiányos, a nulla találatot a felhasználó
        // hibának hinné. Mondjuk meg, hány misézőhelyről tudunk egyáltalán valamit.
        foreach (\Eloquent\Church::facilityCoverageMessages((bool) $wheelchairFilter, (bool) $glutenFreeFilter) as $message) {
            addMessage($message, 'info');
        }

        // #89: hely körüli keresés. A `hely` szöveget geokódoljuk, majd geo_distance
        // szűrőt teszünk a templom-index `location` mezőjére.
        //
        // #722: a koordináta erősebb jelzés, mint a helynév — ha megvan, azt használjuk,
        // és nem geokódolunk fölöslegesen. (A mise-kereső is így dönt, l. #608.) Az
        // űrlapon a JS a beírt helyet úgyis koordinátává alakítja, tehát a két ág
        // ugyanoda vezet; a helynév-ág JS nélkül és kézzel írt URL-nél él.
        $radius = $params['nearby_radius'] !== false && $params['nearby_radius'] !== ''
            ? (float) $params['nearby_radius']
            : (float) $params['tavolsag'];
        $coordinates = self::validNearbyPoint($params['nearby_lat'], $params['nearby_lon'], $radius);

        // A sidebar „Új keresés" űrlapja ezekből viszi tovább a hely szerinti szűrést.
        $this->form['hely']['value'] = $params['hely'] !== false ? $params['hely'] : '';
        $this->form['tavolsag']['value'] = (int) $params['tavolsag'];

        if ($coordinates !== null) {
            $this->nearbyOrigin = ['lat' => $coordinates['lat'], 'lon' => $coordinates['lon']];
            $this->nearbyRadius = $coordinates['radius'];
            $search->nearLocation($coordinates['lat'], $coordinates['lon'], $coordinates['radius'],
                $params['hely'] !== false && $params['hely'] !== '' ? $params['hely'] : 'a megadott pont');
        } elseif (!empty($params['hely']) && (int) $params['tavolsag'] > 0) {
            $point = self::geocodePlace($params['hely']);
            if ($point === false) {
                addMessage(
                    'Nem találtuk meg ezt a helyet: „' . htmlspecialchars($params['hely'])
                    . '”. A távolság szerinti szűrést kihagytuk.',
                    'warning'
                );
            } else {
                $search->nearLocation($point['lat'], $point['lon'], (float) $params['tavolsag'], $params['hely']);
            }
        }

        //Let's do the search
        $offset = $this->pagination->take * $this->pagination->active;
        $limit = $this->pagination->take;        		        
        $results = [];
        $results['results'] = $search->getResults($offset, $limit, false);
        $resultsCount = $search->total;

        // #575: ha a keresőmotor (Elasticsearch) nem elérhető, ne néma üres
        // találati oldalt mutassunk, hanem érthető, nem-szakmai üzenetet.
        if ($search->searchFailed) {
            addMessage('A fejlett keresőmotorunk sajnos éppen nem elérhető. Kérlek, próbáld újra pár perc múlva.', 'error');
        }
                		
        

        $url = \Pagination::qe($params, '/?' );
        $this->pagination->set($resultsCount, $url );

        $this->filters = $search->getFilters();

        if ($resultsCount < 1) {
            addMessage('A keresés nem hozott eredményt', 'info');
            return;
        } else if ($resultsCount == 1) {
            $url = '/templom/' . $results['results'][0]->id;            
            $this->redirect($url);
            return;
        } elseif ($resultsCount < $this->pagination->take * $this->pagination->active) {
            addMessage('Csupán ' . $resultsCount . " templomot találtunk.", 'info');
            return;
        }

        /* foreach ($results['results'] as $result) {
            $churchIds[] = $result->id;
        }
        $this->churches = \Eloquent\Church::whereIn('id', $churchIds)->get(); */
        $this->churches = json_decode(json_encode($results['results']), true);
        
    }

}
