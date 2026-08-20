<?php

namespace Html;

use Exception;
use ExternalApi\NapilelkibatyuApi;
use Illuminate\Database\Capsule\Manager as DB;

class SearchResultsMasses extends Html {
    public $liturgicalDays;
    public $filters;
    public $results;
    public $boundaryDataJson;
    public $nearbyMapJson = '[]';
    public $nearbyOrigin;
    public $nearbyRadius;
    // #608: üres közeli találat esetén a legközelebbi jövőbeli mise és a hozzá vezető linkek.
    public $nearbyNextMass;
    public $nearbyLookaheadDays = 7;
    public $nearbyLookaheadUrl;
    public $nearbyWiderRadiusUrls = [];

    public function __construct() {
        parent::__construct();
        global $user, $config;

        //Data for pagination
        $params = [
            'q' => 'SearchResultsMasses',
            'boundaries' => \Request::StringArray('boundaries', []),
            'church_ids' => \Request::IntegerArray('church_ids') ?: [],
            'varos' => \Request::Text('varos'),
            'tavolsag' => \Request::IntegerwDefault('tavolsag', 4),
            'hely' => \Request::Text('hely'),
            'kulcsszo' => \Request::Text('kulcsszo'),
            'espker' => \Request::Integer('espker'),
            'ehm' => \Request::Integer('ehm'),
            'types' => \Request::ArrayArray('types'),  // Be aware: this is a nested array with rite keys, e.g. types[rite1][should], types[rite1][must_not], types[rite2][should], etc.
            'rites' => \Request::StringArray('rites'), // Be aware: this is an array with 'should' and 'must_not' keys, e.g. rites[should], rites[must_not]
            'categories' => \Request::Text('categories'), // Simple comma-separated list of selected category keys
            // #644: akadálymentesség és gluténmentes áldozás szűrők (vesszős lista).
            'wheelchair' => \Request::Text('wheelchair'),
            'gluten_free' => \Request::Text('gluten_free'),
            'start_date' => \Request::Text('start_date'),
            'start_time' => \Request::Text('start_time'),
            'end_date' => \Request::Text('end_date'),
            'end_time' => \Request::Text('end_time'),
            'lang' => \Request::StringArray('lang'), // Be aware: this is an array with 'should' and 'must_not' keys, e.g. lang[should], lang[must_not]
            'timezone' => \Request::Text('timezone'),
            'nearby_lat' => \Request::Text('nearby_lat'),
            'nearby_lon' => \Request::Text('nearby_lon'),
            'nearby_radius' => \Request::Text('nearby_radius'),
        ];

        $nearby = null;
        $hasLatitude = $params['nearby_lat'] !== false && $params['nearby_lat'] !== '';
        $hasLongitude = $params['nearby_lon'] !== false && $params['nearby_lon'] !== '';
        $hasRadius = $params['nearby_radius'] !== false && $params['nearby_radius'] !== '';

        // #722: a helynévhez és a koordinátához eddig két külön távolság-választó tartozott
        // (`tavolsag` és `nearby_radius`) ugyanahhoz a kereséshez. Az űrlapon már csak egy
        // van; a régi `nearby_radius` paraméter továbbra is érvényes (a találati oldal
        // rejtett mezői és a kézzel írt URL-ek miatt), de ha hiányzik, a `tavolsag` lép a
        // helyébe.
        if (!$hasRadius && (int) $params['tavolsag'] > 0) {
            $params['nearby_radius'] = $params['tavolsag'];
            $hasRadius = true;
        }

        // #608: a hely szerinti szűrés hibás bemenete nem fatális hiba. Tipikus eset a
        // magyar tizedesvessző: a number input az értelmezhetetlen értéket üres stringként
        // küldi el, így csak az egyik koordináta érkezik meg. Ilyenkor jelezzük a bajt,
        // és hely nélkül keresünk tovább — a felhasználó így legalább kap találatokat.
        if ($hasLatitude !== $hasLongitude) {
            addMessage('A szélességi és a hosszúsági fokot együtt kell megadni, tizedesponttal (például 47.4979). A hely szerinti szűrést kihagytam.', 'error');
        } elseif ($hasLatitude) {
            $latitude = $params['nearby_lat'];
            $longitude = $params['nearby_lon'];
            $radius = $hasRadius ? $params['nearby_radius'] : null;

            if (!is_numeric($latitude) || !is_numeric($longitude) || !is_numeric($radius)) {
                addMessage('A helyzet és a sugár csak szám lehet, tizedesponttal (például 47.4979). A hely szerinti szűrést kihagytam.', 'error');
            } elseif ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                addMessage('A megadott koordináta érvénytelen: a szélesség -90 és 90, a hosszúság -180 és 180 közé eshet. A hely szerinti szűrést kihagytam.', 'error');
            } elseif ($radius <= 0 || $radius > 200) {
                addMessage('A sugárnak 0 és 200 km között kell lennie. A hely szerinti szűrést kihagytam.', 'error');
            } else {
                $nearby = [
                    'lat' => (float) $latitude,
                    'lon' => (float) $longitude,
                    'radius' => (float) $radius,
                ];
                $this->nearbyOrigin = ['lat' => $nearby['lat'], 'lon' => $nearby['lon']];
                $this->nearbyRadius = $nearby['radius'];
            }
        }

