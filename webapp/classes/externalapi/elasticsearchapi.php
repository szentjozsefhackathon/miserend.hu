<?php

namespace ExternalApi;

class ElasticsearchApi extends \ExternalApi\ExternalApi {

    public $name = 'elasticsearch';    
	public $format = 'json';
	public $apiUrl = "http://elasticsearch:9200/";

	public $testQuery = '_cluster/health/churches?pretty';
	public $cache = false; // Az Elasticsearch-nek meg van a saját cache-je. Arra hagyatkozunk
	
	public $q; // Ez a solr keresőben a query, nem pedig az API-ban a query
    public $data;

	/**
	 * Az Elasticsearch a SAJÁT infrastruktúránk (compose-hálózat), nem harmadik fél —
	 * az EXTERNAL_APIS_OFFLINE kapcsoló nem vonatkozik rá. Enélkül a tesztek alatt a
	 * kereső is elnémulna.
	 */
	protected function isInternalService(): bool {
		return true;
	}
			
	function run() {					
		$this->curl_setopt(CURLOPT_HTTPHEADER ,['Content-Type: application/json']);		 	
		parent::run();
	}
	
	function buildQuery( $query = false, $data = false) {
		
		if($query != false) {
			$this->query = $query;
			$this->rawQuery = $this->query;			
		} else if ($this->query) {
			$this->rawQuery = $this->query;
		} else {
			throw new \Exception('We need query');
		}
			
		if($data != false) 
			$this->data = $data;
			
		if(isset($this->data)) {
			//$this->curl_setopt(CURLOPT_POST ,1);		 	
			//$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"PUT");		 	
			$this->curl_setopt(CURLOPT_POSTFIELDS,$this->data);			
		}
	
	}
	
	/**
	 * #627: a curl-opciók és a $data az OBJEKTUMON halmozódnak, ezért egy korábbi,
	 * testtel járó kérés (pl. _search) törzse átszivárogna a következő, test nélküli
	 * GET-be — az ES pedig 400-zal utasítja el a testtel érkező GET-et
	 * ("does not support having a body"). Minden test nélküli kérés előtt takarítunk,
	 * hogy a hívási sorrend ne számítson.
	 */
	private function clearRequestBody(): void {
		$this->data = null;
		$this->curl_setopt(CURLOPT_POSTFIELDS, '');
	}

	function isexistsIndex($name) {
		$this->clearRequestBody();
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"GET");
		$this->buildQuery("_cat/indices/".$name."?format=json");
		$this->run();

		if($this->responseCode == 404) {
			return false;
		}

