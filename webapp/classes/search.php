<?php

use Carbon\Carbon;
use ExternalApi\ElasticsearchApi;

class Search {

    /**
     * #864: FORDÍTÁS + ESCAPE, egy lépésben — a szűrő-címkékhez.
     *
     * A `filters` tömb elemei HTML-t tartalmaznak (`<b>`, `<i>`), ezért a sablon
     * `|raw`-val írja ki őket. Ez rendben van, amíg CSAK a mi HTML-ünk kerül bele —
     * csakhogy a fordítandó kulcs a felhasználó kérésparaméteréből jön, a `t()` pedig
     * ISMERETLEN kulcsnál magát a kulcsot adja vissza (`translator.php:91`):
     *
     *     return self::$translations[$key] ?? ($default ?? $key);
     *
     * Vagyis egy nem létező „nyelvre" vagy „rítusra" szűrve a bemenet változatlanul
     * kijutott a lapra. Mérve, azonosítás nélkül:
     *
     *     /index.php?q=SearchResultsChurches&rites[should]=<img src=x onerror=alert(1)>
     *     -> a válasz törzsében: <img src=x onerror=alert(1)>
     *
     * Nincs CSP-fejléc, tehát a script lefut. Bejelentkezett gondnoknál vagy adminnál
     * ez munkamenet-átvétel — egyetlen megküldött linkkel.
     *
     * Azért EGY metódus a kettőre, mert a hívóhelyeken a két lépés elválasztva
     * felejthető el: a `htmlspecialchars(t($x))` sorrendjét könnyű elrontani vagy
     * kihagyni, és épp ez történt tizenegy helyen.
     *
     * @param  string|string[] $keys
     * @return string|string[] a fordítás(ok), HTML-be biztonságosan
     */
    public static function tSafe($keys) {
        if (is_array($keys)) {
            return array_map([self::class, 'tSafe'], $keys);
        }

        return htmlspecialchars((string) t((string) $keys), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public $query =  ["bool" => ["must" => [], "must_not" => []]];
    public $sort = [];
    // #608: melyik sort-slot hordozza a geo-távolságot. Vakon a sort[0]-ból olvasni
    // hibás, mert idő szerinti rendezésnél az epoch-milliszekundum is numerikus.
    public $distanceSortIndex = null;
    public $runtimeMappings = [];
    public $total = 0; // Találatok száma
    public $searchFailed = false; // #575: true, ha az ES nem adott érvényes választ (nem elérhető ≠ 0 találat)
    public $filters = []; 
    public $massOrChurch = 'church';
    public $pitId = false; 
    private $pit_keepAlive;
    public $search_after = false; 
    public $defaultTimezone = 'Europe/Budapest';   
    public $timezone = 'Europe/Budapest';
    private $index;
    public $rawQuery;
    public $countHits;
    
    /**
     * Constructor
     *
     * Initialize the Search helper. Sets default runtime options like timezone
     * and accepts optional parameters that can be used to build the internal
     * query structure ($this->query).
     *
     * @param mixed $massOrChurch Mode selector (e.g. 'mass' or 'church') — caller may
     *                            use this to build different queries
     * @param array $params       Optional associative array of parameters to influence
     *                            query building (filters, ranges, pagination hints)
     */
    function __construct($massOrChurch, $params = []) {
        
        switch ($massOrChurch) {
            case 'mass':
            case 'masses':
                $this->massOrChurch = 'mass';
                $this->index = "mass_index";
                break;
            case 'church':
            case 'churches':
                $this->massOrChurch = 'church';
                $this->index = "churches";
                break;
            default:
                throw new Exception("Invalid massOrChurch parameter: " . $massOrChurch);

        }

        /*
                // templom ID-k
                $query['bool']['must'][] = [
                    "terms" => ["church_id" => array_map('intval', $this->tids)]
                ];
              
                // nyelv                
                if ($this->notLang)   $query['bool']['must_not'][] = ["term" => ["lang" => $this->notLang]];

                // típus
                if ($this->type)      $query['bool']['must'][]     = ["term" => ["types" => $this->type]];
                if ($this->notType)   $query['bool']['must_not'][] = ["term" => ["types" => $this->notType]];

                // rítus                
                if ($this->notRite)   $query['bool']['must_not'][] = ["term" => ["rite" => $this->notRite]];

                if ($this->title) {
                    $query['bool']['must'][] = [
                        "wildcard" => [
                            "title" => "*" . strtolower($this->title) . "*"
                        ]
                    ];
                }

                if ($this->notTitle) {
                    $query['bool']['must_not'][] = [
                        "wildcard" => [
                            "title" => "*" . strtolower($this->notTitle) . "*"
                        ]
                    ];
                }

                if ($this->comment) {
                    $query['bool']['must'][] = [
                        "wildcard" => [
                            "comment" => "*" . strtolower($this->comment) . "*"
                        ]
                    ];
                }

                if ($this->notComment) {
                    $query['bool']['must_not'][] = [
                        "wildcard" => [
                            "comment" => "*" . strtolower($this->notComment) . "*"
                        ]
                    ];
                }
                                
                */
            
                

    }

    function addMust($condition) {
        $this->query['bool']['must'][] = $condition;
    }

    function addMustNot($condition) {
        $this->query['bool']['must_not'][] = $condition;
    }

    function tids(array $tids) {
        $this->filters[] = "Templom ID-k: " . htmlspecialchars(implode(', ', $tids));
        if($this->massOrChurch === 'mass') {
            $field = "church_id";
        } else {
            $field = "id";
        }
        if (is_array($tids) && count($tids) > 0) {
            $this->query['bool']['must'][] = [
                "terms" => [$field => $tids]
            ];
        }
    }

    /**
     * Filter by one or more church IDs.
     * Uses a "term" query for a single ID and "terms" for multiple IDs.
     * Works for both the churches index (field: "id") and the mass index (field: "church.id").
     *
     * @param int[] $ids Array of integer church IDs
     */
    function churchIds(array $ids): void {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return;
        }

        if ($this->massOrChurch === 'mass') {
            $field = 'church.id';
        } else {
            $field = 'id';
        }

        if (count($ids) === 1) {
            $this->query['bool']['must'][] = ['term' => [$field => $ids[0]]];
        } else {
            $this->query['bool']['must'][] = ['terms' => [$field => $ids]];
        }

        if (count($ids) === 1) {
            $this->filters[] = "Adott templom";
        } else {
            $this->filters[] = count($ids) . " kiválasztott templom";
        }
    }

    function keyword($keyword) {
        if (empty($keyword)) return;
        $this->filters[] = "Kulcsszó: " . htmlspecialchars($keyword);

        if($this->massOrChurch === 'mass') {
            $prefix = 'church.';
        } else 
            $prefix = false;
        
        $bool = [];
        
        $bool['should'][] = [
            "match" => [
                $prefix."varos" => [
                    "query" => $keyword,
                    "operator" => "or",
                    "boost" => 64
                ]
            ]
        ];

        $bool['should'][] = ['term'=>[$prefix.'names'=>[ 'value' => $keyword, 'boost'=>32 ]]];			
        $bool['should'][] = ['term'=>[$prefix.'varos'=>[ 'value' => $keyword, 'boost'=>18 ]]];
        $bool['should'][] = ['term'=>[$prefix.'alternative_names'=>[ 'value' => $keyword, 'boost'=>7 ]]];
        $bool['should'][] = ['match'=>[$prefix.'names'=>[ 'query' => $keyword, 'boost'=>30 ]]];
        $bool['should'][] = ['match'=>[$prefix.'varos'=>[ 'query' => $keyword, 'boost'=>15 ]]];
        $bool['should'][] = ['match'=>[$prefix.'alternative_names'=>[ 'query' => $keyword, 'boost'=>5 ]]];
        $bool['should'][] = ['wildcard'=>[$prefix.'names'=>[ 'value' => '*'.$keyword.'*', 'boost'=>28 ]]];			
        $bool['should'][] = ['wildcard'=>[$prefix.'varos'=>[ 'value' => '*'.$keyword.'*', 'boost'=>12 ]]];
        $bool['should'][] = ['wildcard'=>[$prefix.'alternative_names'=>[ 'value' => '*'.$keyword.'*', 'boost'=>4 ]]];
    
        /*
         * #793: a szabad szöveget BOUNDARY-ra is lefordítjuk.
         *
         * borazslo döntése: „Sajnos meg kell próbálni a szabad szöveget is boundary-ra
         * fordítani, mert az emberek gyakran egyszerűek és nem jönnek rá a másik
         * útvonalra. Csak belefér a pontatlanság és egyebek. Hiszen a beírt szabadszöveg
         * egyszerre lehet a templom nevének részlete meg egy helyadata is."
         *
         * Eddig a helyre szűkítés CSAK az autocomplete-választáson keresztül működött:
         * aki beírta, hogy „Újbuda", és entert nyomott, nulla találatot kapott, mert a
         * templom-dokumentum egyetlen mezőjében sem szerepel ez a szó — csak a boundary
         * alternatív nevében.
         *
         * `should`-ág, tehát csak BŐVÍTI a találatokat, nem szűkíti: a kulcsszó
         * továbbra is illeszkedhet a templom nevére. A boost szándékosan a pontos
         * városnév-egyezés (18) alatt van — a helymatch valódi jel, de gyengébb, mint
         * amikor maga a beírt szó a település neve a dokumentumban.
         */
        $boundaryIds = self::boundaryIdsForKeyword($keyword);
        if ($boundaryIds !== []) {
            $bool['should'][] = ['terms' => [$prefix . 'boundaries' => $boundaryIds, 'boost' => 16]];
        }

        /*
         * #157: misére keresve MAGÁT A MISÉT is nézzük, ne csak a templomát.
         *
         * Eddig a kulcsszó kizárólag templom-mezőkre ment (`names`, `varos`,
         * `alternative_names`), tehát az esemény CÍME sehol nem számított. Aki
         * „Rorate"-ra vagy „angol nyelvű misé"-re keresett, nulla találatot kapott —
         * pedig a külső naptárakból épp ilyen, beszédes címek jönnek be.
         *
         * borazslo listáján ez így szerepel: „A keresőt fel kell készíteni, hogy ne
         * csak pontos egyezésnél találjon rá egy eseményre."
         *
         * A `match` az elemzett mezőn szótöredék helyett SZAVAKRA illeszt, tehát a
         * „szentmise angol" is megtalálja az „Angol nyelvű szentmise (P. Elek)"-et. A
         * `wildcard` mellette a szó belsejét is engedi, gyengébb súllyal. A boost a
         * városnév-egyezés (64/18) ALATT van: aki egy települést ír be, továbbra is a
         * települést kapja, a cím csak bővíti a találatokat.
         */
        if ($this->massOrChurch === 'mass') {
            $bool['should'][] = ['match'    => ['title'   => ['query' => $keyword, 'boost' => 14]]];
            $bool['should'][] = ['wildcard' => ['title'   => ['value' => '*' . mb_strtolower($keyword) . '*', 'boost' => 6]]];
            $bool['should'][] = ['match'    => ['comment' => ['query' => $keyword, 'boost' => 3]]];
        }

        $this->query['bool']['must'][] = ['bool' => $bool ];
    }

    /**
     * #793: a beírt szöveghez tartozó boundary-azonosítók.
     *
     * Rétegesen keresünk, és az ELSŐ nem üres réteget használjuk: pontos név, majd
     * kezdet szerinti, majd részlet. Enélkül egy rövid, gyakori szó (pl. „Szent")
     * tucatnyi településre illeszkedne, és minden ottani templomot előrehozna egy
     * olyan keresésnél, ami valójában a templom NEVÉRE vonatkozott.
     *
     * Az `alt_name`-re is illesztünk: a köznyelvi név gyakran ott ül (a budapesti
     * V. kerületé „Belváros-Lipótváros", a XI.-é „Újbuda").
     *
     * OSM-azonosítót NEM követelünk meg — az autocomplete igen, de ott a találatra
     * kattintani is lehet. Itt csak szűrünk, és a régi oszlopokból származó
     * boundary-k is valódi területek.
     *
     * @param string $keyword a beírt szöveg
     * @param int $limit hány boundary-t veszünk figyelembe legfeljebb
     * @return array<int,int>
     */
    public static function boundaryIdsForKeyword(string $keyword, int $limit = 25): array {
        $keyword = trim($keyword);
        if (mb_strlen($keyword) < 3) {
            return [];
        }

        $engedett = [
            'religious_administration', 'administrative', 'postal_code',
            'region', 'historic', 'tourism_region', 'wine_growing_area',
        ];

        foreach ([$keyword, $keyword . '%', '%' . $keyword . '%'] as $minta) {
            // Eggyel többet kérünk a korlátnál, hogy MEG TUDJUK KÜLÖNBÖZTETNI a
            // "pont ennyi van" esetet a "ennél több van" esettől.
            $talalat = \Eloquent\Boundary::whereIn('boundary', $engedett)
                ->where(function ($q) use ($minta) {
                    $q->where('name', 'like', $minta)
                      ->orWhere('alt_name', 'like', $minta);
                })
                ->orderBy('admin_level')
                ->take($limit + 1)
                ->pluck('id')
                ->all();

            if ($talalat === []) {
                continue;
            }

            /*
             * Ha egy réteg TÚLCSORDUL, akkor nem megkülönböztető, és eldobjuk.
             *
             * Ez a zajszűrő. A "Szent" például 25+ területre illeszkedik kezdetként
             * (Szentgotthárdi járás, Szentesi, Szentlőrinci járás...), pedig aki ezt
             * beírja, az szinte biztosan a templom NEVÉRE gondol. Ha ezeket
             * beengednénk, minden ottani templom előrébb kerülne.
             *
             * A szabály önszabályozó: nem kell kitalált hosszúság-küszöb, a
             * találatszám maga mondja meg, hogy a szöveg helynévként értelmes-e.
             */
            if (count($talalat) > $limit) {
                continue;
            }

            return array_map('intval', $talalat);
        }

        return [];
    }

    function notTitle($notTitle) {
        $this->filters[] = "Ne legyen közte " . htmlspecialchars($notTitle);
        if (empty($notTitle)) return;        
        
        $this->query['bool']['must_not'][] = [
                "term" => ['title' => strtolower($notTitle) ]
        ];
                
    }

    function boundaries(array $boundaryIds) {
        $boundaries = \Eloquent\Boundary::whereIn('id', $boundaryIds)->get()->map->toSimpleArray();
        
        // Build the filter message based on count
        if (count($boundaries) === 1) {
            $filterText = "A következő területen:";
        } else {
            $filterText = "A következő területek egyikén:";
        }
        
        // Add badges for each boundary
        $badges = [];
        foreach ($boundaries as $boundary) {
            $badge = '<span class="badge" style="background-color: ' . htmlspecialchars($boundary['color']) . ';" title="' . htmlspecialchars($boundary['type']) . '">' . htmlspecialchars($boundary['name']) . '</span>';
            if($boundary['osm']['type'] && $boundary['osm']['id']) {
                $osmUrl = '/collection/' . htmlspecialchars($boundary['osm']['type']) . ':' . htmlspecialchars($boundary['osm']['id']);
                $badge = '<a href="' . $osmUrl . '" target="_blank" style="text-decoration:none;">' . $badge . '</a>';
            }
            $badges[] = $badge;
        }
        
        $this->filters[] = $filterText . ' ' . implode(' ', $badges);
        
        if($this->massOrChurch === 'church') {
            $field = "boundaries";
        } else {
            $field = "church.boundaries";
        }

        if (is_array($boundaryIds) && count($boundaryIds) > 0) {
            $this->addMust([ 'terms' => [$field => $boundaryIds] ]);
        }
    }

    function languages(array $languageAbbrevs) {
        $this->addMust([ 'terms' => ['lang.keyword' => $languageAbbrevs] ]);                
        // #864: fordítás ÉS escape — a nyelvkód a felhasználó paraméteréből jön.
        $translated = self::tSafe(array_map(function($l){ return 'LANGUAGES.'.$l; }, $languageAbbrevs));
        $this->filters[] = "A liturgia nyelve legyen <b>" . implode('</b> vagy <b>', $translated) . "</b>";        
    }

    /*
     * #644: akadálymentesség szűrő.
     *
     * SZÁNDÉKOSAN csak pozitív irányban lehet keresni ('yes' és/vagy 'limited'):
     * arra nincs mód, hogy valaki kifejezetten a NEM akadálymentes helyeket keresse.
     *
     * Mindkét indexen működik: a misekeresőben a templom a `church.` alatt van.
     */
    const WHEELCHAIR_VALUES = ['yes', 'limited'];

    function wheelchair(array $values): void {
        $values = array_values(array_intersect($values, self::WHEELCHAIR_VALUES));
        if (empty($values)) return;

        $prefix = $this->massOrChurch === 'mass' ? 'church.' : '';
        $this->addMust([ 'terms' => [$prefix . 'wheelchair.keyword' => $values] ]);

        $labels = ['yes' => 'akadálymentes', 'limited' => 'részben akadálymentes'];
        $this->filters[] = 'Kerekesszékkel <b>'
            . implode('</b> vagy <b>', array_map(fn($v) => $labels[$v], $values)) . '</b>';
    }

    /*
     * #644: csökkentett gluténtartalmú áldozás szűrő.
     *
     * A hétköznap és a vasárnap KÜLÖN kérdés (borazslo: „gyakran lehet olyan hogy
     * keresek olyan helyet ahol hétköznap lehet, meg olyat ahol legalább vasárnap").
     * Ha mindkettőt kéri, mindkettőnek teljesülnie kell.
     *
     * A 'no' és az üres érték nem számít lehetőségnek, ezért azokat kizárjuk.
     */
    const GLUTEN_FREE_POSSIBLE = ['always', 'at_end', 'at_start', 'ask_sacristy', 'bring_host'];

    function glutenFree(array $days): void {
        $fields = [
            'holidays' => ['gluten_free_holidays', 'ünnepnapokon'],
            'weekdays' => ['gluten_free_weekdays', 'hétköznapokon'],
        ];
        $prefix = $this->massOrChurch === 'mass' ? 'church.' : '';

        $chosen = [];
        foreach ($days as $day) {
            if (!isset($fields[$day])) continue;
            [$field, $label] = $fields[$day];
            $this->addMust([ 'terms' => [$prefix . $field . '.keyword' => self::GLUTEN_FREE_POSSIBLE] ]);
            $chosen[] = $label;
        }

        if (!empty($chosen)) {
            $this->filters[] = 'Csökkentett gluténtartalmú áldozás <b>' . implode('</b> és <b>', $chosen) . '</b>';
        }
    }

    function rites(array $rites) {
        $this->filters[] = "Rítus legyen: <b>" . htmlspecialchars(implode(' vagy ', t($rites))) . "</b>";
        if (is_array($rites) && count($rites) > 0) {
            $this->query['bool']['must'][] = [
                "terms" => ["rite" => $rites]
            ];
        }
    }

    /**
     * #89: távolság-alapú szűrés egy pont körül.
     *
     * A `churches` index `location` mezője geo_point — a mappingben eddig is szerepelt,
     * csak soha nem volt feltöltve, ezért a keresőben a „hely + távolság" páros néma
     * no-op maradt: a találatok teljesen figyelmen kívül hagyták a megadott helyet
     * (innen a jegy példája: Szentendre + 10 km -> Micske, Szirmabesenyő).
     *
     * A mise-indexben NINCS geo_point, ezért ott ez a szűrő nem használható közvetlenül —
     * a hívó előbb templom-azonosítókat keres ezzel, majd churchIds()-szal szűkít.
     *
     * @param float  $lat
     * @param float  $lon
     * @param float  $km    sugár kilométerben
     * @param string $label emberi felirat a szűrő-listához
     */
    function nearLocation(float $lat, float $lon, float $km, string $label = ''): void {
        if ($this->massOrChurch === 'mass') {
            throw new \Exception('A geo_distance szűrő csak a templom-indexen használható.');
        }
        if ($km <= 0) {
            return;
        }

        $this->query['bool']['filter'][] = [
            'geo_distance' => [
                'distance' => $km . 'km',
                'location' => ['lat' => $lat, 'lon' => $lon],
            ],
        ];

        $this->filters[] = $label !== ''
            ? 'Legfeljebb <b>' . (int) $km . ' km</b>-re innen: <b>' . htmlspecialchars($label) . '</b>'
            : 'Legfeljebb <b>' . (int) $km . ' km</b>-re a megadott helytől';
    }

    function timeRange($fromDatetime, $toDatetime) {
        // Keep human-readable filter text in the configured timezone
        $filter = "Időpont: <b>" . htmlspecialchars(twig_hungarian_date_format($fromDatetime)) . "</b> - <b>" . htmlspecialchars(twig_hungarian_date_format($toDatetime)) . "</b>";
        if ($this->timezone !== $this->defaultTimezone) {
            $filter .= " (" . $this->timezone . ")";
        }
        $this->filters[] = $filter;

        // Convert the provided datetimes (assumed to be in $this->timezone) to UTC
        try {
            $fromUtc = Carbon::parse($fromDatetime, $this->timezone)->setTimezone('UTC')->format('Y-m-d\\TH:i:s') . 'Z';
            $toUtc = Carbon::parse($toDatetime, $this->timezone)->setTimezone('UTC')->format('Y-m-d\\TH:i:s') . 'Z';
        } catch (\Exception $e) {
            // If parsing fails, fall back to raw values (defensive)
            $fromUtc = $fromDatetime;
            $toUtc = $toDatetime;
        }

        $this->query['bool']['must'][] = [
            "range" => [
                "start_date" => [
                    "gte" => $fromUtc,
                    "lte" => $toUtc
                ]
            ]
        ];
    }

    /**
     * @param bool $sortByDistance true → a legközelebbi templom az első (alap közeli keresés).
     *                             false → a legkorábbi mise az első (#608: „legközelebbi mise" keresés).
     */
    function nearby(float $latitude, float $longitude, float $radiusKm, bool $sortByDistance = true): void {
        if ($this->massOrChurch !== 'mass') {
            throw new LogicException('Nearby mass search is only available on the mass index.');
        }
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Invalid geographic coordinates.');
        }
        if ($radiusKm <= 0 || $radiusKm > 200) {
            throw new InvalidArgumentException('Radius must be between 0 and 200 kilometres.');
        }

        $origin = ['lat' => $latitude, 'lon' => $longitude];
        $locationField = 'church_runtime_location';
        $this->runtimeMappings[$locationField] = [
            'type' => 'geo_point',
            'script' => [
                'source' => "if (doc['church.lat'].size() != 0 && doc['church.lon'].size() != 0) { emit(doc['church.lat'].value, doc['church.lon'].value); }",
            ],
        ];
        $this->query['bool']['filter'][] = [
            'geo_distance' => [
                'distance' => rtrim(rtrim(number_format($radiusKm, 3, '.', ''), '0'), '.') . 'km',
                $locationField => $origin,
            ],
        ];
        $geoSort = [
            '_geo_distance' => [
                $locationField => $origin,
                'order' => 'asc',
                'unit' => 'km',
                'mode' => 'min',
                'distance_type' => 'arc',
                'ignore_unmapped' => true,
            ],
        ];
        if ($sortByDistance) {
            $this->sort = [$geoSort];
            $this->distanceSortIndex = 0;
        } else {
            // Idő szerint rendezünk, a távolság csak holtversenyt dönt — de a sort
            // értékéből továbbra is ki tudjuk olvasni a km-t.
            $this->sort = [['start_date' => ['order' => 'asc']], $geoSort];
            $this->distanceSortIndex = 1;
        }
        $this->filters[] = 'Legfeljebb <b>' . htmlspecialchars((string) $radiusKm) . ' km</b> távolságra';
    }