        // Time range search
        // #608: a dátum és az idő ellenőrzés nélkül ment tovább az Elasticsearchbe és a
        // liturgikus API-nak, így egy elgépelt érték nyers ES-stacktrace-t öntött a
        // felhasználó képébe. Értelmezhetetlen bemenetnél az alapértelmezett időszakot
        // használjuk, és jelezzük — a keresés így legalább lefut.
        $invalidTimeRange = false;
        $start_date = $this->validDate($params['start_date'], date('Y-m-d'), $invalidTimeRange);
        $start_time = $this->validTime($params['start_time'], '00:00', $invalidTimeRange);
        $end_date = $this->validDate($params['end_date'], date('Y-m-d', strtotime('+1 week')), $invalidTimeRange);
        $end_time = $this->validTime($params['end_time'], '23:59', $invalidTimeRange);
        if ($invalidTimeRange) {
            addMessage('A megadott dátum vagy időpont értelmezhetetlen, ezért az alapértelmezett időszakot használom.', 'error');
        }

        $from = $start_date."T".$start_time.":00";
        $until = $end_date."T".$end_time.":00";

        $api = new \ExternalApi\NapilelkibatyuApi();
        $this->liturgicalDays = $api->getLiturgicalDaysInRange($from, $until);

        $typesReq = isset($params['types']) ? $params['types'] : [];
        $ritesReq = isset($params['rites']) ? $params['rites'] : [];
        $categoriesReq = isset($params['categories']) ? $params['categories'] : '';

        // #357: a típus/rítus/kategória szűrők a „szűkítő" filterek — ezeket iktatjuk ki,
        // ha 0 találat van, hogy legalább valamit visszaadjunk.
        $hasNarrowingFilters = !empty($typesReq) || !empty($ritesReq) || !empty($categoriesReq);

