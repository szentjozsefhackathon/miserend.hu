<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #496: a boundary köznyelvi (alternatív) nevén is megtalálható legyen.
 *
 * borazslo iránya szerint a helyre szűkítés boundary-n keresztül megy, nem a
 * templom-dokumentum szöveges mezőin:
 *
 *   „Sosem keresünk már megyében, hanem boundary alapján valamilyen osm entity-ben,
 *    ami éppen megye máskor meg ország, vagy teljesen más."
 *
 * Csakhogy ez a modell eddig féllábon állt: a boundary KIZÁRÓLAG a `name` oszlopán
 * volt megtalálható, a köznyelvi név viszont gyakran az `alt_name`-ben ül. A
 * budapesti V. kerület alt_name-je "Belváros-Lipótváros", a XI.-é "Újbuda" — és
 * ezekre ma nulla találat jön, pedig a látogatók így hívják őket.
 *
 * A tesztek a lekérdezés magját mérik: a keresési feltételt és a rangsort.
 */
class BoundaryAltNameSearchTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** @return int az új boundary azonosítója */
    private function hatar(string $nev, string $altNev = '', int $level = 9): int {
        return DB::table('boundaries')->insertGetId([
            'boundary'    => 'administrative',
            'admin_level' => $level,
            'name'        => $nev,
            'alt_name'    => $altNev,
            'osmtype'     => 'relation',
            'osmid'       => random_int(700000, 799999),
        ]);
    }

    /**
     * Ugyanaz a feltétel, amit az autocompletecombined.php használ.
     *
     * @return array<int,int> a találatok azonosítói, rangsorolt sorrendben
     */
    private function talalatok(string $szoveg): array {
        return \Eloquent\Boundary::where(function ($q) use ($szoveg) {
                $q->where('name', 'like', '%' . $szoveg . '%')
                  ->orWhere('alt_name', 'like', '%' . $szoveg . '%');
            })
            ->orderByRaw(
                "CASE WHEN name = ? OR alt_name = ? THEN 0"
                . " WHEN name LIKE ? OR alt_name LIKE ? THEN 1 ELSE 2 END",
                [$szoveg, $szoveg, $szoveg . '%', $szoveg . '%']
            )
            ->orderBy('id')
            ->pluck('id')->all();
    }

    public function testAKoznyelviNevenIsMegtalalhato(): void {
        $id = $this->hatar('XI. kerület', 'Újbuda');

        self::assertContains($id, $this->talalatok('Újbuda'),
            'Az alt_name nélkül a látogató által használt névre nulla találat jön.');
    }

    public function testAHivatalosNevTovabbraIsMukodik(): void {
        $id = $this->hatar('XI. kerület', 'Újbuda');

        self::assertContains($id, $this->talalatok('XI. kerület'));
    }

    public function testReszletreIsIllik(): void {
        $id = $this->hatar('V. kerület', 'Belváros-Lipótváros');

        self::assertContains($id, $this->talalatok('Lipótváros'));
    }

    /**
     * A #571 rangsora eddig csak a `name`-re nézett. Egy PONTOS alt_name-találat
     * így a 2-es csoportba esett, és kiszorulhatott a take(15) mögé.
     */
    public function testAPontosAlternativTalalatElorekerul(): void {
        // Kitalált név: a seed-adatban létező helynévvel a teszt a valódi sorokba
        // ütközne, és nem azt mérné, amit akar.
        $kereses = 'Tesztvárosrész-Alsó';

        for ($i = 0; $i < 5; $i++) {
            $this->hatar('Zaj ' . $kereses . ' ' . $i);
        }
        $pontos = $this->hatar('XX. kerület', $kereses);

        $talalatok = $this->talalatok($kereses);

        self::assertSame($pontos, $talalatok[0],
            'A pontos alternatív találatnak a lista elején a helye.');
        self::assertGreaterThan(5, count($talalatok), 'A zaj-sorok is legyenek a találatok között.');
    }

    public function testAlternativNevNelkulSemDobHibat(): void {
        $id = $this->hatar('Szentendre', '');

        self::assertContains($id, $this->talalatok('Szentendre'));
    }

    /** Ami sem a névben, sem az alternatívban nincs, az ne jöjjön elő. */
    public function testNemIllozoSzovegreNincsTalalat(): void {
        $id = $this->hatar('Szentendre', 'Sanktandrä');

        self::assertNotContains($id, $this->talalatok('Debrecen'));
    }
}
