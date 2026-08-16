<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Integration tests for OSM boundary checking logic.
 *
 * Tests the checkBoundaries() selection logic, checkBoundariesForOne() tracking,
 * and the boundaries_checked_at coordinate-change reset in Church::save().
 *
 * Uses DB transactions rolled back after each test to keep the DB clean.
 * The OverpassApi is mocked via PHPUnit partial mocks on the OSM class,
 * since downloadBoundaries() is called as $this->downloadBoundaries() internally.
 */
class OsmBoundariesTest extends TestCase {

    /** @var array<int,int> a setUp előtt aktív templomok azonosítói */
    private array $eredetilegAktiv = [];

    /** @var int ID of the primary test church inserted in setUp */
    private int $testChurchId;

    /** @var array Extra church IDs to clean up (rollback covers these too) */
    private array $extraChurchIds = [];

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        // #ci: a seed-adatban ~5133 valódi, érvényes-koordinátás templom van, amiket a
        // checkBoundaries() felszedne a teszt-fixture-ök helyett. NEM elég őket
        // "ellenőrzöttnek" jelölni (boundaries_checked_at), mert akkor is szelektálhatók
        // maradnak, csak alacsonyabb prioritással -> a Skip-tesztek limit=50-nél elkapnák
        // őket. Ezért ok='n'-re állítjuk: a checkBoundaries ELSŐ szűrője where('ok','i'),
        // így a seed teljesen kiesik. Csak a lentebb beszúrt (ok='i') fixture-ök maradnak.
        // A tranzakció a tearDown-ban visszagördül, a seed érintetlen marad.
        //
        // …ELVILEG. MySQL/MariaDB alatt a DDL IMPLICIT COMMITOT okoz: ha a futás során
        // bárhol lefut egy ALTER/CREATE, a nyitott tranzakció csendben lezárul, és a
        // tearDown rollBack()-je már nem csinál semmit. Ilyenkor ez a WHERE nélküli
        // tömeges UPDATE a teljes seedet letiltva hagyja — 5050 templom tűnik el a
        // fejlesztői adatbázisból, hiba és jelzés nélkül. Pontosan ez történt velem.
        //
        // Ezért feljegyezzük, kik voltak aktívak, és a tearDown vissza is állítja őket,
        // ha a visszagördülés elmaradt.
        $this->eredetilegAktiv = DB::table('templomok')->where('ok', 'i')->pluck('id')->all();
        DB::table('templomok')->update(['ok' => 'n']);