        // #89: hely körüli keresés HELYNÉVVEL. A `hely`/`tavolsag` paramétert a kereső
        // EDDIG IS beolvasta, de soha nem alkalmazta — a találatok teljesen figyelmen
        // kívül hagyták a megadott helyet (a jegy példája: Szentendre + 10 km -> Micske).
        //
        // A körkeresést magát a #608 már megoldotta (Search::nearby, geo_distance a
        // mise-indexen), ezért itt csak a helynevet kell koordinátára váltani, és
        // ugyanabba a mechanizmusba beadni. Egy kódút, és a távolság szerinti rendezés
        // is jár hozzá — a korábbi kétlépcsős (templom-index -> 10 000 azonosító ->
        // terms-szűrő) kerülőútra nincs többé szükség.
        //
        // Ha koordináta IS érkezett, az nyer: az pontosabb, mint egy geokódolt helynév.
        $nearbyPlaceName = null;
        if ($nearby === null && !empty($params['hely']) && (int) $params['tavolsag'] > 0) {
            $radius = (float) $params['tavolsag'];

            if ($radius > 200) {
                addMessage('A sugárnak 0 és 200 km között kell lennie. A hely szerinti szűrést kihagytam.', 'error');
            } else {
                $point = \Html\SearchResultsChurches::geocodePlace($params['hely']);
                if ($point === false) {
                    addMessage(
                        'Nem találtuk meg ezt a helyet: „' . htmlspecialchars($params['hely'])
                        . '”. A távolság szerinti szűrést kihagytuk.',
                        'warning'
                    );
                } else {
                    $nearby = [
                        'lat' => (float) $point['lat'],
                        'lon' => (float) $point['lon'],
                        'radius' => $radius,
                    ];
                    $nearbyPlaceName = $params['hely'];
                    $this->nearbyOrigin = ['lat' => $nearby['lat'], 'lon' => $nearby['lon']];
                    $this->nearbyRadius = $nearby['radius'];
                }
            }
        }

        // ---- BASE szűrők (mindig megmaradnak): hely, egyházmegye, nyelv, kulcsszó, időtartam ----
        $applyBaseFilters = function (\Search $search, $rangeFrom = null, $rangeUntil = null, $sortByDistance = true) use ($params, $from, $until, $nearby, $nearbyPlaceName) {
            if ($params['timezone']) $search->timezone = $params['timezone'];

            // Boundaries' based search
            if (!empty($params['boundaries'])) {
                $search->boundaries($params['boundaries']);
                $this->boundaryDataJson = json_encode(\Eloquent\Boundary::whereIn('id', $params['boundaries'])->get()->map->toSimpleArray());
            }

            // Church ID filter (one or more specific churches)
            if (!empty($params['church_ids'])) {
                $search->churchIds($params['church_ids']);
            }

            // egyhazmegye filter
            if ($params['ehm'] > 0) {
                $ehmnev = DB::table('egyhazmegye')->where('id',$params['ehm'])->pluck('nev')[0];
                $search->addMust(["wildcard" => ['church.egyhazmegye.keyword' => $ehmnev ]]);
                $search->filters[] = "Egyházmegye: <b>" . htmlspecialchars($ehmnev) ." egyházmegye</b>";
            }

            // nyelvek filter
            if($params['lang']) {
                $langsShould = isset($params['lang']['should']) ? array_filter(array_map('trim', explode(',', $params['lang']['should']))) : [];
                $langsMustNot = isset($params['lang']['must_not']) ? array_filter(array_map('trim', explode(',', $params['lang']['must_not']))) : [];

                if (!empty($langsShould)) {
                    $search->languages($langsShould);
                }

                if (!empty($langsMustNot)) {
                    $search->addMustNot([ 'terms' => ['church.nyelvek.keyword' => $langsMustNot] ]);
                    $translated = array_map(function($l){ return t('LANGUAGES.'.$l); }, $langsMustNot);
                    $search->filters[] = "A liturgia nyelve ne legyen <b>" . implode('</b> se <b>', $translated) . "</b>";
                }
            }

            // Main keyword search
            if ($params['kulcsszo']) {
                $search->keyword($params['kulcsszo']);
            }

            // #644: a templom adottságai (akadálymentesség, gluténmentes áldozás).
            // BASE szűrők, tehát a 0-találatos fallback SEM dobja el őket: aki
            // kerekesszékkel keres, annak nem segít egy nem akadálymentes templom.
            if ($params['wheelchair']) {
                $search->wheelchair(array_filter(array_map('trim', explode(',', $params['wheelchair']))));
            }
            if ($params['gluten_free']) {
                $search->glutenFree(array_filter(array_map('trim', explode(',', $params['gluten_free']))));
            }

            // Time range
            $search->timeRange($rangeFrom ?? $from, $rangeUntil ?? $until);
            if ($nearby) {
                $search->nearby($nearby['lat'], $nearby['lon'], $nearby['radius'], $sortByDistance);

                // #89: koordinátánál a puszta „legfeljebb X km" elég, helynévnél viszont
                // mondjuk is meg, MITŐL — a felhasználó azt írta be, azt akarja viszontlátni.
                if ($nearbyPlaceName !== null) {
                    $search->filters[] = 'Innen: <b>' . htmlspecialchars($nearbyPlaceName) . '</b>';
                }
            }
        };

