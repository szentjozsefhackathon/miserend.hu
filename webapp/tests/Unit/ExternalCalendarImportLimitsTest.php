<?php

use PHPUnit\Framework\TestCase;

/**
 * #756: az importAllExternalCalendars() két zajforrása.
 *
 *  1. A `cal_masses.title` varchar(255); a hosszabb SUMMARY-tól az import elhasalt.
 *     Élő eset a templom/282 gyászmise-bejegyzése (~430 karakter).
 *  2. Az indexelendő évek listája a feed MINDEN `DTSTART`-jából jött, tehát a régi
 *     (akár 1970-es) események évei is — feleslegesen szorozva a generálást.
 */
class ExternalCalendarImportLimitsTest extends TestCase
{
    private static function years(string $ics): array
    {
        $m = new \ReflectionMethod(\ExternalCalendarImporter::class, 'extractIndexedYears');
        $m->setAccessible(true);
        return $m->invoke(null, $ics);
    }

    /* ---------- 1) cím-hossz ---------- */

    public function testShortSummaryIsKeptAsIs(): void
    {
        self::assertSame('Szentmise', \ExternalCalendarImporter::trimSummary('Szentmise'));
    }

    public function testWhitespaceIsNormalised(): void
    {
        self::assertSame('Szentmise a plébánián', \ExternalCalendarImporter::trimSummary("  Szentmise \n a   plébánián  "));
    }

    /** A jegyben szereplő valódi cím. */
    public function testTheRealTooLongTitleFitsTheColumn(): void
    {
        $summary = 'Laczkovich Gézáné Mravik Piroska gyászmiséje és temetése, - Búcsúzik tőle: 3 fia Ádám, '
            . '- György és Géza, - testvére Magdolna és sógora László, - két menye Brigitta és Réka, '
            . '- unokái Csenge, - Domos, - Édua, - Annamária, - Zsuzsanna és Ferenc, - keresztfiai Tamás és László, '
            . '- keresztlánya Edit és az ő családjaik, - rokonok, - barátok és ismerősök';

        $trimmed = \ExternalCalendarImporter::trimSummary($summary);

        self::assertGreaterThan(255, mb_strlen($summary), 'A fixture nem is volt túl hosszú.');
        self::assertLessThanOrEqual(255, mb_strlen($trimmed));
        self::assertStringStartsWith('Laczkovich Gézáné Mravik Piroska gyászmiséje', $trimmed);
        self::assertStringEndsWith('…', $trimmed);
    }

    /** Többájtos vágás: érvényes UTF-8 kell maradjon. */
    public function testCutStaysValidUtf8(): void
    {
        $trimmed = \ExternalCalendarImporter::trimSummary(str_repeat('árvíztűrő ', 60));

        self::assertLessThanOrEqual(255, mb_strlen($trimmed));
        self::assertSame($trimmed, mb_convert_encoding($trimmed, 'UTF-8', 'UTF-8'), 'Csonka többájtos karakter maradt.');
    }

    /** Szóköz nélküli szörnyeteg: vágjunk, de akkor is férjünk bele. */
    public function testSummaryWithoutSpacesIsStillTruncated(): void
    {
        $trimmed = \ExternalCalendarImporter::trimSummary(str_repeat('a', 600));

        self::assertLessThanOrEqual(255, mb_strlen($trimmed));
        self::assertStringEndsWith('…', $trimmed);
    }

    /* ---------- 2) indexelendő évek ---------- */

    private static function ics(array $dtstarts): string
    {
        $lines = ['BEGIN:VCALENDAR'];
        foreach ($dtstarts as $d) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'DTSTART:' . $d;
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines);
    }

    public function testEpochYearIsNotIndexed(): void
    {
        $years = self::years(self::ics(['19700101T000000Z', date('Y') . '0101T090000Z']));

        self::assertNotContains(1970, $years, 'Az 1970-es évet is végiggenerálnánk.');
    }

    public function testOldYearsAreDropped(): void
    {
        $years = self::years(self::ics(['20030101T090000Z', '20140101T090000Z']));

        self::assertNotContains(2003, $years);
        self::assertNotContains(2014, $years);
    }

    /** A keresés három éve mindig benne van, akkor is, ha a feed csak régi dátumokat tartalmaz. */
    public function testTheSearchWindowIsAlwaysPresent(): void
    {
        $now = (int)date('Y');
        $years = self::years(self::ics(['19700101T000000Z']));

        self::assertSame([$now - 1, $now, $now + 1], $years);
    }

    /** Az ablakon belüli jövőbeli év viszont kell. */
    public function testNearFutureYearsAreKept(): void
    {
        $now = (int)date('Y');
        $years = self::years(self::ics([($now + 3) . '0101T090000Z']));

        self::assertContains($now + 3, $years);
    }

    /** Elgépelt évszám ne robbantsa fel a generálást. */
    public function testAbsurdFutureYearIsDropped(): void
    {
        $years = self::years(self::ics(['29990101T090000Z']));

        self::assertNotContains(2999, $years);
    }
}
