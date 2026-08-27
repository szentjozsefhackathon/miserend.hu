<?php

use PHPUnit\Framework\TestCase;

/**
 * #873: a CSRF-őr viselkedése.
 *
 * Amit itt rögzítek, az nem implementációs részlet, hanem a védelem ígérete:
 * ami állapotot változtat, az CSAK POST-tal és CSAK érvényes tokennel indulhat el.
 */
final class CsrfTest extends TestCase {

    protected function setUp(): void {
        \Csrf::reset();
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER[\Csrf::HEADER]);
    }

    protected function tearDown(): void {
        \Csrf::reset();
        $_POST = [];
        unset($_SERVER[\Csrf::HEADER]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /** Egy kérésen belül ugyanaz a token — különben az űrlap és az ellenőrzés elbeszélne egymás mellett. */
    public function testTheTokenIsStableWithinOneRequest(): void {
        $elso = \Csrf::token();
        self::assertSame($elso, \Csrf::token());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $elso);
    }

    /** Új látogató = új token. Enélkül egyetlen kitalált érték mindenkinél működne. */
    public function testANewVisitorGetsADifferentToken(): void {
        $elso = \Csrf::token();
        \Csrf::reset();
        self::assertNotSame($elso, \Csrf::token());
    }

    /** A lapra kitett érték NEM a süti értéke — ha kiszivárog, a süti akkor sem derül ki. */
    public function testTheTokenIsNotTheCookieValue(): void {
        $token = \Csrf::token();
        self::assertNotSame($_COOKIE[\Csrf::COOKIE] ?? '', $token);
    }

    public function testWithoutATokenTheRequestIsInvalid(): void {
        \Csrf::token();
        self::assertFalse(\Csrf::valid());
    }

    public function testAWrongTokenIsInvalid(): void {
        \Csrf::token();
        $_POST[\Csrf::FIELD] = str_repeat('a', 64);
        self::assertFalse(\Csrf::valid());
    }

    public function testTheRightTokenIsValid(): void {
        $_POST[\Csrf::FIELD] = \Csrf::token();
        self::assertTrue(\Csrf::valid());
    }

    /** Az ajax-hívások fejlécben küldik — ott is el kell fogadni. */
    public function testTheTokenIsAcceptedFromTheHeaderToo(): void {
        $_SERVER[\Csrf::HEADER] = \Csrf::token();
        self::assertTrue(\Csrf::valid());
    }

    /** A LÉNYEG: GET-tel semmi nem indítható, akkor sem, ha a token stimmel. */
    public function testGetIsRejectedEvenWithAValidToken(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST[\Csrf::FIELD] = \Csrf::token();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('csak POST');
        \Csrf::guard();
    }

    public function testPostWithoutATokenIsRejected(): void {
        \Csrf::token();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('biztonsági token');
        \Csrf::guard();
    }

    public function testPostWithAValidTokenPassesThrough(): void {
        $_POST[\Csrf::FIELD] = \Csrf::token();

        \Csrf::guard();
        self::assertTrue(true, 'nem dobott kivetelt');
    }

    /** A rejtett mező kész HTML, és a token benne escape-elve szerepel. */
    public function testTheHiddenFieldContainsTheToken(): void {
        $mezo = \Csrf::field();
        self::assertStringContainsString('name="' . \Csrf::FIELD . '"', $mezo);
        self::assertStringContainsString(\Csrf::token(), $mezo);
        self::assertStringStartsWith('<input type="hidden"', $mezo);
    }
}
