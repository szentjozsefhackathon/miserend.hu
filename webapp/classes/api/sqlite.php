<?php

namespace Api;

use Illuminate\Database\Capsule\Manager as DB;

class Sqlite extends Api {
    public $title = 'Adatbázis';
    public $format = false;
    public $sqliteFileName;
    public $folder = 'fajlok/sqlite/';
    public $sqlite;
    public $search;
    public $massId = 0; // global-ban kell a mise azonosító, hogy beilleszthessük a táblázatban
    public $sqliteFilePath;


    public function docs() {

        $docs = [];
        
        $docs['input'] = "Semmilyen adatot nem kell küldeni. Sőt meg sem kell hívni külön az API-t csak elkérni a fájlokat az alábbi URL-en";

        $docs['description'] = <<<HTML
        <p>SQLite formátumban a templomok, misék és képek. Naponta frissül. Nem szükséges külön meghívni.</p>
        <p><strong>Átirányít a konkrét fájlhoz:</strong> <code>http://miserend.hu/fajlok/sqlite/miserend_v3.sqlite3</code></p>
        <p><em>(Léteznek még többé-kevésbé működő más url-ek is.)</em></p>
        <p><strong>Vigyázat!</strong> A 2025 második felében kezdődött felújítás a <em>misék</em> adattáblát biztosan meg fogja változtatni!</p>
        <p><strong>Vigyázat!</strong> A 2026-ban beért változásokkal a v3 nem támogatott itt többé. A v4 még igen.</p>
        HTML;

        $docs['response'] = <<<HTML
        <h4>misék: „misek” — v5</h4>
        <p>
            A <strong>v5</strong> más szerkezetet ad, mint a v4: nem minden egyes
            előfordulás külön sor, hanem <strong>minden mise a generált időszakával
            sokszorozva</strong>, konkrét dátumtól dátumig. Az ismétlődés
            <code>rrule</code>-ként, a kivételek konkrét dátumokként mennek — így a
            tábla rövid marad, de rrule-lal könnyen felszorozható és kereshető.
            (Egy templomra két évre mérve 43 sor 1229 előfordulás helyett.)
        </p>
        <ul>
            <li>„mid” (<em>integer not null</em>): a SOR azonosítója</li>
            <li>„tid” (<em>integer</em>): a templom azonosítója (mint az url-ben)</li>
            <li>„mise_id” (<em>integer</em>): a MISE azonosítója. Egy mise több
                időszakkal is szerepel (téli/nyári/adventi), ezen az azonosítón
                ismerhető fel, hogy ugyanarról a miséről van szó.</li>
            <li>„idoszak” (<em>varchar(255)</em>): az időszak neve, pl. <em>Advent</em></li>
            <li>„datumtol”, „datumig” (<em>date</em>): az időszak KONKRÉT első és utolsó
                napja, <strong>ÉÉÉÉ-HH-NN</strong> alakban (a v4-ben ez hónap+nap volt, év nélkül)</li>
            <li>„ido” (<em>time</em>): a mise kezdete, pl. <em>07:00:00</em></li>
            <li>„hossz” (<em>integer</em>): a mise hossza percben</li>
            <li>„rrule” (<em>text</em>): az ismétlődés RFC 5545 szerint, pl.
                <em>FREQ=WEEKLY;UNTIL=20261224T225959Z;BYDAY=MO,WE</em></li>
            <li>„exdate” (<em>text</em>): a kimaradó alkalmak konkrét dátumai, vesszővel
                elválasztva, pl. <em>2026-12-01,2026-12-08</em></li>
            <li>„nyelv” (<em>varchar(32)</em>): nyelvek vesszővel elválasztva</li>
            <li>„milyen” (<em>varchar(64)</em>): egyéb tulajdonságok vesszővel elválasztva</li>
            <li>„megjegyzes” (<em>varchar(255)</em>)</li>
        </ul>

        <h4>misék: „misek” — v4 és korábbi</h4>
        <ul>
            <li>„mid” (<em>integer not null</em>): mise azonosító</li>
            <li>„tid” (<em>integer</em>): a templom azonosítója (mint az url-ben)</li>
            <li><del>„telnyar” (<em>varchar(1)</em>) (≤v3): <strong>t</strong> (téli miserend) / <strong>ny</strong> (nyári miserend)</del></li>
            <li>„periodus” (<em>varchar(4)</em>) (v4+): a mise periódusa/ismétlődése, <strong>NULL</strong> ha mindig van, részletekért lásd: <a href="[[miserend-tulajdonságok]]">miserend-tulajdonságok</a></li>
            <li>„idoszak” (<em>varchar(255)</em>) (v4+): az időszak megnevezése szöveggel kiírva, az azonos nevű időszakok egy kupacba tartoznak. pl.: <em>téli miserend</em> vagy <em>ádventi időszak</em></li>
            <li>„suly” (<em>int</em>) (v4+): az „időszak” súlya. Ha két időszak (részben) átfedi egymást, akkor a nehezebb súlyú időszak miséi érvényesek csak.</li>
            <li>„datumtol” (<em>int</em>) (v4+): az „időszak” első napjának dátuma <strong>(H)HNN</strong> formátumban. (Rendszeresen frissítendő, mert a legközelebbi határt jelöli, ami évenként változhat.)</li>
            <li>„datumig” (<em>int</em>) (v4+): az „időszak” utolsó napjának dátuma <strong>(H)HNN</strong> formátumban. (Rendszeresen frissítendő, mert a legközelebbi határt jelöli, ami évenként változhat.)</li>
            <li>„nap” (<em>integer</em>): <strong>1-7</strong> (hétfő - vasárnap) vagy <strong>0</strong> (bármilyen nap). (A nulla értékre példa a karácsonyi szentmise. Ilyenkor nem számít a nap milyensége, csak a dátum: a „datumtol” és „datumig”, ami ekkor azonos.)</li>
            <li>„ido” (<em>time</em>): pl.: <em>08:30:00</em></li>
            <li>„nyelv” (<em>varchar(3)</em>): a nyelv rövidítése és periódusa, több érték esetén esetén vesszőkkel elválasztva. lásd még: <a href="[[miserend-tulajdonságok]]">miserend-tulajdonságok</a></li>
            <li>„milyen” (<em>varchar(10)</em>): minden nem nyelvi tulajdonság és periódusa, több érték esetén esetén vesszőkkel elválasztva. (A lehetséges értékek teljes listája API verziónként eltér.) lásd még: <a href="[[miserend-tulajdonságok]]">miserend-tulajdonságok</a></li>
            <li>„megjegyzés” (<em>varchar(255)</em>) (v3+): szöveges megjegyzés a misével kapcsolatban, pl. olyan tulajdonságok/periódusok, amik a „milyen” mezőben nem megadhatóak</li>
        </ul>

        <h4>templomok: „templomok”</h4>
        <ul>
            <li>„tid” (<em>integer not null</em>): a templom azonosítója (mint az url-ben)</li>
            <li>„nev” (<em>varchar(200)</em>): a templom teljes és hivatalos neve</li>
            <li>„ismertnev” (<em>varchar(200)</em>): alternatív, közhasználatú név</li>
            <li>„gorog” (<em>integer null</em>) (v3+): <strong>1</strong>/<strong>0</strong>/<strong>NULL</strong> 1, ha görögkatolikus misézőhely</li>
            <li>„orszag” (<em>varchar(30)</em>): az ország neve kiírva (bár az eredeti adatbázis kódolva tárolja)</li>
            <li>„megye” (<em>varchar(80)</em>): a megye egyszerű neve kiírva</li>
            <li>„varos” (<em>varchar(80)</em>): a város neve kiírva. külföld esetén zároljelben másik nyelven pl. <em>Kolozsvár (Cluja-Napoca)</em></li>
            <li>„cim” (<em>varchar(255)</em>): a templom (és nem a plébánia) hivatalos posta címe (ország és város nélkül)</li>
            <li>„geocim” (<em>varchar(255)</em>) (≤v4): a koordináták alapján visszafejtet lehetséges posta cím (leginkább akkor használjuk, ha a „cim” üres)</li>
            <li>„megkozelites” (<em>varchar(255)</em>): mindig üres!</li>
            <li>„lng” (<em>float</em>): a koordináta hosszúsági foka pl. <em>24.9018</em></li>
            <li>„lat” (<em>float</em>): a koordináta szélességi foka pl. <em>46.5643</em></li>
            <li><del>„nyariido” (<em>varchar(10)</em>) (≤v3): a templomban a nyári idő kezdete az aktuális évben (!), <strong>ÉÉÉÉ-HH-NN</strong></del></li>
            <li><del>„teliido” (<em>varchar(10)</em>) (≤v3): a templomban a téli idő kezdete az aktuális évben (!), <strong>ÉÉÉÉ-HH-NN</strong></del></li>
            <li>„kep” (<em>varchar(255)</em>): a templomhoz elérhető első/fő kép teljes url-je, pl.: <em>http://miserend.hu/kepek/templomok/3761/templom2.jpg</em></li>
        </ul>

        <h4>képek: „kep” (v2+)</h4>
        <ul>
            <li>„kid” (<em>integer not null</em>): a kép egyedi azonosítója</li>
            <li>„tid” (<em>integer</em>): a templom azonosítója (mint az url-ben)</li>
            <li>„kep” (<em>varchar(255)</em>): a kép teljes url-je</li>
        </ul>
        HTML;

        return $docs;
    }


