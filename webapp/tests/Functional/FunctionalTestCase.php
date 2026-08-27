<?php

namespace Tests\Functional;

use Facebook\WebDriver\Exception\PhpWebDriverExceptionInterface;
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

    /**
     * Hányszor próbáljuk meg elindítani a böngészőt.
     *
     * A 2026-08-27-i master-futásban a suite ELSŐ munkamenete hasalt el
     * `Operation timed out after 30002 milliseconds with 0 bytes received`-del, a
     * mögötte lévő 79 teszt viszont hibátlanul lefutott. Vagyis nem a korlát szűk és nem
     * az alkalmazás lassú: a chromedriver hidegindítása csúszott át a 30 másodpercen a
     * futtatón.
     *
     * A korlát emelése rossz válasz lenne — pont azért 30 másodperc, hogy egy VALÓDI
     * beakadás gyorsan és beszédesen bukjon el (l. a REQUEST_TIMEOUT_MS indoklását).
     * Egyetlen újrapróbálkozás viszont pont a hidegindítást fedi le: a legrosszabb eset
     * 60 másodperc, ami még mindig töredéke a lépés korlátjának.
     */
    private const INDITASI_PROBALKOZASOK = 2;

    protected static function pantherClient(array $options = []): PantherClient
    {
        $utolsoHiba = null;

        for ($probalkozas = 1; $probalkozas <= self::INDITASI_PROBALKOZASOK; $probalkozas++) {
            try {
                return static::klienstIndit($options);
            } catch (\Throwable $hiba) {
                // Csak a WebDriver felől jövő indulási hibát próbáljuk újra. Bármi más
                // (rossz opció, hiányzó bináris) azonnal szálljon el — azon az
                // újrapróbálkozás nem segít, csak elfedi.
                if (!$hiba instanceof PhpWebDriverExceptionInterface) {
                    throw $hiba;
                }

                $utolsoHiba = $hiba;

                // Látszódjon a naplóban: ha ez sűrűsödik, a futtatóval van baj, és azt
                // egy néma újrapróbálkozás elrejtené.
                fwrite(STDERR, sprintf(
                    "\n[panther] a böngésző indítása nem sikerült (%d/%d): %s\n",
                    $probalkozas,
                    self::INDITASI_PROBALKOZASOK,
                    explode("\n", $hiba->getMessage())[0]
                ));

                if ($probalkozas < self::INDITASI_PROBALKOZASOK) {
                    // Egy másodperc, hogy a félbemaradt chromedriver elengedje a portját.
                    sleep(1);
                }
            }
        }

        throw $utolsoHiba;
    }

    private static function klienstIndit(array $options): PantherClient
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
                /*
                 * A betöltés ne várja meg a képeket és a többi alerőforrást.
                 *
                 * Az alapértelmezett `normal` stratégiánál a WebDriver a `load` eseményig
                 * vár, abba pedig minden `<img>` beleszámít — a templomok LEÍRÁSA viszont
                 * szabad HTML, amibe a szerkesztők idegen kiszolgálókról ágyaznak be
                 * képeket. A #2474-es templom leírásában például hat kép mutat a
                 * `rakosliget.plebania.hu`-ra, sima HTTP-n. Ha az a szerver épp lassú a
                 * futtatóról, a lap sosem készül el, és a teszt időtúllépéssel bukik —
                 * miközben az alkalmazás a HTML-t 200-zal, azonnal kiszolgálta.
                 *
                 * Ez adta a korábbi néma beakadásokat is: a szerver rendben válaszolt, a
                 * böngésző viszont egy harmadik fél képére várt. `eager` mellett a
                 * betöltés a DOMContentLoaded-nél tér vissza. A tesztjeink a DOM
                 * tartalmát mérik, és ahol tényleg megjelenésre várnak, ott úgyis
                 * kifejezett `waitFor()` áll.
                 */
                'capabilities' => [
                    'pageLoadStrategy' => 'eager',
                ],
            ]
        );
    }
}
