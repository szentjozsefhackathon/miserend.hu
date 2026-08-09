<?php

use Illuminate\Database\Capsule\Manager as DB;

/**
 * #706: az adatbázis-struktúra összevetése a mienkkel.
 *
 * Az éles adatbázison mindig kézzel ment végig minden migráció (nincs migrációs
 * rendszerünk), ezért elcsúszhat attól, amit a `docker/mysql/initdb.d` leír: maradhat
 * benne rég kivezetett tábla, hiányozhat egy újabb oszlop, elmaradhat egy index.
 *
 * A várt szerkezetet egy BEVERSENYZETT referencia-fájl írja le
 * (`webapp/schema-reference.json`), amit a friss dev-adatbázisból generálunk. Hogy a
 * referencia ne tudjon észrevétlenül elavulni, teszt őrzi: ha valaki az initdb.d-t
 * módosítja és nem generálja újra, a teszt elbukik.
 *
 * Újragenerálás:
 *   docker exec miserend-miserend-1 php /miserend/webapp/tools/schema-reference.php
 *
 * Éles ellenőrzés: a /health oldal „Adatbázis-struktúra" pontja, vagy CLI-ból:
 *   php /miserend/webapp/tools/schema-check.php
 */
class SchemaCheck {

    /* Súlyosság. A sorrend számít: a hívók erre rendeznek. */
    const DANGER  = 'danger';   // a kód el fog hasalni rajta
    const WARNING = 'warning';  // működik, de eltér — pl. hiányzó index
    const INFO    = 'info';     // kozmetikai, jellemzően karakterkészlet

    static function referenceFile(): string {
        return __DIR__ . '/../schema-reference.json';
    }

    /** A sémát leíró fájlok könyvtára. A Dockerfile `COPY . .`-ja miatt élesben is ott van. */
    static function initdbDirectory(): string {
        return __DIR__ . '/../../docker/mysql/initdb.d';
    }

    /**
     * #706: ujjlenyomat a séma FORRÁSÁRÓL, hogy az elavult referencia kiderüljön.
     *
     * A referencia egy pillanatkép. Ha valaki hozzányúl az initdb.d-hez és nem
     * generálja újra, a beversenyzett fájl csendben hazudni kezdene, és a /health
     * „minden rendben"-t mutatna. A CI-ban ezt teszt fogja meg — de csak ott.
     *
     * Ezért a referenciába beletesszük a sémafájlok ujjlenyomatát, és futásidőben
     * újraszámoljuk. Ha eltér, a /health azt mondja meg, ami igaz: a referencia
     * elavult, az összevetés eredménye nem megbízható. Így élesben sem kell azon
     * múlnia, hogy valaki emlékezett-e az újragenerálásra.
     *
     * NULL, ha a könyvtár nem olvasható — akkor egyszerűen nem állítunk semmit.
     */
    static function initdbFingerprint(): ?string {
        $dir = self::initdbDirectory();
        if (!is_dir($dir) || !is_readable($dir)) return null;

        $files = glob($dir . '/*.{sql,sh}', GLOB_BRACE);
        if ($files === false || !$files) return null;

        sort($files);

        $hash = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($hash, basename($file));
            hash_update_file($hash, $file);
        }