		if($this->responseCode != 200) {
			throw new \Exception("Could not get indices!\n".$this->error);
		}
		if($this->jsonData == []) {
			return false;
		}
		return true;
	}

	function checkIndex($name) {
		$this->clearRequestBody();
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"GET");
		$this->buildQuery("_cat/indices/".$name."?format=json");
		$this->run();
		if($this->responseCode != 200) {
			throw new \Exception("Could not get indices!\n".$this->error);
		}
		if($this->jsonData == []) {
			throw new \Exception("No indices found!\n".$this->error);
		}
		if(count($this->jsonData) != 1) {
			throw new \Exception("There should be exactly one index found!\n".$this->error);
		}
		if($this->jsonData[0]->status != 'open') {
			throw new \Exception("Index is not open!\n".$this->error);
		}
		if($this->jsonData[0]->health == 'red') {
			throw new \Exception("Index health is red!\n".$this->error);
		}
		return $this->jsonData[0];


	}

	function getIndexCreationDate(string $name): ?string {
		$this->clearRequestBody();
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST, "GET");
		$this->buildQuery($name . '/_settings?filter_path=*.settings.index.creation_date');
		$this->run();
		if ($this->responseCode != 200) {
			throw new \Exception("Could not get index settings!\n" . $this->error);
		}

		$creationDate = $this->jsonData->{$name}->settings->index->creation_date ?? null;
		if ($creationDate === null || !is_numeric($creationDate)) {
			return null;
		}

		return date('Y-m-d H:i:s', intdiv((int) $creationDate, 1000));
	}

	/**
	 * #627: az index SAJÁT vízjele — mikor épült fel utoljára teljesen, a mapping
	 * `_meta` mezőjéből. Azért az indexben tároljuk és nem (csak) a crons táblában,
	 * mert a kettő szétcsúszhat: egy visszaállított ES-snapshot a saját (régi)
	 * vízjelét hozza magával, míg a MySQL-seed egy attól független cron-siker
	 * időpontot. A DB nem tudhat az index tartalmáról — az index viszont igen.
	 */
	function getIndexMeta(string $name): array {
		$this->clearRequestBody();
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST, "GET");
		$this->buildQuery($name . '/_mapping?filter_path=*.mappings._meta');
		$this->run();
		if ($this->responseCode != 200) {
			throw new \Exception("Could not get index mapping!\n" . $this->error);
		}

		$meta = $this->jsonData->{$name}->mappings->_meta ?? null;
		return $meta === null ? [] : (array) json_decode(json_encode($meta), true);
	}

	function setIndexMeta(string $name, array $meta): bool {
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST, "PUT");
		$this->buildQuery($name . '/_mapping', json_encode(['_meta' => $meta]));
		$this->run();

		if ($this->responseCode != 200) {
			return false;
		}
		return isset($this->jsonData->acknowledged) && $this->jsonData->acknowledged == 1;
	}

	/** #627: a legkésőbbi indexelt miseidőpont — ebből látszik, meddig ér el az index. */
	function maxMassStartDate(string $name = 'mass_index'): ?string {
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST, "GET");
		$this->buildQuery($name . '/_search', json_encode([
			"size" => 0,
			"aggs" => ["max_start" => ["max" => ["field" => "start_date"]]]
		]));
		$this->run();
		if ($this->responseCode != 200) {
			throw new \Exception("Could not search mass_index!\n" . $this->error);
		}

		$value = $this->jsonData->aggregations->max_start->value_as_string ?? null;
		return is_string($value) ? $value : null;
	}

	function putIndex($name, $data) {
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"PUT");		
		$this->buildQuery($name, json_encode($data));
		$this->run();

		if($this->responseCode != 200)
			return false;
		if(!isset($this->jsonData->acknowledged) OR $this->jsonData->acknowledged != 1)
			return false;
		
		return true;
	}

	function truncateIndex($name) {

		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"POST");		
		$this->buildQuery($name."/_delete_by_query", json_encode(['query'=>['match_all'=>[]]]));
		if($this->responseCode != 200)
			throw new \Exception("Could not truncate index!\n".$this->error);
		
		return true;
	}

	/**
	 * Az imént beírt dokumentumokat azonnal kereshetővé teszi. Alapból ~1 másodperc telik
	 * el, addig egy visszaellenőrző lekérdezés hamis "üres" eredményt adna.
	 */
	function refreshIndex($name) {
		$this->clearRequestBody();
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST, "POST");
		$this->buildQuery($name . '/_refresh');
		$this->run();

		return $this->responseCode == 200;
	}

	function deleteIndex($name) {

		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"DELETE");
		$this->buildQuery($name);
		$this->run();

		if($this->responseCode != 200)
			return false;
		if(!isset($this->jsonData->acknowledged) OR $this->jsonData->acknowledged != 1)
			return false;
		
		return true;
	}
	
	/**
	 * #374: Az ES _bulk NDJSON-payload összeállítása. Tömb-elemeket json_encode-ol,
	 * a már-string elemeket változatlanul átengedi, \n-nel összefűzi + záró \n. Tiszta,
	 * ezért kiemelve a putBulk-ból (a hálózati résztől), hogy tesztelhető legyen.
	 */
	static function buildBulkNdjson($data) {
		if (!is_array($data)) {
			return $data;
		}
		$bulkData = [];
		foreach ($data as $item) {
			$bulkData[] = is_array($item) ? json_encode($item) : $item;
		}
		return implode("\n", $bulkData) . "\n";
	}

	function putBulk($data) {
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"PUT");

		$data = self::buildBulkNdjson($data);

		$this->buildQuery('_bulk', $data);
		$this->run();

		if($this->responseCode != 200)
			return false;

		if(isset($this->jsonData->errors) AND $this->jsonData->errors != "")
			return false;
		
		return true;
	}
	
	function random($params = []) {
		// Defaults
		$default_params = [
			'size'=>10
		];		
		$data = $default_params;		

		$data = [
			"query" => [
				"function_score" => [
					"query" => ["match_all" => new \stdClass()],
					"boost" => "5",
					"random_score" => new \stdClass(),
					"boost_mode" => "multiply"
				]
			]
		];

		foreach($params as $key => $value) {
			$data[$key] = $value;
		}

		$this->curl_setopt(CURLOPT_CUSTOMREQUEST ,"GET");		
		$this->buildQuery('churches/_search', json_encode($data));		
		$this->run();

		if($this->responseCode != 200) {
			throw new \Exception("Could not search churches!\n".$this->error);
		}

		return $this->jsonData->hits;
	}

	
	// Rendszeresen feltöltjük a keresőbe az adatbázisunkat, mert az jó.
	static function updateChurches(array $tids = []) {

		
		$elastic = new \ExternalApi\ElasticsearchApi();
								
		// Első esetben lehet hogy létre kell hozni. De ez innen kikerülhetne ha már biztos a működésünk
		if(!$elastic->isexistsIndex('churches')) {

			$data = file_get_contents(__DIR__ . '/../../fajlok/elasticsearch/mappings/church.json');
			$elastic->curl_setopt(CURLOPT_TIMEOUT, 3600);
			if (!$elastic->putIndex('churches', json_decode($data, true))) {
				throw new \Exception(
						"Failed to create index: churches\n" .
						"Response code: " . $elastic->responseCode . "\n" .
						"Error: " . print_r($elastic->error, true) . "\n" .
						"Response: " . print_r($elastic->jsonData, true) . "\n" .
						"Request: " . print_r($elastic->requestData, true) . "\n" .
						"\$elastic: " . print_r($elastic, true)
				);
			}

		}	
		
		// Előkészítjük feltöltsére az adatokat
		$churches = \Eloquent\Church::where('ok', 'i');
		if(!empty($tids)) {
			$churches = $churches->whereIn('id', $tids);
		}
		$churches = $churches->limit(200000)->get()->map->toElasticArray()->toArray();
		
		if(empty($tids)) {
			// When we update all churches, we can just truncate the index, which is faster. When we update only some churches, we need to delete them one by one, which is slower, but we don't want to delete all churches in that case.
			// Truncate the index
			$elastic->truncateIndex('churches');
		} else {
			// When we update only some churches, we need to delete them one by one, which is slower, but we don't want to delete all churches in that case.			
			// Delete existing masses for the given church IDs		
			$elastic->curl_setopt(CURLOPT_CUSTOMREQUEST, "POST");
			$elastic->buildQuery('churches/_delete_by_query', json_encode([
				"conflicts" => "proceed",
				"query" => [
					"terms" => ["id" => array_map('strval', $tids)]
				]
			]));			
			$elastic->run();
			if(isset($elastic->error)) {			
				throw new \Exception("Could not delete existing masses!\n" . $elastic->error);
			}
			
		}

		// Feltöltjük az adatokat az indexbe. Ezzel új verzióval felülírja a régieket. De mondjuk nem üríti ki a régit.
		
		$bulkData = [];
		foreach($churches as $church) {
			$bulkData[] = json_encode([
				'index' => [
					'_index' => 'churches',
					'_id' => $church['id']
				]
			]);
			$bulkData[] = json_encode($church);
		}
		
		// Skip bulk insert if no data to insert
		if(empty($bulkData)) {
			return;
		}
		
		if(!$elastic->putBulk($bulkData)) {
			
			$errors = [];
			foreach($elastic->jsonData->items as $item ) {
				if(isset($item->index->error)) {					
					$errors[] = $item->index->error->type . ': ' . $item->index->error->reason . "\n";
				}
			}

		       throw new \Exception("Could not update churches!\n" . implode("\n", $errors));
		}
   
	}
	
	/*
	 * Delete specific churches from elasticsearch index
	 */
	static function deleteChurches(array $tids = []) {
		if(empty($tids)) {
			return;
		}
		
		$elastic = new \ExternalApi\ElasticsearchApi();
		$elastic->curl_setopt(CURLOPT_CUSTOMREQUEST, "POST");
		$elastic->buildQuery('churches/_delete_by_query', json_encode([
			"conflicts" => "proceed",
			"query" => [
				"terms" => ["id" => array_map('strval', $tids)]
			]
		]));
		$elastic->run();
	}

	/**
	 * #306: Tiszta döntési logika — kell-e teljes mise-újragenerálás?
	 * Szándékosan DB/ES-mentes (csak a beadott értékekből dönt), hogy unit-tesztelhető legyen.
	 *
	 * @param ?string $lastSuccessAt       a cron legutóbbi sikeres futása (DATETIME) vagy null
	 * @param ?string $maxPeriodUpdatedAt  a cal_generated_periods legnagyobb updated_at-ja (DATE) vagy null
	 * @param bool    $indexEmpty          a mass_index hiányzik vagy 0 dokumentum
	 * @param ?string $maxMassUpdatedAt    a cal_masses legnagyobb updated_at-ja vagy null
	 * @param ?string $indexCreatedAt      a mass_index létrehozásának időpontja vagy null
	 */
	static function shouldFullReindex(
		?string $lastSuccessAt,
		?string $maxPeriodUpdatedAt,
		bool $indexEmpty,
		?string $maxMassUpdatedAt = null,
		?string $indexCreatedAt = null
	): bool {
		// Startup: üres/hiányzó index -> mindenképp fel kell építeni.
		if ($indexEmpty) return true;
		// Nincs korábbi sikeres futás (vagy nulldátum) -> fussunk.
		if (empty($lastSuccessAt) || strpos($lastSuccessAt, '0000-00-00') === 0) return true;
		// Újra létrehozott/visszaállított indexet ez a cron még nem validált.
		if (!empty($indexCreatedAt) && strtotime($indexCreatedAt) > strtotime($lastSuccessAt)) return true;
		// Nemcsak a periódusok, maguk a misék is változhatnak.
		if (!empty($maxMassUpdatedAt)
			&& strpos($maxMassUpdatedAt, '0000-00-00') !== 0
			&& substr($maxMassUpdatedAt, 0, 10) >= substr($lastSuccessAt, 0, 10)) return true;
		// Nincs egyetlen generatedPeriod sem -> ne blokkoljunk (ritka, adatbiztos irány).
		if (empty($maxPeriodUpdatedAt) || strpos($maxPeriodUpdatedAt, '0000-00-00') === 0) return true;
		// Date-inkluzív (>=): ha a periódusok az utolsó sikeres futás NAPJÁN vagy után frissültek,
		// generáljunk újra. Az updated_at DATE (napi granularitás), ezért aznapi módosítás +
		// korábbi aznapi futás esetén inkább egyszer túl-futunk (adatbiztos), mint hogy kihagyjunk.
		return substr($maxPeriodUpdatedAt, 0, 10) >= substr($lastSuccessAt, 0, 10);
	}

	/**
	 * #627: átfogja-e az index a most kért éveket?
	 *
	 * Ezt egyetlen időbélyeg-összehasonlítás sem veszi észre: ha semmi nem változik
	 * az adatbázisban, de átfordul az év, a [Y-1, Y, Y+1] ablak elcsúszik, és az
	 * index utolsó éve egyszer csak hiányzik. Ilyenkor is teljes futás kell.
	 *
	 * @param ?string $maxStartDate a legkésőbbi indexelt miseidőpont (ISO) vagy null
	 * @param array   $years        a most generálandó évek
	 */
	static function indexCoversYears(?string $maxStartDate, array $years): bool {
		if (empty($years)) return true;
		// Nem tudjuk megmondani (üres index / hibás agg) -> ne erre hivatkozva döntsünk.
		if (empty($maxStartDate)) return true;

		$maxYear = (int) substr($maxStartDate, 0, 4);
		return $maxYear >= (int) max($years);
	}

	/** A mass_index állapota a teljes újraindexelés eldöntéséhez. */
	private static function massIndexState(): array {
		$empty = ['empty' => true, 'created_at' => null, 'indexed_at' => null, 'max_start_date' => null];

		$elastic = new \ExternalApi\ElasticsearchApi();
		if (!$elastic->isexistsIndex('mass_index')) {
			return $empty;
		}
		$info = $elastic->checkIndex('mass_index');
		$docs = isset($info->{'docs.count'}) ? (int) $info->{'docs.count'} : 0;
		if ($docs === 0) {
			return $empty;
		}

		$meta = $elastic->getIndexMeta('mass_index');
		return [
			'empty' => false,
			'created_at' => $elastic->getIndexCreationDate('mass_index'),
			'indexed_at' => $meta['full_reindex_at'] ?? null,
			'max_start_date' => $elastic->maxMassStartDate(),
			// A legutóbbi futásban hiba miatt kihagyott templomok.
			'skipped_churches' => array_values(array_map('intval', (array) ($meta['skipped_churches'] ?? []))),
		];
	}

	/** #627: az index megjelöli magát, hogy mikor épült fel utoljára teljesen. */
	/**
	 * Csak a kihagyott templomok listáját írjuk felül — a vízjel DÁTUMÁT nem.
	 *
	 * A pótlás nem teljes újraindexelés: ha a dátumot is előretolnánk, egy közben
	 * megváltozott miserend újragenerálása maradna el.
	 *
	 * @param int[] $skippedChurchIds
	 */
	private static function rememberSkippedChurches(array $skippedChurchIds, callable $log): void {
		try {
			$elastic = new \ExternalApi\ElasticsearchApi();
			$meta = $elastic->getIndexMeta('mass_index');
			$meta['skipped_churches'] = array_values(array_map('intval', $skippedChurchIds));
			$elastic->setIndexMeta('mass_index', $meta);
		} catch (\Throwable $e) {
			$log("A kihagyott templomok listáját nem sikerült frissíteni (" . $e->getMessage() . ").");
		}
	}

	/**
	 * @param int[] $skippedChurchIds templomok, amiket hiba miatt kihagytunk
	 */
	private static function markFullReindex(array $years, callable $log, array $skippedChurchIds = []): void {
		try {
			$elastic = new \ExternalApi\ElasticsearchApi();
			$elastic->setIndexMeta('mass_index', [
				'full_reindex_at' => date('Y-m-d H:i:s'),
				'years' => array_values(array_map('intval', $years)),
				// A "tényleg nincs idei miséje" lista szándékosan NEM öröklődik át: friss
				// teljes indexépítés után újra ellenőrizzük, hátha közben lett miséjük.
				'churches_without_masses' => [],
				// A hiba miatt kihagyott templomok. A vízjel miatt a következő futás
				// egyébként átugraná az egészet — ezt a listát viszont MINDIG újrapróbálja,
				// tehát a kihagyott templomok nem vesznek el véglegesen.
				'skipped_churches' => array_values(array_map('intval', $skippedChurchIds)),
			]);
		} catch (\Throwable $e) {
			// A vízjel hiánya csak annyit jelent, hogy legközelebb újra lefutunk.
			$log("#627: az index vízjelét nem sikerült kiírni (" . $e->getMessage() . ").");
		}
	}

	/**
	 * Mely templomokat érdemes pótindexelni?
	 *
	 * A /health régóta mutatja, hogy vannak misézőhelyek, amiknek van miserendjük az
	 * adatbázisban, de az idei évre egyetlen dokumentumuk sincs a mass_indexben (élesben
	 * 631 ilyen volt). Ez a kereséseikből egyszerűen kihagyja őket.
	 *
	 * A lista nem tisztán hiba: van, akinek tényleg nincs idei miséje (lejárt vagy csak
	 * múltbeli miserend). Ezeket a pótindexelés után megjegyezzük, és nem próbálkozunk
	 * velük újra és újra — különben minden futás ugyanazon a néhány száz templomon
	 * pörögne feleslegesen.
	 *
	 * Szándékosan tiszta függvény (se DB, se ES), hogy unit-tesztelhető legyen.
	 *
	 * @param  int[] $churchIdsWithRules  templomok, amiknek VAN miserendjük
	 * @param  int[] $indexedChurchIds    templomok, amiknek van idei indexelt miséjük
	 * @param  int[] $knownEmptyChurchIds akikről már tudjuk, hogy tényleg nincs idei miséjük
	 * @param  int   $limit               egy futásban ennyivel próbálkozunk
	 * @return int[]
	 */
	public static function massReindexCandidates(
		array $churchIdsWithRules,
		array $indexedChurchIds,
		array $knownEmptyChurchIds,
		int $limit = 100
	): array {
		if ($limit <= 0) {
			return [];
		}

		$skip = array_flip(array_map('intval', array_merge($indexedChurchIds, $knownEmptyChurchIds)));

		$candidates = [];
		foreach ($churchIdsWithRules as $id) {
			$id = (int) $id;
			if (isset($skip[$id])) {
				continue;
			}
			$candidates[$id] = $id;
			if (count($candidates) >= $limit) {
				break;
			}
		}

		return array_values($candidates);
	}

	/**
	 * Pótolja a hiányzó mise-dokumentumokat: megkeresi azokat a templomokat, amiknek van
	 * miserendjük, de nincs idei indexelt miséjük, és csak őket indexeli újra.
	 *
	 * Nem a teljes újraindexelés helyett van, hanem mellette: a teljes futás akkor is
	 * hagyhat lyukat, ha közben elhasal valami, ez pedig magától összevarrja.
	 *
	 * @return array{candidates:int,reindexed:int,still_empty:int}
	 */
	static function reindexMissingMasses(int $limit = 100, ?callable $logger = null): array {
		$log = $logger ?? function($msg) {};
		$elastic = new \ExternalApi\ElasticsearchApi();

		if (!$elastic->isexistsIndex('mass_index')) {
			$log("Nincs mass_index — a pótindexelésnek nincs mit tennie.");
			return ['candidates' => 0, 'reindexed' => 0, 'still_empty' => 0];
		}

		$yearStart = date('Y-01-01');
		$yearEnd   = date('Y-12-31');

		$indexed = $elastic->churchIdsWithMassesInPeriod($yearStart, $yearEnd);
		$meta = $elastic->getIndexMeta('mass_index');
		$knownEmpty = array_map('intval', $meta['churches_without_masses'] ?? []);

		$withRules = \Eloquent\Church::where('ok', 'i')->has('massrules')->orderBy('id')->pluck('id')->toArray();

		$candidates = self::massReindexCandidates($withRules, $indexed, $knownEmpty, $limit);
		if (empty($candidates)) {
			$log("Nincs pótolni való: minden miserenddel rendelkező templomnak van idei indexelt miséje.");
			return ['candidates' => 0, 'reindexed' => 0, 'still_empty' => 0];
		}

		$log("Pótindexelés " . count($candidates) . " templomra.");
		self::updateMasses([], $candidates, $logger);

		// A bulk insert alapból ~1 másodperc múlva válik kereshetővé, addig a
		// visszaellenőrzés hamis "üres" eredményt adna.
		$elastic->refreshIndex('mass_index');

		$indexedAfter = array_flip(array_map('intval', $elastic->churchIdsWithMassesInPeriod($yearStart, $yearEnd)));
		$stillEmpty = [];
		$reindexed = 0;
		foreach ($candidates as $id) {
			if (isset($indexedAfter[$id])) {
				$reindexed++;
			} else {
				$stillEmpty[] = $id;
			}
		}

		if (!empty($stillEmpty)) {
			$log(count($stillEmpty) . " templomnak a pótindexelés után sincs idei miséje — ezekkel nem próbálkozunk újra a következő teljes indexépítésig.");
			$merged = array_values(array_unique(array_merge($knownEmpty, $stillEmpty)));
			sort($merged);
			$meta['churches_without_masses'] = $merged;
			if (!$elastic->setIndexMeta('mass_index', $meta)) {
				$log("A vízjelet nem sikerült frissíteni — legközelebb újra megpróbáljuk ezeket.");
			}
		}

		$log("Pótindexelés kész: " . $reindexed . " templom került be, " . count($stillEmpty) . " maradt üres.");
		return [
			'candidates'  => count($candidates),
			'reindexed'   => $reindexed,
			'still_empty' => count($stillEmpty),
		];
	}

	/*
	 * Frissíti az összes elasticsearch mise indexet az adatbázisból
	 * Ehhez legenerálja az összes miseidőpontot is
	 *
	 * #306: a teljes (top-level, $tids nélküli) cron-futás korábban MINDIG mindent
	 * újragenerált (~40 perc, 500k+ esemény), akkor is, ha semmi nem változott. Most a
	 * shouldFullReindex() őr csak akkor engedi a teljes futást, ha az index üres, újabb a
	 * legutóbbi indexépítésnél, vagy a misék/periódusok változtak azóta. A per-templom PUT
	 * (generate.php) és a rekurzív chunk-hívások mindig nem-üres $tids-szel jönnek, ezért
	 * érintetlenek.
	 *
	 * #627: az "azóta" alapja elsősorban az INDEX saját vízjele (`_meta.full_reindex_at`),
	 * és csak annak hiányában a crons.lastsuccess_at. Így a "friss DB-seed + régi, mentésből
	 * visszatöltött ES-index" eset (0-ról induló docker compose up) nem tud csendben
	 * kimaradni: a DB cron-sora nem tudhat az index tartalmáról, a vízjel viszont az
	 * indexszel együtt utazik. Ugyanezért nézzük az index év-lefedettségét is.
	 */
	static function updateMasses($years = [], $tids = [], ?callable $logger = null) {
		$log = $logger ?? function($msg) {};
		$startTime = time();
		set_time_limit(3000); // Hosszabb idő kellhet a frissítéshez

		$isFullRun = empty($tids);

		if (empty($years)) {
			$years = [date('Y') - 1, date('Y'), date('Y') + 1];
		}

		// #306: teljes cron-futásnál (üres $tids) döntsük el, kell-e egyáltalán újragenerálni.
		if ($isFullRun) {
			try {
				$indexState = self::massIndexState();
			} catch (\Throwable $e) {
				// ES-hiba az index-ellenőrzésnél: a biztonság kedvéért fussunk le teljesen.
				$indexState = ['empty' => true, 'created_at' => null, 'indexed_at' => null, 'max_start_date' => null, 'skipped_churches' => []];
				$log("#306: index-ellenőrzés hibázott (" . $e->getMessage() . ") — biztonságból teljes futás.");
			}
			$cron = \Eloquent\Cron::where('class', '\\ExternalApi\\ElasticsearchApi')
				->where('function', 'updateMasses')->first();
			$cronLastSuccess = $cron->lastsuccess_at ?? null;
			// #627: az index saját vízjele erősebb bizonyíték, mint a DB cron-sora.
			$lastSuccess = $indexState['indexed_at'] ?? $cronLastSuccess;
			$maxUpdated  = \Eloquent\CalGeneratedPeriod::max('updated_at');
			$maxMassUpdated = \Eloquent\CalMass::max('updated_at');
			$coversYears = self::indexCoversYears($indexState['max_start_date'], $years);

			$needsReindex = self::shouldFullReindex(
				$lastSuccess,
				$maxUpdated,
				$indexState['empty'],
				$maxMassUpdated,
				$indexState['created_at']
			) || !$coversYears;

			$skipped = $indexState['skipped_churches'] ?? [];

			if (!$needsReindex) {
				/*
				 * A vízjel miatt itt egyébként megállnánk. A LEGUTÓBB KIHAGYOTT templomokat
				 * viszont mindig újrapróbáljuk: azok hiányoznak az indexből, és mivel a
				 * saját adatuk nem változott, semmi más nem hozná vissza őket — csendben
				 * kimaradnának a keresésből örökre.
				 */
				if (!empty($skipped)) {
					$log("A legutóbb kihagyott " . count($skipped) . " templomot újrapróbálom: "
						. implode(', ', array_slice($skipped, 0, 20))
						. (count($skipped) > 20 ? ', …' : ''));
					$maradek = self::reindexChunks(
						$skipped,
						$chunksize = 100,
						function (array $group) use ($years, $logger): void {
							static::updateMasses($years, $group, $logger);
						},
						$log
					);
					// A vízjel dátumát NEM frissítjük — csak a kihagyottak listáját, hogy a
					// sikeresen pótoltak kikerüljenek belőle.
					self::rememberSkippedChurches(array_keys($maradek), $log);
					if (!empty($maradek)) {
						$log(count($maradek) . " templom továbbra sem indexelhető.");
					}
					return;
				}

				$log("#306: a misék és a generatedPeriods nem változtak a legutóbbi indexépítés óta ("
					. $lastSuccess . "), az index lefedi a(z) " . implode(', ', $years)
					. " éveket és nem üres — teljes újragenerálás kihagyva.");
				return;
			}
			$log("#306: teljes újragenerálás indul (indexEmpty=" . ($indexState['empty'] ? '1' : '0')
				. ", indexedAt=" . $indexState['indexed_at'] . ", indexCreated=" . $indexState['created_at']
				. ", cronLastSuccess=" . $cronLastSuccess . ", maxPeriodUpdated=" . $maxUpdated
				. ", maxMassUpdated=" . $maxMassUpdated . ", maxStartDate=" . $indexState['max_start_date']
				. ", coversYears=" . ($coversYears ? '1' : '0') . ").");
		}

		if( empty($tids)) {
			$tids = \Eloquent\Church::where('ok', 'i')->limit(8000)->pluck('id')->toArray();
		}

		$chunksize = 100;
		if (is_array($tids) && count($tids) > $chunksize) {
			// Egy elhasaló templom eddig az EGÉSZ hátralévő újraindexelést megölte: a
			// rekurzív hívás kivétele kibuborékolt, a soron következő több ezer templom
			// pedig sosem került sorra. Mostantól a hibás darab kimarad, a többi lefut,
			// és a végén dobunk — így a cron továbbra is hibásnak látszik, de nem
			// hagyunk magunk után nagy lyukat az indexben.
			$failedChurches = self::reindexChunks(
				$tids,
				$chunksize,
				function (array $group) use ($years, $logger): void {
					static::updateMasses($years, $group, $logger);
				},
				$log
			);

			/*
			 * A futás VÉGIGMEGY, a hibás templomot kihagyja, és a végén összesítve
			 * kiírja őket.
			 *
			 * Eddig egyetlen hibás templom kivételt dobott, a vízjel tehát nem íródott ki,
			 * és a következő kör elölről kezdte mind az 51 darabot — ugyanazon a templomon
			 * megint elbukva. A cron így soha nem lett sikeres, közben viszont folyamatosan
			 * újraindexelte az egészet.
			 *
			 * A kihagyott templomok NEM vesznek el: a vízjelbe kerülnek, és a következő
			 * futás mindig újrapróbálja őket, akkor is, ha egyébként nem lenne mit tenni.
			 */
			if (!empty($failedChurches)) {
				$reszletek = [];
				foreach ($failedChurches as $tid => $uzenet) {
					$reszletek[] = 'templom #' . $tid . ': ' . $uzenet;
				}
				$osszegzes = count($failedChurches) . " templomot kihagytam hiba miatt:\n"
					. implode("\n", $reszletek);
				$log($osszegzes);
				error_log('[miserend] updateMasses: ' . $osszegzes);
			}

			if ($isFullRun) self::markFullReindex($years, $log, array_keys($failedChurches));
			return;
		}

		$elastic = new \ExternalApi\ElasticsearchApi();
		$elastic->deleteMasses($tids); //Sajnos egyszerre törlük több templomét, de ha aztán a legenárálás elhasal valamelyiknél, akkor elvesztettünk csomót.

		$churchTimezones = [];
		$churches = \Eloquent\Church::whereIn('id', $tids)->get()->keyBy('id');
		foreach($churches as $church_id => $church) {
			$churches[$church_id] = $church->toElasticArray();
		}
		$log("Talált templomok száma: " . count($churches));

		$allMasses = \Eloquent\CalMass::whereIn('church_id', $tids)->get()->all();
		foreach ($churches as $id => $church) {
			$churchTimezones[$id] = $church->time_zone ?? 'Europe/Budapest';
		}

		$debug = [];
		$debug[] = "Talált misék száma: " . count($allMasses);
		
		$log("Talált misék száma: " . count($allMasses));
		
		$massPeriods = \Eloquent\CalMass::generateMassPeriodInstancesForYears($allMasses, $churchTimezones, $years);
		$log("Egyedi periódusokkal felpumpálva már ". count($massPeriods). " a szám.");
		
		$countAllMasses = 0;
		foreach($massPeriods as $k => $mass) {
			$bulkInsert = [];

	           $rrule = new \SimpleRRule($mass['rrule']);
	           $occs = $rrule->getOccurrences();
			/*
			 * #756: itt SORONKÉNT ment ki egy „Talált időpontok száma" a naplóba —
			 * mise-periódusonként. Egyetlen templomnál (pl. #276) ez több ezer sor, és
			 * a valódi hibákat (elhasalt import, túl hosszú cím) elmossa. Az összesített
			 * darabszám a ciklus után úgyis ott van.
			 */
			foreach($occs as $occ) {
				$bulkInsert[] = [
					'index' => [
						'_index' => 'mass_index',
						'_id' => uniqid()
					]
				];
				$bulkInsert[] = [
					'church_id' => $mass['church_id'],
					'mass_id' => $mass['mass_id'],
					'start_date' => $occ->copy()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTime::ATOM),
					'start_minutes' => $occ->copy()->setTimezone(new \DateTimeZone('UTC'))->hour * 60 + $occ->copy()->setTimezone(new \DateTimeZone('UTC'))->minute,
					'title' => $mass['title'],
					'types' => $mass['types'],
					'rite' => $mass['rite'],
					'duration_minutes' => $mass['duration_minutes'],
					// #334: tömbként indexeljük. Az ES keyword mezője natívan kezeli a több
					// értéket, így a nyelvszűrő (terms lang.keyword) a többnyelvű misét is
					// megtalálja bármelyik nyelvére keresve.
					'lang' => \Eloquent\CalMass::splitLanguages(is_array($mass['lang']) ? implode(',', $mass['lang']) : $mass['lang']),
					'comment' => $mass['comment'],
					'church' => $churches[$mass['church_id']]
				];
				
			}			
			$countAllMasses += count($occs);
			if (!empty($bulkInsert)) {
				$elasticResult = $elastic->putBulk($bulkInsert);
				if (!$elasticResult) {
					
					if(isset($elastic->jsonData->errors)) {
						$elastic->error = '';
						$errItems = [];
						foreach($elastic->jsonData->items as $item ) {
							if(isset($item->index->error)) {					
								$errItems[] = $item->index->error->type . ': ' . $item->index->error->reason . "\n";
							}
						}
						$elastic->error .= "\n" . implode("\n", $errItems);
						
					}

					throw new \Exception("Could not insert mass data for church ID ".$mass['church_id']."!\n".$elastic->error);
				}
			}
		}

		$log("Nos hát szépen minden napra szét bontva így lett nekünk már ".$countAllMasses." misénk.");

		$log("Elkészült a frissítés " . (time() - $startTime) . " másodperc alatt azaz ".round((time() - $startTime)/60,2)." perc alatt.");
		if ($isFullRun) self::markFullReindex($years, $log);
		return $debug;
	}

	function deleteMasses($tids = []) {
		
		// Delete existing masses for the given church IDs		
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST, "POST");
		$this->buildQuery('mass_index/_delete_by_query', json_encode([
			"conflicts" => "proceed",
			"query" => [
				"terms" => ["church_id" => $tids]
			]
		]));
		$this->run();
		if(isset($this->error)) {			
			throw new \Exception("Could not delete existing masses!\n" . $this->error);
		}
		
		return true;		
	}

	/**
	 * #641: hétvégi misék EGYETLEN lekérdezésben, akárhány templomra.
	 *
	 * A térkép bbox-végpontja eddig templomonként KÉT Elasticsearch-kört futtatott
	 * (szombat + vasárnap). 460 templomnál ez 920 hálózati kör — ez volt a /terkep
	 * lassúságának fő oka. Terms-aggregáció + top_hits: egy kérés, templomonként
	 * időrendben az első néhány mise.
	 *
	 * @param  int[]    $churchIds
	 * @param  string[] $titleKeys  cím-szűrő (MASS kategória kulcsai); üres = nincs szűrés
	 * @return array    [church_id => [ ['start_date'=>..., 'title'=>...], ... ]]
	 */
	function massesByChurch(array $churchIds, string $fromUtc, string $toUtc, array $titleKeys = [], int $perChurch = 8): array {
		if (empty($churchIds)) {
			return [];
		}

		$must = [
			["terms" => ["church_id" => array_values(array_map('intval', $churchIds))]],
			["range" => ["start_date" => ["gte" => $fromUtc, "lte" => $toUtc]]],
		];
		if (!empty($titleKeys)) {
			$must[] = ["terms" => ["title.keyword" => array_values($titleKeys)]];
		}

		$this->curl_setopt(CURLOPT_CUSTOMREQUEST, "GET");
		$this->buildQuery('mass_index/_search', json_encode([
			"size" => 0,
			"query" => ["bool" => ["must" => $must]],
			"aggs" => [
				"by_church" => [
					"terms" => ["field" => "church_id", "size" => count($churchIds)],
					"aggs" => [
						"masses" => [
							"top_hits" => [
								"size" => $perChurch,
								"sort" => [["start_date" => ["order" => "asc"]]],
								"_source" => ["includes" => ["start_date", "title"]],
							],
						],
					],
				],
			],
		]));
		$this->run();

		if ($this->responseCode != 200) {
			throw new \Exception("Could not search mass_index!\n" . $this->error);
		}

		$byChurch = [];
		$buckets = $this->jsonData->aggregations->by_church->buckets ?? [];
		foreach ($buckets as $bucket) {
			$masses = [];
			foreach ($bucket->masses->hits->hits ?? [] as $hit) {
				$masses[] = [
					'start_date' => $hit->_source->start_date ?? '',
					'title' => $hit->_source->title ?? '',
				];
			}
			$byChurch[(int) $bucket->key] = $masses;
		}

		return $byChurch;
	}

	/**
	 * Templomok újraindexelése darabokban, a hibás templom KIEMELÉSÉVEL.
	 *
	 * Eddig a 100-as darab egyben veszett el, és a hibaüzenet is csak az első öt
	 * templom azonosítóját mondta — abból nem derült ki, MELYIK templom a hibás, a
	 * másik 99 pedig kimaradt az indexből. Mivel a teljes futás egyetlen hibától is
	 * kivételt dob, a cron így soha nem lett sikeres, és minden körben elölről
	 * kezdte az egészet.
	 *
	 * Hibánál a darabot templomonként újrafuttatjuk: a hibás templom pontosan
	 * megnevezhető, a többi bekerül. Ez csak hiba esetén fut le, tehát az ép futást
	 * nem lassítja.
	 *
	 * A darabolást szándékosan itt, I/O nélkül tartjuk — így tesztelhető.
	 *
	 * @param  int[]    $tids
	 * @param  callable $runner  fn(int[] $tids): void — a tényleges indexelés
	 * @param  callable $log
	 * @return array<int,string> templom-id => hibaüzenet
	 */
	static function reindexChunks(array $tids, int $chunksize, callable $runner, callable $log): array
	{
		$failedChurches = [];

		foreach (array_chunk($tids, $chunksize) as $index => $chunk) {
			try {
				$runner($chunk);
				continue;
			} catch (\Throwable $e) {
				$log("A(z) " . ($index + 1) . ". darab hibázott (" . $e->getMessage()
					. ") — templomonként újrapróbálom.");
			}

			foreach ($chunk as $tid) {
				try {
					$runner([$tid]);
				} catch (\Throwable $inner) {
					$failedChurches[$tid] = $inner->getMessage();
					$log("Templom #" . $tid . " újraindexelése hibázott: " . $inner->getMessage());
				}
			}
		}

		return $failedChurches;

   /**
	 * Hány indexelt templomnak HIÁNYZIK a `location` geo_point mezője?
	 *
	 * A távolság szerinti templomkeresés kizárólag erre a mezőre szűr. A mapping
	 * régóta tartalmazza, a dokumentumot építő kód is kiírja — de a meglévő
	 * indexbe csak azoknál került be, amelyeket a javítás ÓTA újraindexeltünk.
	 * Élesben ez azt jelentette, hogy a „X km-en belül" keresés a templomok
	 * túlnyomó részét egyszerűen nem találta meg, és NÉMÁN: nem hiba, csak nulla
	 * találat, amit a felhasználó „nincs ilyen templom"-nak olvas.
	 *
	 * Ezt csak egy teljes újraindexelés javítja (`updateChurches()` tid nélkül).
	 * A /health ezért mutatja a számot: a mapping-változás önmagában nem elég,
	 * és enélkül semmi nem szólt volna.
	 *
	 * @return array{indexed:int,missing:int}|null null, ha az index nem kérdezhető
	 */
	function churchesMissingLocation(): ?array {
		$count = function (?array $query): ?int {
			$this->curl_setopt(CURLOPT_CUSTOMREQUEST, "GET");
			$this->buildQuery('churches/_count', $query === null ? null : json_encode($query));
			$this->run();
			if ($this->responseCode != 200 || !isset($this->jsonData->count)) {
				return null;
			}
			return (int) $this->jsonData->count;
		};

		$indexed = $count(null);
		if ($indexed === null) {
			return null;
		}

		$withLocation = $count(['query' => ['exists' => ['field' => 'location']]]);
		if ($withLocation === null) {
			return null;
		}

		return ['indexed' => $indexed, 'missing' => max(0, $indexed - $withLocation)];

	}

	function churchIdsWithMassesInPeriod($startDate, $endDate) {
		$this->curl_setopt(CURLOPT_CUSTOMREQUEST, "GET");
		$this->buildQuery('mass_index/_search', json_encode([
			"size" => 0,
			"query" => [
				"range" => ["start_date" => [
					"gte" => $startDate, 
					"lte" => $endDate
				]]
			],
			"aggs" => [
				"churches_with_masses_in_period" => [
					"terms" => ["field" => "church_id", "size" => 10000]
				]
			]
		]));
		$this->run();
		if($this->responseCode != 200) {
			throw new \Exception("Could not search mass_index!\n".$this->error);
		}
				
		return array_map(function($bucket) {
			return $bucket->key;
		}, $this->jsonData->aggregations->churches_with_masses_in_period->buckets);
	}

}
