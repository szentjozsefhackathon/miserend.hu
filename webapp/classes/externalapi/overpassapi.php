<?php

namespace ExternalApi;

# http://wiki.openstreetmap.org/wiki/Overpass_API#Introduction

class OverpassApi extends \ExternalApi\ExternalApi {

    public $name = 'overpass';
    public $apiUrl = "https://overpass-api.de/api/interpreter";
	public $testQuery = 'nwr["name"="Tápiószecső"];out geom;';
    public $queryFilter;

    /** #766: a próbálandó végpontok, a beállítottal az élen. @var string[] */
    public $fallbackUrls = [];

    /**
     * #840: hány elem alatt tekintjük a választ HIÁNYOSNAK, nem üresnek?
     *
     * A hívó állítja be, ha tudja, mit vár. 0 = nincs elvárás (az üres találat érvényes
     * válasz marad — ez a #766 szándéka).
     */
    public $minElements = 0;

    /** #840: melyik végpont válaszolt végül. Naplózáshoz és teszthez. */
    public $usedUrl;

    function __construct() {
        global $config;
        // #376: az Overpass-endpoint mostantól konfigurálható. borazslo tapasztalata
        // szerint a fő overpass-api.de instabil lehet; prod átállhat egy stabilabb,
        // EU-s mirrorra (pl. overpass.kumi.systems, Ausztria) a config['overpass']['apiUrl']
        // vagy az OVERPASS_API_URL env révén. (Orosz mirrort ne — nem EU, GDPR/megbízhatóság.)
        if (!empty($config['overpass']['apiUrl'])) {
            $this->apiUrl = $config['overpass']['apiUrl'];
        }

        $this->fallbackUrls = self::buildEndpointList($this->apiUrl, $config['overpass']['fallbackUrls'] ?? null);
    }

    /** #840: ennyivel várunk tovább a válaszra, mint a szerveroldali keret. Másodperc. */
    const TRANSFER_GRACE = 30;

    /**
     * #766: tartalék végpontok.
     *
     * Az Overpass-tükrök természetüknél fogva ingadoznak: hol túlterheltek, hol
     * karbantartás alatt vannak, hol rate-limitelnek. Egyetlen rossz tükör eddig
     * levitte a templomoldalt — a látogató 30 másodperc után egy „Váratlan hiba
     * történt" üzenetet kapott.
     *
     * A beállított végpont marad az ELSŐ; a többi csak akkor jön szóba, ha az nem
     * válaszol. Így a beállítás továbbra is számít, csak nem egyetlen ponton múlik
     * minden.
     *
     * Hoszt szerint deduplikálunk: az `overpass.kumi.systems` ma CNAME-mel ugyanarra a
     * gépre mutat, mint az `overpass.private.coffee` — tartaléknak felvenni önmagát
     * értelmetlen lenne.
     *
     * @param  string[]|null $extra
     * @return string[]
     */
    public static function buildEndpointList(string $primary, ?array $extra = null): array {
        /*
         * #840: az `overpass.osm.ch` INNEN KIKERÜLT, és ez a jegy lényege.
         *
         * Svájci példány: csak svájci adatot tartalmaz. Mérve (2026-08-20), ugyanaz a
         * lekérdezés, csak a hoszt más:
         *   node["place"="city"]["name"="Budapest"];out count;
         *     overpass-api.de            -> 1
         *     overpass.openstreetmap.fr  -> 1
         *     overpass.osm.ch            -> 0     <-- nincs benne Magyarország
         *
         * Élesben ezért futott a napi szinkron másfél hónapon át „sikeresen" EGYETLEN
         * elemmel: a beállított végpont elérhetetlen volt, a private.coffee HTTP 500-at
         * adott a nagy lekérdezésre, az osm.ch pedig 200-at — a világ töredékéről.
         *
         * A helyére a globális `overpass.openstreetmap.fr` kerül; mérve ugyanarra a
         * teljes `url:miserend` lekérdezésre HTTP 200, 5035 elem, 3,17 MB, 24,1 mp.
         *
         * TANULSÁG, ami a listán túl is érvényes: földrajzilag korlátozott tükör ide
         * nem való, mert a hiányos válasza formailag tökéletes. A `rejectionReason()`
         * ezért nem is a listában bízik.
         */
        $alap = $extra ?? [
            'https://overpass-api.de/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
            'https://overpass.openstreetmap.fr/api/interpreter',
        ];

        $lista = [];
        $hosztok = [];
        foreach (array_merge([$primary], $alap) as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            $hoszt = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($hoszt === '' || isset($hosztok[$hoszt])) {
                continue;
            }
            $hosztok[$hoszt] = true;
            $lista[] = $url;
        }

        return $lista;
    }

