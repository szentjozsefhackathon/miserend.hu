<?php

use PHPUnit\Framework\TestCase;

/**
 * #393: az \Api validate* metódusai és a \Request ugyanazt a tudást hordozták kétszer —
 * és el is csúsztak egymástól. A közös \Validate tiszta (se szuperglobális, se DB),
 * ezért itt közvetlenül tesztelhető.
 */
class ValidateTest extends TestCase {

    public function testEgeszSzam(): void {
        $this->assertNull(\Validate::integerError(5));
        $this->assertNull(\Validate::integerError('5'));
        $this->assertNull(\Validate::integerError(0));
        $this->assertNull(\Validate::integerError(-3));

        $this->assertSame('should be an integer.', \Validate::integerError('alma'));
        $this->assertSame('should be an integer.', \Validate::integerError(null));
        $this->assertSame('should be an integer.', \Validate::integerError(1.5));
        $this->assertSame('should be an integer.', \Validate::integerError('1.5'));
        $this->assertSame('should be an integer.', \Validate::integerError([]));
    }

    public function testEgeszSzamHatarok(): void {
        $this->assertNull(\Validate::integerError(10, ['minimum' => 10, 'maximum' => 100]));
        $this->assertNull(\Validate::integerError(100, ['minimum' => 10, 'maximum' => 100]));
        $this->assertSame('should be at least 10.', \Validate::integerError(9, ['minimum' => 10]));
        $this->assertSame('should be at most 100.', \Validate::integerError(101, ['maximum' => 100]));
    }

    public function testTizedestort(): void {
        $this->assertNull(\Validate::floatError(1.5));
        $this->assertNull(\Validate::floatError('1.5'));
        $this->assertNull(\Validate::floatError(2));
        $this->assertSame('should be a float.', \Validate::floatError('alma'));
        $this->assertSame('should be at least 10.5.', \Validate::floatError(10.4, ['minimum' => 10.5]));
        $this->assertSame('should be at most 100.5.', \Validate::floatError(100.6, ['maximum' => 100.5]));
    }

    public function testSzoveg(): void {
        $this->assertNull(\Validate::stringError('alma'));
        $this->assertSame('should be a string.', \Validate::stringError(5));
        $this->assertSame(
            'should be at least 5 characters long.',
            \Validate::stringError('abc', ['minLength' => 5])
        );
        $this->assertSame(
            'should be at most 10 characters long.',
            \Validate::stringError('abcdefghijk', ['maxLength' => 10])
        );
        $this->assertSame(
            'does not match the required pattern.',
            \Validate::stringError('abc', ['pattern' => '^[0-9]+$'])
        );
        $this->assertNull(\Validate::stringError('123', ['pattern' => '^[0-9]+$']));
    }

    public function testLogikai(): void {
        $this->assertNull(\Validate::booleanError(true));
        $this->assertNull(\Validate::booleanError(false));
        $this->assertSame('should be a boolean.', \Validate::booleanError('true'));
        $this->assertSame('should be a boolean.', \Validate::booleanError(1));
    }

    /**
     * A lényeg: a naptárban nem létező nap NEM dátum. Az API korábbi, puszta reguláris
     * kifejezésen alapuló ellenőrzése ezeket mind elfogadta.
     */
    public function testNemLetezoNapokatElutasit(): void {
        $this->assertFalse(\Validate::isDate('2023-02-29'), '2023 nem szökőév.');
        $this->assertFalse(\Validate::isDate('2023-02-31'));
        $this->assertFalse(\Validate::isDate('2026-04-31'), 'Áprilisnak 30 napja van.');
        $this->assertFalse(\Validate::isDate('2026-06-31'));
        $this->assertFalse(\Validate::isDate('2026-11-31'));
    }

    public function testLetezoDatumokatElfogad(): void {
        $this->assertTrue(\Validate::isDate('2026-01-15'));
        $this->assertTrue(\Validate::isDate('2024-02-29'), '2024 szökőév.');
        $this->assertTrue(\Validate::isDate('2026-12-31'));
    }

    public function testRosszAlakuDatumok(): void {
        $this->assertFalse(\Validate::isDate('2026-1-1'));
        $this->assertFalse(\Validate::isDate('2026-13-01'));
        $this->assertFalse(\Validate::isDate('2026-00-10'));
        $this->assertFalse(\Validate::isDate('2026.01.15'));
        $this->assertFalse(\Validate::isDate(''));
        $this->assertFalse(\Validate::isDate(null));
        $this->assertFalse(\Validate::isDate(20260115));
    }

    /**
     * A \Request::validateDateFormat() megmaradt, csak már a közösre mutat — a hívói
     * (Date, DateRequired, DatewDefault) ne törjenek el.
     */
    public function testARequestUgyanaztAzEredmenytAdja(): void {
        foreach (['2026-01-15', '2024-02-29', '2023-02-29', '2023-02-31', '2026-1-1', ''] as $value) {
            $this->assertSame(
                \Validate::isDate($value),
                \Request::validateDateFormat($value),
                "Eltérés a(z) '{$value}' értéknél."
            );
        }
    }
}
