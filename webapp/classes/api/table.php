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
                
                /*
                 * #496 / #497 / #498: az `orszag`, `megye` és `varos` mezők a publikus
                 * API szerződésének részei — a nevük és a jelentésük NEM változhat.
                 * A forrásuk viszont igen: eddig az `orszagok`/`megye` táblákhoz
                 * kapcsolt join adta őket, mostantól az OSM-határok.
                 *
                 * Korrelált alkérdéssel, nem join-nal: egy templomhoz több határ
                 * tartozik, a join megsokszorozná a sorokat.
                 */
                $orszag = self::boundaryNameSql([2]);
                $megye = self::boundaryNameSql([6, 4]);
                $telepules = self::boundaryNameSql([8]);
                $kerulet = self::boundaryNameSql([9]);
                $tartalek = self::boundaryNameSql([6, 9, 10]);

                $this->table = DB::table("templomok as t")
                        ->select("t.*")
                        ->selectRaw("$orszag AS orszag")
                        ->selectRaw("$megye AS megye")
                        // Ugyanaz a szabály, mint a Church::locationCityName()-ben: a
                        // kerület csak 8-as szintű település mellé fűzhető (Köln
                        // kreisfreie Stadt, tehát nincs 8-asa — ott a 6-os a település).
                        ->selectRaw(
                            "TRIM(CONCAT_WS(' ', COALESCE($telepules, $tartalek),"
                            . " CASE WHEN $telepules IS NOT NULL THEN $kerulet END)) AS varos"
                        )
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
    
  
    /**
     * #496 / #497 / #498: egy administratív határ nevének korrelált alkérdése.
     *
     * @param array<int,int> $szintek admin_level-ek, PREFERENCIA sorrendben
     * @return string beilleszthető SQL-részlet (a külső lekérdezésben `t` a templom)
     */
    private static function boundaryNameSql(array $szintek): string {
        $lista = implode(', ', array_map('intval', $szintek));

        return "(SELECT b.name FROM lookup_boundary_church lbc"
            . " JOIN boundaries b ON b.id = lbc.boundary_id"
            . " WHERE lbc.church_id = t.id AND b.boundary = 'administrative'"
            . " AND b.admin_level IN ($lista)"
            . " ORDER BY FIELD(b.admin_level, $lista) LIMIT 1)";
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

        /*
         * #257: a névhalmaz batch-betöltése. Egyesével kérve 10 000 templomnál
         * ugyanennyi lekérdezés lenne — a `with('attributes')` egyetlen körben hozza,
         * és a `Church::names` accessor a #787 óta eager-load-tudatos.
         */
        $churchNames = [];
        foreach (\Eloquent\Church::with('attributes')->whereIn('id', $churchIds)->get() as $church) {
            $churchNames[$church->id] = [
                'names' => $church->names,
                'alternative_names' => $church->alternative_names,
            ];
        }

        foreach ($this->table as $row) {
            $tmp = array();
            foreach ($this->columns as $column) {
                // data in mysql
                if (isset($row->$column) AND in_array($column, array('id', 'nev', 'ismertnev', 'turistautak', 'orszag', 'megye', 'varos', 'cim', 'plebania', 'pleb_eml', 'egyhazmegye', 'espereskerulet', 'leiras', 'megjegyzes', 'miseaktiv', 'misemegj', 'bucsu', 'frissites', 'lat', 'lon', 'geochecked'))) {
                    $tmp[$column] = $row->$column;
                }
                /*
                 * #257: a `name` és az `alt_name` az OSM-névhalmazból jön.
                 *
                 * borazslo kérése a #803-hoz: „Simán csinálhatjuk, hogy a name-hez az
                 * osmból szedett nevek sorából az elsőt tesszük, az alt_name-hez pedig
                 * az alternative_names első elemét tesszük. Az Api V5-ben #56 pedig
                 * mindkét mező helyére egy lista/jsonlista kerülhetne."
                 *
                 * Mindkettőt megcsinálom: a régebbi verziók az ELSŐ nevet kapják (a mező
                 * marad string, tehát a meglévő fogyasztóknak nem törik el), a v5 pedig a
                 * TELJES listát.
                 *
                 * A `names[0]` a `name:hu` -> `name` sorrendet követi, és csak ezek
                 * hiányában esik vissza a helyi `nev` oszlopra — ahol nincs OSM-adat, ott
                 * tehát pontosan a régi értéket adja.
                 */
                if ($column === 'name' || $column === 'alt_name') {
                    $lista = $column === 'name'
                        ? ($churchNames[$row->id]['names'] ?? [])
                        : ($churchNames[$row->id]['alternative_names'] ?? []);

                    $tmp[$column] = $this->version >= 5 ? array_values($lista) : ($lista[0] ?? '');
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
