<?php

use PHPUnit\Framework\TestCase;

/**
 * #766: az Overpass-végpont MINDENHOL a beállítást kövesse.
 *
 * A #376 óta a PHP oldal a `config['overpass']['apiUrl']`-t használja, és az
 * `OVERPASS_API_URL` env-vel átállítható egy stabil mirrorra. A térkép-sablonok
 * viszont beégetve hívták az `overpass-api.de`-t — ráadásul `http://`-n —, tehát a
 * böngészőből induló lekérdezéseket a mirror-váltás nem érintette. Aki átállította a
 * végpontot, joggal hitte, hogy mindent átállított.
 */
class OverpassEndpointConfigTest extends TestCase
{
    /**
     * A böngészőből Overpasst hívó sablonok.
     *
     * A `_map.twig` (OpenLayers) is itt volt, de kiderült, hogy HALOTT KÓD: egyetlen
     * sablon sem include-olta, mindenhol a `_map_leaflet.twig` fut. Törölve — vele
     * együtt az egyetlen olyan oldalelem is, ami külső, BLOKKOLÓ scriptet töltött
     * (openlayers.org, 770 KB).
     *
     * @return string[]
     */
    private static function sablonok(): array
    {
        return [
            __DIR__ . '/../../templates/church/create.twig',
        ];
    }

    public function testNoTemplateHardcodesTheOverpassHost(): void
    {
        foreach (self::sablonok() as $utvonal) {
            self::assertFileExists($utvonal);
            $tartalom = file_get_contents($utvonal);

            self::assertStringNotContainsString(
                'overpass-api.de',
                $tartalom,
                basename($utvonal) . ': beégetett Overpass-hoszt — használd az {{ overpass_api_url }} globálist.'
            );
        }
    }

    /** A csere nem elég: a sablonoknak tényleg a globálist kell hívniuk. */
    public function testTemplatesUseTheConfiguredEndpoint(): void
    {
        foreach (self::sablonok() as $utvonal) {
            self::assertStringContainsString(
                'overpass_api_url',
                file_get_contents($utvonal),
                basename($utvonal) . ': nem a beállított végpontot hívja.'
            );
        }
    }

    /** A globálist mindkét Twig-környezetnek regisztrálnia kell (l. a DANGER-kommentet). */
    public function testBothTwigEnvironmentsRegisterTheGlobal(): void
    {
        foreach ([__DIR__ . '/../../load.php', __DIR__ . '/../../classes/html/html.php'] as $utvonal) {
            self::assertStringContainsString(
                "addGlobal('overpass_api_url'",
                file_get_contents($utvonal),
                basename($utvonal) . ': hiányzik az overpass_api_url globális.'
            );
        }
    }

    /** A beállítás az env-ből jön, alapértéke a hivatalos végpont. */
    public function testConfigReadsTheEnvironmentVariable(): void
    {
        $config = file_get_contents(__DIR__ . '/../../config.php');

        self::assertMatchesRegularExpression(
            "/env\('OVERPASS_API_URL'/",
            $config,
            'az Overpass-végpontnak env-ből állíthatónak kell lennie'
        );
    }
}
