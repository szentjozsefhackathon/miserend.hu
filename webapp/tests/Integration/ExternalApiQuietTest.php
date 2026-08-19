<?php

use PHPUnit\Framework\TestCase;

/**
 * Hibakereső üzemmódban a külső API hibája eddig MINDIG kikerült a lapra, teljes
 * verem-kiírással. A stagingen ettől csúfult el egy templomoldal: az Overpass
 * pillanatnyi elérhetetlensége miatt a látogató egy PHP-hívásláncot kapott, pedig a
 * területi adat hiánya nem akadályozza meg abban, hogy megnézze a miserendet.
 *
 * Ahol a sikertelenség VÁRT kimenet és a hívó kezeli is, ott a $quiet elnyomja a
 * kiírást — a hiba maga viszont továbbra is elérhető marad.
 *
 * A kiírás két úton történhet: `debug > 1` esetén közvetlen echo, `debug == 1`
 * esetén lapüzenet. Itt az echo-ágat mérjük, mert az ugyanazon az egy elágazáson
 * dől el, viszont nem függ sem munkamenettől, sem adatbázistól — így minden
 * környezetben ugyanazt jelenti.
 */
final class ExternalApiQuietTest extends TestCase {

    private $originalDebug;

    protected function setUp(): void {
        parent::setUp();

        /*
         * Ez a viselkedés VALÓDI, elhasalt HTTP-híváson mérhető: a runQuery() catch-ága
         * dönti el, kikerül-e a hiba a lapra. Kikapcsolt külső API-knál (#695) a hívás
         * még a próbálkozás előtt visszatér, tehát ez az elágazás el sem érhető — a
         * teszt ilyenkor nem bukhat el, de nem is állíthat semmit.
         */
        if (\ExternalApi\ExternalApi::isOffline()) {
            $this->markTestSkipped('A külső API-k ki vannak kapcsolva (EXTERNAL_APIS_OFFLINE).');
        }

        global $config;
        $this->originalDebug = $config['debug'] ?? 0;
        $config['debug'] = 2;
    }

    protected function tearDown(): void {
        global $config;
        $config['debug'] = $this->originalDebug;
        parent::tearDown();
    }

    /** Elérhetetlen végpont, hogy a hiba biztosan bekövetkezzen. */
    private function unreachableOverpass(): \ExternalApi\OverpassApi {
        $api = new \ExternalApi\OverpassApi();
        $api->apiUrl = 'http://127.0.0.1:9/nincs-itt-semmi';
        /*
         * #824: a TARTALÉK végpontokat is le kell tiltani.
         *
         * A `run()` a `fallbackUrls` listát járja be, ha az nem üres — a konstruktor
         * pedig feltölti a config valódi, publikus tükreivel. Az `apiUrl` felülírása
         * tehát ELVESZETT: a hívás a valódi Overpass-tükrökre ment. Emiatt ez a teszt
         * hálózatfüggő volt (a naplóban „Operation timed out after 2003 ms"), és a
         * teljes futamban hol elbukott, hol nem. A szomszédos
         * `testBoundaryDownloadStaysSilentOnFailure` ezt a configon már kezeli — itt
         * kimaradt.
         */
        $api->fallbackUrls = ['http://127.0.0.1:9/nincs-itt-semmi'];
        $api->cache = false;
        $api->queryTimeout = 2;
        return $api;
    }

    /** @return string amit a hívás a kimenetre írt */
    private function captureOutput(callable $call): string {
        ob_start();
        try {
            $call();
        } finally {
            return (string) ob_get_clean();
        }
    }

    public function testQuietFailureWritesNothing(): void {
        $api = $this->unreachableOverpass();
        $api->quiet = true;

        $output = $this->captureOutput(fn() => $api->downloadEnclosingBoundaries(47.5, 19.05));

        self::assertTrue($api->hasError(), 'a hívásnak el kellett hasalnia');
        self::assertSame('', $output, 'csendes módban semmi nem kerülhet a lapra');
    }

    /* A csendes mód nem nyeli el a hibát, csak nem teszi ki: a hívó lássa. */
    public function testQuietStillRecordsTheError(): void {
        $api = $this->unreachableOverpass();
        $api->quiet = true;

        $this->captureOutput(fn() => $api->downloadEnclosingBoundaries(47.5, 19.05));

        self::assertTrue($api->hasError());
        self::assertNotSame('', $api->getErrorMessage());
    }

    /* Alapértelmezésben marad a régi viselkedés: hibakereső módban kiírjuk. */
    public function testLoudFailureStillWrites(): void {
        $api = $this->unreachableOverpass();

        $output = $this->captureOutput(fn() => $api->downloadEnclosingBoundaries(47.5, 19.05));

        self::assertTrue($api->hasError());
        self::assertNotSame('', $output, 'csendes mód nélkül a hibának ki kell kerülnie');
    }

    /*
     * A területi adatok pótlása ilyen „várt kudarc" hely: false-szal tér vissza, a
     * hívó ezt kezeli — közben semmit nem ír a lapra. Ez a templomoldal esete.
     */
    public function testBoundaryDownloadStaysSilentOnFailure(): void {
        global $config;
        $originalUrl = $config['overpass']['apiUrl'] ?? null;
        $originalFallback = $config['overpass']['fallbackUrls'] ?? null;
        $config['overpass']['apiUrl'] = 'http://127.0.0.1:9/nincs-itt-semmi';
        /*
         * #766: a tartalék végpontok itt KI vannak kapcsolva. Nélküle ez a teszt már nem
         * azt méri, amit a neve mond: az elérhetetlen elsődleges után a valódi, publikus
         * tükrök válaszolnának, tehát nem lenne kudarc, amin csendben lehetne maradni.
         */
        $config['overpass']['fallbackUrls'] = ['http://127.0.0.1:9/ez-sem-letezik'];

        /*
         * Cache nélkül. Az ExternalApi a lekérdezés TARTALMÁBÓL képzi a cache-kulcsot,
         * nem a hosztból — egy korábbi, sikeres futás válasza tehát akkor is kijönne,
         * ha most egyetlen végpont sem elérhető, és a teszt hamis zöldet mutatna.
         * A koordináta is szándékosan egyedi, hogy ne találjon régi bejegyzést.
         */
        try {
            $result = null;
            $output = $this->captureOutput(function () use (&$result) {
                $result = (new \OSM())->downloadBoundaries(47.5001234, 19.0501234);
            });

            // #570/#700: a kudarc jelzése `false` lett, hogy megkülönböztethető legyen
            // a „lefutott, de nincs itt határ" ([]) esettől — csak az utóbbinál szabad
            // a templomot ellenőrzöttnek bélyegezni.
            self::assertFalse($result, 'elérhetetlen Overpassnál false a helyes válasz');
            self::assertSame('', $output, 'a templomoldal nem kaphat hibakiírást');
        } finally {
            if ($originalUrl === null) {
                unset($config['overpass']['apiUrl']);
            } else {
                $config['overpass']['apiUrl'] = $originalUrl;
            }
            if ($originalFallback === null) {
                unset($config['overpass']['fallbackUrls']);
            } else {
                $config['overpass']['fallbackUrls'] = $originalFallback;
            }
        }
    }
}
