<?php

namespace Api;

use Illuminate\Database\Capsule\Manager as DB;

class Table extends Api {

    public $title = 'Listák / táblázatok';
    public $tableName;
    public $columns;
    public $table; //output
    public $format = 'json'; //or text
    
    public $validColumnsTables = array(
        'templomok' => array(
            'id', 'nev', 'ismertnev', 'turistautak', 'orszag', 'megye', 'varos', 'cim',
            'plebania', 'pleb_eml', 'egyhazmegye',
            'espereskerulet', 'leiras', 'megjegyzes', 'miseaktiv', 'misemegj',
            'frissites', 'lat', 'lon', 'geochecked', 'name', 'alt_name',
            'denomination', 'url')
    );

    public $fields = [
        'table' => [
            'validation' => [
                'enum' => ['templomok']
            ],
            'description' => 'A lekérdezni kívánt tábla neve. Jelenleg csak a „templomok” tábla érhető el.',
            'default' => 'templomok',
            'example' => 'templomok'
        ],
        'columns' => [
            'required' => true,
            'validation' => [
                'list' => ['string' => []]
            ],
            'description' => 'A lekérdezni kívánt oszlopok listája. Részleteket lásd lejjebb.',
            'example' => ['id', 'nev', 'varos', 'lat', 'lon']
        ],
        'format' => [
            'validation' => [
                'enum' => ['json', 'text', 'csv']
            ],
            'description' =>  'A visszatérés formátuma',
            'default' => 'json'
        ],
        'delimiter' => [
            'validation' => 'string',
            'description' =>  '„format:csv” esetén az oszlopokat elválasztó jel',
            'default' => ';'
        ]
    ];
    
    public function docs() {

        $docs = [];
        
        $validColumns = '<p>';
        foreach($this->validColumnsTables as $table => $columns) {
            $validColumns .= "Engedélyezett oszlopok a <code>".$table."</code> tábla esetén: <code>".implode(', ',$columns)."</code><br/>";
        }
        $validColumns .= '</p>';

        $docs['description'] = <<<HTML
        <p>Az adatokat nem csak a teljes sqlite letöltésével lehet megkapni: a megfelelő url-re küldött JSON segítségével a számunkra érdekes oszlopokkal és minden sorral tér vissza az API.</p>
        <p><strong>Vigyázzat!</strong> Az egyes oszlopok / mezők neve, léte és tartalmának formátuma / értéktartománya előzetes figyelmeztetés nélkül változhat. Ezért ez a szolgáltatás rendszeresített / automatizált használata jelenleg nem ajánlott!</p>        
        $validColumns
        HTML;

        $docs['response'] = <<<HTML
        <ul>
            <li>„error”: <strong>0</strong>, ha nincs hiba. <strong>1</strong>, ha van valami hiba.</li>
            <li>„templomok”: a visszakapott templomok listája a kívánt mezőkkel</li>
            <li>„text” (opcionális): „error:1” esetén a hiba szöveges leírása</li>
        </ul>
        HTML;

        return $docs;
    }

    public function validateInput() {        
        foreach ($this->input['columns'] as $column) {
            if (!in_array($column, $this->validColumnsTables[$this->tableName])) {
                throw new \Exception("Column '$column' is invalid in '$this->tableName'.");
            }
        }        
    }

    public function run() {
        parent::run();

        $this->tableName = isset($this->input['table']) ? $this->input['table'] : $this->fields['table']['default'];
        if (!array_key_exists($this->tableName, $this->validColumnsTables)) {
            throw new \Exception("Table '$this->tableName' is invalid.");
        }
        $this->getInputJson();

        
        $this->delimiter = isset($this->input['delimiter']) ? $this->input['delimiter'] : $this->fields['delimiter']['default'];
        
        if (isset($this->input['format'])) {
            $this->format = $this->input['format'];
        }
        $this->columns = $this->input['columns'];


        switch ($this->tableName) {
            case 'templomok':
                
                $this->table = DB::table("templomok as t")
                        ->SELECT("t.*","orszagok.nev as orszag","megye.megyenev as megye")
                        ->leftJoin('orszagok', 'orszagok.id','=','t.orszag')
                        ->leftJoin('megye', 'megye.id',"=","megye")
                        ->where('t.ok',"=","i")
                        ->limit(10000)
                        ->get();
                
                $this->mapTemplomok();
                break;

            default:
                throw new \Exception("Table '$this->tableName' is accepted, but we cannot process.");
                break;
        }

        if ($this->format == 'text')
            $this->format = 'csv';

        $this->return[$this->tableName] = $this->table;

        return;
    }
    
  
    function mapTemplomok() {
        $output = array();

        // #542: a denomination az OSM-ből (attributes tábla 'denomination' kulcs,
        // fromOSM=1) jön, nem a törékeny egyházmegye-id (17,18,34) heurisztikából.
        // Egyszeri batch-load, hogy ne legyen N+1 az export során.
        $churchIds = [];
        foreach ($this->table as $r) {
            if (isset($r->id)) { $churchIds[] = $r->id; }
        }
        $osmDenominations = empty($churchIds) ? [] :
            \Eloquent\Attribute::whereIn('church_id', $churchIds)
                ->where('key', 'denomination')
                ->pluck('value', 'church_id')->toArray();

        foreach ($this->table as $row) {
            $tmp = array();
            foreach ($this->columns as $column) {
                // data in mysql
                if (isset($row->$column) AND in_array($column, array('id', 'nev', 'ismertnev', 'turistautak', 'orszag', 'megye', 'varos', 'cim', 'plebania', 'pleb_eml', 'egyhazmegye', 'espereskerulet', 'leiras', 'megjegyzes', 'miseaktiv', 'misemegj', 'bucsu', 'frissites', 'lat', 'lon', 'geochecked'))) {
                    $tmp[$column] = $row->$column;
                }
                // simple data mapping
                /*
                 * #257: a tábla-export a helyi oszlopokat adja `name` / `alt_name` néven.
                 * Ez PUBLIKUS szerződés: a mezők jelentésének megváltoztatása a meglévő
                 * fogyasztóknál némán más adatot eredményezne. Az OSM-névhalmaz kiadása
                 * ezért új mezőt kíván (mint a `Church::toAPIArray()`-ben a `names` és az
                 * `alternative_names`), és a következő API-verzióhoz tartozik.
                 */
                $mapping = array('name' => 'nev', 'alt_name' => 'ismertnev');
                if (array_key_exists($column, $mapping)) {
                    $tmp[$column] = $row->{$mapping[$column]};
                }
                //extra mapping
                switch ($column) {
                    case 'denomination':
                        //http://wiki.openstreetmap.org/wiki/Key:denomination#Christian_denominations
                        // #542: elsődlegesen az OSM-denomination (attributes); ÁTMENETI
                        // fallback az egyházmegye-heurisztika, amíg az OSM-sync nem fed le mindent.
                        $cid = $row->id ?? null;
                        if ($cid !== null && !empty($osmDenominations[$cid])) {
                            $tmp[$column] = $osmDenominations[$cid];
                        } elseif (in_array($row->egyhazmegye, array(17, 18, 34))) {
                            $tmp[$column] = 'greek_catholic';
                        } else {
                            $tmp[$column] = 'roman_catholic';
                        }
                        break;

                    case 'url':
                        $tmp[$column] = DOMAIN . '/templom/' . $row->id;
                        break;

                    default:
                        # code...
                        break;
                }
            }
            $output[] = $tmp;
        }
        $this->table = $output;
    }

}
