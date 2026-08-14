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

        if ($current === null) {
            self::markTestSkipped('az initdb.d nem olvasható ebben a környezetben');
        }

        self::assertSame(
            $reference['_meta']['initdb_fingerprint'], $current,
            "A sémafájlok változtak a referencia készítése óta.\n" . self::REGENERATE
        );
    }

    /* Az elavulást a check() jelezze is, ne csak tudja. */
    public function testCheckReportsStaleness(): void {
        $result = \SchemaCheck::check();

        self::assertArrayHasKey('stale', $result);
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
