<?php

use PHPUnit\Framework\TestCase;

/**
 * A /health „elastic-misék nélküli misézőhelyek" listáján bukkant elő: az
 * Őrangyalok-kápolna (Nagyatád, #4553) két miséje `2026-01-01TNaN:NaN:NaN` kezdéssel
 * ült az adatbázisban.
 *
 * A naptárszerkesztő mentése ELLENŐRZÉS NÉLKÜL írta ki, amit a frontend küldött, az
 * üresen hagyott időpontból pedig `NaN` lett. Az ilyen mise **némán eltűnik**: az
 * újraindexelő kihagyja („Invalid RRULE dtstart, skipping mass ID …"), tehát a keresőbe
 * soha nem kerül be — a szerkesztőben viszont ott van, a gondnok jogosan hiszi, hogy
 * felvitte.
 */
class MassDateTimeValidationTest extends TestCase {

    /** @dataProvider ervenyesIdopontok */
    public function testValidStartDatesAreAccepted(string $ertek): void {
        $this->assertNull(\Eloquent\CalMass::invalidDateTimeReason(['start_date' => $ertek]));
    }

    public static function ervenyesIdopontok(): array {
        return [
            'dátum és idő' => ['2026-01-01T10:00:00'],
            'szóközzel' => ['2026-01-01 10:00:00'],
            'másodperc nélkül' => ['2026-12-31T23:59'],
            'UTC jelöléssel' => ['2026-08-09T10:00:00Z'],
            'időzóna-eltolással' => ['2026-08-09T12:00:00+02:00'],
            'csak dátum' => ['2026-01-01'],
        ];
    }

    /** @dataProvider ervenytelenIdopontok */
    public function testInvalidStartDatesAreRejected(string $ertek): void {
        $ok = \Eloquent\CalMass::invalidDateTimeReason(['start_date' => $ertek]);
        $this->assertNotNull($ok, "Ezt el kellett volna utasítani: $ertek");
        $this->assertStringContainsString($ertek, $ok, 'A hibaüzenet mondja meg, mi a rossz érték.');
    }

    public static function ervenytelenIdopontok(): array {
        return [
            // Az élesen talált érték.
            'NaN idő' => ['2026-01-01TNaN:NaN:NaN'],
            'NaN óra' => ['2026-01-01TNaN:00:00'],
            'undefined' => ['undefined'],
            'null szöveg' => ['null'],
            'Invalid Date' => ['Invalid Date'],
            'hiányos dátum' => ['2026-01'],
            'fordított sorrend' => ['01/01/2026 10:00'],
        ];
    }

    /** Az ismétlődés dátumai ugyanígy számítanak — a generátor ezeken hasal el. */
    public function testInvalidRruleDtstartIsRejected(): void {
        $ok = \Eloquent\CalMass::invalidDateTimeReason([
            'start_date' => '2026-01-01T10:00:00',
            'rrule' => ['freq' => 'monthly', 'dtstart' => '2026-01-01TNaN:NaN:NaN', 'until' => '2026-12-31T23:59'],
        ]);
        $this->assertNotNull($ok);
        $this->assertStringContainsString('dtstart', $ok);
    }

    public function testInvalidRruleUntilIsRejected(): void {
        $ok = \Eloquent\CalMass::invalidDateTimeReason([
            'start_date' => '2026-01-01T10:00:00',
            'rrule' => ['freq' => 'monthly', 'dtstart' => '2026-01-01T10:00:00', 'until' => 'NaN'],
        ]);
        $this->assertNotNull($ok);
        $this->assertStringContainsString('until', $ok);
    }

    /** A frontend JSON-sztringként is küldheti az rrule-t. */
    public function testRruleAsJsonStringIsAlsoChecked(): void {
        $ok = \Eloquent\CalMass::invalidDateTimeReason([
            'start_date' => '2026-01-01T10:00:00',
            'rrule' => '{"freq":"monthly","dtstart":"2026-01-01TNaN:NaN:NaN"}',
        ]);
        $this->assertNotNull($ok);
    }

    /** Az élesen talált, teljes sor — pont ez nem mehet át többé. */
    public function testTheRowFoundInProductionIsRejected(): void {
        $this->assertNotNull(\Eloquent\CalMass::invalidDateTimeReason([
            'start_date' => '2026-01-01TNaN:NaN:NaN',
            'rrule' => ['freq' => 'monthly', 'until' => '2026-12-31T23:59',
                        'dtstart' => '2026-01-01TNaN:NaN:NaN', 'bysetpos' => 1, 'byweekday' => ['SU']],
            'title' => 'Szentmise',
        ]));
    }

    /** Ismétlődés nélküli, hibátlan mise továbbra is átmegy. */
    public function testAPlainValidMassPasses(): void {
        $this->assertNull(\Eloquent\CalMass::invalidDateTimeReason([
            'start_date' => '2026-03-02T10:00:00',
            'rrule' => null,
            'title' => 'Szentmise',
        ]));
    }
}
