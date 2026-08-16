<?php

namespace Api;

use Illuminate\Database\Capsule\Manager as DB;

class NearBy extends Api {

	public $title = 'Közeli templomok és misék';
    public $format = 'json'; //or text	
    public $requiredVersion = ['>=',4]; // API v4-től érhető el

	public $fields = [
		'lat' => [
			'required' => true, 
			'validation' => [
				'float'=> [ 
					'minimum' => -90, 
					'maximum' => 90 
				]
			], 
			'description' => 'a szélességi fok',
			'example' => 47.497913
		],
		'lon' => [
			'required' => true, 
			'validation' => [
				'float' => [ 
					'minimum' => -180, 
					'maximum' => 180 
				]
			], 
			'description' => 'a hosszúsági fok',
			'example' => 19.040236
		],
		'limit' => [
			'validation' => [
				'integer' => [ 
					'minimum' => 1, 
					'maximum' => 100 
				]
			],
			'description' =>  'az egyszerre megmutantandó válaszok száma',
			'default' => 10
		],
		'whenMass' => [
			'validation' => [
				'enum' => ['today', 'tomorrow', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
				['date' => []]]				
			],
			'description' =>  'csak az adott napi misék megjelenítése',
			'default' => false
		],
		'response_length' => [
			'validation' => [
				'enum' => ['minimal', 'medium','full']
			],
			'description' =>  'az egy templomra vonatkozó válaszok részletessége',
			'default' => 'minimal'
		]
	];
		
	
    public function docs() {

        $docs = [];
         
        $docs['description'] = <<<HTML
            <p>Adott koordinátákhoz legközelebbi templomok listáját adja vissza az adott napi misékkel együtt.</p>
        HTML;

        $docs['response'] = <<<HTML
        <ul>
            <li>„error”: <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
            <li>„templomok”: A közeli templomok listája. Mindegyik egy <em>templom</em> adattömb, ahogy az egy-egy templom lekérésénél láttuk.</li>
        </ul>
        HTML;

        return $docs;
    }


    public function run() {
        parent::run();
		
        $this->getInputJson();
		$limit = isset($this->input['limit']) ? $this->input['limit'] : 10;

		// #94: a tényleges hiba forrása — a kliens (Android app) GPS-fix nélkül
		// (0,0)-t küld, és erre minden magyar templom ~5000+ km-re jön vissza
		// (error:0-val, mintha érvényes találat lenne). A 0,0 a Guineai-öbölben
		// van, ott nincs katolikus misézőhely; kezeljük „nincs érvényes helyzet"-
		// ként és adjunk vissza hibát a szemét 5000 km-es lista helyett.
		if ((float) $this->input['lat'] === 0.0 && (float) $this->input['lon'] === 0.0) {
			$this->return['error'] = 1;
			$this->return['text'] = 'Érvénytelen helyzet (0,0) — nem sikerült meghatározni a pozíciót.';
			$this->return['templomok'] = [];
			return;
		}

		$this->return['templomok'] = \Eloquent\Church::select()
				->addSelect(DB::raw("ST_distance_sphere( ST_GeomFromText('POINT ( ".$this->input['lat']." ".$this->input['lon']." )', 4326), ST_GeomFromText(CONCAT('POINT ( ',lat,' ', lon, ')'), 4326) ) as distance"))
                ->where('ok','i')
				// #94 (másodlagos, defenzív): a koordináta nélküli templomok a
				// templomok.lat/lon DEFAULT 0.0/0.0 értékén ülnek. Ezeket a meglévő
				// `lat <> ''` szűrő DECIMAL-coercion révén (''→0) már kizárja, de ez
				// törékeny/rejtett — explicit `NOT (lat=0 AND lon=0)`-val biztosítjuk,
				// hogy egy 0,0 templom akkor se szivárogjon be, ha a szűrő változik.
				->where('lat','<>','')
				->where('lon','<>','')
				->whereRaw('NOT (lat = 0 AND lon = 0)')
                ->orderBy('distance', 'ASC')
				->limit($limit)
                ->get()->map->toAPIArray(
					isset($this->input['response_length']) ? $this->input['response_length'] : (  $this->fields['response_length']['default'] ? $this->fields['response_length']['default'] : false ), 
					isset($this->input["whenMass"]) ? $this->input["whenMass"] : (  $this->fields['whenMass']['default'] ? $this->fields['whenMass']['default'] : false ),
					$this->version);
				
        //$this->return['lat'] = $this->input['lat'];

		// #724: itt egy `nearby.log` fájlba írtuk a hívó KOORDINÁTÁJÁT, User-Agentjét és
		// a pontos időt, egy hónapos megőrzéssel, és a /stat hőtérképen meg is jelenítette.
		// Ez szembement a saját adatvédelmi tájékoztatónkkal, ami azt ígéri, hogy a
		// helyadatot „semmilyen formában nem rögzítjük — sem azonosítva, sem anonim módon".
		//
		// A helyadat + időpont + User-Agent együtt akkor is azonosíthat valakit, ha nevet
		// nem tárolunk mellé: az otthona és a plébániája közti napi mozgásból egy ember
		// kirajzolódik. Ezért nem finomítottuk, hanem megszüntettük.
		//
		// A „hányan használják" kérdésre a \Stats válaszol, helyadat nélkül.
        return;
    }
}
