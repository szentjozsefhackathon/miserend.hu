<?php

namespace ExternalApi;

use Illuminate\Database\Capsule\Manager as DB;
        
class ExternalApi {
    public $cache = "1 week"; //false or any time in strtotime() format
    public $cacheDir = PATH . 'fajlok/tmp/';
    public $queryTimeout = 30;
    public $query;
    public $name = 'external';
    public $format = 'json'; // enum('json','xml')
    public $strictFormat = true; // if rawData not in XML/JSON format throw new \Exception
    private $curl_opts = [];
    public $rawQuery;
    public $rawData;
    public $responseCode;
    public $jsonData;
    public $xmlData;
    public $cacheFilePath;
    public $cacheFileTime;
    public $error;
    public $isTesting;
    public $headerAuthorization;
    public $postfields;
    public $apiUrl;

    /**
     * Hibakereső üzemmódban se írjuk ki a hibát a lapra.
     *
     * Ott állítsd be, ahol a sikertelenség VÁRT kimenet, és a hívó kezeli is — a hiba
     * ilyenkor is elérhető marad a $this->error / hasError() felől.
     */
    public $quiet = false;
	
    function __construct() {
        
    }

    function run() {
        $this->runQuery();
    }

    /**
     * Ki van-e kapcsolva a kifelé menő hálózat?
     *
     * A funkcionális (Panther) tesztek a VALÓDI oldalt töltik be, a templom-oldal pedig
     * külső szolgáltatásokat hív (kozossegek.hu, Overpass) — üres cache-sel ez helyben is
     * ~12 másodperc, a CI-ban pedig a WebDriver 180 másodperces korlátját is átlépheti.
     * Így bukott véletlenszerűen a ChurchDetailPageTest és a ChurchRemarkFormTest, olyan
     * PR-eken is, amik hozzá se értek a kódhoz.
     *
     * A kapcsoló SZÁNDÉKOSAN opt-in: alapból nincs bekapcsolva, tehát dev és production
     * viselkedése nem változik. A teszt-compose-ok állítják be (l. compose.test.yml,
     * compose.coverage.yml).
     */
    public static function isOffline(): bool {
        $value = env('EXTERNAL_APIS_OFFLINE', '');
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Ez a szolgáltatás a saját infrastruktúránk-e?
     *
     * Az Elasticsearch ugyanezen az ősosztályon keresztül beszél, pedig a compose-hálózaton
     * belül van — nem harmadik fél. Az offline kapcsoló SEM vonatkozhat rá, különben a
     * kereső is elnémulna a tesztek alatt (ezt a saját tesztjeim fogták meg: az
     * ElasticsearchApiLoggerTest azonnal elbukott az első próbálkozásnál).
     */
    protected function isInternalService(): bool {
        return false;
    }

    function runQuery() {
        // Offline módban meg sem próbálkozunk: se hálózat, se cache-írás. A hívók a
        // szokásos „üres válasz" ágon mennek tovább, pontosan úgy, mintha a külső
        // szolgáltatás nem adott volna adatot.
        if (self::isOffline() && !$this->isInternalService()) {
            $this->responseCode = 0;
            $this->rawData = '';
            if ($this->format == 'json') $this->jsonData = json_decode('[]');
            if ($this->format == 'xml')  $this->xmlData = false;
            $this->error = 'A külső API-k ki vannak kapcsolva (EXTERNAL_APIS_OFFLINE).';
            if (isset($this->isTesting) and $this->isTesting == true) {
                throw new \Exception($this->error);
            }
            return false;
        }

		if(isset($this->rawData)) unset($this->rawData);
        try {
        
            if (!isset($this->rawQuery)) {
                $this->buildQuery();
            }

            if ($this->cache) {
                $this->loadCacheFilePath();
                $this->tryToLoadFromCache();
            }

            if (!isset($this->rawData)) {
                $this->downloadData();
            }

            // Ha a cache be van kapcsolva, akkor szeretnénk elmenteni a letöltött adatokat.
            // De pl. az overpass API-nál gyakori az 503, ha túlterhelt, és ilyenkor nem szeretnénk elmenteni a cache-be a hibás választ.
            // Viszont pl. a kozossegek.hu talán 404-et ad vissza sokszor, ha nem találja a keresett adatot, és ezeket a cache-be menteni szeretnénk, hogy ne kelljen újra lekérdezni az API-t.
            if ($this->cache AND ( isset($this->responseCode) && !in_array($this->responseCode, [503, 504]) ) ) {
                $this->saveToCache();
            }
            
            
        } catch(\Exception $e){
			if(isset($this->isTesting) and $this->isTesting == true)
				throw new \Exception($e->getMessage());
				
            global $config;
            if($this->format == 'json' ) $this->jsonData = [];
			if($this->format == 'xml' ) $this->xmlData = [];
            $this->error = \Html\Html::printExceptionVerbose($e,true);

            /*
             * Van, ahol a sikertelenség VÁRT kimenet, és a hívó kezeli is (pl. a
             * területi adatok pótlása a templomoldalon: ha az Overpass épp nem ér rá,
             * a lap ugyanúgy megjelenik). Ott a hibakereső üzemmód teljes
             * verem-kiírása csak a látogató képébe önti a belső működést — a
             * stagingen pontosan ez történt egy templomoldalon.
             *
             * A hibát ilyenkor is eltesszük ($this->error, hasError()), csak nem
             * tesszük ki a lapra.
             */
            if(empty($this->quiet)) {
                if($config['debug'] > 1) echo $this->error;
                elseif($config['debug'] > 0) addMessage($this->error,'warning');
            }
            return false;
        }
        return true;
    }

    function tryToLoadFromCache() {
        if (file_exists($this->cacheFilePath)) {
            $this->cacheFileTime = date('Y-m-d H:i:s',filemtime($this->cacheFilePath));
            if (filemtime($this->cacheFilePath) > strtotime("-" . $this->cache)) {
                $this->rawData = file_get_contents($this->cacheFilePath);
				if($this->format == 'json' ) {
					$this->jsonData = json_decode($this->rawData);
					
					if ($this->jsonData === null) {						
						if($this->strictFormat)
							throw new \Exception("External API data has been loaded from cache but data is not a valid JSON!\n".$this->rawData);
						else {
							$this->jsonData = json_decode("[]");
							return true;
						}
					} else {
						return true;
					}
				} elseif($this->format == 'xml' ) {
					$this->xmlData = @simplexml_load_string($this->rawData);					
					if ($this->xmlData == false) {
						if($this->strictFormat)
							throw new \Exception("External API data has been loaded from cache but data is not a valid XML!\n".$this->rawData);
						else {
							$this->xmlData = false;
							return true;
						}
					} else {
						return true;
					}
				
				}
                return true;
            } else {
                unlink($this->cacheFilePath);
                return false;
            }
        } else {
            return false;
        }
    }

    function saveToCache() {
        if (!file_put_contents($this->cacheFilePath, $this->rawData)) {
            throw new \Exception("We could not save the cacheFile to " . $this->cacheFilePath);
        }
    }

    function downloadData() {        
        $header = array("cache-control: no-cache","Content-Type: application/".$this->format);
		if(isset($this->headerAuthorization))
			$header[] = $this->headerAuthorization;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$this->apiUrl . $this->rawQuery);
		//echo $this->apiUrl . $this->rawQuery."\n";
        
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->queryTimeout);

