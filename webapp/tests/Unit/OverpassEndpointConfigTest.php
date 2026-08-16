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
    /** @return string[] */
    private static function sablonok(): array
    {
        return [
            __DIR__ . '/../../templates/_map.twig',
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

    /**
     * A globálisnak tényleg ott kell lennie a felépített környezetben.
     *
     * Ez eddig a két fájl FORRÁSSZÖVEGÉBEN kereste az `addGlobal` hívást, mert a
     * Twig-környezet két teljes másolatban élt (load.php és Html::loadTwig), mindkettőn
     * a DANGER-figyelmeztetéssel. Azóta egyetlen gyár építi mindkettőt, tehát a szöveges
     * keresés a lényeget vesztette — a lényeg viszont maradt: a globális legyen ott.
     */
    public function testTheBuiltEnvironmentRegistersTheGlobal(): void
    {
        global $config;
        $eredeti = $config['overpass']['apiUrl'] ?? null;
        // Kifejezett érték, mert a beállítás környezetenként más — kikapcsolt külső
        // API-knál (#695) üres is lehet, és akkor az üresség a HELYES kimenet.
        $config['overpass']['apiUrl'] = 'https://teszt.example.com/api/interpreter';

        try {
            $globals = buildTwigEnvironment()->getGlobals();

            self::assertArrayHasKey('overpass_api_url', $globals,
                'hiányzik az overpass_api_url globális');
            self::assertSame('https://teszt.example.com/api/interpreter',
                $globals['overpass_api_url'],
                'a globálisnak a beállított végpontot kell hoznia');
        } finally {
            if ($eredeti === null) {
                unset($config['overpass']['apiUrl']);
            } else {
                $config['overpass']['apiUrl'] = $eredeti;
            }
        }
    }

    /**
     * És egyik hívó se építsen sajátot: pont a másolatok szétcsúszása volt a baj.
     */
    public function testNoCallSiteBuildsItsOwnEnvironment(): void
    {
        foreach ([__DIR__ . '/../../load.php', __DIR__ . '/../../classes/html/html.php'] as $utvonal) {
            $tartalom = file_get_contents($utvonal);

            self::assertStringNotContainsString('new \\Twig\\Environment', $tartalom,
                basename($utvonal) . ': saját Twig-környezetet épít — használd a buildTwigEnvironment()-et.');
            self::assertStringContainsString('buildTwigEnvironment(', $tartalom,
                basename($utvonal) . ': nem a közös gyárból veszi a Twig-környezetet.');
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
