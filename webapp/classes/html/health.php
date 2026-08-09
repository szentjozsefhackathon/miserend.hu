<?php

namespace Html;

use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

class Health extends Html {
    public $infos;
    public $cronjobs;
    public $stuckCronjobs;
    public $elasticsearch;
    public $churchesWithNoElasticMasses;
    public $churchesWithNoElasticMassesCount;
    public $externalapis;
    public $boundariesStats;
    public $schemaCheck;
    public $emails;
    public $mailing;
    public $foremail;

    public function __construct() {
        parent::__construct();

        global $user;
        if (!$user->checkRole('"any"')) {
            throw new \Exception('Nincs jogosultságod megtekinteni az egészség oldalt.');
        }

        $this->setTitle('Miserend.hu állapotáról');
  
		//General informations
		global $config;
		
		$this->infos = [
			['server', $_SERVER['SERVER_SOFTWARE']],
			['php verzió', phpversion()],
			['php extensions', implode(', ',get_loaded_extensions())],
			['environment', $config['env'] ],
			['debug', $config['debug']],
			['error_reporting', $config['error_reporting'] ],
			['mail/debug', $config['mail']['debug'] ]
		];
		
		// Check GD extension specifically
		if (!extension_loaded('gd')) {
			$this->infos[] = ['GD Extension', '<span class="text-danger">⚠️ HIÁNYZIK! A képfeltöltés nem fog működni.</span>'];
		} else {
			$gd_info = gd_info();
			$gd_functions = [
				'imagecreatefromjpeg' => function_exists('imagecreatefromjpeg'),
				'imagecreatefrompng' => function_exists('imagecreatefrompng'),
				'imagecopyresampled' => function_exists('imagecopyresampled')
			];
			$missing_functions = array_keys(array_filter($gd_functions, function($exists) { return !$exists; }));
			
			if (empty($missing_functions)) {
				$this->infos[] = ['GD Extension', '<span class="text-success">✅ Telepítve és működőképes</span>'];
			} else {
				$this->infos[] = ['GD Extension', '<span class="text-warning">⚠️ Telepítve, de hiányzó függvények: ' . implode(', ', $missing_functions) . '</span>'];
			}
		}
		
		
		$results = [];
		for($i=1;$i<=4;$i++) {		
			$tables = [];
			$sqlite = new \Api\Sqlite();
			$sqlite->version = $i;			
			
			if(!$tables = $sqlite->checkSqliteFile()) {
				$alert = 'danger';
			} else 
				$alert = 'success';
				
			if(file_exists($sqlite->sqliteFilePath)) {
				$filemtime = date ("Y-m-d H:i:s.", filemtime($sqlite->sqliteFilePath));
			} else {
				$alert = 'danger';
				$filemtime = false;
			}
								
			$tmp = " <a class=\"alert-".$alert."\" href=\"$sqlite->folder$sqlite->sqliteFileName\">".$sqlite->sqliteFileName."</a> ";
			if($filemtime) $tmp .= "(".$filemtime.") ";
			
			if($alert == "success") {	
				foreach($tables as $name => $count) {
					$tables[$name] = $name.": ".$count;
				}
				$tmp .= ": ".implode(', ',$tables);
			}
			
			$results[] = $tmp;
		}
		$this->infos[] = ["sqlite files",implode("<br/>",$results)];
		$results = [] ;

		// Health of nearby log
		try {
			$loginfo = \Api\NearBy::getLogFileInfo();
			if (!is_array($loginfo)) {
				$this->infos[] = ['nearby.log', '<span class="text-warning">Nincs információ</span>'];
			} else {
				if (isset($loginfo['file_size'])) {
					$sizeKb = round($loginfo['file_size'] / 1024, 2);
					$this->infos[] = ['nearby.log mérete', $sizeKb . ' KB'];
				} else {
					$this->infos[] = ['nearby.log mérete', '<span class="text-warning">ismeretlen</span>'];
				}

				if (isset($loginfo['line_count'])) {
					$this->infos[] = ['nearby.log hossza', $loginfo['line_count'] . ' sor'];
				} else {
					$this->infos[] = ['nearby.log hossza', '<span class="text-warning">ismeretlen</span>'];
				}
			}
		} catch (\Exception $e) {
			$this->infos[] = ['nearby.log', '<span class="text-danger">Hiba: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</span>'];
		}
		

		// Health of CronJobs
		$this->cronjobs = \Eloquent\Cron::orderBy('deadline_at','DESC')->get()->toArray();

		// Az elakadt munkákat külön is kiemeljük: az attempts oszlop egyetlen bukott
		// futástól is piros, ezért abban elveszett, hogy volt cron, ami hónapok óta nem
		// futott le sikeresen.
		$this->stuckCronjobs = [];
		foreach ($this->cronjobs as $i => $cron) {
			$reason = \Eloquent\Cron::stuckReason(
				$cron['lastsuccess_at'] ?? null,
				(string) ($cron['frequency'] ?? '')
			);
			$this->cronjobs[$i]['stuck_reason'] = $reason;
			if ($reason !== null) {
				$this->stuckCronjobs[] = [
					'id'       => $cron['id'],
					'name'     => $cron['class'] . '::' . $cron['function'] . '()',
					'reason'   => $reason,
					'attempts' => $cron['attempts'] ?? 0,
				];
			}
		}

		// Health of ElasticSearch database
		$elastic = new \ExternalApi\ElasticsearchApi();
		$elastic->query ="_cat/indices?format=json";
		$elastic->run();		
		if(isset($elastic->jsonData))
			$this->elasticsearch = $elastic->jsonData;

		$ids = $elastic->churchIdsWithMassesInPeriod(date('Y-01-01'), date('Y-12-31'));
		$this->churchesWithNoElasticMasses = \Eloquent\Church::whereNotIn('id', $ids)->has('massrules')->get()->toArray();
		$this->churchesWithNoElasticMassesCount = count($this->churchesWithNoElasticMasses);
		
		// Health of ExternalApis
		$this->externalapis = [];		
		$apisToTest = \ExternalApi\ExternalApi::collectExternalApis();
		foreach($apisToTest as $apiToTest) {
			$this->externalapis[$apiToTest] = ['name' => $apiToTest, 'stat' => 0];
			
			try {
			
				$className = "\ExternalApi\\".$apiToTest;
				
				if(!class_exists($className) )				
					throw new \Exception('Hiányzó osztály!');
				
				$externalapi = new $className();
				
				$this->externalapis[$apiToTest]['apiUrl'] = $externalapi->apiUrl ;
				$this->externalapis[$apiToTest]['cache'] = $externalapi->cache ;
				
				if(!method_exists($externalapi,'test')) 
					throw new \Exception('Hiányzik a tesztelő függvény!');
				
				
				$testresult = $externalapi->test();
				if($testresult !== true) 
					throw new \Exception($testresult);
				
				
								
				$this->externalapis[$apiToTest]['testresult'] = 'OK';
			}
			catch (\Exception $e) {
				$this->externalapis[$apiToTest]['testresult'] = $e->getMessage();
			}
			
		}
		
		$results = [];
        $results = DB::table('stats_externalapi')
			->select('name',DB::raw('SUM(count) count'))
			->where('date','>',date('Y-m-d',strtotime('-1 month')))
			->groupBy('name')->orderBy('date','asc')
			->get();        			
		foreach($results as $result) {			
			foreach ($this->externalapis as $key => &$api) {
				if (strtolower($key) === $result->name . "api") {
					$api['stat'] = $result->count;
					break;
				}
			}
		}
		
		// Health of Boundaries
		// 1. Mikor volt utoljára ellenőrizve egy-egy templomhoz a boundary (boundaries_checked_at).
		// Ez mutatja meg valósan, mikor volt utoljára lefuttatva a checkBoundaries cron egy adott templomnál.
		// (A korábbi boundaries.updated_at az OSM adatok változási dátumát mutatta, ami évek óta nem változhat,
		//  ezért adott hamisan nagy értéket az átlagos frissítettségnél.)
		$churchBoundaryStats = DB::table('templomok')
			->where('ok', 'i')
			->whereNotNull('lat')
			->where('lat', '!=', 0)
			->whereNotNull('lon')
			->where('lon', '!=', 0)
			->select(
				DB::raw('COUNT(*) as count'),
				DB::raw('SUM(CASE WHEN boundaries_checked_at IS NULL THEN 1 ELSE 0 END) as never_checked_count'),
				DB::raw('AVG(CASE WHEN boundaries_checked_at IS NOT NULL THEN DATEDIFF(NOW(), boundaries_checked_at) END) as avg_days_old'),
				DB::raw('MAX(boundaries_checked_at) as newest'),
				DB::raw('MIN(CASE WHEN boundaries_checked_at IS NOT NULL THEN boundaries_checked_at END) as oldest')
			)
			->first();
		
		/*
		 * #570: „ellenőrizve" != „van boundaryja". A checkBoundariesForOne() akkor is
		 * megjelöli a templomot ellenőrzöttként, ha az Overpass hibázott vagy nem adott
		 * eredményt — a templom mégis boundary NÉLKÜL marad. A területi (települési,
		 * egyházmegyei) keresés viszont KIZÁRÓLAG a lookup_boundary_church alapján szűr,
		 * ezért pontosan ez a szám mondja meg, hány templom TALÁLHATÓ MEG így egyáltalán.
		 *
		 * Enélkül a fenti „soha nem ellenőrzött" sor félrevezető: lehet 0, miközben a
		 * templomok fele mégsem kereshető területre.
		 */
		/*
		 * #706: az adatbázis-struktúra összevetése azzal, amit az initdb.d leír.
		 * Az élesen mindig kézzel ment végig minden migráció, ezért elcsúszhat:
		 * maradhat rég kivezetett tábla, hiányozhat egy újabb oszlop vagy index.
		 * A hiba SOHA ne vigye le a /health-et — a többi ellenőrzés fontosabb.
		 */
		try {
			$this->schemaCheck = \SchemaCheck::check();
		} catch (\Throwable $e) {
			$this->schemaCheck = ['available' => false, 'reason' => 'Az ellenőrzés hibára futott: ' . $e->getMessage()];
		}

		$churchesWithBoundary = DB::table('lookup_boundary_church')
			->join('templomok', 'templomok.id', '=', 'lookup_boundary_church.church_id')
			->where('templomok.ok', 'i')
			->whereNull('templomok.deleted_at')
			->distinct()
			->count('lookup_boundary_church.church_id');

		$this->boundariesStats = [
			'with_osm' => [
				'count' => $churchBoundaryStats->count ?? 0,
				'never_checked_count' => $churchBoundaryStats->never_checked_count ?? 0,
				'with_boundary_count' => $churchesWithBoundary,
				'without_boundary_count' => max(0, ($churchBoundaryStats->count ?? 0) - $churchesWithBoundary),
				'avg_days_old' => $churchBoundaryStats->avg_days_old ? round($churchBoundaryStats->avg_days_old, 2) : 0,
				'newest' => $churchBoundaryStats->newest ?? null,
				'oldest' => $churchBoundaryStats->oldest ?? null
			]
		];
		
		// 2. Orphan boundaries száma (lookup_boundary_church-ben nincsen)
		$orphanBoundaries = DB::table('boundaries')
			->leftJoin('lookup_boundary_church', 'boundaries.id', '=', 'lookup_boundary_church.boundary_id')
			->whereNull('lookup_boundary_church.church_id')
			->count();
		
		$this->boundariesStats['orphan_count'] = $orphanBoundaries;
		
		// 3. Templomok száma, amiknek nincs olyan boundary, aminek van osmid és osmtype
		$churchesWithoutOsmBoundary = DB::table('templomok')
			->where('ok', 'i') // csak engedélyezett templomok
			->whereNotExists(function($query) {
				$query->select(DB::raw(1))
					->from('lookup_boundary_church')
					->join('boundaries', 'lookup_boundary_church.boundary_id', '=', 'boundaries.id')
					->where('lookup_boundary_church.church_id', DB::raw('templomok.id'))
					->whereNotNull('boundaries.osmtype')
					->whereNotNull('boundaries.osmid')
					->where('boundaries.osmtype', '!=', '')
					->where('boundaries.osmid', '!=', 0);
			})
			->count();
		
		$this->boundariesStats['churches_without_osm_boundary'] = $churchesWithoutOsmBoundary;
		
		// 4. Templomok összes száma (engedélyezettekből)
		$totalChurches = DB::table('templomok')
			->where('ok', 'i')
			->count();
		
		// Templomok with osm boundaries száma
		$churchesWithOsmBoundary = $totalChurches - $churchesWithoutOsmBoundary;
		
		$this->boundariesStats['total_churches'] = $totalChurches;
		$this->boundariesStats['churches_with_osm_boundary'] = $churchesWithOsmBoundary;
		$this->boundariesStats['churches_with_osm_percentage'] = $totalChurches > 0 ? round(($churchesWithOsmBoundary / $totalChurches) * 100, 2) : 0;
		$this->boundariesStats['churches_without_osm_percentage'] = $totalChurches > 0 ? round(($churchesWithoutOsmBoundary / $totalChurches) * 100, 2) : 0;
		
		// Health of Mailing
		$this->emails = DB::table('emails')
			->select('type', 'status', DB::raw('COUNT(*) as total'))
			->where('created_at', '>=', Carbon::now()->subDays(30))
			->groupBy('type', 'status')
			->orderBy('updated_at','DESC')
			->get();

		$this->mailing = $config['smtp'];
		unset($this->mailing['Password']); // a health oldal ne szivárogtassa ki
		$this->mailing['debug'] = $config['mail']['debug'];

		// #610: a levélküldés némán dőlt el a konfigurációnál — ez legyen látható.
		$this->mailing['warning'] = false;
		if (trim((string) $this->mailing['Host']) === '') {
			$this->mailing['warning'] = 'Nincs beállítva SMTP kiszolgáló (SMTP_HOST): egyetlen levél sem megy ki.';
		} elseif (in_array($config['env'], ['production','staging']) AND $this->mailing['Host'] == 'mailcatcher') {
			$this->mailing['warning'] = 'A(z) '.$config['env'].' környezet a dev mailcatcher-re küld: minden levél elveszik.';
		}

		$email = new \Eloquent\Email();

		$html = '';

		// We send the test results as a test email
		$this->foremail = true;
		global $user;
		$this->user = $user;
		$this->loadTwig();
        $this->getTemplateFile();
        $html = $this->twig->render(strtolower($this->template), (array) $this);
		$html = $this->inlineCssFiles($html);
		$this->foremail = false;


		$this->mailing['testresult'] = $email->test($html);
		$this->mailing['testaccepted'] = $this->mailing['testresult'] === \Eloquent\Email::SMTP_ACCEPTED;
			
		return;		
    }
}