        if(isset($this->postfields)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->postfields);      
                          
        } else {
            curl_setopt($ch, CURLOPT_HTTPHEADER,$header); // Valahogy ha post-ban küldünk adatot, akkor jobb ha ez nincs itt.
        }


        
        

        curl_setopt($ch, CURLOPT_HEADER  , false);  // we want headers
        curl_setopt($ch, CURLOPT_RETURNTRANSFER , true);
		curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_USERAGENT, "miserend.hu");



		foreach($this->curl_opts as $name => $value ) {
			curl_setopt($ch, $name, $value );
		}
		
        $this->rawData = curl_exec($ch);

        $this->responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE ); 
		if(curl_error($ch)) {
			$this->error = [curl_errno($ch), curl_error($ch)];		
			throw new \Exception($this->error[1]." (curl)");
		}
        
        $this->saveStat();
        
		if($this->format == 'json' ) {
			$this->jsonData = json_decode($this->rawData);
			if ($this->jsonData === null ) {
				if($this->strictFormat)
					throw new \Exception("External API return data is not a valid JSON! \n<br/> ResponseCode: " . $this->responseCode . " \n<br/> Response: ". $this->rawData );
				else
					$this->jsonData = json_decode("[]");
			}
		}
		else if($this->format == 'xml' ) {
			$this->xmlData = @simplexml_load_string($this->rawData);
			if ($this->xmlData === null ) {
				if($this->strictFormat)
					throw new \Exception("External API return data is not a valid XML! \n<br/> ResponseCode: " . $this->responseCode . " <br/>\n Response: ". $this->rawData );
				else
					$this->xmlData = false;
			}
		}
		
		
		if(!in_array($this->responseCode,[200,404]))				
			throw new \Exception("External API returned bad http response code: " . $this->responseCode. "\n<br/> Response: ". $this->rawData);
        
    }

    function clearOldCache() {
        $this->cache;
        $this->cacheDir;
        $files = scandir($this->cacheDir);
        foreach ($files as $file) {
            if (preg_match('/^' . $this->name . '_(.*)\.'.$this->format.'/i', $file)) {
                $filemtime = filemtime($this->cacheDir . $file);
                $deadline = strtotime('now -' . $this->cache);
                if ($filemtime < $deadline) {
                    unlink($this->cacheDir . $file);
                }
            }
        }
    }

    function loadCacheFilePath() {
        $this->cacheFilePath = $this->cacheDir . $this->name . "_" . md5($this->query ?? '') . ".".$this->format;
    }
       
    function saveStat() {
        
        $url = $this->apiUrl.$this->rawQuery;
        $url = ( strlen($url) > 255 ) ? substr($url, 0, 252).'...' : $url;
        $query = DB::table('stats_externalapi')->where('url',$url)->where('date',date('Y-m-d'));
        if($current = $query->first()) {   
            if($current->rawdata != $this->rawData ) $diff = $current->diff + 1; else $diff = $current->diff;            

            //$maxAllowedPacket = DB::select(DB::raw('SHOW VARIABLES LIKE "max_allowed_packet"'))[0]->Value;
            $maxAllowedPacket = 4194304 - 1000; // 4MB - 1KB
            if (strlen($this->rawData) > $maxAllowedPacket) {
                $rawData = substr($this->rawData, 0, $maxAllowedPacket);                
            } else {
                $rawData = $this->rawData;
            }

            $echo = $query->update([
                        'name' => $this->name,
                        'url' => $url ,                    
                        'date' => date('Y-m-d'),                
                        'responsecode' => $this->responseCode,
                        'rawdata' => $rawData,
                        'count'=> $current->count + 1,
                        'diff'=> $diff
            ]);
        } else {
            DB::table('stats_externalapi')->insert(
                [
                    'name' => $this->name,
                    'url' => $url,                    
                    'date' => date('Y-m-d'),                
                    'responsecode' => $this->responseCode,
                    'rawdata' => $this->rawData,
                    'count'=> 1,
                    'diff' => 1
                ]
            );
        }
    }
    
	
	function test() {
	
		$return = true;
		$this->isTesting = true;
		
		$cache = $this->cache;
		$this->cache = false;
		
		try {
			if(!isset($this->testQuery)) 
				throw new \Exception("Hiányzik a testQuery, így nem tudjuk ellenőrizni.");
			
			$this->query = $this->testQuery;
			
			$this->run();			
		
		}
		catch (\Exception $e) {
			$return = $e->getMessage();
		}
				
		$this->cache = $cache;
		$this->isTesting = false;
		return $return;	
	}
	
	/**
	 * Van-e egyáltalán mit lefuttatni ezen a végponton?
	 *
	 * A „nem tudjuk ellenőrizni" NEM ugyanaz, mint a „hibás". A /health eddig
	 * mindkettőt pirosra festette, így a Mapquest — aminek szándékosan nincs
	 * testQuery-je, mert a hívás fizetős kvótát fogyaszt (#129) — hónapok óta
	 * hibaként virított. Az állandó piros pedig pont azt öli meg, amiért az oldal
	 * van: egy idő után senki nem nézi meg, mi az.
	 */
	function isTestable(): bool {
		return isset($this->testQuery);
	}

	/**
	 * Ha nincs ellenőrzés, itt mondhatja meg a leszármazott, hogy MIÉRT nincs.
	 * Enélkül csak annyi látszik, hogy nem tudjuk — az meg gyanúsan hasonlít a
	 * „valaki elfelejtette megírni"-ra.
	 */
	function testSkipReason(): ?string {
		return isset($this->testSkipReason) ? $this->testSkipReason : null;
	}

	function curl_setopt($name, $value) {
		$this->curl_opts[$name] = $value;
	}

	/**
	 * Check if the last API call resulted in an error.
	 */
	function hasError(): bool {
		return !empty($this->error);
	}

	/**
	 * Get the error message as a plain string.
	 * Handles both string and array error formats.
	 */
	function getErrorMessage(): string {
		if (is_array($this->error)) {
			return implode(' | ', array_map('strval', $this->error));
		}
		return (string) ($this->error ?? '');
	}

	/**
	 * Get a user-friendly HTML error message with collapsible details.
	 * Uses <details><summary> for a click-to-expand UI.
	 */
	function getErrorMessageHtml(string $summary = 'Külső API hiba'): string {
		$errorText = htmlspecialchars($this->getErrorMessage());
		return "$summary <details><summary>Részletek</summary><pre>$errorText</pre></details>";
	}
	
	
    /**
     * Összegyűjti az összes ExternalAPI-t amit használunk.
     * Azaz visszaadja a classes/externalapi könyvtárban található összes PHP fájl által ténylegesen definiált osztály nevét (kivéve externalapi.php).
     *
     * @return array Az endpoint osztályok nevei (string)
     */
    public static function collectExternalApis() {
        $dir = __DIR__ . '/';
        $files = scandir($dir);
        $result = [];
        foreach ($files as $file) {
            if (substr($file, -4) === '.php' && $file !== 'externalapi.php') {
                $before = get_declared_classes();
                include_once($dir . $file);
                $after = get_declared_classes();
                $new = array_diff($after, $before);
                foreach ($new as $class) {
                    $result[] = preg_replace('/^ExternalApi\\\/', '', $class);
                }
            }
        }

        // Az ExternalApi osztály nem külön ExternalApi, hanem az összefoglaló, ezért kivesszük a listából
        if (($key = array_search('ExternalApi', $result)) !== false) {
            unset($result[$key]);
            $result = array_values($result);
        }
        
        sort($result);
        return $result;
    }

    public static function clearAllOldCache() {
        $apis = self::collectExternalApis();
        foreach ($apis as $apiName) {
            $className = '\\ExternalApi\\' . $apiName;
            $apiInstance = new $className();
            $apiInstance->clearOldCache();
        }
    }   

}
class Exception extends \Exception {
	
}