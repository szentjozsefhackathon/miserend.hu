<?php

namespace Html;

use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

class Health extends Html {

    /** Hányszor próbáljuk meg a külső végpontot, mielőtt kiesésnek minősítjük. */
    const EXTERNAL_API_TEST_ATTEMPTS = 3;
    public $infos;
    public $cronjobs;
    public $stuckCronjobs;
    public $elasticsearch;
    public $churchesWithNoElasticMasses;
    public $churchesWithNoElasticMassesCount;
    public $churchesMissingLocation;
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
		
		/*
		 * #496: a határ-lefedettség hiánya eddig SEHOL nem látszott. A tünete az, hogy
		 * egy település alatt nem jön ki a templom — a szerkesztő ilyenkor a saját
		 * adatát hibáztatja, pedig a boundary-szinkron nem ért oda. Élesben a szlovák
		 * minta 23%-ának egyáltalán nincs határa.
		 */
		$hatarNelkul = \Crons::churchesWithoutBoundaryCount();
		if ($hatarNelkul === 0) {
			$this->infos[] = ['Határ nélküli templomok', '<span class="text-success">✅ nincs ilyen</span>'];
		} else {
			$this->infos[] = ['Határ nélküli templomok',
				'<span class="text-warning">⚠️ ' . $hatarNelkul . ' aktív, koordinátás templomnak nincs '
				. 'administratív határa — a települési keresés nem találja meg őket. '
				. 'A boundary-szinkron (cron 42) fokozatosan pótolja; a régen ellenőrzötteket '
				. 'a cron 497 állítja vissza a sor elejére.</span>'];
		}

		/*
		 * #568: meddig kell még a szabad szöveges búcsú-mező elemzője?
		 *
		 * borazslo kérése: „ha a /health megmutatja, hogy még mennyi régi módi búcsú
		 * adatot találtunk, és akkor ha az egyszer csak elfogy, akkor kiírhatja, hogy
		 * »Megszűnt ennek a búcsú szöveget feldolgozó scriptnek a létjogosultsága.
		 * Vegyél fel rá egy issue-t és ki lehet vezetni.«"
		 */
		$bucsuForras = \Bucsu::forrasStatisztika();
		if ($bucsuForras['szoveges'] === 0) {
			$this->infos[] = ['Búcsú-adat forrása',
				'<span class="text-success">✅ Megszűnt ennek a búcsú szöveget feldolgozó '
				. 'scriptnek a létjogosultsága. Vegyél fel rá egy issue-t és ki lehet vezetni. '
				. '(' . $bucsuForras['patron_day'] . ' templomnál OSM <code>patron_day</code>.)</span>'];
		} else {
			$this->infos[] = ['Búcsú-adat forrása',
				'<span class="text-muted">' . $bucsuForras['szoveges'] . ' templomnál még a régi, '
				. 'szabad szöveges mezőből olvassuk ki a búcsút; '
				. $bucsuForras['patron_day'] . ' templomnál van OSM <code>patron_day</code>. '
				. 'Amíg ez a szám nem nulla, az elemző kell.'
				. ($bucsuForras['ertelmezhetetlen'] > 0
					? ' (További ' . $bucsuForras['ertelmezhetetlen'] . ' templomnál van kitöltött mező, '
					  . 'de nem tudjuk értelmezni — ezek javítható adatok.)'
					: '')
				. '</span>'];
		}

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
		
		
		/*
		 * #822: az sqlite-fájlok állapota.
		 *
		 * A ciklus `1..4` volt, kézzel beírva. Két baja volt ezzel:
		 *
		 *  - a v5 (#56/#778) EGYÁLTALÁN nem látszott, pedig a `LEGUJABB_VERZIO`
		 *    kiadott verzió — épp arról nem tudtuk meg, hogy elkészült-e;
		 *  - a v1–v3 örökké PIROSAN állt, mert azokat évek óta nem generáljuk. Az
		 *    állandó, tennivaló nélküli piros arra tanít, hogy a lapot ne vegyük
		 *    komolyan — pont az ellenkezőjét éri el, mint amiért van.
		 *
		 * Mostantól a `Sqlite::GENERALT_VERZIOK` a forrás: azokat mérjük, amiket
		 * tényleg építünk. A régebbieket felsoroljuk, de „befagyasztva" jelöléssel,
		 * hibajelzés nélkül.
		 */
		$results = [];
		$generalt = \Api\Sqlite::GENERALT_VERZIOK;
		$osszes = range(1, max($generalt));

