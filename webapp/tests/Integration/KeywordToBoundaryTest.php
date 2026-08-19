<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #793: a szabad szöveg boundary-ra fordítása a keresésben.
 *
 * borazslo döntése:
 *
 *   „Sajnos meg kell próbálni a szabad szöveget is boundary-ra fordítani, mert az
 *    emberek gyakran egyszerűek és nem jönnek rá a másik útvonalra. Csak belefér a
 *    pontatlanság és egyebek. Hiszen a beírt szabadszöveg egyszerre lehet a templom
 *    nevének részlete meg egy helyadata is."
 *
 * Eddig a helyre szűkítés KIZÁRÓLAG az autocomplete-választáson keresztül működött:
 * aki beírta, hogy „Újbuda", és entert nyomott, nulla találatot kapott — a
 * templom-dokumentum egyetlen mezőjében sem szerepel ez a szó, csak a boundary
 * alternatív nevében.
 *
 * A „pontatlanság belefér" nem jelenti azt, hogy bármit beengedünk: a zajszűrő azt
 * méri, hogy a beírt szöveg helynévként MEGKÜLÖNBÖZTETŐ-e.
 */
class KeywordToBoundaryTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function hatar(string $nev, string $altNev = '', string $tipus = 'administrative'): int {
        return DB::table('boundaries')->insertGetId([
            'boundary' => $tipus,
            'admin_level' => 8,
            'name' => $nev,
            'alt_name' => $altNev,
            'osmtype' => 'relation',
            'osmid' => random_int(500000, 599999),
        ]);
    }

    /** A cél: a köznyelvi névre is találjunk területet. */
    public function testAKoznyelviNevreIsTalalBoundaryt(): void {
        $id = $this->hatar('Teszt-kerület', 'Tesztújbuda');

        self::assertContains($id, \Search::boundaryIdsForKeyword('Tesztújbuda'));
    }

    public function testAHivatalosNevreIsTalal(): void {
        $id = $this->hatar('Tesztszentendre');

        self::assertContains($id, \Search::boundaryIdsForKeyword('Tesztszentendre'));
    }

    /** Részletre is illeszt, ha az elég megkülönböztető. */
    public function testReszletreIsIllik(): void {
        $id = $this->hatar('Alsótesztfalva');

        self::assertContains($id, \Search::boundaryIdsForKeyword('tesztfalva'));
    }

    // ---- a zajszűrő ---------------------------------------------------------

    /**
     * Ez a lényeg: aki azt írja be, hogy „Szent", az szinte biztosan a templom
     * NEVÉRE gondol. Ha az összes Szent- kezdetű települést beengednénk, minden
     * ottani templom előrébb kerülne.
     *
     * A szabály önszabályozó: ha egy réteg túlcsordul a korláton, nem
     * megkülönböztető, tehát eldobjuk. Nem kell kitalált hosszúság-küszöb.
     */
    public function testATulSokTerulethezIllozoSzovegetEldobja(): void {
        for ($i = 0; $i < 30; $i++) {
            $this->hatar('Zajteszt ' . $i);
        }

        self::assertSame([], \Search::boundaryIdsForKeyword('Zajteszt'),
            'A túlcsorduló réteget el kell dobni, különben minden ottani templom előrébb kerül.');
    }

    /** Néhány találat viszont belefér — ezt engedte meg borazslo. */
    public function testAKevesTalalatBelefer(): void {
        for ($i = 0; $i < 5; $i++) {
            $this->hatar('Kevesteszt ' . $i);
        }

        self::assertCount(5, \Search::boundaryIdsForKeyword('Kevesteszt'));
    }

    /** A pontos egyezés akkor is nyer, ha részletként sok minden illeszkedne. */
    public function testAPontosEgyezesElsobbsegetElvez(): void {
        $pontos = $this->hatar('Rétegteszt');
        for ($i = 0; $i < 30; $i++) {
            $this->hatar('Rétegteszt melléknév ' . $i);
        }

        self::assertSame([$pontos], \Search::boundaryIdsForKeyword('Rétegteszt'));
    }

    public function testTulRovidSzovegreNemKeresunk(): void {
        $this->hatar('AB');

        self::assertSame([], \Search::boundaryIdsForKeyword('AB'));
    }

    public function testNemIllozoSzovegreUresLista(): void {
        self::assertSame([], \Search::boundaryIdsForKeyword('xyzzyplughnincsilyen'));
    }

    /** Csak a keresésre engedélyezett területtípusok jöhetnek szóba. */
    public function testATiltottTeruletTipusKimarad(): void {
        $id = $this->hatar('Tiltottteszt', '', 'protected_area');

        self::assertNotContains($id, \Search::boundaryIdsForKeyword('Tiltottteszt'));
    }

    // ---- a lekérdezésbe kerülés ----------------------------------------------

    /**
     * A boundary-ág `should`, tehát csak BŐVÍTI a találatokat: a kulcsszó
     * továbbra is illeszkedhet a templom nevére.
     */
    public function testABoundaryAgSholdKentKerulALekerdezesbe(): void {
        $this->hatar('Lekerdezesteszt');

        $search = new \Search('churches');
        $search->keyword('Lekerdezesteszt');

        $json = json_encode($search->query);
        self::assertStringContainsString('"should"', $json);
        self::assertStringContainsString('boundaries', $json);
        self::assertStringNotContainsString('"must_not"', str_replace('"must_not":[]', '', $json));
    }

    public function testHelynevNelkuliKulcsszonalNincsBoundaryAg(): void {
        $search = new \Search('churches');
        $search->keyword('xyzzyplughnincsilyen');

        self::assertStringNotContainsString('boundaries', json_encode($search->query));
    }
}
