<?php

use PHPUnit\Framework\TestCase;

/**
 * #766: egyetlen rossz Overpass-tükör ne vigye le a templomoldalt.
 *
 * Az Overpass-tükrök természetüknél fogva ingadoznak: hol túlterheltek, hol
 * karbantartás alatt vannak, hol rate-limitelnek. Eddig a beállított végpont
 * kiesése azt jelentette, hogy a látogató 30 másodperc várakozás után egy
 * „Váratlan hiba történt" üzenetet kapott.
 */
class OverpassFallbackTest extends TestCase
{
    private static function lista(string $primary, ?array $extra = null): array
    {
        return \ExternalApi\OverpassApi::buildEndpointList($primary, $extra);
    }

    /** A beállított végpont MARAD az első — a beállítás nem válik súlytalanná. */
    public function testConfiguredEndpointComesFirst(): void
    {
        $lista = self::lista('https://sajat.example/api/interpreter');

        self::assertSame('https://sajat.example/api/interpreter', $lista[0]);
        self::assertGreaterThan(1, count($lista), 'kell tartalék is');
    }

    /**
     * Hoszt szerinti deduplikálás. Az `overpass.kumi.systems` ma CNAME-mel ugyanarra a
     * gépre mutat, mint az `overpass.private.coffee` — épp ezért nem segített a
     * kettő közti váltogatás. Ugyanazt a hosztot ne vegyük fel kétszer.
     */
    public function testTheSameHostIsNotListedTwice(): void
    {
        $lista = self::lista('https://overpass-api.de/api/interpreter');

        $hosztok = array_map(fn($u) => parse_url($u, PHP_URL_HOST), $lista);
        self::assertSame($hosztok, array_values(array_unique($hosztok)));
        self::assertSame('overpass-api.de', $hosztok[0]);
    }

    /** Eltérő útvonal, azonos hoszt: akkor is egy bejegyzés. */
    public function testSameHostWithDifferentPathIsDeduplicated(): void
    {
        $lista = self::lista(
            'https://pelda.example/api/interpreter',
            ['https://pelda.example/masik/interpreter', 'https://masik.example/api/interpreter']
        );

        self::assertSame(
            ['https://pelda.example/api/interpreter', 'https://masik.example/api/interpreter'],
            $lista
        );
    }

    public function testEmptyEntriesAreIgnored(): void
    {
        $lista = self::lista('https://pelda.example/api/interpreter', ['', '   ', 'nem-url']);

        self::assertSame(['https://pelda.example/api/interpreter'], $lista);
    }

    /** A tartaléklista felülírható — pl. ha valaki csak egyetlen, saját tükröt akar. */
    public function testFallbackListCanBeOverridden(): void
    {
        $lista = self::lista('https://elso.example/api', ['https://masodik.example/api']);

        self::assertSame(['https://elso.example/api', 'https://masodik.example/api'], $lista);
    }

    /* ---------- kapcsolat-időkorlát ---------- */

    /**
     * A kapcsolatfelvételre külön, RÖVID korlát kell. Eddig csak a teljes kérésre volt
     * korlát (30 mp), tehát egy elérhetetlen kiszolgáló a teljes 30 másodpercet elvitte,
     * és addig a látogató oldala ült.
     */
    public function testConnectTimeoutIsMuchShorterThanTheQueryTimeout(): void
    {
        $api = new \ExternalApi\OverpassApi();

        self::assertGreaterThan(0, $api->connectTimeout);
        self::assertLessThan(
            $api->queryTimeout,
            $api->connectTimeout,
            'a kapcsolatfelvétel korlátja nem lehet akkora, mint a teljes kérésé'
        );
    }

    /** A curl tényleg megkapja — enélkül a mező csak dísz lenne. */
    public function testTheConnectTimeoutIsActuallyPassedToCurl(): void
    {
        $forras = file_get_contents(__DIR__ . '/../../classes/externalapi/externalapi.php');

        self::assertStringContainsString(
            'CURLOPT_CONNECTTIMEOUT, $this->connectTimeout',
            $forras
        );
    }
}
