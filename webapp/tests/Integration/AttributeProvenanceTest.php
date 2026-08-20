<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #840: a `fromOSM` jelzőt a KULCS döntse el, ne az, hogy ki írta a sort utoljára.
 *
 * A jelző korábban három kérdésre válaszolt egyszerre: „ki írta", „OSM-névtérbe
 * tartozik-e a kulcs", és „a szinkron tulajdona-e". 2024-ig ez ugyanaz volt, mert
 * egyetlen író létezett. A #484 óta a `\GlutenFreeCommunion` a második író, és a
 * `diet:gluten_free` egyszerre OSM-címke ÉS helyben szerkesztett érték.
 *
 * Mivel az `updateOrCreate` a (church_id, key) párra illeszt, az UTOLSÓ író bélyegezte
 * meg a jelzőt: a sor oda-vissza billegett aszerint, hogy az éjszakai cron vagy egy
 * /edit mentés futott-e utoljára. A /josm ezért nem rosszul szűrt, hanem
 * VERSENYHELYZETET mutatott — élesben emiatt tűnt el a `diet:gluten_free`, pedig három
 * templomnál ott van az OSM-ben is (1515, 889, 1254).
 */
final class AttributeProvenanceTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        DB::beginTransaction();
        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'fromOSM teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    private function jelzo(string $key): ?int {
        $ertek = DB::table('attributes')
            ->where('church_id', $this->churchId)->where('key', $key)->value('fromOSM');

        return $ertek === null ? null : (int) $ertek;
    }

    /* ---- A döntés egyetlen helyen ---- */

    public function testTheKeyDecidesNotTheWriter(): void {
        self::assertTrue(\Eloquent\Attribute::isOsmKey('diet:gluten_free'));
        self::assertTrue(\Eloquent\Attribute::isOsmKey('wheelchair'));
        self::assertTrue(\Eloquent\Attribute::isOsmKey('church:type'));

        self::assertFalse(\Eloquent\Attribute::isOsmKey(\GlutenFreeCommunion::HOLIDAYS_KEY));
        self::assertFalse(\Eloquent\Attribute::isOsmKey(\GlutenFreeCommunion::WEEKDAYS_KEY));
    }

    /** A kivétel-lista ott van, ahol a kulcs születik — ne csússzon szét a kettő. */
    public function testTheExceptionListLivesWithTheKeys(): void {
        self::assertSame(
            [\GlutenFreeCommunion::HOLIDAYS_KEY, \GlutenFreeCommunion::WEEKDAYS_KEY],
            \GlutenFreeCommunion::LOCAL_KEYS
        );
    }

    /* ---- A valódi eset ---- */

    /**
     * EZ A JEGY TÁRGYA: a `diet:gluten_free` OSM-címke, tehát a jelzője 1 — akkor is, ha
     * mi írjuk a /edit oldalról. Eddig 0-val írtuk, és ezzel átbillentettük a szinkron
     * által beállított értéket.
     */
    public function testTheDerivedOsmTagStaysAnOsmTag(): void {
        \GlutenFreeCommunion::save($this->churchId, [
            \GlutenFreeCommunion::HOLIDAYS_KEY => 'always',
            \GlutenFreeCommunion::WEEKDAYS_KEY => 'always',
        ]);

        self::assertSame(1, $this->jelzo(\GlutenFreeCommunion::OSM_KEY),
            'a diet:gluten_free OSM-cimke, a jelzoje 1');
    }

    /** A saját névterű kulcsaink viszont maradjanak 0-n. */
    public function testOurOwnKeysStayLocal(): void {
        \GlutenFreeCommunion::save($this->churchId, [
            \GlutenFreeCommunion::HOLIDAYS_KEY => 'always',
            \GlutenFreeCommunion::WEEKDAYS_KEY => 'ask_sacristy',
        ]);

        self::assertSame(0, $this->jelzo(\GlutenFreeCommunion::HOLIDAYS_KEY));
        self::assertSame(0, $this->jelzo(\GlutenFreeCommunion::WEEKDAYS_KEY));
    }

    /**
     * A LÉNYEG: a jelző NE billegjen aszerint, hogy ki írt utoljára.
     *
     * Előbb a szinkron írja (mintha az OSM-ből jött volna), aztán a /edit mentés — a
     * jelzőnek mindkét irányban ugyanannak kell maradnia.
     */
    public function testTheFlagDoesNotFlipFlopBetweenWriters(): void {
        // 1. a szinkron írja
        \Eloquent\Attribute::updateOrCreate(
            ['church_id' => $this->churchId, 'key' => \GlutenFreeCommunion::OSM_KEY],
            ['value' => 'yes', 'fromOSM' => (int) \Eloquent\Attribute::isOsmKey(\GlutenFreeCommunion::OSM_KEY)]
        );
        $szinkronUtan = $this->jelzo(\GlutenFreeCommunion::OSM_KEY);

        // 2. a szerkesztő menti felül
        \GlutenFreeCommunion::save($this->churchId, [
            \GlutenFreeCommunion::HOLIDAYS_KEY => 'at_end',
            \GlutenFreeCommunion::WEEKDAYS_KEY => 'at_end',
        ]);
        $mentesUtan = $this->jelzo(\GlutenFreeCommunion::OSM_KEY);

        self::assertSame($szinkronUtan, $mentesUtan,
            'a jelzo nem fugghet attol, ki irt utoljara');
        self::assertSame(1, $mentesUtan);
    }

    /**
     * ...és ezért a /josm statisztikájában is látszik.
     *
     * Az oldal `fromOSM = 1`-re szűr; a régi kódban a /edit mentés kivette onnan a sort.
     */
    public function testTheTagShowsUpInTheJosmStatistics(): void {
        \GlutenFreeCommunion::save($this->churchId, [
            \GlutenFreeCommunion::HOLIDAYS_KEY => 'always',
            \GlutenFreeCommunion::WEEKDAYS_KEY => 'always',
        ]);

        $latszik = DB::table('attributes')
            ->where('church_id', $this->churchId)
            ->where('key', \GlutenFreeCommunion::OSM_KEY)
            ->where('fromOSM', 1)
            ->exists();

        self::assertTrue($latszik, 'a /josm statisztika fromOSM=1-re szur');
    }
}