        $this->testChurchId = $this->insertChurch([
            'nev' => 'PHPUnit Test Church',
            'ok' => 'i',
            'lat' => 47.5,
            'lon' => 19.0,
            'boundaries_checked_at' => null,
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();

        /*
         * Biztonsági háló a fenti WHERE nélküli UPDATE alá. Ha a rollBack() megtette a
         * dolgát, ez a lekérdezés nem talál semmit, és nem is ír. Ha viszont a
         * tranzakció egy implicit commit miatt már lezárult, itt állítjuk helyre a
         * seedet — a fejlesztő ne azzal szembesüljön, hogy üres lett a kereső.
         */
        $hianyzo = array_values(array_diff(
            $this->eredetilegAktiv,
            DB::table('templomok')->where('ok', 'i')->pluck('id')->all()
        ));

        if ($hianyzo !== []) {
            DB::table('templomok')->whereIn('id', $hianyzo)->update(['ok' => 'i']);
        }

        parent::tearDown();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function insertChurch(array $overrides = []): int {
        $defaults = [
            'nev'        => 'Test Church',
            'ok'         => 'i',
            'lat'        => 47.0,
            'lon'        => 19.0,
            'cim'        => 'Test utca 1.',
            'plebania'   => '',
            'leiras'     => '',
            'megjegyzes' => '',
            'misemegj'   => '',
            'bucsu'      => '',
            'kontakt'    => '',
            'kontaktmail'=> '',
            'adminmegj'  => '',
            'log'        => '',
            'letrehozta' => '',
            'modositotta'=> '',
            'moddatum'   => '0000-00-00 00:00:00',
            'frissites'  => date('Y-m-d'),
            'boundaries_checked_at' => null,
        ];
        return DB::table('templomok')->insertGetId(array_merge($defaults, $overrides));
    }

    /** Create a partial mock of OSM that stubs downloadBoundaries() */
    /**
     * #570/#700: a `downloadBoundaries()` háromféle választ ad — `false` (a lekérdezés
     * elhasalt), `[]` (lefutott, de nincs itt határ) és a határok tömbje. A dublőrnek
     * mindhármat tudnia kell, mert épp a köztük lévő különbség a lényeg.
     *
     * @param array|false|null $returnValue
     */
    private function osmWithMockedDownload($returnValue): OSM {
        $osm = $this->getMockBuilder(OSM::class)
            ->onlyMethods(['downloadBoundaries'])
            ->getMock();
        $osm->method('downloadBoundaries')->willReturn($returnValue);
        return $osm;
    }

    // ─── checkBoundaries() selection order ──────────────────────────────────

    /**
     * A NULL boundaries_checked_at (soha nem ellenőrzött) templomnak kell elsőnek kerülnie,
     * még akkor is, ha van régebben ellenőrzött temploml is.
     */
    public function testCheckBoundariesSelectsNullCheckedAtFirst(): void {
        // Egy régebben ellenőrzött másik egyház
        $this->insertChurch([
            'lat' => 47.6, 'lon' => 19.1,
            'boundaries_checked_at' => '2020-01-01 00:00:00',
        ]);

        $selectedLats = [];
        $osm = $this->getMockBuilder(OSM::class)
            ->onlyMethods(['downloadBoundaries'])
            ->getMock();
        $osm->method('downloadBoundaries')
            ->willReturnCallback(function($lat, $lon) use (&$selectedLats) {
                $selectedLats[] = (float) $lat;
                return null;
            });

        $osm->checkBoundaries(1); // limit = 1 → csak 1 kerül sorra

        $this->assertCount(1, $selectedLats,
            'Pontosan 1 templomnak kell sorra kerülnie limit=1 esetén.');
        $this->assertEquals(47.5, $selectedLats[0],
            'A NULL boundaries_checked_at-ű templomnak kell előre kerülnie.');
    }

    /**
     * Ha több templomnak is van boundaries_checked_at értéke, a legrégebbit kell előre venni.
     */
    public function testCheckBoundariesSelectsOldestFirst(): void {
        // Beállítjuk a primary test church checked_at értékét (újabb)
        DB::table('templomok')->where('id', $this->testChurchId)
            ->update(['boundaries_checked_at' => '2024-06-01 00:00:00']);

        // Egy régebben ellenőrzött egyház
        $olderId = $this->insertChurch([
            'lat' => 47.6, 'lon' => 19.1,
            'boundaries_checked_at' => '2020-01-01 00:00:00',
        ]);

        $selectedLats = [];
        $osm = $this->getMockBuilder(OSM::class)
            ->onlyMethods(['downloadBoundaries'])
            ->getMock();
        $osm->method('downloadBoundaries')
            ->willReturnCallback(function($lat, $lon) use (&$selectedLats) {
                $selectedLats[] = (float) $lat;
                return null;
            });

        $osm->checkBoundaries(1);

        $this->assertCount(1, $selectedLats);
        $this->assertEquals(47.6, $selectedLats[0],
            'A legrégebben ellenőrzött templomnak kell előre kerülnie.');
    }

    /**
     * Null vagy nulla koordinátájú templomra NEM szabad lefuttatni a downloadBoundaries-t.
     */
    public function testCheckBoundariesSkipsChurchesWithoutCoordinates(): void {
        // A primary test church lat=NULL legyen
        DB::table('templomok')->where('id', $this->testChurchId)
            ->update(['lat' => null, 'lon' => null]);

        $osm = $this->getMockBuilder(OSM::class)
            ->onlyMethods(['downloadBoundaries'])
            ->getMock();
        $osm->expects($this->never())
            ->method('downloadBoundaries');

        $osm->checkBoundaries(50);
        // Ha ide értünk és a downloadBoundaries nem volt meghívva, a teszt sikerült
    }

    /**
     * lat=0 koordinátájú templomra szintén nem szabad futtatni.
     */
    public function testCheckBoundariesSkipsZeroCoordinates(): void {
        DB::table('templomok')->where('id', $this->testChurchId)
            ->update(['lat' => 0, 'lon' => 0]);

        $osm = $this->getMockBuilder(OSM::class)
            ->onlyMethods(['downloadBoundaries'])
            ->getMock();
        $osm->expects($this->never())
            ->method('downloadBoundaries');

        $osm->checkBoundaries(50);
    }

    // ─── checkBoundariesForOne() boundaries_checked_at tracking ────────────

    /**
     * Sikeres API hívás esetén boundaries_checked_at frissüljön.
     */
    public function testCheckBoundariesForOneUpdatesBoundariesCheckedAtOnSuccess(): void {
        // Egy boundary létrehozása, amelyet a mock visszaad
        $boundaryId = DB::table('boundaries')->insertGetId([
            'boundary'    => 'administrative',
            'admin_level' => 8,
            'name'        => 'Test Boundary',
            'created_at'  => date('Y-m-d'),
            'updated_at'  => date('Y-m-d'),
        ]);

        $osm = $this->osmWithMockedDownload([$boundaryId]);

        $church = \Eloquent\Church::find($this->testChurchId);
        $osm->checkBoundariesForOne($church);

        $updated = DB::table('templomok')->where('id', $this->testChurchId)->first();
        $this->assertNotNull($updated->boundaries_checked_at,
            'boundaries_checked_at kell, hogy be legyen állítva sikeres API hívás után.');
    }

    /**
     * #570/#700: API-hibánál NEM szabad „ellenőrizve" bélyeget tenni.
     *
     * Ez a teszt korábban az ellenkezőjét rögzítette (az örökös hurok elkerülésére),
     * és pontosan ettől lett vak a települési keresés: a batch a legrégebben
     * ellenőrzöttet veszi előre, tehát egy rate-limitelt futás a templomot határok
     * NÉLKÜL tette a sor végére — véglegesen. A hurok elleni védelem most máshol van:
     * hibánál megszakítjuk a köteget, tehát nem pörgünk ugyanazon.
     */
    public function testCheckBoundariesForOneDoesNotStampOnApiFailure(): void {
        foreach ([false, null] as $kudarc) {
            DB::table('templomok')->where('id', $this->testChurchId)
                ->update(['boundaries_checked_at' => null]);

            $osm = $this->osmWithMockedDownload($kudarc);
            $church = \Eloquent\Church::find($this->testChurchId);

            $sikeres = $osm->checkBoundariesForOne($church);

            $updated = DB::table('templomok')->where('id', $this->testChurchId)->first();
            $this->assertFalse($sikeres, 'A kudarcot jeleznie kell a hívó felé.');
            $this->assertNull($updated->boundaries_checked_at,
                'API-hibánál nem állíthatjuk, hogy ellenőriztük — különben a templom határok nélkül esik ki a sorból.');
        }
    }

    /** Kudarc esetén a köteg álljon meg: a rate-limitet további kérésekkel csak mélyítenénk. */
    public function testCheckBoundariesStopsTheBatchOnFailure(): void {
        $this->insertChurch(['lat' => 47.6, 'lon' => 19.1, 'boundaries_checked_at' => null]);
        $this->insertChurch(['lat' => 47.7, 'lon' => 19.2, 'boundaries_checked_at' => null]);

        $hivasok = 0;
        $osm = $this->getMockBuilder(OSM::class)
            ->onlyMethods(['downloadBoundaries'])
            ->getMock();
        $osm->method('downloadBoundaries')
            ->willReturnCallback(function () use (&$hivasok) {
                $hivasok++;
                return false;
            });

        $osm->checkBoundaries(10);

        $this->assertSame(1, $hivasok,
            'Az első kudarc után nem szabad tovább kopogtatni az Overpasson.');
    }

    /**
     * Ha az API üres tömböt ad vissza, boundaries_checked_at szintén frissüljön.
     */
    public function testCheckBoundariesForOneUpdatesBoundariesCheckedAtOnEmptyResult(): void {
        $osm = $this->osmWithMockedDownload([]);

        $church = \Eloquent\Church::find($this->testChurchId);
        $osm->checkBoundariesForOne($church);

        $updated = DB::table('templomok')->where('id', $this->testChurchId)->first();
        $this->assertNotNull($updated->boundaries_checked_at,
            'boundaries_checked_at kell, hogy frissüljön üres API eredmény esetén is.');
    }

    // ─── Church::save() koordinátaváltozás reset ────────────────────────────

    /**
     * Ha a koordináta megváltozik, boundaries_checked_at NULL-ra kell resetelődnie,
     * hogy a cron újra lefuttassa a boundary ellenőrzést.
     */
    public function testChurchCoordinateChangeResetsBoundariesCheckedAt(): void {
        // Beállítjuk, hogy már volt ellenőrizve
        DB::table('templomok')->where('id', $this->testChurchId)
            ->update(['boundaries_checked_at' => '2024-01-01 00:00:00']);

        $church = \Eloquent\Church::find($this->testChurchId);
        $this->assertEquals('2024-01-01 00:00:00', $church->boundaries_checked_at,
            'Előfeltétel: boundaries_checked_at legyen beállítva.');

        $church->lat = 48.0; // koordináta változás!
        $church->save();

        $updated = DB::table('templomok')->where('id', $this->testChurchId)->first();
        $this->assertNull($updated->boundaries_checked_at,
            'boundaries_checked_at NULL-ra kell resetelődjön, ha a lat koordináta megváltozik.');
    }

    /**
     * Ha csak a lon koordináta változik, szintén resetelődjön.
     */
    public function testChurchLonChangeResetsBoundariesCheckedAt(): void {
        DB::table('templomok')->where('id', $this->testChurchId)
            ->update(['boundaries_checked_at' => '2024-01-01 00:00:00']);

        $church = \Eloquent\Church::find($this->testChurchId);
        $church->lon = 20.0; // lon változás!
        $church->save();

        $updated = DB::table('templomok')->where('id', $this->testChurchId)->first();
        $this->assertNull($updated->boundaries_checked_at,
            'boundaries_checked_at NULL-ra kell resetelődjön, ha a lon koordináta megváltozik.');
    }

    /**
     * Ha a koordináta NEM változik (pl. csak a név), boundaries_checked_at maradjon meg.
     */
    public function testChurchNonCoordinateSaveDoesNotResetBoundariesCheckedAt(): void {
        $checkedAt = '2024-06-15 10:00:00';
        DB::table('templomok')->where('id', $this->testChurchId)
            ->update(['boundaries_checked_at' => $checkedAt]);

        $church = \Eloquent\Church::find($this->testChurchId);
        $church->nev = 'Módosított Teszt Egyház'; // csak a névváltozik, koordináta nem
        $church->save();

        $updated = DB::table('templomok')->where('id', $this->testChurchId)->first();
        $this->assertNotNull($updated->boundaries_checked_at,
            'boundaries_checked_at NEM kell resetelődjön, ha a koordináta nem változik.');
    }
}
