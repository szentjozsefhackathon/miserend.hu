<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #496 / #497 / #498: a helynevek az OSM-határokból, a régi oszlopok helyett.
 *
 * A három jegy a `templomok.varos`, `.megye` és `.orszag` kivezetéséről szól. Ahhoz,
 * hogy az oszlopok eldobhatók legyenek, előbb minden fogyasztónak ezeken a
 * metódusokon kell keresztülmennie.
 *
 * A legfontosabb elvárás, hogy a látogató NE vegye észre a váltást: a származtatott
 * érték adja vissza ugyanazt, amit a régi oszlop. Az `admin_level` jelentése viszont
 * országonként más, ezért itt valódi, mért határláncokkal dolgozunk.
 */
class LocationNamesFromBoundariesTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        // Az árva `lookup_boundary_church` sorok miatt nem elég a templomok maximuma:
        // egy régen törölt templom azonosítójára ütköznénk rá, és a teszttemplom
        // ÖRÖKÖLNÉ annak határláncát — nálam így lett a magyar templomból „Deutschland".
        $this->churchId = max(
            (int) DB::table('templomok')->max('id'),
            (int) DB::table('lookup_boundary_church')->max('church_id')
        ) + 1;
        $minta['id'] = $this->churchId;
        $minta['nev'] = 'Helynév Teszt';
        $minta['ok'] = 'i';
        DB::table('templomok')->insert($minta);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** @param array<int,array{0:int,1:string}> $szintek [admin_level, név] párok */
    private function hatarlanc(array $szintek, string $iso = ''): void {
        foreach ($szintek as [$level, $nev]) {
            $id = DB::table('boundaries')->insertGetId([
                'boundary'    => 'administrative',
                'admin_level' => $level,
                'name'        => $nev,
                'alt_name'    => '',
                'iso3166_1'   => $level === 2 ? $iso : '',
                'osmtype'     => 'relation',
                'osmid'       => 800000 + $level,
            ]);
            DB::table('lookup_boundary_church')->insert([
                'boundary_id' => $id,
                'church_id'   => $this->churchId,
            ]);
        }
    }

    private function templom(): \Eloquent\Church {
        return \Eloquent\Church::find($this->churchId);
    }

    /**
     * Budapest: a régi oszlopban "Budapest XI. kerület" áll. A legspecifikusabb
     * határ "Szentimreváros" lenne — pontosabb, de a látogatónak visszalépés.
     */
    public function testBudapestiTemplomnalATelepulesEsAKeruletEgyutt(): void {
        $this->hatarlanc([
            [2, 'Magyarország'], [6, 'Budapest'], [8, 'Budapest'],
            [9, 'XI. kerület'], [10, 'Szentimreváros'],
        ], 'HU');

        self::assertSame('Budapest XI. kerület', $this->templom()->locationCityName());
    }

    public function testKeruletNelkuliTelepulesnelCsakATelepules(): void {
        $this->hatarlanc([[2, 'Magyarország'], [6, 'Pest'], [8, 'Szentendre']], 'HU');

        self::assertSame('Szentendre', $this->templom()->locationCityName());
    }

    /**
     * Köln kreisfreie Stadt: NINCS 8-as szintje, a település a 6-oson ül. Ha vakon a
     * 9-esre esnénk vissza, "Innenstadt" jönne ki "Köln" helyett.
     */
    public function testKreisfreieStadtnalAHatosSzintATelepules(): void {
        $this->hatarlanc([
            [2, 'Deutschland'], [4, 'Nordrhein-Westfalen'], [5, 'Regierungsbezirk Köln'],
            [6, 'Köln'], [9, 'Innenstadt'], [10, 'Altstadt-Nord'],
        ], 'DE');

        self::assertSame('Köln', $this->templom()->locationCityName(),
            'A kerületet csak 8-as szintű település mellé szabad fűzni.');
    }

    /** Romániában nincs 6-os szint: a megyét (judet) a 4-es hordozza. */
    public function testRomanTemplomnalANegyesSzintAMegye(): void {
        $this->hatarlanc([[2, 'România'], [4, 'Alba'], [8, 'Vințu de Jos']], 'RO');

        $templom = $this->templom();
        self::assertSame('Alba', $templom->locationCountyName());
        self::assertSame('Vințu de Jos', $templom->locationCityName());
    }

    public function testMagyarTemplomnalAHatosSzintAMegye(): void {
        $this->hatarlanc([[2, 'Magyarország'], [4, 'Közép-Magyarország'], [6, 'Pest'], [8, 'Szentendre']], 'HU');

        self::assertSame('Pest', $this->templom()->locationCountyName(),
            'Ahol van 6-os szint, ott a 4-es csak statisztikai nagyrégió.');
    }

    public function testAzOrszagnevAKettesSzintrolJon(): void {
        $this->hatarlanc([[2, 'Magyarország'], [8, 'Szentendre']], 'HU');

        self::assertSame('Magyarország', $this->templom()->locationCountryName());
    }

    // ---- határ nélkül --------------------------------------------------------

    /**
     * A régi oszlopokra való visszaesés MEGSZŰNT: az oszlopok nincsenek többé.
     *
     * Ez tudatos veszteség, és pont ezért előzte meg három lépés: a 47 koordináta
     * nélküli templom helyadata a megjegyzés mezőbe került (cron 496), a határ nélkül
     * maradtak havonta újrapróbálkoznak (cron 497), és a /health kiírja, hányan
     * vannak. Üres string az őszinte válasz — a régi oszlop csendes visszaszivárgása
     * elrejtené, hogy a szinkron nem ért oda.
     */
    public function testHatarNelkulUresATelepules(): void {
        self::assertSame('', $this->templom()->locationCityName());
    }

    public function testCsakOrszaghatarralATelepulesUres(): void {
        $this->hatarlanc([[2, 'Magyarország']], 'HU');

        $templom = $this->templom();
        self::assertSame('Magyarország', $templom->locationCountryName());
        self::assertSame('', $templom->locationCityName());
    }

    // ---- országhoz kötött logika --------------------------------------------

    public function testAMagyarorszagiTemplomFelismereseAzIsoKodbol(): void {
        $this->hatarlanc([[2, 'Magyarország'], [8, 'Szentendre']], 'HU');

        self::assertTrue($this->templom()->isInHungary());
    }

    public function testAKulfoldiTemplomNemMagyarorszagi(): void {
        $this->hatarlanc([[2, 'Deutschland'], [6, 'Köln']], 'DE');

        self::assertFalse($this->templom()->isInHungary());
    }

    /**
     * Az ISO ma 7964 határból egynél van kitöltve, tehát a névre illesztésnek is
     * mennie kell — enélkül a statisztika üresen maradna.
     */
    public function testAStatisztikaSzurojeNevreIsIllik(): void {
        $this->hatarlanc([[2, 'Magyarország'], [8, 'Szentendre']]);

        $talalat = \Eloquent\Church::inHungary()->where('templomok.id', $this->churchId)->count();

        self::assertSame(1, $talalat, 'ISO-kód nélkül, puszta névre is találnia kell.');
    }

    public function testAStatisztikaSzurojeNemHozKulfoldit(): void {
        $this->hatarlanc([[2, 'Deutschland'], [6, 'Köln']], 'DE');

        self::assertSame(0, \Eloquent\Church::inHungary()->where('templomok.id', $this->churchId)->count());
    }

    // ---- ami az API-ba kikerül -----------------------------------------------

    /** A publikus API mezőnevei nem változnak, csak a forrásuk. */
    public function testAzApiValaszaAszarmaztatottNeveketAdja(): void {
        $this->hatarlanc([[2, 'Magyarország'], [6, 'Pest'], [8, 'Szentendre']], 'HU');

        // A `megye` csak a bővebb válaszban szerepel; a minimal a /nearby alapja.
        $tomb = $this->templom()->toAPIArray('normal');

        self::assertSame('Magyarország', $tomb['orszag']);
        self::assertSame('Pest', $tomb['megye']);
        self::assertSame('Szentendre', $tomb['varos']);
    }

    /** A /nearby minimal válasza is a származtatott neveket adja. */
    public function testAMinimalValaszIsSzarmaztatott(): void {
        $this->hatarlanc([[2, 'Magyarország'], [8, 'Szentendre']], 'HU');

        $tomb = $this->templom()->toAPIArray('minimal');

        self::assertSame('Magyarország', $tomb['orszag']);
        self::assertSame('Szentendre', $tomb['varos']);
    }
}
