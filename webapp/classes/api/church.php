<?php

namespace Api;

use Illuminate\Database\Capsule\Manager as DB;

class Church extends Api {

    public $title = 'Egy misézőhely adatai és miséi röviden';
    public $format = 'json'; //or text
    public $requiredVersion = ['>=',4]; // API v4-től érhető el

    /**
     * #297: egyszerre több azonosító is lekérhető.
     *
     * A KAPP a felhasználó által meglátogatott templomokat gyűjti, és utólag akar
     * hozzájuk adatot kérni. Eredetileg koordinátalistát akartak küldeni az
     * api/nearby-nak, de a jegyben ez lett a megállapodás: az app a logoláskor egy
     * nearby-jal kitalálja a templomot, és utána már csak azonosítókkal dolgozik.
     * Így itt elég a lista fogadása, koordináta-illesztés nem kell.
     *
     * Az `id` ezért már nem kötelező — pontosan az egyiket kell megadni. A régi,
     * egy-azonosítós hívások változatlanul működnek, a válaszuk alakja sem változik.
     */
    const MAX_IDS = 100;

    public $fields = [
        'id' => [
            'validation' => 'integer',
            'description' => 'A misézőhely azonosítója, amely egyedi azonosító a rendszerben. Az „ids” helyett adható meg.',
            'example' => 7
        ],
        'ids' => [
            'validation' => [
                'list' => ['integer' => ['minimum' => 1]]
            ],
            'description' => 'Több misézőhely azonosítója egyszerre, legfeljebb ' . self::MAX_IDS . ' darab. Az „id” helyett adható meg.',
            'example' => [7, 1254]
        ],
        'response_length' => [
            'validation' => [
                'enum' => ['minimal', 'medium','full']
            ],
            'description' =>  'A válasz részletessége',
            'default' => 'medium'
        ]
    ];

    /* Pontosan az egyiket kérjük — enélkül nem tudnánk, milyen alakú választ vár a hívó. */
    public function validateInput() {
        $hasId  = isset($this->input['id']);
        $hasIds = isset($this->input['ids']);

        if(!$hasId && !$hasIds) {
            throw new \Exception("Field 'id' or 'ids' is required in JSON.");
        }
        if($hasId && $hasIds) {
            throw new \Exception("Field 'id' and 'ids' cannot be used together.");
        }
        if($hasIds && count($this->input['ids']) < 1) {
            throw new \Exception("Field 'ids' should not be empty.");
        }
        if($hasIds && count($this->input['ids']) > self::MAX_IDS) {
            throw new \Exception("Field 'ids' should not contain more than ".self::MAX_IDS." items.");
        }
    }

    public function docs() {
        $docs = [];
             
        $docs['description'] = <<<HTML
        <p>Egy templom adatát adja vissza. Csak röviden, a legszükségesebb adatokkal. Az aktuális napi misék rendjét is hozza.</p>
        <p>Több templom is kérhető egyszerre: az <code>id</code> helyett add meg az <code>ids</code> listát (legfeljebb 100 elem). A kettő együtt nem használható.</p>
        HTML;

        $docs['response'] = <<<HTML
            <ul>
            <li>„error”: <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
            <li>„text” (opcionális): „error:1” esetén a hiba szöveges leírása</li>
        </ul>
        <p><strong>„ids” esetén</strong> a válasz két kulcsot tartalmaz:</p>
        <ul>
            <li>„templomok”: a templomok listája, a KÉRT sorrendben</li>
            <li>„hianyzo”: azok az azonosítók, amelyekhez nincs templom — ezek nem buktatják el a kérést, csak kimaradnak a listából</li>
        </ul>
        <p><strong>Egyetlen „id” esetén</strong> a válasz alakja változatlan: közvetlenül a templom adatai jönnek, lista nélkül.</p>
        <p>Majd következik egy <em>templom</em> tömb és adatai.</p>
        <ul>
            <li>„id”</li>
            <li>„nev”</li>
            <li>„ismertnev”</li>
            <li>„varos”</li>
            <li>„lat”</li>
            <li>„lon”</li>
            <li>„links” (<em>string[]</em>): a templom honlapjai (üres tömb ha nincs)</li>
            <li>„tavolsag” (<em>integer</em>): távolság méterben</li>
            <li>„misek”: az adott napi szentmisék listája
                <ul>
                    <li>„idopont” (<em>YYYY-MM-NN HH:ii:ss</em>): a szentmise időpontja</li>
                    <li>„informacio” (<em>string</em>, opcionális): megjegyzés, nyelv, stílus, satöbbi., ha van</li>
                </ul>
            </li>
        </ul>
        HTML;

        return $docs;
    }
    
    public function run() {
        parent::run();
		
        $this->getInputJson();

        $responseLength = isset($this->input['response_length']) ? $this->input['response_length'] : false;

        /*
         * #297: több azonosító esetén LISTÁT adunk vissza, egy azonosítónál a régi,
         * egyetlen objektumot — így a meglévő hívók válasza nem változik.
         */
        if(isset($this->input['ids'])) {
            $ids = array_values(array_unique(array_map('intval', $this->input['ids'])));

            $churches = \Eloquent\Church::whereIn('id', $ids)->get()
                    ->keyBy('id')
                    ->map->toAPIArray($responseLength, false, $this->version);

            /*
             * A kért sorrendben adjuk vissza, és a nem létező azonosítót KIHAGYJUK
             * ahelyett, hogy az egész kérést elbuktatnánk: az app gyűjtött listája
             * tartalmazhat időközben törölt templomot, és attól még a többi adat kell.
             * A hiányzókat külön felsoroljuk, hogy a hívó tudjon róluk.
             */
            $found = [];
            $missing = [];
            foreach($ids as $id) {
                if(isset($churches[$id])) {
                    $found[] = $churches[$id];
                } else {
                    $missing[] = $id;
                }
            }

            $this->return = [
                'templomok' => $found,
                'hianyzo'   => $missing,
            ];

            return;
        }

        $church = \Eloquent\Church::Where('id',$this->input['id'])->get()->map->toAPIArray($responseLength, false, $this->version);


        if(count($church) < 1 ) {
            $this->return = [
                'error' => 1,
                'text' => 'Nem létezik misézőhely ezzel az asonosítóval.'
            ];
            return;
        }

       $this->return = $church[0];

       return;
    }
    
	
}
