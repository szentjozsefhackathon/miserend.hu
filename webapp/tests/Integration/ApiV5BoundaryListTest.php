<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #56 / #805: az API v5 a teljes adminisztratív határlistát adja.
 *
 * borazslo kérése:
 *
 *   „Ha mi úgysem használunk fix orszag-megye-varos-t akkor nem lenne helyesebb API
 *    v5 templomlekérdezéshez egyszerűen berakni az összes adminisztrációs boundary-t
 *    admin_level sorrendben egy tömbben szépen listázva? Flexibilisebb és hosszabb
 *    távon is működöbb."
 *
 * Igaza van, és pont ez a kivezetés tanulsága: az `admin_level` jelentése országonként
 * MÁS (Romániában nincs 6-os szint, Kölnben a 6-os maga a város), tehát a fix három
 * mező eleve hazugság volt. A lista nem próbál dönteni arról, melyik szint „a megye" —
 * a fogyasztó látja a szintet, és eldönti maga.
 */
class ApiV5BoundaryListTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        /*
         * A `max(templomok.id) + 1` NEM elég: a `lookup_boundary_church` táblában
         * maradhatnak ÁRVA sorok olyan templom-azonosítóra, ami már nem létezik.
         * Ilyenkor a friss fixtúra-templom örökölné az idegen határokat, és a teszt
         * hatot látna kettő helyett — pont ebbe futottam bele.
         */
        $this->churchId = max(
            (int) DB::table('templomok')->max('id'),
            (int) DB::table('lookup_boundary_church')->max('church_id')
        ) + 1;
        $minta['id'] = $this->churchId;
        $minta['nev'] = 'Határlista teszt';
        $minta['ok'] = 'i';
        DB::table('templomok')->insert($minta);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** @param array<int,array{0:int,1:string,2:string}> $szintek [level, név, alt_név] */
    private function hatarlanc(array $szintek): void {
        foreach ($szintek as [$level, $nev, $alt]) {
            $id = DB::table('boundaries')->insertGetId([
                'boundary' => 'administrative', 'admin_level' => $level,
                'name' => $nev, 'alt_name' => $alt,
                'osmtype' => 'relation', 'osmid' => 400000 + $level,
            ]);
            DB::table('lookup_boundary_church')->insert([
                'boundary_id' => $id, 'church_id' => $this->churchId,
            ]);
        }
    }

    private function templom(): \Eloquent\Church {
        return \Eloquent\Church::find($this->churchId);
    }

    public function testAVerzioOtTartalmazzaAHatarlistat(): void {
        $this->hatarlanc([[2, 'Magyarország', ''], [8, 'Szentendre', '']]);

        $valasz = $this->templom()->toAPIArray('normal', false, 5);

        self::assertArrayHasKey('hatarok', $valasz);
        self::assertCount(2, $valasz['hatarok']);
    }

    /** A régebbi verziók borítéka nem változik. */
    public function testARegebbiVerziokNemKapjakMeg(): void {
        $this->hatarlanc([[2, 'Magyarország', '']]);

        self::assertArrayNotHasKey('hatarok', $this->templom()->toAPIArray('normal', false, 4));
        self::assertArrayNotHasKey('hatarok', $this->templom()->toAPIArray('normal', false, 3));
    }

    /** A sorrend a lényeg: admin_level szerint növekvőn. */
    public function testAdminLevelSzerintRendezett(): void {
        $this->hatarlanc([[8, 'Település', ''], [2, 'Ország', ''], [6, 'Megye', '']]);

        $szintek = array_column($this->templom()->toAPIArray('normal', false, 5)['hatarok'], 'szint');

        self::assertSame([2, 6, 8], $szintek);
    }

    /** Az OSM-hivatkozás is kimegy, hogy a fogyasztó vissza tudja keresni. */
    public function testAzOsmHivatkozasKimegy(): void {
        $this->hatarlanc([[2, 'Magyarország', '']]);

        $elso = $this->templom()->toAPIArray('normal', false, 5)['hatarok'][0];

        self::assertSame('relation/400002', $elso['osm']);
    }

    public function testAzAlternativNevIsKimegy(): void {
        $this->hatarlanc([[9, 'XI. kerület', 'Újbuda']]);

        self::assertSame('Újbuda', $this->templom()->toAPIArray('normal', false, 5)['hatarok'][0]['alt_nev']);
    }

    public function testAlternativNevNelkulNull(): void {
        $this->hatarlanc([[2, 'Magyarország', '']]);

        self::assertNull($this->templom()->toAPIArray('normal', false, 5)['hatarok'][0]['alt_nev']);
    }

    /** Határ nélküli templomnál üres lista, nem hiányzó kulcs. */
    public function testHatarNelkulUresLista(): void {
        self::assertSame([], $this->templom()->toAPIArray('normal', false, 5)['hatarok']);
    }

    /**
     * A fix mezőket NEM vesszük el a v5-ben: a boríték kompatibilis marad, és a
     * meglévő kliensek nem esnek szét egy verzióváltáson.
     */
    public function testAFixMezokMegmaradnakAVerzioOtben(): void {
        $this->hatarlanc([[2, 'Magyarország', ''], [8, 'Szentendre', '']]);

        $valasz = $this->templom()->toAPIArray('normal', false, 5);

        self::assertSame('Magyarország', $valasz['orszag']);
        self::assertSame('Szentendre', $valasz['varos']);
    }
}
