<?php

namespace Tests\Functional;

use Symfony\Component\Panther\Client as PantherClient;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Közös ős a böngészős (Panther) tesztekhez.
 *
 * Azért kell, mert a kliens létrehozása mindenhol IDŐKORLÁT NÉLKÜL történt, és ettől
 * a CI rendszeresen beragadt: a PHPUnit kiírta a bannert, aztán 12 percig semmi, majd
 * a lépés időtúllépéssel elbukott — egyetlen teszteredmény és egyetlen használható
 * hibaüzenet nélkül.
 *
 * Az ok a Pantherben van. A `ChromeManager::getDefaultOptions()` nem ad meg
 * `request_timeout_in_ms`-t, a `start()` pedig így hívja a WebDrivert:
 *
 *     RemoteWebDriver::create($url, $capabilities,
 *         $this->options['connection_timeout_in_ms'] ?? null,
 *         $this->options['request_timeout_in_ms'] ?? null);
 *
 * A php-webdriver a `CURLOPT_TIMEOUT_MS`-t CSAK akkor állítja be, ha kapott értéket —
 * null esetén tehát a kérésnek nincs időkorlátja. Ha a böngésző-munkamenet nyitása
 * elakad, a curl a világ végéig vár.
 *
 * Itt ezért kifejezett korlátokat adunk. Beragadásnál a teszt másodperceken belül,
 * valódi WebDriver-hibaüzenettel bukik el, nem a job felső korlátjánál némán.
 *
 * A `browser` kulcs egyúttal a HELYÉRE kerül: a Panther az ELSŐ paraméterből olvassa
 * (`$options['browser']`), a tesztek viszont a harmadikba (manager-opciók) tették,
 * ahol nem jelent semmit.
 */
abstract class FunctionalTestCase extends PantherTestCase
{
    /** A böngésző-munkamenet nyitása normálisan ezredmásodpercek kérdése. */
    private const CONNECTION_TIMEOUT_MS = 30000;

    /**
     * Egyetlen WebDriver-kérés felső korlátja.
     *
     * Eredetileg 120 másodperc volt, „bőven a leglassabb oldalbetöltés fölött". Csakhogy
     * a teljes suite 80 teszttel együtt 59 másodperc, tehát egyetlen kérés sem közelíti
     * ezt — a 120 másodperc nem tartalékot adott, hanem árat: ha a böngésző-munkamenet
     * beakad, öt-hat ilyen kérés önmagában kimeríti a lépés 12 perces korlátját, és a
     * job úgy hal meg, hogy EGYETLEN teszteredmény sem látszik.
     *
     * 30 másodperc bőven elég a leglassabb oldalra is, viszont egy beakadás így a
     * hatodába kerül: a suite lefut, és megmondja, MELYIK teszt akadt meg.
     */
    private const REQUEST_TIMEOUT_MS = 30000;

    protected static function pantherClient(array $options = []): PantherClient
    {
        return static::createPantherClient(
            array_merge([
                'external_base_uri' => getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000',
                'browser' => static::CHROME,
            ], $options),
            [],
            [
                'connection_timeout_in_ms' => self::CONNECTION_TIMEOUT_MS,
                'request_timeout_in_ms' => self::REQUEST_TIMEOUT_MS,
            ]
        );
    }
}
