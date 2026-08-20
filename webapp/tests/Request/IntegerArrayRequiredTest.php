<?php

use PHPUnit\Framework\TestCase;

/**
 * #860: a bemenet-ellenőrzőben ottfelejtett `printr()` visszaechózta a felhasználó
 * nyers bemenetét — a kivétel eldobása ELŐTT.
 *
 * Reprodukálva, bejelentkezés nélkül, sima GET-tel:
 *
 *   GET /ajax/calendar/generate?tids[]=<img src=x onerror=alert(1)>&years[]=2026
 *   -> HTTP 200, Content-Type: text/html
 *      <pre><img src=x onerror=alert(1)></pre>{"error":true,...,"code":400}
 *
 * Három baj egyszerre:
 *   1. reflected XSS, `text/html` válaszban;
 *   2. a JSON-válasz szemét-prefixet kapott, tehát a kliens `JSON.parse`-a elhasalt —
 *      pont a „mindig tiszta JSON hibaválasz" szabályt ütötte ki;
 *   3. a státusz 200 lett 400 helyett, mert a fejléc a kimenettel már elment.
 *
 * Hogy hibakeresés-maradék volt: az iker metódus, az `IntegerArray()` betűre ugyanez,
 * csak `printr` nélkül. Tesztje egyiknek sem volt — a `RequestTest` kizárólag a
 * `printr` nélküli változatot hívta.
 */
final class IntegerArrayRequiredTest extends TestCase
{
    protected function setUp(): void
    {
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_REQUEST = [];
    }

    /** A LÉNYEG: az ellenőrző NE írjon semmit a kimenetre. */
    public function testItPrintsNothingOnInvalidInput(): void
    {
        $_REQUEST['tids'] = ['<img src=x onerror=alert(1)>'];

        ob_start();
        try {
            \Request::IntegerArrayRequired('tids');
            $dobott = false;
        } catch (\Exception $e) {
            $dobott = true;
        }
        $kimenet = ob_get_clean();

        self::assertTrue($dobott, 'a hibas bemenetre kivetelt kell dobni');
        self::assertSame('', $kimenet,
            'a bemenet-ellenorzo NEM irhat a kimenetre: XSS-t okoz, elrontja a JSON-t, '
            . 'es a fejlecet is elkuldi, tehat a statuszkod is rossz lesz');
    }

    /** A felhasználó bemenete a kivétel ÜZENETÉBE se kerüljön bele. */
    public function testTheUserInputIsNotEchoedInTheMessage(): void
    {
        $_REQUEST['tids'] = ['<script>alert(1)</script>'];

        try {
            \Request::IntegerArrayRequired('tids');
            self::fail('kivetelt kellett volna dobnia');
        } catch (\Exception $e) {
            self::assertStringNotContainsString('<script>', $e->getMessage());
        }
    }

    /* A védelem ne rontsa el a rendes működést. */

    public function testItAcceptsAValidArray(): void
    {
        $_REQUEST['tids'] = ['1', '2', '3'];

        self::assertSame(['1', '2', '3'], \Request::IntegerArrayRequired('tids'));
    }

    public function testItRejectsAMissingParameter(): void
    {
        $this->expectException(\Exception::class);

        \Request::IntegerArrayRequired('tids');
    }

    public function testItRejectsANonArray(): void
    {
        $_REQUEST['tids'] = '5';

        $this->expectException(\Exception::class);

        \Request::IntegerArrayRequired('tids');
    }

    /** Az iker metódus is hallgasson — ma is így van, de ne csússzon szét a kettő. */
    public function testTheTwinMethodIsSilentToo(): void
    {
        $_REQUEST['tids'] = ['<img src=x onerror=alert(1)>'];

        ob_start();
        try {
            \Request::IntegerArray('tids');
        } catch (\Exception $e) {
            // várt
        }
        self::assertSame('', ob_get_clean());
    }

    /**
     * A `printr()` maga is escape-eljen.
     *
     * A hívásokat kivettük, de a függvény legyen védett: hibakereséskor az ember pont
     * nem arra figyel, honnan jött az adat.
     */
    public function testThePrintrHelperEscapesHtml(): void
    {
        ob_start();
        printr('<img src=x onerror=alert(1)>');
        $kimenet = ob_get_clean();

        self::assertStringNotContainsString('<img', $kimenet);
        self::assertStringContainsString('&lt;img', $kimenet);
    }
}