		foreach($osszes as $i) {
			$tables = [];
			$sqlite = new \Api\Sqlite();
			$sqlite->version = $i;
			$befagyasztott = !in_array($i, $generalt, true);

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

			// A befagyasztott verzióknál a hiba nem tennivaló: nem is generáljuk őket.
			if($befagyasztott && $alert == 'danger') {
				$alert = 'secondary';
			}

			$tmp = " <a class=\"alert-".$alert."\" href=\"$sqlite->folder$sqlite->sqliteFileName\">".$sqlite->sqliteFileName."</a> ";
			if($befagyasztott) $tmp .= "<small class=\"text-muted\">(befagyasztva, nem generáljuk)</small> ";
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

		// #724: a nearby.log méretét/hosszát mutató blokk megszűnt a naplóval együtt.

		// Health of CronJobs
		$this->cronjobs = \Eloquent\Cron::orderBy('deadline_at','DESC')->get()->toArray();

		// Az elakadt munkákat külön is kiemeljük: az attempts oszlop egyetlen bukott
		// futástól is piros, ezért abban elveszett, hogy volt cron, ami hónapok óta nem
		// futott le sikeresen.
		$this->stuckCronjobs = [];
		foreach ($this->cronjobs as $i => $cron) {
			$reason = \Eloquent\Cron::stuckReason(
				$cron['lastsuccess_at'] ?? null,
				(string) ($cron['frequency'] ?? ''),
				null,
				// A napi ablak nélkül a számolás félrevezet: az ablakon KÍVÜLI órák nem
				// a munka hibái. L. Cron::eligibleSecondsBetween().
				$cron['from'] ?? null,
				$cron['until'] ?? null
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

		/*
		 * A távolság szerinti templomkeresés a `location` geo_pointra szűr. Ha az
		 * indexben nincs kitöltve, a keresés NÉMÁN nem talál semmit — nem hiba, csak
		 * nulla találat, amit „nincs ilyen templom"-nak olvas a felhasználó. Pontosan
		 * ez történt: a mapping és a dokumentum-építés is rendben volt, de az index
		 * nagy része a javítás előtti teljes újraindexelésből maradt.
		 *
		 * A szám SOHA ne vigye le a /health-et — a többi ellenőrzés fontosabb.
		 */
		try {
			$this->churchesMissingLocation = $elastic->churchesMissingLocation();

			/*
			 * #826: a hiányzó `location` két, teljesen különböző okból állhat elő, és
			 * az eddigi tanács („teljes újraindexelés kell") csak az egyikre igaz.
			 *
			 * Élesben MIND a 15 hiányzó olyan templom volt, amelynek nincs koordinátája
			 * az adatbázisban — azoknak a dokumentumában soha nem is lesz `location`,
			 * akárhányszor indexeljük újra. Az ő bajuk adatbaj (#497), nem index-baj.
			 * A lap eddig tehát olyan műveletre küldte az embert, ami nem segít.
			 */
			$hianyzoIds = $elastic->churchIdsMissingLocation();
			if (is_array($hianyzoIds) && $hianyzoIds !== []) {
				$indexelheto = \Eloquent\Church::whereIn('id', $hianyzoIds)
						->whereNotNull('lat')->where('lat', '!=', 0)
						->whereNotNull('lon')->where('lon', '!=', 0)
						->count();
				$this->churchesMissingLocation['reindexable'] = $indexelheto;
				$this->churchesMissingLocation['no_coordinates'] = count($hianyzoIds) - $indexelheto;
			}
		} catch (\Throwable $e) {
			$this->churchesMissingLocation = null;
		}

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

				// Amit szándékosan nem ellenőrzünk, az nem hiba. Külön jelöljük, hogy a
				// piros tényleg csak a bajt jelentse.
				if (method_exists($externalapi, 'isTestable') && !$externalapi->isTestable()) {
					$this->externalapis[$apiToTest]['testable'] = false;
					$this->externalapis[$apiToTest]['testresult'] = $externalapi->testSkipReason()
						?? 'Nincs ellenőrző lekérdezés ehhez a végponthoz.';
					continue;
				}

				/*
				 * Újrapróbálkozás, mert egyetlen sikertelen kérés még nem jelent kiesést.
				 *
				 * Az Overpass például kimérhetően akadozik: egymás után ötször hívva
				 * 200/504/200/200/429 jött vissza, és mindössze 2 párhuzamos slotot ad.
				 * Havi ~29 000 hívásnál tehát rendszeresen belefutunk — miközben a
				 * területi adatok frissülnek, azaz a szolgáltatás ÉL. Egy egy lövésű
				 * ellenőrzés ilyenkor hazudik: pirosat mutat egy működő végpontra.
				 *
				 * Ha az első próbálkozás nem sikerül, de egy későbbi igen, azt is
				 * megmutatjuk — az akadozás önmagában is információ.
				 */
				$attempts = 0;
				$testresult = null;
				while ($attempts < self::EXTERNAL_API_TEST_ATTEMPTS) {
					$attempts++;
					$testresult = $externalapi->test();
					if ($testresult === true) break;
					if ($attempts < self::EXTERNAL_API_TEST_ATTEMPTS) usleep(700000);
				}

				if($testresult !== true)
					throw new \Exception($testresult);

				$this->externalapis[$apiToTest]['testresult'] = 'OK';
				$this->externalapis[$apiToTest]['attempts'] = $attempts;
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

		/*
		 * #827: KÖZIGAZGATÁSI határ — ez külön szám, és ez a fontosabbik.
		 *
		 * A fenti sor BÁRMILYEN kapcsolatot beszámít. Csakhogy az OSM sokféle határt
		 * ad vissza (`postal_code`, `judicial`, `wine_community`, `timezone`…), és a
		 * `religious_administration` határokat ráadásul MI hozzuk létre minden
		 * templomhoz az egyházmegyéjéből. Egy templom tehát „100%-ban kereshető"-nek
		 * látszhat úgy, hogy egyetlen közigazgatási határa sincs.
		 *
		 * Márpedig a HELYNEVEK kizárólag közigazgatási határból jönnek
		 * (`locationCityName()` és társai). Ez a szám dönti el, hogy a `templomok.varos`
		 * eldobása (#496/#497/#498) hány templomot hagyna helynév nélkül — épp ezért
		 * nem szabad összemosni a kettőt.
		 */
		$churchesWithAdminBoundary = DB::table('lookup_boundary_church')
			->join('templomok', 'templomok.id', '=', 'lookup_boundary_church.church_id')
			->join('boundaries', 'boundaries.id', '=', 'lookup_boundary_church.boundary_id')
			->where('templomok.ok', 'i')
			->whereNull('templomok.deleted_at')
			->where('boundaries.boundary', 'administrative')
			->distinct()
			->count('lookup_boundary_church.church_id');

		$this->boundariesStats = [
			'with_osm' => [
				'count' => $churchBoundaryStats->count ?? 0,
				'never_checked_count' => $churchBoundaryStats->never_checked_count ?? 0,
				'with_boundary_count' => $churchesWithBoundary,
				'without_boundary_count' => max(0, ($churchBoundaryStats->count ?? 0) - $churchesWithBoundary),
				// #827: a helynevekhez ez a szám kell, nem a fenti.
				'with_admin_boundary_count' => $churchesWithAdminBoundary,
				'without_admin_boundary_count' => max(0, ($churchBoundaryStats->count ?? 0) - $churchesWithAdminBoundary),
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

		/*
		 * #825: nem csak a SZÁMUKAT mutatjuk meg, hanem magukat a sorokat is.
		 *
		 * Eddig egy piros szám állt itt, tennivaló nélkül. Egy szám alapján viszont nem
		 * lehet eldönteni, hogy baj-e: az árva határ lehet ártalmatlan maradék (a
		 * templom átkerült egy másik határ alá), lehet egy elrontott szinkron nyoma, és
		 * lehet olyan egyházmegye-határ is, amit MI hozunk létre — utóbbi teljesen
		 * szabályos, csak épp nincs alatta templom.
		 *
		 * Törölni szándékosan NEM törlünk: a határ-sor eldobása visszafordíthatatlan,
		 * és egy ma árva határ holnap újra kötődhet. Előbb látni kell, mik ezek.
		 */
		$this->boundariesStats['orphan_list'] = DB::table('boundaries')
			->leftJoin('lookup_boundary_church', 'boundaries.id', '=', 'lookup_boundary_church.boundary_id')
			->whereNull('lookup_boundary_church.church_id')
			->select('boundaries.id', 'boundaries.name', 'boundaries.boundary',
				'boundaries.admin_level', 'boundaries.osmtype', 'boundaries.osmid',
				'boundaries.updated_at')
			->orderBy('boundaries.boundary')
			->orderBy('boundaries.admin_level')
			->limit(200)
			->get();
		
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