        /*
         * #671: az adottság-adat még nagyon hiányos (a seedben egyetlen misézőhelynek
         * sincs `wheelchair` attribútuma), ezért a szűrt keresés könnyen nulla találatot
         * ad — amit a felhasználó hibának hisz. Megmondjuk, hány helyről tudunk valamit.
         *
         * Szándékosan ITT, a closure-ön KÍVÜL: az $applyBaseFilters a 0-találatos
         * fallback-ágon még egyszer lefut, az üzenetet viszont csak egyszer akarjuk.
         */
        foreach (\Eloquent\Church::facilityCoverageMessages(
            (bool) $params['wheelchair'],
            (bool) $params['gluten_free']
        ) as $facilityMessage) {
            addMessage($facilityMessage, 'info');
        }

        // ---- SZŰKÍTŐ szűrők (típus / rítus / kategória) — ezeket dobjuk a fallback-ban ----
        $applyNarrowingFilters = function (\Search $search) use ($typesReq, $ritesReq, $categoriesReq) {
            if (!empty($typesReq) || !empty($ritesReq)) {
                // 1) Handle rites.must_not - exclude these rites entirely
                if (!empty($ritesReq['must_not'])) {
                    $mustNotRites = array_filter(array_map('trim', explode(',', $ritesReq['must_not'])));
                    foreach ($mustNotRites as $r) {
                        if ($r === '') continue;
                        $search->filters[] = "A rítus nem lehet: <i>" . htmlspecialchars(t($r)) . "</i>";
                        $search->query['bool']['must_not'][] = [ 'term' => ['rite.keyword' => $r] ];
                    }
                }

                // 2) Handle rites.should - at least one of these rite+type combinations must match
                if (!empty($ritesReq['should'])) {
                    $shouldRites = array_filter(array_map('trim', explode(',', $ritesReq['should'])));
                    $shouldClauses = [];

                    if (!empty($shouldRites)) {
                        $translated = array_map(function($r){ return t($r); }, $shouldRites);
                        $search->filters[] = 'A rítus lehet <i>' . implode('</i> vagy <i>', $translated) . '</i>';
                    }
                    foreach ($shouldRites as $r) {
                        if ($r === '') continue;
                        $cl = [ 'bool' => [ 'must' => [ [ 'term' => ['rite.keyword' => $r] ] ] ] ];

                        if (!empty($typesReq[$r]) && is_array($typesReq[$r])) {
                            $tShould = [];
                            if (!empty($typesReq[$r]['should'])) {
                                if (is_array($typesReq[$r]['should'])) {
                                    $tShould = $typesReq[$r]['should'];
                                } else {
                                    $tShould = array_filter(array_map('trim', explode(',', $typesReq[$r]['should'])));
                                }
                            }
                            $tMustNot = [];
                            if (!empty($typesReq[$r]['must_not'])) {
                                if (is_array($typesReq[$r]['must_not'])) {
                                    $tMustNot = $typesReq[$r]['must_not'];
                                } else {
                                    $tMustNot = array_filter(array_map('trim', explode(',', $typesReq[$r]['must_not'])));
                                }
                            }

                            if (!empty($tShould)) {
                                $shouldTerms = [];
                                foreach ($tShould as $tt) {
                                    if ($tt === '') continue;
                                    $shouldTerms[] = [ 'term' => ['types.keyword' => $tt] ];
                                }
                                $cl['bool']['must'][] = [ 'bool' => [
                                    'should' => $shouldTerms,
                                    'minimum_should_match' => 1
                                ]];
                            }

                            if (!empty($tMustNot)) {
                                foreach ($tMustNot as $tt) {
                                    $cl['bool']['must_not'][] = [ 'term' => ['types.keyword' => $tt] ];
                                }
                            }
                            foreach($tShould as $k => $ts)  $tShould[$k] = t($ts);
                            foreach($tMustNot as $k => $ts)  $tMustNot[$k] = t($ts);

                            if (!empty($tShould) or !empty($tMustNot)) {
                                $search->filters[] = "Ha <b>".t($r)."</b> rítus, akkor  " .
                                    (!empty($tShould) ? "legyen: <b>" . implode('</b> vagy <b>', $tShould) . "</b>" : '') .
                                    (!empty($tShould) && !empty($tMustNot) ? ", de " : '') .
                                    (!empty($tMustNot) ? "ne legyen: <b>" . implode('</b> vagy <b>', $tMustNot) . "</b>" : '');
                            }
                        }

                        $shouldClauses[] = $cl;
                    }

                    if (!empty($shouldClauses)) {
                        $search->query['bool']['must'][] = [ 'bool' => [ 'should' => $shouldClauses, 'minimum_should_match' => 1 ] ];
                    }
                }
            }

            // Process categories filter
            if (!empty($categoriesReq)) {
                $selectedCategories = array_filter(array_map('trim', explode(',', $categoriesReq)));

                /*
                 * #299: a klauzula előállítása a \MassDefinitions-ben van, mert az
                 * API-nak (api/search.php `categories`) is pontosan ugyanez kell. Egy
                 * implementáció, két hívó — így a kettő nem tud szétcsúszni.
                 *
                 * #157: a szűrő eddig egy zárt CÍMLISTÁRA ment. A `cal_masses.title`
                 * viszont szabad szöveg — importnál a nyers ICS-cím kerül bele —, tehát
                 * minden importált esemény kiesett MINDEN kategóriából. Mostantól az
                 * indexelt `category` is számít, a cím-lista pedig mellette marad.
                 */
                $clause = (new \MassDefinitions())->categoryQueryClause($selectedCategories);
                if ($clause !== null) {
                    $search->query['bool']['must'][] = $clause;
                    $translatedCategoryNames = array_map(function($c){ return t('MASS_TITLE_CATEGORY.' . $c); }, $selectedCategories);
                    $search->filters[] = "Kategóriák: <b>" . implode('</b> vagy <b>', $translatedCategoryNames) . "</b>";
                }
            }
        };

