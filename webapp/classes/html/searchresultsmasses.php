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
            'timezone' => \Request::Text('timezone')
        ];

        // Time range search
        $start_date = (isset($params['start_date']) AND $params['start_date'] != '') ? $params['start_date'] : date('Y-m-d');
        $start_time = (isset($params['start_time']) AND $params['start_time'] != '') ? $params['start_time'] : '00:00';
        $end_date = (isset($params['end_date']) AND $params['end_date'] != '') ? $params['end_date'] : date('Y-m-d', strtotime('+1 week'));
        $end_time = (isset($params['end_time']) AND $params['end_time'] != '') ? $params['end_time'] : '23:59';

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

        // ---- BASE szűrők (mindig megmaradnak): hely, egyházmegye, nyelv, kulcsszó, időtartam ----
        $applyBaseFilters = function (\Search $search) use ($params, $from, $until) {
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
            $search->timeRange($from, $until);
        };

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

                $allTitles = (new \MassDefinitions())->titlesByCategories($selectedCategories);
                if (!empty($allTitles)) {
                    foreach ($allTitles as $title) {
                        $cleanTitle = preg_replace('/^MASS_TITLE\./', '', $title);
                        $allTitles[] = $title;
                        $translatedTitle = t('MASS_TITLE.' . $cleanTitle);
                        $allTitles[] = $translatedTitle;
                    }
                    $titleFilters = array_unique($allTitles);
                    $titleFilters = array_values($titleFilters);
                    if (!empty($titleFilters)) {
                        $search->query['bool']['must'][] = [ 'terms' => ['title.keyword' => $titleFilters] ];
                        $translatedCategoryNames = array_map(function($c){ return t('MASS_TITLE_CATEGORY.' . $c); }, $selectedCategories);
                        $search->filters[] = "Kategóriák: <b>" . implode('</b> vagy <b>', $translatedCategoryNames) . "</b>";
                    }
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
            foreach ($results as &$result) {
                $church = \Eloquent\Church::find($result->church_id);
                $result->church = $church->toArray();

                $result->mass = \Eloquent\CalMass::find($result->mass_id)->toArray();
                if($result->mass['rrule'])
                    $rrule = new \SimpleRRule($result->mass['rrule']);
                    if(isset($rrule)) {
                        $result->mass['rrule']['readable'] = $rrule->toText();
                    }
                if(isset($result->mass['periodId'])) {
                    $result->period = \Eloquent\CalPeriod::find($result->mass['periodId'])->toArray();
                }
            }
        }

        $url = \Pagination::qe($params, '/?' );
        $this->pagination->set($search->total, $url );

        $this->filters = $search->getFilters();

        $this->template = 'search/resultsmasses.twig';

        $this->results = $results;
    }

}