        return hash_final($hash);
    }

    /**
     * Fájlonkénti ujjlenyomat, hogy elavuláskor MEGMONDHASSUK, mi változott.
     *
     * A puszta „a könyvtár azóta változott" üzenet nem visz közelebb a megoldáshoz:
     * borazslo élesben pontosan ezt kapta, és nem derült ki belőle, hogy valójában a
     * referenciát generáltam elavult konténerből. Fájlszinten ez egy pillantás.
     *
     * @return array<string,string>|null  fájlnév => sha256, vagy null ha nem olvasható
     */
    static function initdbFileHashes(): ?array {
        $dir = self::initdbDirectory();
        if (!is_dir($dir) || !is_readable($dir)) return null;

        $files = glob($dir . '/*.{sql,sh}', GLOB_BRACE);
        if ($files === false || !$files) return null;

        sort($files);

        $hashes = [];
        foreach ($files as $file) {
            $hashes[basename($file)] = hash_file('sha256', $file);
        }

        return $hashes;
    }

    /**
     * Mi változott a referencia készítése óta?
     *
     * @return string[] ember által olvasható sorok; üres tömb, ha nem tudjuk megmondani
     */
    static function initdbDifferences(array $reference): array {
        $stored  = $reference['_meta']['initdb_files'] ?? null;
        $current = self::initdbFileHashes();

        if (!is_array($stored) || !is_array($current)) {
            return [];
        }

        $lines = [];
        foreach (array_diff_key($current, $stored) as $name => $_) {
            $lines[] = 'új fájl: ' . $name;
        }
        foreach (array_diff_key($stored, $current) as $name => $_) {
            $lines[] = 'eltűnt fájl: ' . $name;
        }
        foreach (array_intersect_key($current, $stored) as $name => $hash) {
            if ($hash !== $stored[$name]) $lines[] = 'megváltozott: ' . $name;
        }

        sort($lines);
        return $lines;
    }

    /**
     * Egy adatbázis szerkezete normalizált alakban, az information_schema-ból.
     *
     * Szándékosan NEM a `SHOW CREATE TABLE` szövegét hasonlítjuk: az tartalmaz
     * AUTO_INCREMENT-számlálót, sorrendi esetlegességeket és szerververzió-függő
     * formázást, amitől két azonos szerkezet is különbözőnek látszana.
     */
    static function readStructure(string $schema): array {
        $tables = [];

        $rows = DB::select(
            'SELECT table_name, engine, table_collation
               FROM information_schema.tables
              WHERE table_schema = ? AND table_type = "BASE TABLE"
              ORDER BY table_name', [$schema]);

        foreach ($rows as $row) {
            $name = $row->table_name ?? $row->TABLE_NAME;
            $tables[$name] = [
                'engine'    => $row->engine ?? $row->ENGINE,
                'collation' => $row->table_collation ?? $row->TABLE_COLLATION,
                'columns'   => [],
                'indexes'   => [],
            ];
        }

        if (!$tables) {
            return ['tables' => []];
        }

        $columns = DB::select(
            'SELECT table_name, column_name, column_type, is_nullable, column_default, extra
               FROM information_schema.columns
              WHERE table_schema = ?
              ORDER BY table_name, ordinal_position', [$schema]);

        foreach ($columns as $c) {
            $table = $c->table_name ?? $c->TABLE_NAME;
            if (!isset($tables[$table])) continue;
            $tables[$table]['columns'][$c->column_name ?? $c->COLUMN_NAME] = [
                'type'     => strtolower((string) ($c->column_type ?? $c->COLUMN_TYPE)),
                'nullable' => ($c->is_nullable ?? $c->IS_NULLABLE) === 'YES',
                'default'  => self::normaliseDefault($c->column_default ?? $c->COLUMN_DEFAULT ?? null),
                'extra'    => strtolower((string) ($c->extra ?? $c->EXTRA ?? '')),
            ];
        }

        $indexes = DB::select(
            'SELECT table_name, index_name, non_unique, seq_in_index, column_name
               FROM information_schema.statistics
              WHERE table_schema = ?
              ORDER BY table_name, index_name, seq_in_index', [$schema]);

        foreach ($indexes as $i) {
            $table = $i->table_name ?? $i->TABLE_NAME;
            if (!isset($tables[$table])) continue;
            $index = $i->index_name ?? $i->INDEX_NAME;
            if (!isset($tables[$table]['indexes'][$index])) {
                $tables[$table]['indexes'][$index] = [
                    'unique'  => ((int) ($i->non_unique ?? $i->NON_UNIQUE)) === 0,
                    'columns' => [],
                ];
            }
            $tables[$table]['indexes'][$index]['columns'][] = $i->column_name ?? $i->COLUMN_NAME;
        }

        return ['tables' => $tables];
    }

    /**
     * Az alapértelmezések írásmódja szerver- és verziófüggő: a MariaDB hol
     * aposztróf közé teszi a szöveget, hol nem, a NULL-t pedig hol NULL-ként, hol
     * "NULL" sztringként adja vissza. Enélkül két azonos oszlop is eltérőnek látszana.
     */
    private static function normaliseDefault($value): ?string {
        if ($value === null) return null;

        $value = trim((string) $value);
        if ($value === '') return '';
        if (strcasecmp($value, 'NULL') === 0) return null;

        // A körbevevő aposztrófokat levesszük ('0' és 0 ugyanaz az alapértelmezés).
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
        }

        // current_timestamp() és CURRENT_TIMESTAMP ugyanaz.
        $lower = strtolower(str_replace(' ', '', $value));
        if ($lower === 'current_timestamp' || $lower === 'current_timestamp()') {
            return 'current_timestamp';
        }

        return $value;
    }

    /**
     * A várt és a tényleges szerkezet összevetése.
     *
     * TISZTA függvény: nincs benne adatbázis-hívás, ezért közvetlenül tesztelhető.
     *
     * @return array<int,array{severity:string,table:string,kind:string,message:string}>
     */
    static function compare(array $expected, array $actual): array {
        $findings = [];

        $expectedTables = $expected['tables'] ?? [];
        $actualTables   = $actual['tables'] ?? [];

        foreach ($expectedTables as $table => $want) {
            if (!isset($actualTables[$table])) {
                $findings[] = self::finding(self::DANGER, $table, 'missing_table',
                    'Hiányzik a tábla.');
                continue;
            }
            $findings = array_merge($findings, self::compareTable($table, $want, $actualTables[$table]));
        }

        foreach ($actualTables as $table => $have) {
            if (!isset($expectedTables[$table])) {
                $findings[] = self::finding(self::WARNING, $table, 'extra_table',
                    'Nálunk nincs ilyen tábla — vélhetően rég kivezetett, eldobható.');
            }
        }

        return self::sortBySeverity($findings);
    }

    private static function compareTable(string $table, array $want, array $have): array {
        $findings = [];

        foreach ($want['columns'] as $column => $wantColumn) {
            if (!isset($have['columns'][$column])) {
                $findings[] = self::finding(self::DANGER, $table, 'missing_column',
                    "Hiányzik az oszlop: `$column` ({$wantColumn['type']}).");
                continue;
            }
            $haveColumn = $have['columns'][$column];

            if ($wantColumn['type'] !== $haveColumn['type']) {
                $findings[] = self::finding(self::WARNING, $table, 'column_type',
                    "`$column` típusa {$haveColumn['type']}, nálunk {$wantColumn['type']}.");
            }

            // A szigorúbb (NOT NULL) oldal a veszélyes: oda nem fér be a NULL.
            if ($wantColumn['nullable'] !== $haveColumn['nullable']) {
                $severity = $haveColumn['nullable'] ? self::WARNING : self::DANGER;
                $findings[] = self::finding($severity, $table, 'column_nullable',
                    "`$column` " . ($haveColumn['nullable'] ? 'NULL-ozható' : 'NOT NULL')
                    . ', nálunk ' . ($wantColumn['nullable'] ? 'NULL-ozható' : 'NOT NULL') . '.');
            }

            if ($wantColumn['default'] !== $haveColumn['default']) {
                $findings[] = self::finding(self::INFO, $table, 'column_default',
                    "`$column` alapértelmezése " . self::show($haveColumn['default'])
                    . ', nálunk ' . self::show($wantColumn['default']) . '.');
            }

            if ($wantColumn['extra'] !== $haveColumn['extra']) {
                $findings[] = self::finding(self::WARNING, $table, 'column_extra',
                    "`$column` extra jelzője " . self::show($haveColumn['extra'])
                    . ', nálunk ' . self::show($wantColumn['extra']) . '.');
            }
        }

        foreach ($have['columns'] as $column => $_) {
            if (!isset($want['columns'][$column])) {
                $findings[] = self::finding(self::WARNING, $table, 'extra_column',
                    "Nálunk nincs ilyen oszlop: `$column` — vélhetően kivezetett.");
            }
        }

        foreach ($want['indexes'] as $index => $wantIndex) {
            if (!isset($have['indexes'][$index])) {
                // Hiányzó egyedi index ADATHIBÁT enged be, nem csak lassít.
                $severity = $wantIndex['unique'] ? self::DANGER : self::WARNING;
                $findings[] = self::finding($severity, $table, 'missing_index',
                    ($wantIndex['unique'] ? 'Hiányzik az EGYEDI index' : 'Hiányzik az index')
                    . ": `$index` (" . implode(', ', $wantIndex['columns']) . ').');
                continue;
            }
            $haveIndex = $have['indexes'][$index];
            if ($wantIndex['columns'] !== $haveIndex['columns']) {
                $findings[] = self::finding(self::WARNING, $table, 'index_columns',
                    "`$index` oszlopai (" . implode(', ', $haveIndex['columns'])
                    . '), nálunk (' . implode(', ', $wantIndex['columns']) . ').');
            }
            if ($wantIndex['unique'] !== $haveIndex['unique']) {
                $findings[] = self::finding(self::WARNING, $table, 'index_unique',
                    "`$index` " . ($haveIndex['unique'] ? 'egyedi' : 'nem egyedi')
                    . ', nálunk ' . ($wantIndex['unique'] ? 'egyedi' : 'nem egyedi') . '.');
            }
        }

        foreach ($have['indexes'] as $index => $_) {
            if (!isset($want['indexes'][$index])) {
                $findings[] = self::finding(self::INFO, $table, 'extra_index',
                    "Nálunk nincs ilyen index: `$index`.");
            }
        }

        if (($want['engine'] ?? null) !== ($have['engine'] ?? null)) {
            $findings[] = self::finding(self::WARNING, $table, 'engine',
                'Tárolómotor ' . self::show($have['engine'] ?? null)
                . ', nálunk ' . self::show($want['engine'] ?? null) . '.');
        }

        // A karakterkészlet külön kategória: a #669 épp ezeket egységesíti, ezért
        // önmagában nem hiba, de a haladás látszik rajta.
        if (($want['collation'] ?? null) !== ($have['collation'] ?? null)) {
            $findings[] = self::finding(self::INFO, $table, 'collation',
                'Egybevetés ' . self::show($have['collation'] ?? null)
                . ', nálunk ' . self::show($want['collation'] ?? null) . '.');
        }

        return $findings;
    }

    private static function finding(string $severity, string $table, string $kind, string $message): array {
        return ['severity' => $severity, 'table' => $table, 'kind' => $kind, 'message' => $message];
    }

    private static function show($value): string {
        if ($value === null) return 'NULL';
        if ($value === '')   return '(nincs)';
        return (string) $value;
    }

    private static function sortBySeverity(array $findings): array {
        $rank = [self::DANGER => 0, self::WARNING => 1, self::INFO => 2];
        usort($findings, function ($a, $b) use ($rank) {
            return [$rank[$a['severity']] ?? 9, $a['table'], $a['kind']]
               <=> [$rank[$b['severity']] ?? 9, $b['table'], $b['kind']];
        });
        return $findings;
    }

    /** Súlyosságonkénti darabszám — a /health összefoglalójához. */
    static function summarise(array $findings): array {
        $counts = [self::DANGER => 0, self::WARNING => 0, self::INFO => 0];
        foreach ($findings as $f) {
            if (isset($counts[$f['severity']])) $counts[$f['severity']]++;
        }
        return $counts;
    }

    static function loadReference(): ?array {
        $file = self::referenceFile();
        if (!is_readable($file)) return null;

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) && isset($decoded['tables']) ? $decoded : null;
    }

    /**
     * A futó adatbázis összevetése a referenciával.
     *
     * @return array{available:bool,reason?:string,findings?:array,counts?:array}
     */
    static function check(?string $schema = null): array {
        $reference = self::loadReference();
        if ($reference === null) {
            return ['available' => false, 'reason' => 'Nincs beolvasható referencia-fájl (' . basename(self::referenceFile()) . ').'];
        }

        $schema = $schema ?? DB::connection()->getDatabaseName();

        try {
            $actual = self::readStructure($schema);
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'Nem olvasható a szerkezet: ' . $e->getMessage()];
        }

        if (!$actual['tables']) {
            return ['available' => false, 'reason' => 'Az adatbázis („' . $schema . '") üres vagy nem olvasható.'];
        }

        $findings = self::compare($reference, $actual);

        /*
         * Ha a séma forrása azóta változott, hogy a referencia készült, akkor az
         * összevetés eredménye nem megbízható — se a „minden rendben", se az
         * eltérés-lista. Ezt meg kell mondani, nem elhallgatni.
         */
        $storedFingerprint  = $reference['_meta']['initdb_fingerprint'] ?? null;
        $currentFingerprint = self::initdbFingerprint();
        $stale = $storedFingerprint !== null
              && $currentFingerprint !== null
              && $storedFingerprint !== $currentFingerprint;

        return [
            'available'    => true,
            'schema'       => $schema,
            'findings'     => $findings,
            'counts'       => self::summarise($findings),
            'stale'        => $stale,
            // Elavulásnál a legfontosabb kérdés: MI változott. Enélkül az üzenet
            // csak riaszt, de nem segít.
            'stale_details' => $stale ? self::initdbDifferences($reference) : [],
            'generated_at' => $reference['_meta']['generated_at'] ?? null,
        ];
    }
}
