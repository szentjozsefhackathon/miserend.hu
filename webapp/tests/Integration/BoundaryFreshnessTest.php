<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #842: ne kérdezzük újra az Overpasstól azt, ami már a saját táblánkban van.
 *
 * Az éles /health két számot mutat egymás mellett, és a kettő együtt írja le a bajt:
 *   - „Területre kereshető (van boundary-kapcsolata): 5052 / 5052 (100%)"
 *   - „Misézőhelyek amiknek nem kerestük még soha: 4950 / 5052"
 *
 * Nem ellentmondás: a `boundaries_checked_at` oszlop utólagos migrációval került be,
 * NULL alapértékkel, tehát minden régi templom „soha nem ellenőrzött"-nek látszik.
 * A `checkBoundaries()` emiatt hónapok óta olyan adatot kérdez újra, ami megvan —
 * 50 templom háromóránként, napi 400 Overpass-hívás, és a sor sosem fogy el, mert nem
 * volt megállási feltétel.
 *
 * Két javítás, két különböző okból:
 *   1. frissességi küszöb: a fél évnél frissebbet ne kérdezzük újra;
 *   2. visszatöltés: aminek MEGVAN a határa, kapjon bélyeget — szétszórva, hogy fél év
 *      múlva ne egyszerre járjon le mind.
 */
final class BoundaryFreshnessTest extends TestCase {

    /** @var int[] */
    private array $eredetilegAktiv = [];

    protected function setUp(): void {
        DB::beginTransaction();

        /*
         * Ugyanaz a fogás, mint az OsmBoundariesTest-ben: a seed ötezer aktív temploma
         * elnyomná a fixture-öket a kötegben. A tranzakció visszagördül, de a DDL okozta
         * implicit commit ellen a tearDown külön is visszaállítja őket.
         */
        $this->eredetilegAktiv = DB::table('templomok')->where('ok', 'i')->pluck('id')->all();
        DB::table('templomok')->update(['ok' => 'n']);
    }

    protected function tearDown(): void {
        DB::rollBack();

        if ($this->eredetilegAktiv && DB::table('templomok')->where('ok', 'i')->count() === 0) {
            DB::table('templomok')->whereIn('id', $this->eredetilegAktiv)->update(['ok' => 'i']);
        }
    }

    private function templom(array $overrides = []): int {
        return DB::table('templomok')->insertGetId(array_merge([
            'nev' => 'Frissesség teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
            'boundaries_checked_at' => null,
        ], $overrides));
    }

    /** Administratív határ hozzákötése — ettől lesz a templom „már megvan" állapotú. */
    private function hatart(int $churchId): void {
        $boundaryId = DB::table('boundaries')->insertGetId([
            'osmtype' => 'relation',
            'osmid' => random_int(900000000, 999999999),
            'name' => 'Teszt közigazgatási határ',
            'boundary' => 'administrative',
        ]);
        DB::table('lookup_boundary_church')->insert([
            'boundary_id' => $boundaryId,
            'church_id' => $churchId,
        ]);
    }

    private function osmDublor($valasz): OSM {
        $osm = $this->getMockBuilder(OSM::class)->onlyMethods(['downloadBoundaries'])->getMock();
        $osm->method('downloadBoundaries')->willReturn($valasz);
        return $osm;
    }

    /* ---- 1. Frissességi küszöb ---- */

    /** A frissen ellenőrzött templomot NE kérdezzük újra. */
    public function testAFreshlyCheckedChurchIsNotQueriedAgain(): void {
        $id = $this->templom(['boundaries_checked_at' => date('Y-m-d H:i:s', strtotime('-10 days'))]);

        $elotte = DB::table('templomok')->where('id', $id)->value('boundaries_checked_at');
        $this->osmDublor([])->checkBoundaries();
        $utana = DB::table('templomok')->where('id', $id)->value('boundaries_checked_at');

        self::assertSame($elotte, $utana, 'a friss belyeget nem szabad frissiteni');
    }

    /** A régen ellenőrzöttet viszont igen — a határok azért változhatnak. */
    public function testAStaleChurchIsQueriedAgain(): void {
        $regen = date('Y-m-d H:i:s', strtotime('-2 years'));
        $id = $this->templom(['boundaries_checked_at' => $regen]);

        $this->osmDublor([])->checkBoundaries();

        self::assertNotSame($regen,
            DB::table('templomok')->where('id', $id)->value('boundaries_checked_at'),
            'a fel evnel regebbi adatot frissiteni kell');
    }

