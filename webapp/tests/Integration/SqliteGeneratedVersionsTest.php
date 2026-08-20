<?php

use PHPUnit\Framework\TestCase;

/**
 * #822: mely SQLite-verziókat építjük újra, és mit mutat róluk a health oldal?
 *
 * A cron ciklusa kézzel beírt `for ($i = 4; $i >= 4; $i--)` volt, a health oldalé
 * `for($i=1;$i<=4;$i++)`. Két külön helyen, két külön számmal — és egyik sem tudott
 * a v5-ről.
 *
 * Következmény: a #56/#778 megírta a v5 mise-tábláját, de a cron soha nem építette
 * meg a v5 fájlt, a health oldal pedig nem is nézte, hogy létezik-e. Aki v5-öt kért,
 * hiányzó vagy elavult fájlt kapott, és ez sehol nem látszott.
 */
class SqliteGeneratedVersionsTest extends TestCase {

    /** A generált verziók a KIADOTT verziókhoz igazodnak, nem kézzel beírt számhoz. */
    public function testALegujabbKiadottVerzioBenneVanAGeneraltakban(): void {
        self::assertContains(
            \Api\Api::LEGUJABB_VERZIO,
            \Api\Sqlite::GENERALT_VERZIOK,
            'A legújabb kiadott verzióhoz kell sqlite fájl, különben a kliens üres kézzel marad.'
        );
    }

    /**
     * A kurrensnek hirdetett verzió (#800: még a v4) sem eshet ki: arra épül a
     * legtöbb meglévő kliens.
     */
    public function testAKurrensVerzioIsGeneralodik(): void {
        self::assertContains(\Api\Api::AJANLOTT_VERZIO, \Api\Sqlite::GENERALT_VERZIOK);
    }

    /**
     * A v1–v3 szándékosan kimarad: befagyasztott formátumok, a fájljuk nem változik.
     * Újragenerálásuk csak a cron futásidejét növelné.
     */
    public function testARegiVerziokatNemGeneraljukUjra(): void {
        foreach ([1, 2, 3] as $regi) {
            self::assertNotContains($regi, \Api\Sqlite::GENERALT_VERZIOK,
                "A v$regi befagyasztott, nem kell újraépíteni.");
        }
    }

    public function testAGeneraltVerziokNemUresek(): void {
        self::assertNotEmpty(\Api\Sqlite::GENERALT_VERZIOK);
    }

    /** A fájlnév a verzióból származik — ezen múlik, hogy a health mit keres. */
    public function testAFajlnevAVerziotKoveti(): void {
        foreach (\Api\Sqlite::GENERALT_VERZIOK as $verzio) {
            $sqlite = new \Api\Sqlite();
            $sqlite->version = $verzio;
            $sqlite->setFileName();

            self::assertSame("miserend_v{$verzio}.sqlite3", $sqlite->sqliteFileName);
        }
    }
}