    function dateRange($fromDate, $toDate) {
        // Keep human-readable filter text in the configured timezone
        $filter = "Dátum: <b>" . htmlspecialchars(twig_hungarian_date_format($fromDate)) . "</b> - <b>" . htmlspecialchars(twig_hungarian_date_format($toDate)) . "</b>";
        if ($this->timezone !== $this->defaultTimezone) {
            $filter .= " (" . $this->timezone . ")";
        }
        $this->filters[] = $filter;

        // Build local datetimes at day boundaries and convert to UTC for ES
        try {
            $fromLocal = $fromDate . 'T00:00:00';
            $toLocal = $toDate . 'T23:59:59';
            $fromUtc = Carbon::parse($fromLocal, $this->timezone)->setTimezone('UTC')->format('Y-m-d\\TH:i:s') . 'Z';
            $toUtc = Carbon::parse($toLocal, $this->timezone)->setTimezone('UTC')->format('Y-m-d\\TH:i:s') . 'Z';
        } catch (\Exception $e) {
            $fromUtc = $fromDate . "T00:00:00";
            $toUtc = $toDate . "T23:59:59";
        }

        $this->query['bool']['must'][] = [
            "range" => [
                "start_date" => [
                    "gte" => $fromUtc,
                    "lte" => $toUtc
                ]
            ]
        ];
    }
    