    public function run() {
        parent::run();

        $this->setFilePath();

        $this->search = new \Search("masses");

        try {
            if (!$this->generateSqlite()) {
                throw new \Exception("Could not make the requested sqlite3 file.");
            }
            return true;
        } finally {
            if ($this->search->pitId !== false) {
                $this->search->closePit();
            }
        }
    }

    function setFileName() {
        $this->sqliteFileName = 'miserend_v' . $this->version . '.sqlite3';
    }

    /**
     * #858: a fájlnév MINDIG a mostani verzióból számolódik.
     *
     * Itt korábban `if (!isset($this->sqliteFileName))` állt — vagyis az első beállítás
     * után a név BEFAGYOTT. Ez addig ártalmatlan volt, amíg egy példány egy verziót
     * épített, de a #822 óta a `cron()` UGYANAZON a példányon megy végig a
     * `GENERALT_VERZIOK`-on:
     *
     *     foreach (self::GENERALT_VERZIOK as $verzio) {   // [4, 5]
     *         $this->version = $verzio;
     *         $this->run();                               // -> setFilePath()
     *     }
     *
     * Az első kör beállította a `miserend_v4.sqlite3`-at, a második kör pedig a `version`
     * átállítása ELLENÉRE ugyanoda írt. Következmény, mérve:
     *
     *     version=4  ->  miserend_v4.sqlite3
     *     version=5  ->  miserend_v4.sqlite3     <-- ide íródik a v5 tartalom
     *
     * Ez ROSSZABB, mint egy hiányzó fájl: a v5 sqlite soha nem készült el (a /health-en
     * és élesben is 404), a `miserend_v4.sqlite3` viszont a V5 SÉMÁJÁT kapta — a v4-es
     * kliensek tehát olyan `misek` táblát töltöttek le, ami nem az ő alakjuk.
     *
     * A `$sqliteFilePath` is újraszámolódik, mert a `run()` és a `generateSqlite()` is
     * `isset()`-tel őrzi — ugyanaz a befagyás történne vele.
     */
    function setFilePath() {
        $this->setFileName();
        $this->sqliteFilePath = PATH . $this->folder . $this->sqliteFileName;
    }