        // Set title based on number of selected categories
        $selectedCategories = !empty($categoriesReq) ? array_filter(array_map('trim', explode(',', $categoriesReq))) : [];
        if (count($selectedCategories) === 1) {
            $categoryKey = reset($selectedCategories);
            $categoryTranslation = t('MASS_TITLE_CATEGORY.' . $categoryKey);
            $this->setTitle($categoryTranslation . ' keresése');
        } else {
            $this->setTitle('Események keresése');
        }

        $offset = $this->pagination->take * $this->pagination->active;
        $limit = $this->pagination->take;

        // ---- 1. menet: teljes keresés (base + szűkítő szűrők) ----
        $search = new \Search('masses');
        $applyBaseFilters($search);
        $applyNarrowingFilters($search);
        $results = $search->getResults($offset, $limit, false);

        // #724: csak az ELSŐ menetet számoljuk. A lazított (#357) és a lookahead keresés
        // ugyanannak a kérésnek a folytatása, azok külön nem használati esemény.
        if (!$search->searchFailed) {
            \Stats::countSearch($params['kulcsszo'] !== false ? $params['kulcsszo'] : null, (int) $search->total);
        }

        // #575: ha a keresőmotor (Elasticsearch) nem elérhető, érthető üzenet a
        // néma üres oldal helyett. (A #357 lazított 2. menet is elhasalna ES nélkül.)
        if ($search->searchFailed) {
            addMessage('A fejlett keresőmotorunk sajnos éppen nem elérhető. Kérlek, próbáld újra pár perc múlva.', 'error');
        }