    /** A soha nem ellenőrzött továbbra is elsőbbséget élvez. */
    public function testANeverCheckedChurchIsStillPickedUp(): void {
        $id = $this->templom(['boundaries_checked_at' => null]);

        $this->osmDublor([])->checkBoundaries();

        self::assertNotNull(DB::table('templomok')->where('id', $id)->value('boundaries_checked_at'));
    }

    /* ---- 2. Visszatöltés ---- */

    /** Akinek MEGVAN a határa, kapjon bélyeget — az Overpasst ne kérdezzük róla. */
    public function testTheBackfillStampsChurchesThatAlreadyHaveABoundary(): void {
        $id = $this->templom(['boundaries_checked_at' => null]);
        $this->hatart($id);

        $erintett = \Crons::backfillBoundaryCheckedAt();

        self::assertGreaterThanOrEqual(1, $erintett);
        self::assertNotNull(DB::table('templomok')->where('id', $id)->value('boundaries_checked_at'));
    }

    /**
     * Akinek NINCS határa, az maradjon NULL.
     *
     * Nekik pont a NULL a jelzésük: a `requeueChurchesWithoutBoundary()` és a köteg
     * elsőbbségi sorrendje is ezen múlik. Ha a visszatöltés őket is megbélyegezné, épp
     * a valódi hiányt takarnánk el.
     */
    public function testTheBackfillLeavesChurchesWithoutABoundaryAlone(): void {
        $id = $this->templom(['boundaries_checked_at' => null]);

        \Crons::backfillBoundaryCheckedAt();

        self::assertNull(DB::table('templomok')->where('id', $id)->value('boundaries_checked_at'));
    }

    /** Idempotens: a második futás már nem talál munkát. */
    public function testTheBackfillIsIdempotent(): void {
        $id = $this->templom(['boundaries_checked_at' => null]);
        $this->hatart($id);

        \Crons::backfillBoundaryCheckedAt();
        $elso = DB::table('templomok')->where('id', $id)->value('boundaries_checked_at');

        \Crons::backfillBoundaryCheckedAt();

        self::assertSame($elso, DB::table('templomok')->where('id', $id)->value('boundaries_checked_at'));
    }

    /**
     * A bélyeg a frissességi ablakON BELÜL van, de nem feltétlenül ma.
     *
     * Ha mind a mai napot kapná, fél év múlva egyszerre járna le az összes, és a mai
     * helyzet térne vissza egy csapásra.
     */
    public function testTheBackfillSpreadsTheStampsOverTheWindow(): void {
        $ids = [];
        for ($i = 0; $i < 40; $i++) {
            $id = $this->templom(['boundaries_checked_at' => null, 'lat' => 47.0 + $i / 1000]);
            $this->hatart($id);
            $ids[] = $id;
        }

        \Crons::backfillBoundaryCheckedAt();

        $belyegek = DB::table('templomok')->whereIn('id', $ids)
            ->pluck('boundaries_checked_at')->all();

        $hatar = strtotime('-' . \OSM::BOUNDARY_FRESHNESS);
        foreach ($belyegek as $b) {
            self::assertNotNull($b);
            self::assertGreaterThan($hatar, strtotime($b), 'az ablakon belul kell lennie');
            self::assertLessThanOrEqual(time() + 1, strtotime($b), 'jovobeli belyeg nem lehet');
        }

        self::assertGreaterThan(1, count(array_unique($belyegek)),
            'a belyegeknek szet kell szorodniuk, kulonben egyszerre jar le mind');
    }

    /**
     * #890: a bélyeg horgonya a PHP órája, NEM a MySQL `NOW()`-ja.
     *
     * A fenti teszt ezt csak szakaszosan fogta meg: a MySQL órájára horgonyzott bélyeg
     * akkor kerül a jövőbe, ha a `FLOOR(RAND() * $napok)` épp nullát húz — negyven
     * templomnál nagyjából minden ötödik futásban. A master emiatt volt hol zöld, hol
     * piros, ugyanarra a kódra.
     *
     * A kifejezés viszont determinisztikusan megnézhető, tehát ez az eset MINDIG szól.
     */
    public function testTheBackfillStampIsAnchoredToThePhpClock(): void {
        $kifejezes = \Crons::backfillBelyegKifejezes('2026-08-27 09:15:00', 180);

        self::assertStringContainsString("'2026-08-27 09:15:00'", $kifejezes,
            'a horgonynak a kapott, PHP-ből jövő időpontnak kell lennie');
        self::assertStringNotContainsString('NOW()', $kifejezes,
            'a MySQL órája három órával előrébb jár a PHP-énál (#890) — nem keverhetjük');
        self::assertStringContainsString('RAND()', $kifejezes,
            'a szórás soronként kell, különben egyszerre jár le minden bélyeg');
    }
}
