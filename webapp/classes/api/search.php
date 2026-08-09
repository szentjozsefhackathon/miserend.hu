<?php

namespace Api;

use Illuminate\Database\Capsule\Manager as DB;

class Search extends Api {

    public $title = 'Misézőhely keresése';
    public $format = 'json'; //or text
    public $requiredVersion = ['>=',4]; // API v4-től érhető el

    public $fields = [
        'q' => [
            // #299: a kötelezőséget a validateInput() dönti el, mert az a `categories`
            // értékétől függ. A hibaüzenet változatlan.
            'validation' => 'string',
            'description' => 'a keresőkifejezés. Kötelező, kivéve ha a `categories` mise nélküli szolgálatra szűkít (pl. csak szentségimádásra) — ott a kategória maga is elég szűk halmaz',
            'example' => 'Szent István'
        ],
        'offset' => [
            'validation' => 'integer', 
            'description' => 'hanyadik választól mutassuk az eredményeket (lapozó használatához)', 
            'default' => 0
        ],
        'limit' => [
            'validation' => ['integer' => [
                'minimum' => 1,
                'maximum' => 100    
            ]], 
            'description' => 'az egyszerre megmutatandó válaszok száma', 
            'default' => 10
        ],
        'response_length' => [
            'validation' => [
                'enum' => ['minimal', 'medium','full']
            ],
            'description' =>  'A válasz részletessége', 
            'default' => 'medium'
        ],
        'when' => [
            'validation' => [
                'enum' => ['today', 'tomorrow', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
                ['date'=>[]]]
            ],
            'description' => 'csak az adott napi misék megjelenítése',
            'default' => false
        ],
        'categories' => [
            'validation' => [
                'list' => ['enum' => ['MASS', 'ADORATION', 'CONFESSION', 'OTHER']]
            ],
            'description' => 'mely eseményfajtákra keresünk: MASS (misefélék), ADORATION (szentségimádás), CONFESSION (gyóntatás), OTHER (egyéb imaalkalom). Megadás nélkül mindegyikre',
            'example' => ['ADORATION'],
            'default' => false
        ]
    ];

    /** A `q` nélkül is értelmes kategóriák: ezek önmagukban elég szűk halmazt adnak. */
    const CATEGORIES_WITHOUT_KEYWORD = ['ADORATION', 'CONFESSION'];

     public function docs() {

        $docs = [];            
	 
        $docs['description'] = <<<HTML
        <p>Templomok között lehet keresni egy (akár összetett) keresőszó megadásával.</p>
        <p>A <code>categories</code> megadásával nem csak misére kereshetsz, hanem
        szentségimádásra (<code>ADORATION</code>), gyóntatásra (<code>CONFESSION</code>) vagy
        egyéb imaalkalomra (<code>OTHER</code>) is — ezek ugyanolyan naptáresemények, mint a
        mise, csak más a fajtájuk. Például <code>{"categories":["ADORATION"],"when":"today"}</code>
        megadja, hol van ma szentségimádás.</p>
        HTML;

        $docs['response'] = <<<HTML
        <ul>
        	<li>„error”: <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
        	<li>„templomok”: A megtalált templomok listája. Mindegyik egy <em>templom</em> adattömb, ahogy az egy-egy templom lekérésénél láttuk.</li>
            <li>„text” (opcionális): „error:1” esetén a hiba szöveges leírása</li>
        </ul>
        HTML;

        return $docs;
    }

    public function run() {
        parent::run();
		
        $this->getInputJson();
		
		$offset = isset($this->input['offset']) ? $this->input['offset'] : 0;
		$limit = isset($this->input['limit']) ? $this->input['limit'] : 10;
		
        $search = new \Search('masses');
        $search->keyword(isset($this->input['q']) ? $this->input['q'] : '');
        if (isset($this->input['when']) && $this->input['when']) {
            $search->day($this->input['when']);
        }

        // #299: a szentségimádás és a gyóntatás ugyanolyan cal_masses-esemény, mint a mise
        // — csak más a címe. Tehát nem külön adatforrás és nem külön válaszszerkezet kell,
        // hanem egy cím-szűrő ugyanazon a mise-indexen. A cím-alakokat a \MassDefinitions
        // adja, ugyanaz, amit a webes keresés (searchresultsmasses) használ.
        $categories = $this->selectedCategories();
        if (!empty($categories)) {
            $titleFilters = (new \MassDefinitions())->titleFiltersByCategories($categories);
            if (!empty($titleFilters)) {
                $search->query['bool']['must'][] = [ 'terms' => ['title.keyword' => $titleFilters] ];
            }
        }

		$results = $search->getResults($offset, $limit, true);
            
        $this->return = [
            'offset' => $offset,
            'limit' => $limit,
            'sum' => $search->total,
            'error' => 1,
            'templomok' => []
        ];
        
        $ids = array_keys($results);
        unset($results);

		if(count($ids) == 0)
        {
            if($search->total != 0) {
                $this->return['error'] = 1;
                $this->return['text'] = 'Elvileg találtunk több templomot, de mégsem találtunk. Hmm.';
                return;
            }

			$this->return['templomok'] = [];
            $this->return['error'] = 0;
			return;
		}

		$this->return['templomok'] = \Eloquent\Church::select()	
			->whereIN('id',$ids)
			->orderByRaw("FIELD(id, " . implode(',', $ids) . ")")
			->get()->map->toAPIArray(
                isset($this->input['response_length']) ? $this->input['response_length'] : (  $this->fields['response_length']['default'] ? $this->fields['response_length']['default'] : false ), 
                isset($this->input["when"]) ? $this->input["when"] : (  $this->fields['when']['default'] ? $this->fields['when']['default'] : false ));
        
        if(count($ids) == count($this->return['templomok'])) {
            $this->return['error'] = 0;
        } else {
            $this->return['error'] = 1;
            $this->return['text'] = 'Belső hiba történt: nem sikerült minden templomot lekérni.';
        }

        return;
    }

    /**
     * #299: a kulcsszó akkor hagyható el, ha a kategóriaszűrő önmagában elég szűk.
     *
     * Misére (és kategória nélkül, ami ugyanaz, csak tágabb) továbbra is kötelező: a
     * mise-index több millió időpont, kulcsszó nélkül a találathalmaz értelmetlen — és
     * a lapozás miatt drága is. Szentségimádásra és gyóntatásra viszont épp az a
     * tipikus kérdés, hogy „hol van MA egyáltalán", tehát ott kulcsszó nélkül is
     * értelmes a válasz.
     */
    public function validateInput() {
        if (isset($this->input['q'])) {
            return;
        }

        $categories = $this->selectedCategories();
        if (empty($categories) || array_diff($categories, self::CATEGORIES_WITHOUT_KEYWORD)) {
            // Ugyanaz a hibaüzenet, mint eddig — a meglévő kliensek nem vesznek észre semmit.
            $this->requiredInput(['q']);
        }
    }

    /**
     * @return string[] a kért kategóriák; üres tömb, ha nincs szűkítés
     */
    private function selectedCategories(): array {
        if (!isset($this->input['categories']) || !is_array($this->input['categories'])) {
            return [];
        }

        return array_values(array_unique(array_filter($this->input['categories'], 'is_string')));
    }

}
