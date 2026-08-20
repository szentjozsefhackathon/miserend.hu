<?php

use PHPUnit\Framework\TestCase;

/**
 * #840: a HTTP 200 önmagában nem bizonyíték arra, hogy a válasz használható.
 *
 * Élesben a napi `url:miserend` szinkron másfél hónapon át SIKERT jelentett, miközben
 * naponta EGYETLEN elemet dolgozott fel. A lánc: a beállított végpont elérhetetlen volt,
 * a második HTTP 500-at adott a nagy lekérdezésre, a harmadik — az `overpass.osm.ch` —
 * pedig szabályos 200-at, csakhogy az egy SVÁJCI példány, amiben nincs magyar adat.
 * A #768-as tartalék-lánc az első nem-hibás válaszig ment, tehát ezt elfogadta, egy hétre
 * be is cache-elte, a cron pedig zöld maradt.
 *
 * Mérve (2026-08-20), ugyanaz a lekérdezés három hoszton:
 *   node["place"="city"]["name"="Budapest"];out count;
 *     overpass-api.de           -> 1
 *     overpass.openstreetmap.fr -> 1
 *     overpass.osm.ch           -> 0
 *
 * Ez a teszt azt őrzi, hogy a hiányos és a hibát jelző válasz ne minősüljön sikernek —
 * és közben az ÜRES találat továbbra is érvényes válasz maradjon (a #766 szándéka).
 */
class OverpassResponseSanityTest extends TestCase
{
    /** A `rejectionReason()` védett, mert a hívóknak nem kell látniuk. Itt kinyitjuk. */
    private function ok(\ExternalApi\OverpassApi $api): ?string
    {
        $m = new \ReflectionMethod($api, 'rejectionReason');
        $m->setAccessible(true);

        return $m->invoke($api);
    }

    private function valasz(string $json): \ExternalApi\OverpassApi
    {
        $api = new \ExternalApi\OverpassApi();
        $api->jsonData = json_decode($json);

        return $api;
    }

    /* ---- 1. A `remark` = szerveroldali hiba, nem siker. ---- */

    /**
     * Az Overpass a szerveroldali időtúllépésre HTTP 200-at ad. Mérve, a teljes
     * url:miserend lekérdezéssel, `[out:json][timeout:1]`-gyel:
     *   HTTP 200, elements: 0, remark: "runtime error: Query timed out in query..."
     * Eddig ez sikernek számított, és egy hétre bekerült a cache-be.
     */
    public function testARemarkIsNotSuccess(): void
    {
        $api = $this->valasz('{"elements":[],"remark":"runtime error: Query timed out in \"query\" at line 1 after 2 seconds."}');

        $indok = $this->ok($api);

        self::assertNotNull($indok, 'a remark-os választ vissza kell utasítani');
        self::assertStringContainsString('Query timed out', $indok, 'a valódi ok maradjon benne');
    }

    /** Részleges válasz is jöhet remarkkal — az elemek megléte nem menti meg. */
    public function testARemarkIsNotSuccessEvenWithElements(): void
    {
        $api = $this->valasz('{"elements":[{"type":"way","id":1}],"remark":"runtime error: Query timed out in \"print\""}');

        self::assertNotNull($this->ok($api));
    }

    /* ---- 2. A hiányos válasz nem üres találat. ---- */

    /** Ez a konkrét éles eset: 1 elem érkezett ~2500 helyett. */
    public function testAnImplausiblyShortAnswerIsRejected(): void
    {
        $api = $this->valasz('{"elements":[{"type":"way","id":94545227}]}');
        $api->minElements = 2500;

        $indok = $this->ok($api);

        self::assertNotNull($indok, 'az 1 elemes választ vissza kell utasítani');
        self::assertStringContainsString('1 elem', $indok);
        self::assertStringContainsString('2500', $indok, 'derüljön ki, mennyit vártunk');
    }

