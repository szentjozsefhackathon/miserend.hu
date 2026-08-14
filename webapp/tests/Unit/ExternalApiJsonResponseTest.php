<?php

use PHPUnit\Framework\TestCase;

/**
 * A külső API hibája ne szennyezze a JSON-választ, és a rate-limit ne ragadjon a cache-be.
 *
 * Élő eset: a főoldal egyházmegye-rétege (`ajax/DiocesesInBBox`) Overpassra megy. Amikor
 * az 429-cel felelt, a hibakereső üzemmód a TELJES verem-kiírást a válasz törzsébe
 * echózta — a JSON értelmezhetetlen lett, a látogató pedig fájlútvonalakat és belső
 * hívásláncot látott a főoldalon.
 */
class ExternalApiJsonResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        \ExternalApi\ExternalApi::markJsonResponse(false);
        parent::tearDown();
    }

    public function testJsonResponseIsNotMarkedByDefault(): void
    {
        \ExternalApi\ExternalApi::markJsonResponse(false);
        self::assertFalse(\ExternalApi\ExternalApi::isJsonResponse());
    }

    public function testJsonResponseCanBeMarked(): void
    {
        \ExternalApi\ExternalApi::markJsonResponse(true);
        self::assertTrue(\ExternalApi\ExternalApi::isJsonResponse());
    }

    /** A jelölés globális: minden külső API-példány lássa. */
    public function testTheMarkIsVisibleToEveryInstance(): void
    {
        \ExternalApi\ExternalApi::markJsonResponse(true);

        $overpass = new \ExternalApi\OverpassApi();
        self::assertTrue($overpass::isJsonResponse());
    }

    /**
     * A múló hibák válaszát nem mentjük cache-be. A 429 ugyanolyan múló, mint az
     * 503/504: ha elmentenénk, egyetlen kvótatúllépés a teljes cache-élettartamra
     * beégetné a hibaszöveget a valódi válasz helyére.
     */
    public function testTransientErrorCodesAreNotCached(): void
    {
        $forrás = file_get_contents(__DIR__ . '/../../classes/externalapi/externalapi.php');

        self::assertMatchesRegularExpression(
            '/!in_array\(\$this->responseCode,\s*\[429,\s*503,\s*504\]\)/',
            $forrás,
            'A 429 kikerült a nem-cache-elendő kódok közül.'
        );
    }

    /** Az ajax és az api útvonal jelölje meg magát — enélkül a védelem néma marad. */
    public function testEntryPointMarksJsonRoutes(): void
    {
        $index = file_get_contents(__DIR__ . '/../../index.php');

        self::assertStringContainsString('markJsonResponse(true)', $index);
        self::assertStringContainsString("str_starts_with((string) \$path->url, 'ajax')", $index);
        self::assertStringContainsString("str_starts_with((string) \$path->url, 'api/')", $index);
    }
}
