<?php

use PHPUnit\Framework\TestCase;

/**
 * #765: az RRULE `BYDAY` feldolgozása.
 *
 * A hiba: a `SU,MO` alak tömböt adott, a `2SU` viszont sima stringet, a SimpleRRule
 * pedig tömböt vár — a sorszámos alak ezért TypeError-ral megölte az EGÉSZ import
 * futását (templom/281). Ráadásul a régi minta némán veszített adatot: az ismétlődő
 * `(SU|MO|…)*` csoportból csak az utolsó találat maradt meg.
 */
class ByDayParsingTest extends TestCase
{
    private static function parse(string $ertek): ?array
    {
        return \ExternalCalendarImporter::parseByDay($ertek);
    }

    public function testPlainWeekdayList(): void
    {
        self::assertSame(
            ['byweekday' => ['SU', 'MO'], 'bysetpos' => null],
            self::parse('SU,MO')
        );
    }

    public function testSingleWeekday(): void
    {
        self::assertSame(['byweekday' => ['FR'], 'bysetpos' => null], self::parse('FR'));
    }

    /** A jegyben szereplő eset: minden hónap 2. vasárnapja. */
    public function testOrdinalWeekdayYieldsAnArray(): void
    {
        $eredmeny = self::parse('2SU');

        self::assertIsArray($eredmeny['byweekday'], 'a byweekday MINDIG tömb — ezen hasalt el az import');
        self::assertSame(['SU'], $eredmeny['byweekday']);
        self::assertSame(2, $eredmeny['bysetpos']);
    }

    public function testNegativeOrdinal(): void
    {
        self::assertSame(['byweekday' => ['FR'], 'bysetpos' => -1], self::parse('-1FR'));
    }

    public function testExplicitPlusSign(): void
    {
        self::assertSame(['byweekday' => ['WE'], 'bysetpos' => 3], self::parse('+3WE'));
    }

    /** Több nap AZONOS sorszámmal: a napok egyike sem veszhet el. */
    public function testSameOrdinalOnSeveralWeekdaysKeepsEveryDay(): void
    {
        $eredmeny = self::parse('2SU,2MO');

        self::assertSame(['SU', 'MO'], $eredmeny['byweekday'], 'a régi minta a vasárnapot elnyelte');
        self::assertSame(2, $eredmeny['bysetpos']);
    }

    /** Különböző sorszámokat nem tudunk egyetlen bysetpos-ra leképezni — inkább kihagyjuk. */
    public function testDifferentOrdinalsAreRejected(): void
    {
        self::assertNull(self::parse('1SU,3SU'));
    }

    public function testGarbageIsRejected(): void
    {
        self::assertNull(self::parse(''));
        self::assertNull(self::parse('XX'));
        self::assertNull(self::parse('2'));
        self::assertNull(self::parse('SU,,MO'));
        self::assertNull(self::parse('2SUMO'));
    }

    public function testLowercaseIsAccepted(): void
    {
        self::assertSame(['byweekday' => ['SU'], 'bysetpos' => 2], self::parse('2su'));
    }

    /* ---------- a SimpleRRule oldala ---------- */

    /**
     * A már adatbázisban lévő, STRINGES byweekday se hasaljon el: a korábbi futások
     * ilyen sorokat írtak be, és azok különben minden generáláskor újra elszállnának.
     */
    public function testSimpleRRuleAcceptsAStringByWeekday(): void
    {
        $rrule = new \SimpleRRule([
            'freq' => 'monthly',
            'dtstart' => '2026-01-01T09:00:00',
            'until' => '2026-06-30T09:00:00',
            'byweekday' => 'SU',
            'bysetpos' => 2,
        ]);

        self::assertNotEmpty($rrule->getOccurrences(), 'stringes byweekday mellett is kell időpont');
    }

    public function testSimpleRRuleAcceptsAnEmptyByWeekday(): void
    {
        $rrule = new \SimpleRRule([
            'freq' => 'daily',
            'dtstart' => '2026-01-01T09:00:00',
            'count' => 3,
            'byweekday' => '',
        ]);

        self::assertCount(3, $rrule->getOccurrences());
    }
}
