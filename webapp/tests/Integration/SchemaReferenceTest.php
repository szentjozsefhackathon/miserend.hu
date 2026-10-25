<?php

use PHPUnit\Framework\TestCase;

/**
 * #706: a beversenyzett séma-referencia NE tudjon észrevétlenül elavulni.
 *
 * A referencia egy pillanatkép a `docker/mysql/initdb.d`-ből felépülő szerkezetről.
 * Ha valaki hozzányúl a sémához és nem generálja újra, a referencia csendben
 * hazudni kezdene — és a /health „minden rendben"-t mutatna olyan eltérésekre is,
 * amikről tudnia kellene.
 *
 * Ez a teszt ugyanazon a friss adatbázison fut, amit az initdb.d épít, ezért ha
 * elcsúsznak, itt bukik el.
 *
 * Elbukott? Akkor generáld újra:
 *   docker exec miserend-miserend-1 php /miserend/webapp/tools/schema-reference.php
 */
final class SchemaReferenceTest extends TestCase {

    private const REGENERATE = 'Generáld újra: docker exec miserend-miserend-1 php /miserend/webapp/tools/schema-reference.php';

    public function testReferenceFileExistsAndParses(): void {
        self::assertFileExists(\SchemaCheck::referenceFile());

        $reference = \SchemaCheck::loadReference();

        self::assertIsArray($reference, 'a referencia-fájl nem értelmezhető JSON');
        self::assertNotEmpty($reference['tables'], 'a referencia nem tartalmaz táblát');
    }

    /* A lényeg: a beversenyzett referencia egyezzen a valódi sémával. */
    public function testReferenceMatchesTheCurrentDatabase(): void {
        $result = \SchemaCheck::check();

        self::assertTrue($result['available'], $result['reason'] ?? '');

        if ($result['findings']) {
            $lines = array_map(
                fn($f) => sprintf('  [%s] %s: %s', $f['severity'], $f['table'], $f['message']),
                $result['findings']
            );

            self::fail(
                "A séma-referencia elavult — " . count($result['findings']) . " eltérés:\n"
                . implode("\n", $lines) . "\n\n" . self::REGENERATE
            );
        }

        self::assertSame([], $result['findings']);
    }

    /*
     * #706: a referencia hordozza a sémafájlok ujjlenyomatát, hogy az elavulás
     * FUTÁSIDŐBEN is kiderüljön — ne csak itt, a CI-ban. Enélkül az éles /health
     * „minden rendben"-t mondana akkor is, ha valaki elfelejtette újragenerálni.
     */
    public function testReferenceCarriesTheInitdbFingerprint(): void {
        $reference = \SchemaCheck::loadReference();

        self::assertArrayHasKey('_meta', $reference, 'hiányzik a _meta blokk. ' . self::REGENERATE);
        self::assertNotEmpty($reference['_meta']['initdb_fingerprint'] ?? null);
        self::assertNotEmpty($reference['_meta']['generated_at'] ?? null);
    }

    /* A ténylegesen telepített sémafájlokkal egyeznie kell. */
    public function testFingerprintMatchesTheDeployedSchemaFiles(): void {
        $reference = \SchemaCheck::loadReference();
        $current   = \SchemaCheck::initdbFingerprint();

        /*
         * #911: ez korábban `markTestSkipped` volt, és pont ez volt a baj.
         *
         * Ha a sémafájlok nem olvashatók, az ellenőrzés NÉMÁN kimaradt — az eredmény
         * zöld futás, miközben senki nem vetett össze semmit. Az elavulás így hónapokig
         * észrevétlen maradhatott: a referencia tíz initdb-fájlt ismert a tizennyolcból.
         *
         * Minden környezetben, ahol ez a teszt fut, a könyvtárnak ott kell lennie: a
         * compose becsatolja a forrásfából (`docker/compose.dev.yml`,
         * `docker/compose.coverage.yml`), élesben pedig a Dockerfile `COPY . .`-ja
         * teszi be. Ha mégis hiányzik, azt meg kell tudni — nem elhallgatni.
         */
        self::assertNotNull(
            $current,
            "Nem olvashatók a sémafájlok: " . \SchemaCheck::initdbDirectory() . "\n"
            . "Enélkül ez az ellenőrzés vakon futna. Hiányzik a becsatolás?\n"
            . "  - ./mysql/initdb.d:/miserend/docker/mysql/initdb.d:ro"
        );

        self::assertSame(
            $reference['_meta']['initdb_fingerprint'], $current,
            "A sémafájlok változtak a referencia készítése óta.\n" . self::REGENERATE
        );
    }

    /* Az elavulást a check() jelezze is, ne csak tudja. */
    public function testCheckReportsStaleness(): void {
        $result = \SchemaCheck::check();

        self::assertArrayHasKey('stale', $result);

        /*
         * #911: a `stale` akkor is HAMIS, ha nem tudtunk összevetni — a `check()` a két
         * ujjlenyomat egyezését nézi, és a hiányzó oldalt nem tekinti eltérésnek. Az
         * alábbi állítás nélkül tehát ez a teszt üresen menne át abban a környezetben,
         * ahol a legnagyobb szükség volna rá.
         */
        self::assertNotNull(
            \SchemaCheck::initdbFingerprint(),
            'nincs mivel összevetni: a `stale = false` itt nem jelent semmit'
        );

        self::assertFalse($result['stale'], 'a beversenyzett referencia nem lehet elavult. ' . self::REGENERATE);
    }

    /*
     * Néhány tábla, aminek biztosan benne kell lennie. Ha a referencia valaha
     * csonkán generálódik (pl. félig felállt adatbázisról), ez fogja meg.
     */
    public function testReferenceCoversTheCoreTables(): void {
        $reference = \SchemaCheck::loadReference();

        foreach (['templomok', 'cal_masses', 'boundaries', 'user', 'photos'] as $table) {
            self::assertArrayHasKey($table, $reference['tables'], "hiányzik a referenciából: $table");
            self::assertNotEmpty($reference['tables'][$table]['columns'], "üres oszloplista: $table");
        }
    }
}
