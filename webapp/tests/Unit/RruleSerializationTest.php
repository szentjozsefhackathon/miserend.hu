<?php

use PHPUnit\Framework\TestCase;

/**
 * #800: az ismétlődési szabály szöveges alakja.
 *
 * Ez eddig a `Html\Church\Ical` privát metódusa volt. Az sqlite-export (API v5)
 * ugyanezt igényli — borazslo kifejezetten az iCal formátumát kérte referenciának —,
 * ezért közösbe került. A tesztek azt rögzítik, hogy a két kimenet nem tud
 * szétcsúszni: ugyanaz a szabály ugyanazt a sztringet adja mindkét helyen.
 */
class RruleSerializationTest extends TestCase {

    public function testHetiSzabaly(): void {
        $szabaly = ['freq' => 'weekly', 'byweekday' => ['MO', 'WE']];

        self::assertSame('FREQ=WEEKLY;BYDAY=MO,WE', \SimpleRRule::toRfcString($szabaly));
    }

    /** A `byweekday` a mi nevünk, az RFC 5545-é `BYDAY`. */
    public function testAByweekdayBydayraFordul(): void {
        self::assertStringContainsString('BYDAY=', \SimpleRRule::toRfcString(['freq' => 'weekly', 'byweekday' => ['SU']]));
        self::assertStringNotContainsString('BYWEEKDAY', \SimpleRRule::toRfcString(['freq' => 'weekly', 'byweekday' => ['SU']]));
    }

    /** A dtstart nem a szabály része, hanem az esemény kezdete. */
    public function testADtstartNemKerulBele(): void {
        $szoveg = \SimpleRRule::toRfcString(['freq' => 'weekly', 'dtstart' => '2026-11-29T07:00:00+01:00']);

        self::assertSame('FREQ=WEEKLY', $szoveg);
    }

    public function testAzUntilUtcbenMegy(): void {
        $szoveg = \SimpleRRule::toRfcString(['freq' => 'weekly', 'until' => '2026-12-24T23:59:59+01:00']);

        self::assertSame('FREQ=WEEKLY;UNTIL=20261224T225959Z', $szoveg,
            'Az UNTIL-nek UTC-ben, Z-vel zárva kell mennie.');
    }

    public function testAzExdateNemKerulAzRruleBe(): void {
        $szoveg = \SimpleRRule::toRfcString(['freq' => 'weekly', 'exdate' => ['2026-12-08']]);

        self::assertSame('FREQ=WEEKLY', $szoveg,
            'A kivétel külön mező — a szabályba keverve értelmezhetetlen lenne.');
    }

    public function testUresSzabaly(): void {
        self::assertSame('', \SimpleRRule::toRfcString([]));
        self::assertSame('', \SimpleRRule::toRfcString(null));
    }

    // ---- kivételek -----------------------------------------------------------

    /** borazslo kérése: "az exdates azok konkrét dátumok". */
    public function testAzExdatekKonkretDatumok(): void {
        $datumok = \SimpleRRule::exdates(['exdate' => ['2026-12-08T07:00:00+01:00', '2026-12-01']]);

        self::assertSame(['2026-12-01', '2026-12-08'], $datumok);
    }

    public function testAzExdatekRendezettekEsEgyediek(): void {
        $datumok = \SimpleRRule::exdates(['exdate' => ['2026-12-08', '2026-12-01', '2026-12-08']]);

        self::assertSame(['2026-12-01', '2026-12-08'], $datumok);
    }

    public function testExdateNelkulUresLista(): void {
        self::assertSame([], \SimpleRRule::exdates(['freq' => 'weekly']));
        self::assertSame([], \SimpleRRule::exdates(null));
    }

    /** Értelmezhetetlen dátumot inkább kihagyunk, mint hogy szemetet exportáljunk. */
    public function testAzErtelmezhetetlenDatumKimarad(): void {
        $datumok = \SimpleRRule::exdates(['exdate' => ['2026-12-08', 'nem dátum']]);

        self::assertSame(['2026-12-08'], $datumok);
    }
}