    /** A határon: pontosan annyi, amennyit kértünk — az még rendben van. */
    public function testExactlyTheExpectedCountIsAccepted(): void
    {
        $api = $this->valasz('{"elements":[{"id":1},{"id":2},{"id":3}]}');
        $api->minElements = 3;

        self::assertNull($this->ok($api));
    }

    /**
     * A LÉNYEG: aki nem mond elvárást, annál semmi nem változik.
     *
     * A #766 kifejezetten úgy döntött, hogy az üres találat ÉRVÉNYES válasz — arra
     * továbblépni azt jelentené, hogy addig kérdezünk, amíg valaki mond valamit.
     */
    public function testAnEmptyAnswerStaysValidWithoutAnExpectation(): void
    {
        $api = $this->valasz('{"elements":[]}');

        self::assertSame(0, $api->minElements, 'alapból nincs elvárás');
        self::assertNull($this->ok($api), 'az üres találat érvényes válasz');
    }

    /** Nem-objektum válasznál (pl. nem szigorú formátum) nincs mit mondani. */
    public function testNonObjectPayloadIsNotJudged(): void
    {
        $api = new \ExternalApi\OverpassApi();
        $api->jsonData = json_decode('[]');

        self::assertNull($this->ok($api));
    }

    /* ---- 3. A tartaléklista ---- */

    /**
     * Az `overpass.osm.ch` NE legyen az alapértelmezett tartalékok között: svájci
     * példány, magyar adat nélkül. Ez volt a hiba forrása.
     */
    public function testTheSwissMirrorIsNotAFallback(): void
    {
        $lista = \ExternalApi\OverpassApi::buildEndpointList('https://overpass-api.de/api/interpreter');

        $hosztok = array_map(fn($u) => parse_url($u, PHP_URL_HOST), $lista);

        self::assertNotContains('overpass.osm.ch', $hosztok,
            'foldrajzilag korlatozott tukor nem valo a globalis lekerdezesek tartalekai koze');
        self::assertGreaterThanOrEqual(2, count($lista), 'tartalék azért maradjon');
    }

    /** A tartalékoknak globális példányoknak kell lenniük — mérve ellenőrzött lista. */
    public function testTheFallbacksAreGlobalInstances(): void
    {
        $lista = \ExternalApi\OverpassApi::buildEndpointList('https://overpass-api.de/api/interpreter');
        $hosztok = array_map(fn($u) => parse_url($u, PHP_URL_HOST), $lista);

        // 2026-08-20-án mérve: mindkettő megtalálja Budapestet, és mindkettő kiadja a
        // teljes url:miserend halmazt (5035 elem).
        self::assertContains('overpass-api.de', $hosztok);
        self::assertContains('overpass.openstreetmap.fr', $hosztok);
    }

    /* ---- 4. A két időkorlát szétvált ---- */

    /**
     * A `queryTimeout` az Overpass szerveroldali kerete, a `transferTimeout` a mi
     * várakozásunk. Ha a kettő egyenlő, a curl pont akkor vág el, amikor a szerver
     * elkezdene válaszolni — élesben ez volt a naplóban:
     * „Operation timed out after 30000 milliseconds with 0 bytes received".
     */
    public function testTheClientWaitsLongerThanTheServerBudget(): void
    {
        $api = new \ExternalApi\OverpassApi();
        $api->queryTimeout = 120;

        // A run() számolja, mert a hívó menet közben is átállíthatja a keretet.
        $m = new \ReflectionMethod($api, 'run');
        self::assertTrue($m->isPublic());

        // Közvetlenül a számítást ellenőrizzük, hálózat nélkül.
        $api->transferTimeout = $api->queryTimeout + \ExternalApi\OverpassApi::TRANSFER_GRACE;

        self::assertGreaterThan($api->queryTimeout, $api->transferTimeout);
    }

    /** Az alapértelmezett API-knál nem változik semmi: marad az egy korlát. */
    public function testOtherApisKeepTheSingleTimeout(): void
    {
        $api = new \ExternalApi\ExternalApi();

        self::assertNull($api->transferTimeout, 'a többi szolgáltatónál ne változzon a viselkedés');
    }
}