    /**
     * #766: végigpróbáljuk a végpontokat, amíg valamelyik válaszol.
     *
     * Csak KAPCSOLÓDÁSI hibánál lépünk tovább. Az üres találat érvényes válasz — arra
     * továbblépni azt jelentené, hogy addig kérdezünk, amíg valaki mond valamit, ami
     * rosszabb a nemleges válasznál.
     *
     * A cache-t az ősosztály a lekérdezés tartalmából képzi, nem a hosztból, tehát a
     * tartalékra váltás nem hidegíti ki.
     */
    function run() {
        $vegpontok = !empty($this->fallbackUrls) ? $this->fallbackUrls : [$this->apiUrl];
        $utolsoHiba = null;
        $this->usedUrl = null;

        /*
         * #840: a válaszra tovább várunk, mint amennyi keretet az Overpassnak adtunk.
         * Itt számoljuk, nem a konstruktorban: a hívó a `queryTimeout`-ot menet közben
         * is átállíthatja (a napi szinkron például jóval nagyobb keretet kér), és a
         * kettőnek együtt kell mozognia.
         */
        $this->transferTimeout = $this->queryTimeout + self::TRANSFER_GRACE;

        foreach ($vegpontok as $index => $url) {
            $this->apiUrl = $url;
            $this->error = null;

            parent::run();

            if (!$this->hasError()) {
                $this->usedUrl = $url;
                if ($index > 0) {
                    error_log('[miserend] Overpass: a(z) ' . $vegpontok[0] . ' nem válaszolt, '
                        . $url . ' válaszolt helyette.');
                }
                return;
            }

            $utolsoHiba = $this->error;
        }

        /*
         * Mindegyik elhasalt: az utolsó hiba marad kint, hogy a /health és a napló
         * valódi okot mutasson, ne csak annyit, hogy „nem sikerült".
         *
         * #840: a `jsonData`-t is KIÜRÍTJÜK. Eddig az utolsó — visszautasított —
         * végpont hiányos válasza ottmaradt, és a hívók egy része csak azt nézte, van-e
         * `elements` (l. `OSM::syncUrlMiserendFromOSM`), a hibát nem. Így egy elutasított
         * válasz mégis feldolgozásra került volna.
         */
        $this->error = $utolsoHiba;
        $this->jsonData = json_decode('{"elements":[]}');
    }

    /**
     * #840: érdemi-e a válasz? A HTTP 200 önmagában nem bizonyíték.
     *
     * KÉT dolgot szűrünk, és egyiket sem lehet a listával kiváltani:
     *
     * 1. `remark` — az Overpass a szerveroldali időtúllépésre és futásidejű hibára is
     *    HTTP 200-at ad, üres vagy részleges `elements`-szel és egy `remark` mezővel.
     *    Mérve: `[out:json][timeout:1]` a teljes url:miserend lekérdezéssel ->
     *    HTTP 200, elements: 0, remark: "runtime error: Query timed out in query...".
     *    Ez sikernek látszott, és egy hétre bekerült a cache-be.
     *
     * 2. HIÁNYOS válasz — ha a hívó megmondta, mennyit vár (`minElements`), és ennél
     *    lényegesen kevesebb jött, az nem üres találat, hanem rossz forrás. Az ÜRES
     *    találat továbbra is érvényes válasz marad: aki nem állít `minElements`-t,
     *    annál semmi nem változik (ez a #766 szándéka).
     */
    protected function rejectionReason(): ?string {
        if (!is_object($this->jsonData)) {
            return null;
        }

        $remark = trim((string) ($this->jsonData->remark ?? ''));
        if ($remark !== '') {
            return 'Az Overpass hibát jelzett a válaszban (' . $this->apiUrl . '): ' . $remark;
        }

        if ($this->minElements > 0) {
            $darab = is_array($this->jsonData->elements ?? null) ? count($this->jsonData->elements) : 0;
            if ($darab < $this->minElements) {
                return 'Az Overpass válasza hiányos (' . $this->apiUrl . '): ' . $darab
                    . ' elem érkezett, legalább ' . $this->minElements . ' kellene.';
            }
        }

        return null;
    }

    function buildQuery() {
        $this->rawQuery = "[out:json][timeout:" . $this->queryTimeout . "];";
        $this->rawQuery .= $this->query;
        $this->rawQuery = "?data=" . urlencode($this->rawQuery); 
    }

    function buildEnclosingBoundariesQuery($lat, $lon) {
        $this->queryFilter = "['type'='boundary' ]['disused:boundary'!~'.*']"; // A nem aktuális disued:boundary-knak nincs nevük meg ilyenek olykor, és az hibát tud generálni
        $this->buildEnclosingFeaturesQuery($lat, $lon);
    }

    function buildEnclosingFeaturesQuery($lat, $lon) {
        $this->query = "is_in(" . $lat . "," . $lon . ")->.a;"
                . "node" . $this->queryFilter . "(pivot.a);out bb center tags;"
                . "way" . $this->queryFilter . "(pivot.a);out bb center tags;"
                . "relation" . $this->queryFilter . "(pivot.a);out bb center tags;";
        $this->buildQuery();
    }

    function buildSimpleQuery($filter = false, $out = "body qt center") {  
        if ($filter) {
            $this->queryFilter = $filter;
        }
        $this->query = "("
                . "node" . $this->queryFilter . ";"
                . "way" . $this->queryFilter . ";"
                . "relation" . $this->queryFilter . ";);"
                . "out " . $out . ";";
        $this->buildQuery();
    }
	
	function buildOneEntityQuery($type, $id) {
		$this->query = "("
                . $type . "(id:" . $id . ");"
				. ");"
                . "out body qt center;";
        $this->buildQuery();
	
	}

    function downloadEnclosingBoundaries($lat, $lon) {
        $this->buildEnclosingBoundariesQuery($lat, $lon);
        $this->run();
    }

    function buildChurchesWithinBoundaryQuery($osmtype, $osmid) {        
        $this->query = $osmtype."(".$osmid.")->.rel;"
                . ".rel map_to_area->.searchArea;"
                . "( nwr[\"url:miserend\"](area.searchArea); );"
                . "out body;";

        $this->buildQuery();
    }

    function downloadChurchesWithinBoundary($osmtype, $osmid) {        
        $this->buildChurchesWithinBoundaryQuery($osmtype, $osmid);
        $this->run();
    }

	
	
    function downloadUrlMiserend() {
        $this->buildSimpleQuery("['url:miserend']");
        $this->run();
    }

}
