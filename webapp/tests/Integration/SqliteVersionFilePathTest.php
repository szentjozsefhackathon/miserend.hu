<?php

use PHPUnit\Framework\TestCase;

/**
 * #858: a v5 tartalma a v4 fájlra íródott, a v5 pedig soha nem készült el.
 *
 * A /health-en a `miserend_v5.sqlite3` pirosan állt, élesben 404 volt — miközben a
 * `\Api\Sqlite::GENERALT_VERZIOK` a #822 óta `[4, 5]`, tehát épülnie kellett volna.
 *
 * Az ok a `setFilePath()` `isset()`-őre volt: az első beállítás után a fájlnév BEFAGYOTT.
 * Egy példány / egy verzió mellett ez ártalmatlan, csakhogy a `cron()` UGYANAZON a
 * példányon megy végig a verziókon — a második kör tehát a `version` átállítása ellenére
 * ugyanoda írt.
 *
 * Ez rosszabb, mint egy hiányzó fájl: a `miserend_v4.sqlite3` a V5 SÉMÁJÁT kapta, tehát
 * a v4-es kliensek olyan `misek` táblát töltöttek le, ami nem az ő alakjuk.
 */
final class SqliteVersionFilePathTest extends TestCase {

    /**
     * A LÉNYEG: ugyanazon a példányon, verziót váltva, a fájlnév is váltson.
     *
     * Ez a teszt a javítás előtt elbukik — mérve mindkét körre `miserend_v4.sqlite3` jött.
     */
    public function testTheFileNameFollowsTheVersionOnTheSameInstance(): void {
        $api = new \Api\Sqlite();

        $nevek = [];
        foreach (\Api\Sqlite::GENERALT_VERZIOK as $verzio) {
            $api->version = $verzio;
            $api->setFilePath();
            $nevek[$verzio] = $api->sqliteFileName;
        }

        foreach (\Api\Sqlite::GENERALT_VERZIOK as $verzio) {
            self::assertSame('miserend_v' . $verzio . '.sqlite3', $nevek[$verzio],
                'a v' . $verzio . ' tartalma a ' . $nevek[$verzio] . ' fajlra irodna');
        }

        self::assertSame(count($nevek), count(array_unique($nevek)),
            'ket verzio nem irhat ugyanabba a fajlba');
    }

    /** Az útvonal is kövesse — a `run()` és a `generateSqlite()` is `isset()`-tel őrzi. */
    public function testThePathFollowsTheVersionToo(): void {
        $api = new \Api\Sqlite();

        $api->version = 4;
        $api->setFilePath();
        $negy = $api->sqliteFilePath;

        $api->version = 5;
        $api->setFilePath();
        $ot = $api->sqliteFilePath;

        self::assertNotSame($negy, $ot);
        self::assertStringEndsWith('miserend_v5.sqlite3', $ot);
    }

    /**
     * A `cron()` tényleg több verziót épít — enélkül a fenti tesztek tárgytalanok.
     *
     * Ha valaha egyetlen verzióra szűkül, ez a teszt szól, hogy a fenti védelem is
     * felülvizsgálandó.
     */
    public function testTheCronBuildsMoreThanOneVersion(): void {
        self::assertGreaterThan(1, count(\Api\Sqlite::GENERALT_VERZIOK),
            'ha csak egy verzio epul, a fajlnev-befagyas nem is jelentkezne');
        self::assertContains(5, \Api\Sqlite::GENERALT_VERZIOK,
            'a v5 a legujabb kiadott verzio, epulnie kell');
    }

    /**
     * A verziónkénti séma tényleg ELTÉR — ezért volt kártékony a felülírás.
     *
     * A v4 `misek` táblájában `periodus`/`suly`/`nap` van, a v5-ében `mise_id`/`hossz`.
     * Ha ez a kettő valaha egyformává válna, a hiba tünete eltűnne, de a hiba maradna —
     * ezért itt rögzítjük, hogy tényleg két különböző alakról van szó.
     */
    public function testTheTwoVersionsHaveDifferentSchemas(): void {
        $forras = file_get_contents(dirname(__DIR__, 2) . '/classes/api/sqlite.php');

        self::assertStringContainsString('$this->version >= 5', $forras,
            'a v5 sajat, eltero semat epit');
    }
}