        // #357: ha 0 találat ÉS volt szűkítő szűrő → 2. menet a szűkítők nélkül.
        if ($search->total == 0 && $hasNarrowingFilters) {
            $relaxed = new \Search('masses');
            $applyBaseFilters($relaxed);
            $relaxedResults = $relaxed->getResults($offset, $limit, false);

            if ($relaxed->total > 0) {
                addMessage(
                    'A megadott típus/rítus/kategória szűrőkkel nem találtunk misét, ezért azokat kiiktattuk. '
                    . 'A többi feltételnek (hely, nyelv, időpont) megfelelő misék alább láthatók.',
                    'info'
                );
                $search = $relaxed;
                $results = $relaxedResults;
            }
        }

        if ($search->total != 0) {
            $mapChurches = [];
            /*
             * #608: az Elasticsearch index természeténél fogva lemarad az adatbázistól —
             * hivatkozhat olyan templomra, misére vagy időszakra, amit közben töröltek.
             * Ezek a ->find(...)->toArray() láncok ilyenkor `toArray() on null`-lal DÖNTÖTTÉK
             * LE a teljes találati oldalt (HTTP 500, üres válasz). Egy elavult indexsor nem
             * viheti el az egész keresést: a hiányzó templomú/miséjű találatot kihagyjuk,
             * a hiányzó időszakot pedig egyszerűen nem tesszük a sorhoz.
             */
            $staleResults = [];
            foreach ($results as $index => &$result) {
                $church = \Eloquent\Church::find($result->church_id);
                $mass = \Eloquent\CalMass::find($result->mass_id);
                if (!$church || !$mass) {
                    $staleResults[] = $index;
                    continue;
                }
                $result->church = $church->toArray();

                $result->mass = $mass->toArray();
                if($result->mass['rrule'])
                    $rrule = new \SimpleRRule($result->mass['rrule']);
                    if(isset($rrule)) {
                        $result->mass['rrule']['readable'] = $rrule->toText();
                    }
                if(isset($result->mass['periodId'])) {
                    $period = \Eloquent\CalPeriod::find($result->mass['periodId']);
                    if ($period) {
                        $result->period = $period->toArray();
                    }
                }
                if ($nearby && (float) $result->church['lat'] !== 0.0 && (float) $result->church['lon'] !== 0.0) {
                    $churchId = (int) $result->church_id;
                    if (!isset($mapChurches[$churchId])) {
                        $mapChurches[$churchId] = [
                            'id' => $churchId,
                            'name' => $result->church['names'][0] ?? '',
                            'city' => is_array($result->church['varos']) ? ($result->church['varos'][0] ?? '') : $result->church['varos'],
                            'lat' => (float) $result->church['lat'],
                            'lon' => (float) $result->church['lon'],
                            'distance_km' => $result->distance_km ?? null,
                            'times' => [],
                        ];
                    }
                    $mapChurches[$churchId]['times'][] = date('H:i', strtotime($result->start_date));
                }
            }
            unset($result);

            // Az elavult indexsorok ne jelenjenek meg üres sorként a találati listában.
            if ($staleResults !== []) {
                foreach ($staleResults as $index) {
                    unset($results[$index]);
                }
                $results = array_values($results);
                error_log('[#608] ' . count($staleResults) . ' elavult Elasticsearch-találat kihagyva '
                    . '(törölt templom vagy mise) — az index frissítésre szorul.');
            }

            if ($nearby) {
                foreach ($mapChurches as &$mapChurch) {
                    // #608: a gombostű a legkorábbi időpontot mutatja, a kártya az összeset.
                    $mapChurch['times'] = array_values(array_unique($mapChurch['times']));
                    sort($mapChurch['times']);
                }
                unset($mapChurch);
                $this->nearbyMapJson = json_encode(array_values($mapChurches), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            }
        }

        // #608: a „következő két óra" a nap nagy részében üres (este szinte mindig).
        // Néma üres lista helyett megkeressük a legközelebbi jövőbeli misét ugyanott,
        // ugyanazokkal a szűrőkkel — különben olyan misét ígérnénk, amit a felhasználó kiszűrt.
        if ($nearby && $search->total == 0 && !$search->searchFailed) {
            $lookaheadUntil = date('Y-m-d\TH:i:s', strtotime($from . ' +' . $this->nearbyLookaheadDays . ' days'));

            $lookahead = new \Search('masses');
            $applyBaseFilters($lookahead, $from, $lookaheadUntil, false);
            $applyNarrowingFilters($lookahead);
            $lookaheadResults = $lookahead->getResults(0, 1, false);

            if (!empty($lookaheadResults)) {
                $next = $lookaheadResults[0];
                $nextChurch = \Eloquent\Church::find($next->church_id);
                $nextChurchArray = $nextChurch ? $nextChurch->toArray() : [];
                $this->nearbyNextMass = [
                    'title' => $next->title ?? '',
                    'start_date' => $next->start_date,
                    'distance_km' => $next->distance_km ?? null,
                    'church_id' => (int) $next->church_id,
                    'church_name' => $nextChurchArray['names'][0] ?? '',
                    'church_city' => is_array($nextChurchArray['varos'] ?? null)
                        ? ($nextChurchArray['varos'][0] ?? '')
                        : ($nextChurchArray['varos'] ?? ''),
                    'total' => $lookahead->total,
                ];
                $this->nearbyLookaheadUrl = $this->buildSearchUrl($params, [
                    'end_date' => substr($lookaheadUntil, 0, 10),
                    'end_time' => substr($lookaheadUntil, 11, 5),
                ]);
            } else {
                // A sugáron belül egyáltalán nincs mise a következő héten — itt nem az idő
                // a szűk keresztmetszet, hanem a távolság.
                foreach ([5, 10, 15] as $widerRadius) {
                    if ($widerRadius <= $nearby['radius']) continue;
                    $this->nearbyWiderRadiusUrls[$widerRadius] = $this->buildSearchUrl($params, [
                        'nearby_radius' => $widerRadius,
                        'end_date' => substr($lookaheadUntil, 0, 10),
                        'end_time' => substr($lookaheadUntil, 11, 5),
                    ]);
                }
            }
        }

        $url = \Pagination::qe($params, '/?' );
        $this->pagination->set($search->total, $url );

        $this->filters = $search->getFilters();

        $this->template = 'search/resultsmasses.twig';

        $this->results = $results;
    }

    /**
     * #608: csak ÉÉÉÉ-HH-NN alakú, létező naptári dátumot engedünk tovább.
     * Hiányzó érték nem hiba (alapértelmezést kap), az értelmezhetetlen viszont igen.
     */
    private function validDate($value, string $default, bool &$invalid): string {
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return $value;
        }

        $invalid = true;
        return $default;
    }

    /**
     * #608: csak ÓÓ:PP alakú, létező időpontot engedünk tovább.
     */
    private function validTime($value, string $default, bool &$invalid): string {
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value)) {
            return $value;
        }

        $invalid = true;
        return $default;
    }

    /**
     * #608: keresési URL az aktuális paraméterekből, néhány felülírt értékkel.
     * A \Request::Text() a hiányzó mezőkre false-t ad, amit a http_build_query
     * "0"-vá alakítana (pl. kulcsszo=0) — ezért az üres értékeket kidobjuk.
     */
    private function buildSearchUrl(array $params, array $overrides): string {
        $merged = array_filter(
            array_merge($params, $overrides),
            function ($value) {
                return $value !== false && $value !== null && $value !== '' && $value !== [];
            }
        );

        return \Pagination::qe($merged, '/?');
    }

}
