<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * A szentségimádás-import két, egymást erősítő hibába futott élesben:
 *
 *  1. `findChurch()` az `ElasticsearchApi::search()`-öt hívta, ami a #309 óta nem
 *     létezik → "Call to undefined method". A cron minden nap az első tételnél elszállt.
 *  2. A tábla ürítése a feldolgozás ELŐTT futott, így az 1. pont minden futásban ki is
 *     ürítette a szentségimádásokat az oldalról.
 *
 * A `testEveryRegisteredCronJobIsCallable` ezt nem foghatta meg: az csak az osztály és a
 * belépő metódus létezését nézi, a törzsében lévő hívásokat nem.
 */
class SzentsegimadasImportTest extends TestCase {

    /** Szentendre, Péter-Pál-templom — a seed első temploma. */
    private const CHURCH_ID = 1;

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        try {
            \ExternalApi\ElasticsearchApi::updateChurches([self::CHURCH_ID]);
            @file_get_contents('http://elasticsearch:9200/churches/_refresh', false,
                stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true]]));
        } catch (\Throwable $e) {
            // A teszt maga kezeli, ha nincs index.
        }
    }

    protected function setUp(): void {
        parent::setUp();
        DB::connection()->beginTransaction();
    }

    protected function tearDown(): void {
        DB::connection()->rollBack();
        parent::tearDown();
    }

    public function testFindChurchUsesTheCurrentSearchLayer(): void {
        $church = \Eloquent\Church::find(self::CHURCH_ID);
        $this->assertNotNull($church, 'Hiányzik a teszt-templom a seedből.');

        $api = new \ExternalApi\szentsegimadasApi();
        $api->error = ['null' => [], 'multiple' => []];

        // A lényeg: ez a hívás egyáltalán lefut. Korábban "Call to undefined method"-dal állt meg.
        $found = $api->findChurch([
            'templom' => $church->nev,
            'varos' => $church->varos,
        ]);

        if ($found === false) {
            $this->markTestSkipped('A templom-index nem adott egyértelmű találatot a teszt-templomra.');
        }

        $this->assertSame(self::CHURCH_ID, (int) $found);
    }

    public function testReplaceAllSwapsTheWholeDay(): void {
        $api = new \ExternalApi\szentsegimadasApi();

        $api->replaceAll([
            $api->toDatabaseRow($this->row('2026-01-01', '08:00')),
            $api->toDatabaseRow($this->row('2026-01-02', '09:00')),
        ]);
        $this->assertSame(2, DB::table('szentsegimadasok')->count());

        $api->replaceAll([$api->toDatabaseRow($this->row('2026-02-01', '10:00'))]);
        $this->assertSame(1, DB::table('szentsegimadasok')->count());
        $this->assertSame('10:00', DB::table('szentsegimadasok')->value('starttime'));
    }

    /**
     * A lényeg: ha a csere közben bármi elszáll, a tegnapi adat marad az oldalon.
     * Korábban a truncate a feldolgozás előtt futott, ezért minden hiba ürítéssel járt.
     */
    public function testFailedReplaceKeepsThePreviousData(): void {
        $api = new \ExternalApi\szentsegimadasApi();
        $api->replaceAll([$api->toDatabaseRow($this->row('2026-01-01', '08:00'))]);

        try {
            $api->replaceAll([
                $api->toDatabaseRow($this->row('2026-03-01', '11:00')),
                ['church_id' => null, 'date' => '2026-03-02', 'starttime' => '12:00',
                 'endtime' => '13:00', 'type' => null, 'info' => null],
            ]);
            $this->fail('A hibás sornak meg kell buktatnia a cserét.');
        } catch (\Throwable $e) {
            // várt: a NOT NULL church_id-ba nem megy be a NULL
        }

        $this->assertSame(1, DB::table('szentsegimadasok')->count());
        $this->assertSame('08:00', DB::table('szentsegimadasok')->value('starttime'));
    }

    private function row(string $date, string $start): array {
        return [
            'church_id' => self::CHURCH_ID,
            'nap' => $date,
            'kezdes' => $start,
            'veg' => '18:00',
            'allapot' => 'nyilvános',
            'info' => '',
        ];
    }
}