    function connectToSqlite($name, $file = false) {
        try {
            $this->sqlite = DB::connection($name);
        } catch (\InvalidArgumentException $e) {
            if ($file == false) {
                throw new \Exception("Sqlite connection '$name' does not exists and there is no file for it to open.");
            }
            if (!file_exists($file)) {
                $this->createEmptySqliteFile($file);
            }
            global $capsule;
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => $file,
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci'
                    ], $name);
            $this->sqlite = DB::connection($name);
        }
    }


    function generateSqlite() {
        echo "Sqlite is beginning right now...";
        if(!isset($this->sqliteFilePath)) {
            $this->setFilePath();
        }

        $directory = dirname($this->sqliteFilePath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException("SQLite directory is not writable: " . $directory);
        }

        $temporaryFile = tempnam($directory, '.' . $this->sqliteFileName . '.');
        if ($temporaryFile === false) {
            throw new \RuntimeException("Could not create temporary SQLite file in " . $directory);
        }

        $connectionName = 'sqlite_v' . $this->version . '_' . bin2hex(random_bytes(6));
        try {
            $this->connectToSqlite($connectionName, $temporaryFile);
            $this->sqlite->beginTransaction();
            $this->dropAllTables();
            echo "<br/>\nCreate Tables ...";
            $this->createTables();
            $this->insertData();
            echo "<br/>\n";
            $this->sqlite->commit();
            DB::disconnect($connectionName);
            $this->sqlite = null;

            if (!rename($temporaryFile, $this->sqliteFilePath)) {
                throw new \RuntimeException("Could not publish generated SQLite file.");
            }
            return true;
        } catch (\Throwable $e) {
            if ($this->sqlite !== null && $this->sqlite->transactionLevel() > 0) {
                $this->sqlite->rollBack();
            }
            DB::disconnect($connectionName);
            $this->sqlite = null;
            throw $e;
        } finally {
            if (file_exists($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    function dropAllTables() {
        $tables = $this->sqlite->table('sqlite_master')->select('name')->get();
        foreach ($tables as $table) {
            $this->sqlite->statement("DROP TABLE IF EXISTS " . $table->name);
        }
    }

    function createTables() {
        $this->createTableTemplomok();
        $this->createTableMisek();
        if ($this->version > 1) {
            $this->createTableKepek();
        }
    }

    function insertData() {
        ini_set('memory_limit', '800M');
        DB::disableQueryLog();
        $this->sqlite->disableQueryLog();

        echo "<br/>\ninsertDataTemplomok ... <br/>\n";
        $this->insertDataTemplomok();

        echo "<br/>\ninsertDataMisek ... <br/>\n";                
        if ($this->version >= 5) {
            // #800: a v5 nem a keresőindex felszorzott példányaiból dolgozik, hanem a
            // generált periódusokból — templomonként, mert a generálás így működik.
            $this->insertDataMisekV5();
        } else {
            $chunkSize = 3000;
            $this->insertDataMisek($chunkSize);
            while( $this->search->countHits > 0 ) {
                $this->insertDataMisek($chunkSize);
            }
        }
        if ($this->version > 1) {
            $this->insertDataKepek();
        }
        $this->sqlite->enableQueryLog();
        DB::enableQueryLog();
    }

    function createTableTemplomok() {
        $createtabletemplomok = "CREATE TABLE IF NOT EXISTS [templomok] (
            [tid] INTEGER  NOT NULL PRIMARY KEY,
            [nev] VARCHAR(200)  NULL,
            [ismertnev] vaRCHAR(200)  NULL,";

        if ($this->version > 2)
            $createtabletemplomok .= "
            [gorog] INTEGER NULL,";

        $createtabletemplomok .= "
            [orszag] vARCHAR(30)  NULL,
            [megye] vARCHAR(80)  NULL,
            [varos] vaRCHAR(80)  NULL,
            [cim] vARCHAR(255)  NULL,
            [geocim] vARCHAR(255)  NULL,
            [megkozelites] vARCHAR(255)  NULL,
            [lng] fLOAT  NULL,
            [lat] flOAT  NULL,";

        if ($this->version < 4)
            $createtabletemplomok .= "
            [nyariido] vARCHAR(10)  NULL,
            [teliido]vARCHAR(10)  NULL,";

        $createtabletemplomok .= "
            [kep] vARCHAR(255)  NULL        
        )";

        $this->sqlite->statement($createtabletemplomok);
    }

    function createTableMisek() {
        /*
         * #800: a v5 miserendje MÁS SZERKEZET, nem a v4 bővítése.
         *
         * A v4-ig minden egyes előfordulás külön sor volt (datumtol = datumig = egy
         * nap, "Ezen a napon: ..."), fél évre előre felszorozva. borazslo azt kérte,
         * hogy ehelyett az iCal-hoz hasonló alak menjen: minden mise a GENERÁLT
         * PERIÓDUSSAL sokszorozva, konkrét dátumtól dátumig, mellette az ismétlődés
         * rrule-ként és a kivételek konkrét dátumokként. Így a tábla rövid marad,
         * viszont rrule-lal könnyen felszorozható és kereshető.
         */
        if ($this->version >= 5) {
            $this->sqlite->statement("CREATE TABLE IF NOT EXISTS [misek] (
                [mid] INTEGER  PRIMARY KEY NOT NULL,
                [tid] INTEGER  NULL,
                [mise_id] INTEGER  NULL,
                [idoszak] VARCHAR(255)  NULL,
                [datumtol] DATE  NULL,
                [datumig] DATE  NULL,
                [ido] TIME  NULL,
                [hossz] INTEGER  NULL,
                [rrule] TEXT  NULL,
                [exdate] TEXT  NULL,
                [nyelv] VARCHAR(32)  NULL,
                [milyen] VARCHAR(64)  NULL,
                [megjegyzes] VARCHAR(255)  NULL
            )");

            return;
        }

        $createtablemisek = "CREATE TABLE IF NOT EXISTS [misek] (
            [mid] INTEGER  PRIMARY KEY NOT NULL,
            [tid] iNTEGER  NULL,";

        if ($this->version < 4)
            $createtablemisek .= "      [telnyar] VARCHAR(1)  NULL,";

        if ($this->version > 3) {
            $createtablemisek .= "      
                [periodus] VARCHAR(4)  NULL,
                [idoszak] VARCHAR(255)  NULL,
                [suly] INT NULL,
                [datumtol] INT  NULL,
                [datumig] INT  NULL,";
        }

        $createtablemisek .= "
            [nap] inTEGER  NULL,
            [ido] TIME  NULL,
            [nyelv] VARCHAR(3)  NULL,
            [milyen] VARCHAR(10)  NULL";

        if ($this->version > 2)
            $createtablemisek .= "
            , [megjegyzes] VARCHAR(255) NULL";
        $createtablemisek .= "  )";

        $this->sqlite->statement($createtablemisek);
    }

    function createTableKepek() {
        $this->sqlite->statement("CREATE TABLE IF NOT EXISTS [kepek] (
            [kid] INTEGER  PRIMARY KEY NOT NULL,
            [tid] INTEGER  NULL,
            [kep] vARCHAR(255)  NULL
        )");
    }

    function insertDataTemplomok() {
        set_time_limit(120);
        $churches = \Eloquent\Church::where('ok', 'i')->orderBy('id')->get();
        if (!$churches) {
            throw new Exception("There are no valid churches.");
        }
        $sum = count($churches);
        $c = 1;
        foreach ($churches as $church) {
            $line = "v" . $this->version . " " . (int) ( microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) . "s : " . $c++ . "/" . $sum . " -- " . $church->id . " " . $church->nev;
            echo "\r" . str_pad($line, 120)."<br/>";
            $church->location;

            $insert = [
                'tid' => $church->id,
                'nev' => $church->names[0],
                'ismertnev' => isset($church->alternative_names[0]) ? $church->alternative_names[0] : "",
            ];

            //Location
	    //print_r($church->location);
            $insert['orszag'] = $church->location->country['name'];
            if (isset($church->location->county)) {
                $insert['megye'] = $church->location->county['name'];
            } else {
                $insert['megye'] = "";
            }
            $insert['varos'] = $church->location->city['name'];
            $insert['cim'] = $church->cim;
            $insert['geocim'] = $church->geoaddress;
            $insert['lng'] = $church->location->lon;
            $insert['lat'] = $church->location->lat;
            $insert['megkozelites'] = false;
			

            if ($this->version > 2) {
                if (in_array($church->egyhazmegye, array(18, 17))) { //Görög egyházmegyék kódja
                    $insert['gorog'] = 1;
                } else
                    $insert['gorog'] = 0;
            }

            if ($this->version < 4) {
                $insert['nyariido'] = date('Y-') . date('m-d', strtotime($church->nyariido));
                $insert['teliido'] = date('Y-') . date('m-d', strtotime($church->teliido));
            }

            if (isset($church->photos[0])) {
                $insert['kep'] = DOMAIN . "/kepek/templomok/" . $church->id . "/" . $church->photos[0]->filename;
            } else {
                $insert['kep'] = '';
            }
            $inserts[] = $insert;
        }
        $this->insertDataSql('templomok', $inserts);
    }

    function insertDataMisek($limit = 30) {
        if(!$this->search->pitId) $this->search->openPit("5m");

        set_time_limit(60);
        $inserts = [];
        //Mivel a serach a pit miatt sokáig él, ezért a query-t mindig letakarítjuk, mert különben zavart okoz
        $this->search->query = ["bool" => ["must" => [], "must_not" => []]];
        $this->search->filters = [];

        $this->search->dateRange(date('Y-m-d'), date('Y-m-d', strtotime('+6 months')));

        $misek = $this->search->getResults(0,$limit); // Az $offset mindig 0, mert PIT esetén nem kell!
        
        $attributeMapping = [
            'FAMILY' => 'csal',
            'STUDENT' => 'd',
            'UNIVERSITY_YOUTH' => 'ifi',
            'GUITAR' => 'g',
            'SILENT' => 'cs'
        ]; // Van ORGAN is, de azt nem tudja kezelni a régi miserend alkalmazás azt hiszem
        
        foreach($misek as $mass) {
            $this->massId++;
            $insert = [
                'mid' => $this->massId, // Nem használhatjuk a $mass->mass_id -t mert az nem a konkrét példány azonosítója, hanem az anya miséé
                'tid' => $mass->church_id,
                'nap' => 0,
                'ido' => isset($mass->start_minutes) ? sprintf('%02d:%02d:00', (int) floor($mass->start_minutes / 60), (int) ($mass->start_minutes % 60)) : null,
                'nyelv' => $mass->lang,
                'milyen' => implode(',', array_filter(array_map(function($t) use ($attributeMapping) {
                    return isset($attributeMapping[$t]) ? $attributeMapping[$t] : null;
                }, is_array($mass->types) ? $mass->types : array_filter(array_map('trim', explode(',', (string)$mass->types)))))),
            ];

            

             if ($this->version >= 4) {
                $insert['datumtol'] =  date('nd', strtotime($mass->start_date));
                $insert['datumig'] =  date('nd', strtotime($mass->start_date));
                $insert['periodus'] = 0;
                $insert['idoszak'] = "Ezen a napon: ".date('Y-m-d', strtotime($mass->start_date));
                $insert['suly'] = 1;
            }

            if ($this->version >= 3) {
                $insert['megjegyzes'] = $mass->comment;
            }

            if ($this->version < 4) {
                /*  A v3-ig csak tél/nyár megkülönböztetés volt. Egyedi nap sem létezett
                * Így itt nem tudunk mit csinálni, a backward compatibilitás egyszerűen
                * nem lehetséges
                */

            }

            if (isset($insert)) {
                $inserts[] = $insert;
            }
                                    
        }

        if(count($inserts) == 0) {
            echo "Itt a vége, nem volt már találat<br>";            
        } else {
            $this->insertDataSql('misek', $inserts);
        }

        $line = "v" . $this->version . " " . (int) ( microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) . "s : " . $this->massId . "/" . $this->search->total . " -- ";
            echo "\r" . str_pad($line, 120)."<br>";             
    }

    /**
     * #800: a v5 miserendje — minden mise a generált periódusával sokszorozva.
     *
     * A forrás ugyanaz, mint az iCal-exporté (CalMass::generateMassPeriodInstancesForYears),
     * mert borazslo kifejezetten azt a formátumot kérte referenciának. Így a két
     * kimenet nem tud szétcsúszni.
     *
     * Templomonként megyünk: a periódus-generálás egy templom összes miséjét együtt
     * nézi (az időszakok egymáshoz képest súlyozódnak), tehát nem lehet tetszőleges
     * mise-halmazra darabolni.
     *
     * @param int $chunkSize hány templomot dolgozzunk fel egy körben
     */
    function insertDataMisekV5(int $chunkSize = 200) {
        $evek = [(int) date('Y'), (int) date('Y') + 1];

        // Az időszak NEVE nem szerepel a generált szerkezetben, csak az azonosítója.
        $idoszakNevek = DB::table('cal_generated_periods')->pluck('name', 'id')->toArray();

        $churchIds = DB::table('cal_masses')->distinct()->orderBy('church_id')->pluck('church_id')->all();
        $osszes = count($churchIds);
        $kesz = 0;

        foreach (array_chunk($churchIds, $chunkSize) as $csoport) {
            $inserts = [];

            foreach ($csoport as $churchId) {
                $masses = \Eloquent\CalMass::where('church_id', $churchId)->get()->all();
                if ($masses === []) {
                    continue;
                }

                foreach (\Eloquent\CalMass::generateMassPeriodInstancesForYears($masses, [], $evek) as $periodus) {
                    $this->massId++;
                    $inserts[] = $this->massPeriodToRow($periodus, $idoszakNevek);
                }
            }

            if ($inserts !== []) {
                $this->insertDataSql('misek', $inserts);
            }

            $kesz += count($csoport);
            $line = "v" . $this->version . " " . (int) (microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) . "s : "
                . $kesz . "/" . $osszes . " templom, " . $this->massId . " sor";
            echo "\r" . str_pad($line, 120) . "<br>";
        }
    }

    /**
     * Egy generált periódus-példány sqlite-sorrá alakítása.
     *
     * @param array<string,mixed> $periodus a generateMassPeriodInstancesForYears egy eleme
     * @param array<int,string> $idoszakNevek generated_period_id => név
     * @return array<string,mixed>
     */
    private function massPeriodToRow(array $periodus, array $idoszakNevek): array {
        $rrule = is_array($periodus['rrule'] ?? null) ? $periodus['rrule'] : [];

        // Az időpont a szabály kezdetéből jön: a dtstart hordozza az órát és a percet.
        $ido = null;
        if (!empty($rrule['dtstart'])) {
            try {
                $ido = (new \DateTime((string) $rrule['dtstart']))->format('H:i:s');
            } catch (\Throwable $e) {
                $ido = null;
            }
        }

        return [
            'mid' => $this->massId,
            'tid' => (int) ($periodus['church_id'] ?? 0),
            // A mise SAJÁT azonosítója: egy mise több periódussal is szerepel, és a
            // fogyasztónak tudnia kell, hogy ugyanarról a miséről van szó.
            'mise_id' => (int) ($periodus['mass_id'] ?? 0),
            'idoszak' => $idoszakNevek[$periodus['generated_period_id'] ?? null] ?? '',
            'datumtol' => $periodus['start_date'] ?? null,
            'datumig' => $periodus['end_date'] ?? null,
            'ido' => $ido,
            'hossz' => (int) ($periodus['duration_minutes'] ?? 0),
            'rrule' => \SimpleRRule::toRfcString($rrule),
            'exdate' => implode(',', \SimpleRRule::exdates($rrule)),
            'nyelv' => implode(',', (array) ($periodus['lang'] ?? [])),
            'milyen' => implode(',', (array) ($periodus['types'] ?? [])),
            'megjegyzes' => $periodus['comment'] ?? null,
        ];
    }

    function insertDataKepek() {
        $photos = \Eloquent\Photo::orderBy('church_id')->get();
        if (!$photos) {
            throw new Exception("There are no valid churches.");
        }

        foreach ($photos as $photo) {
            $insert = [
                'kid' => $photo->id,
                'tid' => $photo->church_id,
                'kep' => DOMAIN . $photo->url
            ];
            $inserts[] = $insert;
        }
        $this->insertDataSql('kepek', $inserts);
    }

    function insertDataSql($table, $inserts) {
        $limit = (int) ( 999 / count($inserts[0]) ); //SQLite variable limit is 999       
        $churchChunks = array_chunk($inserts, $limit);
        foreach ($churchChunks as $chunk) {
            $this->sqlite->table($table)->insert($chunk);
        }
    }

    function getEmptySqliteFile() {
        $coded = "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MCkgIE5VTEwsCiAgICAgICAgICAgIFtpc21lcnRuZXZdIHZhUkNIQVIoMjAwKSAgTlVMTCwKICAgICAgICAgICAgW29yc3phZ10gdkFSQ0hBUigzMCkgIE5VTEwsCiAgICAgICAgICAgIFttZWd5ZV0gdkFSQ0hBUig4MCkgIE5VTEwsCiAgICAgICAgICAgIFt2YXJvc10gdmFSQ0hBUig4MCkgIE5VTEwsCiAgICAgICAgICAgIFtjaW1dIHZBUkNIQVIoMjU1KSAgTlVMTCwKICAgICAgICAgICAgW2dlb2NpbV0gdkFSQ0hBUigyNTUpICBOVUxMLAogICAgICAgICAgICBbbWVna296ZWxpdGVzXSB2QVJDSEFSKDI1NSkgIE5VTEwsCiAgICAgICAgICAgIFtsbmddIGZMT0FUICBOVUxMLAogICAgICAgICAgICBbbGF0XSBmbE9BVCAgTlVMTCwKICAgICAgICAgICAgW255YXJpaWRvXSB2QVJDSEFSKDEwKSAgTlVMTCwKICAgICAgICAgICAgW3RlbGlpZG9ddkFSQ0hBUigxMCkgIE5VTEwsCiAgICAgICAgICAgIFtrZXBdIHZBUkNIQVIoMjU1KSAgTlVMTCAgICAgICAgCiAgICAgICAgKQ==";
        return base64_decode($coded);
    }

    function createEmptySqliteFile($file) {
        $emptySqliteFile = $this->getEmptySqliteFile();
        $fp = fopen($file, 'w');
        fwrite($fp, $emptySqliteFile);
        fclose($fp);
        return true;
    }

    function checkSqliteFile() {
		$tables = $return = false;
	
        if(!isset($this->sqliteFilePath)) {
            $this->setFilePath();
        }
        if(!file_exists($this->sqliteFilePath)) {
            return false;
        }
        $this->connectToSqlite('sqlite_v' . $this->version, $this->sqliteFilePath);
        try{
			$tables = $this->sqlite->table('sqlite_master')->select('name')->get();
			
			foreach($tables as $table) {
				$return[$table->name] = $this->sqlite->table($table->name)->count();				
			}

        } catch (\Illuminate\Database\QueryException $e) {            
            global $config;
            $mailHeader = 'MIME-Version: 1.0' . "\r\n" . 'Content-type: text/html; charset=UTF-8' . "\r\n";
            $mailHeader .= 'From: ' . $config['mail']['sender'] . "\r\n";
            $mailTo = $config['mail']['debugger'];
            $mailSubject = "[miserend.hu] API error";
            $mailContent = $this->sqliteFilePath." is not a valid sqlite file.";
            mail($mailTo, $mailSubject, $mailContent, $mailHeader);

            return false;
        }
        return $return;
    }

    /**
     * #822: mely SQLite-verziókat építjük újra.
     *
     * A v1–v3 BEFAGYASZTOTT: a fájlok kimennek a régi klienseknek, de tartalmuk nem
     * változik, tehát újragenerálni sem kell. A v4 a kurrens (`Api::AJANLOTT_VERZIO`),
     * a v5 pedig a legújabb kiadott (`Api::LEGUJABB_VERZIO`).
     *
     * A v5 eddig KIMARADT: a #56/#778 megírta a v5 mise-tábláját, de a cron csak a
     * v4-et építette (`for ($i = 4; $i >= 4; $i--)`), tehát a v5 sqlite SOHA nem
     * készült el. Aki v5-öt kért, elavult vagy hiányzó fájlt kapott.
     *
     * FIGYELEM: ez megduplázza ennek a cronnak a futásidejét (két teljes fájl,
     * fejenként 5000+ templom és 280e+ mise).
     */
    const GENERALT_VERZIOK = [4, 5];

    function cron() {
        foreach (self::GENERALT_VERZIOK as $verzio) {
            $_REQUEST['v'] = $verzio;
            $this->version = $verzio;
            $this->run();
        }
    }

}
