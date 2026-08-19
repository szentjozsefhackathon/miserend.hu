<?php
namespace Html\Ajax\Calendar;

use Carbon\Carbon;
use ExternalApi\ElasticsearchApi;
use Eloquent\CalGeneratedPeriod;
use Eloquent\CalMass;
use Eloquent\CalPeriod;

if (!headers_sent()) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

class Generate extends \Html\Ajax\Calendar\CalendarApi {

    protected $elastic;
    public $format = 'json';

    public function __construct($path = false) {
        
        parent::__construct($path);
        if($_SERVER['REQUEST_METHOD'] === false ) {
            return;            
        }   

        // #392: az ES-kapcsolat / index-létrehozás (createMassIndex, ami "File not
        // found" / "Failed to create index" throw-okat dobhat) eddig az index.php
        // globális catch-éig ért, ami HTML Exception-oldalt renderel — nem JSON-t.
        // Egy JSON-t váró calendar-kliens ezen JSON.parse-szal elhasal. Wrapping ->
        // mindig tiszta JSON hibaválasz.
        try {
            $this->elastic = new ElasticsearchApi();
            if (!$this->elastic->isexistsIndex('mass_index')) {
                $this->createMassIndex();
            }
        } catch (\Throwable $e) {
            $this->sendJsonError('Elasticsearch előkészítése sikertelen: ' . $e->getMessage(), 503);
        }

        // #392: az IntegerArrayRequired hiányzó/nem-numerikus paraméterre Exception-t dob;
        // enélkül a globális handler HTML-hibaoldalt renderelne a JSON-kliensnek (a #392-tünet).
        try {
            $this->tids = \Request::IntegerArrayRequired('tids');
        } catch (\Throwable $e) {
            $this->sendJsonError('Hiányzó vagy érvénytelen templom ID.', 400);
            exit;
        }
        if (empty($this->tids)) {
            $this->sendJsonError('Nincs templom ID megadva.', 400);
            exit;
        }

        try {
            $this->years = \Request::IntegerArrayRequired('years');
        } catch (\Throwable $e) {
            $this->sendJsonError('Hiányzó vagy érvénytelen év.', 400);
            exit;
        }
        if (empty($this->years)) {
            $this->sendJsonError('Nincs év megadva.', 400);
            exit;
        }

        // Ezen a végponton EDDIG SEMMILYEN jogosultság-ellenőrzés nem volt, pedig a PUT
        // teljes mise-újraindexelést indít. Kipróbálva: bejelentkezés nélkül, sima
        // curl-lel HTTP 200, és le is futott. Egy teljes futás 15+ perc és
        // erősen terheli az Elasticsearchöt — bárki, korlátlanul indíthatta.
        //
        // Ugyanaz a szabály, mint a szerkesztésnél: aki a templomhoz írhat, az
        // regenerálhatja is a miséit. Minden kért templomra megköveteljük.
        foreach ($this->tids as $tid) {
            $church = \Eloquent\Church::find($tid);
            if (!$church) {
                $this->sendJsonError('Nincs ilyen templom: ' . $tid, 404);
                exit;
            }
            $church->append(['writeAccess']);
            if (!$church->writeAccess) {
                $this->sendJsonError('Hiányzó jogosultság!', 403);
                exit;
            }
        }

        

        switch ($_SERVER['REQUEST_METHOD']) {
            case 'OPTIONS':
                http_response_code(200);
                exit();

            case 'GET':  
                  // Itt egy keresés volt korábban, de úgy tűnt semmi nem használja. 
                  break;
                              
            case 'PUT':
                // #392: az updateMasses() dobhat (ES timeout, network, mapping
                // conflict) — nyers throw helyett JSON hibaválasz. A debug-logolás
                // a master logger-callbackjén marad ($generateLog).
                $generateLog = [];
                try {
                    $debug = \ExternalApi\ElasticsearchApi::updateMasses($this->years, $this->tids,
                        function($msg) use (&$generateLog) { $generateLog[] = $msg; }
                    );

                    $this->content = json_encode([
                        'success' => true,
                        'debug'   => array_merge($debug ?? [], $generateLog, $this->debugLog)
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                } catch (\Throwable $e) {
                    $this->sendJsonError('Misék regenerálása sikertelen: ' . $e->getMessage(), 500);
                }
                break;
        }}

    private array $debugLog = [];

    private function logDebug(string $msg, array $ctx = []): void {
        $line = $msg;
        if (!empty($ctx)) {
            $line .= " | " . json_encode($ctx, JSON_UNESCAPED_UNICODE);
        }
        $this->debugLog[] = $line;
    }

    

  
    


    


    /**
     * Létrehozza a mass_index indexet az Elasticsearch-ben.
     * Ez a metódus a mass.json és church.json fájlokat használja a mapping és settings beállításokhoz.
     * @throws \Exception
     */
    public function createMassIndex(): void
    {
 
        $massFilePath = '../../../fajlok/elasticsearch/mappings/mass.json';
        if (!file_exists($massFilePath)) {
            throw new \Exception("File not found: " . $massFilePath);
        }
        $massData = file_get_contents($massFilePath);
        $churchFilePath = '../../../fajlok/elasticsearch/mappings/church.json';
        if (!file_exists($churchFilePath)) {
            throw new \Exception("File not found: " . $churchFilePath);
        }
        $churchData = file_get_contents($churchFilePath);
        $data = json_decode($massData, true);
        $data['settings'] = json_decode($churchData, true)['settings'];
        $data['mappings']['properties']['church'] = json_decode($churchData, true)['mappings'];

        if (!$this->elastic->putIndex('mass_index', $data)) {				
            throw new \Exception("Failed to create index: mass_index");
        }                
    }







}
