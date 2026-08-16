<?php

use PHPUnit\Framework\TestCase;

/**
 * #791: minden külső API lejárt cache-ének takarítása.
 *
 * borazslo kérése a #791-hez:
 *
 *   „a ExternalApi::clearAllOldCache() tényleg jó ötlet bekötni, márha jól van
 *    megírva :D És akkor nem is kell a másik clearOldCache"
 *
 * A „márha jól van megírva" a lényeg: a `clearOldCache()` eddig KIZÁRÓLAG az
 * Overpassra futott, tehát sosem találkozott azzal, ami a többi API-nál előfordul —
 * `cache = false` (Elasticsearch, OpenStreetMap, OSRM) és hiányzó cache-könyvtár.
 * Egyetlen hiba a tízből megállítaná a többi takarítását is.
 */
class ClearAllOldCacheTest extends TestCase {

    private string $konyvtar;

    protected function setUp(): void {
        parent::setUp();
        $this->konyvtar = sys_get_temp_dir() . '/cachetest_' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->konyvtar, 0777, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->konyvtar . '*') as $f) {
            @unlink($f);
        }
        @rmdir($this->konyvtar);
        parent::tearDown();
    }

    /**
     * @param string|false $cache a cache élettartama, vagy false ha nincs cache
     * @param string|null $konyvtar felülírt cache-könyvtár
     */
    private function api($cache, ?string $konyvtar = null): \ExternalApi\ExternalApi {
        $api = new \ExternalApi\ExternalApi();
        $api->name = 'teszt';
        $api->format = 'json';
        $api->cache = $cache;
        $api->cacheDir = $konyvtar ?? $this->konyvtar;
        return $api;
    }

    /** @param int $koraNapokban hány napja készült a fájl */
    private function cacheFajl(string $nev, int $koraNapokban): string {
        $utvonal = $this->konyvtar . $nev;
        file_put_contents($utvonal, '{}');
        touch($utvonal, strtotime("-$koraNapokban days"));
        return $utvonal;
    }

    public function testALejartFajltTorli(): void {
        $regi = $this->cacheFajl('teszt_abc.json', 30);

        self::assertSame(1, $this->api('1 week')->clearOldCache());
        self::assertFileDoesNotExist($regi);
    }

    public function testAFrissFajltMeghagyja(): void {
        $friss = $this->cacheFajl('teszt_abc.json', 1);

        self::assertSame(0, $this->api('1 week')->clearOldCache());
        self::assertFileExists($friss);
    }

    /** Más API fájljához nem nyúlunk. */
    public function testMasApiFajljatNemBantja(): void {
        $mas = $this->cacheFajl('masapi_abc.json', 30);

        $this->api('1 week')->clearOldCache();

        self::assertFileExists($mas);
    }

    /**
     * Az Elasticsearch, az OpenStreetMap és az OSRM `cache = false`-szal fut.
     * Eddig ez csak VÉLETLENÜL volt ártalmatlan: a strtotime('now -' . false)
     * false-ot ad, és a `$filemtime < false` PHP 8-ban hamis.
     */
    public function testCacheNelkuliApinalNemTorolSemmit(): void {
        $fajl = $this->cacheFajl('teszt_abc.json', 300);

        self::assertSame(0, $this->api(false)->clearOldCache());
        self::assertFileExists($fajl);
    }

    /**
     * Hiányzó könyvtárnál a scandir() false-t ad, a `foreach (false)` pedig hibát
     * dobna — és megállítaná a TÖBBI API takarítását is.
     */
    public function testHianyzoKonyvtarnalNemDob(): void {
        $api = $this->api('1 week', '/nincs/ilyen/konyvtar/');

        self::assertSame(0, $api->clearOldCache());
    }

    public function testErtelmezhetetlenCacheErteknelNemTorol(): void {
        $fajl = $this->cacheFajl('teszt_abc.json', 300);

        self::assertSame(0, $this->api('ez nem időtartam')->clearOldCache());
        self::assertFileExists($fajl);
    }

    // ---- az összesítő ---------------------------------------------------------

    /** Mind a tíz API-t végigveszi, és API-nként számol be. */
    public function testMindenApitVegigvesz(): void {
        $eredmeny = \ExternalApi\ExternalApi::clearAllOldCache();

        self::assertNotEmpty($eredmeny);
        foreach (\ExternalApi\ExternalApi::collectExternalApis() as $nev) {
            self::assertArrayHasKey($nev, $eredmeny, "Kimaradt: $nev");
            self::assertIsInt($eredmeny[$nev]);
        }
    }

    // ---- a cron-regisztráció -------------------------------------------------

    /**
     * borazslo a #806-hoz: „Nem SQL-ben kéne bekötni az új cronjobot ami lecseréli a
     * régit, hanem van volt valami cron fájl amiben php alapon gyűjtjük és
     * ellenőrizzük."
     *
     * A forrás a `webapp/fajlok/crons.php` (#638). A régi, egy-API-s sort nem kell
     * külön törölni: a `Cron::pruneRemoved()` kitakarítja, mert kikerült a listából.
     */
    public function testACronRegiszterbenSzerepelAzOsszesitoTakaritas(): void {
        $registry = \Eloquent\Cron::registry();

        $talalat = array_filter($registry, fn($job) =>
            ($job['function'] ?? '') === 'clearAllOldCache');

        self::assertCount(1, $talalat, 'A clearAllOldCache-nek pontosan egyszer kell szerepelnie.');
    }

    /** A leváltott, egy-API-s takarítás nem maradhat a listában. */
    public function testARegiEgyApisTakaritasKikerult(): void {
        $registry = \Eloquent\Cron::registry();

        $talalat = array_filter($registry, fn($job) =>
            ($job['function'] ?? '') === 'clearOldCache');

        self::assertSame([], $talalat,
            'A pruneRemoved() csak akkor takarítja ki a régi sort, ha nincs a listában.');
    }

    /**
     * Egy rossz API ne akadályozza meg a többit. Ez volt a legnagyobb kockázata
     * annak, hogy egyetlen cronra bízzuk mind a tízet.
     */
    public function testEgyHibasApiNemAllitjaMegATobbit(): void {
        $eredmeny = \ExternalApi\ExternalApi::clearAllOldCache();

        // Mind lefutott: a hibásaknál 0 áll, nem hiányzó kulcs.
        self::assertGreaterThanOrEqual(
            count(\ExternalApi\ExternalApi::collectExternalApis()),
            count($eredmeny)
        );
    }
}
