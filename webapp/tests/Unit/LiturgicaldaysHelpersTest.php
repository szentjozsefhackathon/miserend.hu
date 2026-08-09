<?php

use PHPUnit\Framework\TestCase;

/**
 * #374: A Liturgicaldays privát segédfüggvényeinek lefedése + egy valódi UTF-8 bug
 * regressziós zárja.
 *
 * A getShortName() korábban byte-alapú substr()-t használt, ami félbevághat egy
 * többbájtos UTF-8 karaktert -> érvénytelen UTF-8 -> json_encode FALSE -> az EGÉSZ
 * liturgikus-nap endpoint üres választ ad. A javítás mb_substr; a teszt lezárja,
 * hogy a kimenet json-kódolható maradjon.
 *
 * Mindkét metódus privát, és a konstruktor hálózati I/O-t végez, ezért
 * newInstanceWithoutConstructor + Reflection.
 */
class LiturgicaldaysHelpersTest extends TestCase
{
    private function invoke(string $method, $arg)
    {
        $obj = (new \ReflectionClass(\Html\Ajax\Calendar\Liturgicaldays::class))
            ->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(\Html\Ajax\Calendar\Liturgicaldays::class, $method);
        $m->setAccessible(true);
        return $m->invoke($obj, $arg);
    }

    // ─── getShortName() ─────────────────────────────────────────────────────

    public function testShortNameKeepsMultibyteCharIntact(): void
    {
        $short = $this->invoke('getShortName', 'aaaÁrpád');
        $this->assertSame('aaaÁ.', $short);
        // Regressziós zár: a kimenet legyen json-kódolható (a bug FALSE-t adott).
        $this->assertNotFalse(json_encode(['short' => $short]));
    }

    public function testShortNameAccentedFourChars(): void
    {
        $this->assertSame('Évkö.', $this->invoke('getShortName', 'Évközi idő'));
    }

    public function testShortNameMapHitReturnsMapped(): void
    {
        // 'Pünkösd' a rövidítés-térképben van -> 'Pünk' (nem az első 4 karakter).
        $this->assertSame('Pünk', $this->invoke('getShortName', 'Pünkösd'));
    }

    public function testShortNameEmptyReturnsEmpty(): void
    {
        $this->assertSame('', $this->invoke('getShortName', ''));
    }

    // ─── isValidIso8601() (format-only) ─────────────────────────────────────

    public function testIsValidIso8601AcceptsFullDateTime(): void
    {
        $this->assertTrue($this->invoke('isValidIso8601', '2026-05-17T00:00:00'));
    }

    public function testIsValidIso8601RejectsDateWithoutTime(): void
    {
        $this->assertFalse($this->invoke('isValidIso8601', '2026-05-17'));
    }

    public function testIsValidIso8601RejectsSingleDigitParts(): void
    {
        $this->assertFalse($this->invoke('isValidIso8601', '2026-5-7T00:00:00'));
    }

    /**
     * Dokumentálja, hogy CSAK formátumot ellenőriz (nem naptár-/tartomány-tudatos):
     * a 2026-13-45T99:99:99 formátumilag illeszkedik, ezért true.
     */
    public function testIsValidIso8601IsFormatOnly(): void
    {
        $this->assertTrue($this->invoke('isValidIso8601', '2026-13-45T99:99:99'));
    }
}