    /**
     * day()
     *
     * Convenience helper that sets the search time range to a single calendar day.
     *
     * Accepted input values for $whenDate:
     *  - a weekday name: 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'
     *    (these are resolved to the next occurrence of that weekday as a YYYY-MM-DD date)
     *  - the strings 'today' or 'tomorrow'
     *  - an explicit date string in ISO format: 'YYYY-MM-DD'
     *
     * The function validates the resolved date and then calls $this->timeRange()
     * with the day's full interval (00:00 - 23:59). If the input cannot be
     * interpreted as a valid date it throws an exception.
     *
    * Note: api/nearby and api/search also call this function and perform their own local validation.
    * Ensure their validation logic remains consistent with this function's expectations.
     * 
     * @param string $whenDate day name, 'today', 'tomorrow' or date 'YYYY-MM-DD'
     * @throws \Exception when the provided value cannot be parsed to a valid date
     */
    function day($whenDate) {
        if (in_array($whenDate, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])) {
            $whenDate = date('Y-m-d', strtotime("next $whenDate"));
        }
        else if ($whenDate == 'today') {
            $whenDate = date('Y-m-d');
        } else if ($whenDate == 'tomorrow' ) {
            $whenDate = date('Y-m-d', strtotime('+1 day'));
        }

        if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $whenDate)) {
            throw new \Exception("'whenDate' should be a day or today or a date (yyyy-mm-dd).");
        }
                
        $this->timeRange($whenDate."T00:00", $whenDate."T23:59");
    }


    /**
     * Execute the search and return results.
     *
     * The method builds an Elasticsearch query payload from $this->query,
     * executes it against the `mass_index/_search` endpoint and returns
     * the found documents. The start_date field of each hit is parsed as UTC
     * and converted to the configured timezone ($this->timezone). A helper
     * field `start_minutes` is computed (minutes since midnight) for easier
     * client-side filtering/sorting.
     *
     * If $groupByChurch is true the returned array is keyed by church id,
     * otherwise a flat list of result objects is returned.
     *
     * @param int  $from          Pagination offset (default 0)
     * @param int  $size          Number of results to return (default 10)
     * @param bool $groupByChurch When true, results are grouped by church id
     * @return array              Array of hits (or grouped hits) with normalized dates
     */
    function getResults($from = 0, $size = 10, $groupByChurch = false) {
        $this->total = 0;

        $esQuery = [
            "_source" => [
                "excludes" => ["church"]
            ],
            "query" => $this->query,
            "from"  => $from,
            "size"  => $size,
            "track_total_hits" => true
        ];
        if (!empty($this->runtimeMappings)) {
            $esQuery['runtime_mappings'] = $this->runtimeMappings;
        }
    
        // Nagy adatkupacoknál jobb PIT-et nyitni ( openPit() ) és azt használva kérdezgetni le
        if ($this->pitId) {
            $esQuery['pit'] = [
                'id' => $this->pitId,
                'keep_alive' => $this->pit_keepAlive
            ];
            if($this->search_after != false ) {
                $esQuery['search_after'] = $this->search_after;
                $esQuery['from'] = 0;
            }
            $url = '_search';
        } else {
            $url = $this->index.'/_search';
        }
        


        if($this->massOrChurch === 'mass') {
            $esQuery['sort'] = array_merge($this->sort, [
                [ "start_date" =>  [ "order" => "asc" ] ],
                [ "_score" =>  [ "order" => "desc" ] ],                
                [ "church_id" => [ "order" => "asc" ] ]
            ]);
        } else if ($this->massOrChurch === 'church') {
            
            $esQuery['sort'] = [
                [ "_score" =>  [ "order" => "desc" ] ],
                [ "nev.keyword" =>  [ "order" => "asc" ] ]            
            ];        
        }

        $elastic = new ElasticSearchApi();
        $elastic->curl_setopt(CURLOPT_CUSTOMREQUEST, "GET");
        $this->rawQuery = json_encode($esQuery);
        $elastic->buildQuery($url, json_encode($esQuery));
        $elastic->run();

        $result = [];   

        if (isset($elastic->jsonData->hits->hits)) {
            $this->countHits = count($elastic->jsonData->hits->hits);
            $this->total = $elastic->jsonData->hits->total->value;    
            if($this->massOrChurch === 'mass') {
                $result = $this->prepareMassesResults($elastic->jsonData->hits->hits, $groupByChurch);
            } else if ($this->massOrChurch === 'church') {
                $result = $this->prepareChurchesResults($elastic->jsonData->hits->hits);
            }                        
            $lastHit = end($elastic->jsonData->hits->hits);
            if($lastHit) {
                $this->search_after = $lastHit->sort;
            }

        } else {
            // #575: nincs `hits` a válaszban → az ES nem adott érvényes találati
            // listát (nem fut / index gond / hálózati hiba). Ez NEM "0 találat",
            // hanem a kereső elérhetetlensége — a hívó ezt jelzi a felhasználónak.
            $this->searchFailed = true;
        }

        return $result;
    }

    public function openPit($keepAliveTime) {
        $this->pit_keepAlive = $keepAliveTime;

        $elastic = new ElasticSearchApi();
        $elastic->curl_setopt(CURLOPT_CUSTOMREQUEST, "POST");
        $elastic->buildQuery($this->index.'/_pit?keep_alive='.$this->pit_keepAlive);
        $elastic->run();

        if (isset($elastic->jsonData->id)) {
            $this->pitId = $elastic->jsonData->id;                        
        } else {
            throw new Exception("Failed to open PIT: " . $elastic->error);
        }
        return true;
    }

    public function closePit($pitId = false) {
        if (!$pitId) {
            $pitId = $this->pitId;
        }
        if (!$pitId) {
            throw new Exception("No PIT ID provided or opened.");
        }

        $elastic = new ElasticSearchApi();
        $elastic->curl_setopt(CURLOPT_CUSTOMREQUEST, "DELETE");
        $elastic->buildQuery('_pit', json_encode(['id' => $pitId]));
        $elastic->run();
 
        if (isset($elastic->jsonData->succeeded) && $elastic->jsonData->succeeded == true) {
            if ($pitId == $this->pitId) {
                $this->pitId = false;
            }
            return true;
        } else {
            throw new Exception("Failed to close PIT: " . $elastic->error);
        }
    }

    private function prepareMassesResults($hits, $groupByChurch) {
        $result = [];
        foreach ($hits as $hit) {
            $churchId = $hit->_source->church_id;

            $source = $hit->_source;
            $source->score = $hit->_score;
            if ($this->distanceSortIndex !== null
                && isset($hit->sort[$this->distanceSortIndex])
                && is_numeric($hit->sort[$this->distanceSortIndex])) {
                $source->distance_km = round((float) $hit->sort[$this->distanceSortIndex], 2);
            }

            $dateUtc = Carbon::parse($source->start_date)->setTimezone('UTC');

            if ($this->timezone !== 'UTC') {
                $dateLocal = $dateUtc->copy()->setTimezone($this->timezone);
                $source->start_date = $dateLocal->format('c');
                $source->start_minutes = $dateLocal->hour * 60 + $dateLocal->minute;
            } else {
                $source->start_date = $dateUtc->format('c');
                $source->start_minutes = $dateUtc->hour * 60 + $dateUtc->minute;
            }

            if($groupByChurch) {
                if (!isset($result[$churchId])) $result[$churchId] = [];
                $result[$churchId][] = $source;
            } else {
                $result[] = $source;
            }
        }
        return $result;

    }

    private function prepareChurchesResults($hits) {
        $result = [];
        foreach ($hits as $hit) {             
            $source = $hit->_source; 
            $source->score = $hit->_score;           
            $result[] = $source;
        }
        return $result;
    }

    function getFilters() {
        return $this->filters;
    }

}
