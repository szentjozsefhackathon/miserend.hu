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
        $alap = $extra ?? [
            'https://overpass-api.de/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
            'https://overpass.osm.ch/api/interpreter',
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

        foreach ($vegpontok as $index => $url) {
            $this->apiUrl = $url;
            $this->error = null;

            parent::run();

            if (!$this->hasError()) {
                if ($index > 0) {
                    error_log('[miserend] Overpass: a(z) ' . $vegpontok[0] . ' nem válaszolt, '
                        . $url . ' válaszolt helyette.');
                }
                return;
            }

            $utolsoHiba = $this->error;
        }

        // Mindegyik elhasalt: az utolsó hiba marad kint, hogy a /health és a napló
        // valódi okot mutasson, ne csak annyit, hogy „nem sikerült".
        $this->error = $utolsoHiba;
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
