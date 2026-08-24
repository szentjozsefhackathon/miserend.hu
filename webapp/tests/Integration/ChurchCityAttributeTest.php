<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #881: a település a felületen.
 *
 * A #496/#497/#498 eldobta a `templomok.varos` oszlopot, és a helyére a határokból
 * számolt `locationCityName()` lépett. Az API-tömbök átálltak rá — a FELÜLET viszont
 * nem: tizenhét sablon hivatkozik `church.varos`-ra. Oszlop és accessor híján az
 * Eloquent üres értéket adott, és a település eltűnt a listákból:
 *
 *   /templom/list        ->  <strong>Szent Anna-templom</strong> ()
 *   /egyhazmegye/list    ->  a „város" oszlop üres
 *   /home                ->  a javaslat-lista templomneve mögött üres zárójel
 *   mise-keresés         ->  ugyanaz
 *
 * Ez a teszt a `varos` HÁROM útját fogja: az accessort, a `toArray()`-t (a
 * kereső-találatok azt adják a sablonnak), és a lekérdezésszámot — mert egy
 * listaoldalon soronkénti lekérdezés-lavina ugyanolyan hiba lenne, mint az üres érték.
 */
final class ChurchCityAttributeTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        DB::beginTransaction();
        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Település teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    /** Egy közigazgatási határ hozzákötése a teszt-templomhoz. */
    private function hatart(int $szint, string $nev, string $tipus = 'administrative'): void {
        $id = (int) DB::table('boundaries')->insertGetId([
            'boundary' => $tipus, 'admin_level' => $szint, 'name' => $nev,
        ]);
        DB::table('lookup_boundary_church')->insert([
            'boundary_id' => $id, 'church_id' => $this->churchId,
        ]);
    }

    private function church(): \Eloquent\Church {
        return \Eloquent\Church::findOrFail($this->churchId);
    }

    /** A LÉNYEG: a `varos` a határokból jön, nem üres. */
    public function testTheCityComesFromTheBoundaries(): void {
        $this->hatart(8, 'Szentendre');

        self::assertSame('Szentendre', $this->church()->varos);
    }

    /** Nagyvárosnál a kerülettel együtt — ahogy a régi oszlopban is volt. */
    public function testInABigCityTheDistrictIsAppended(): void {
        $this->hatart(8, 'Budapest');
        $this->hatart(9, 'XI. kerület');

        self::assertSame('Budapest XI. kerület', $this->church()->varos);
    }

    /**
     * Nincs 8-as szint (pl. német kreisfreie Stadt): a 6-os a település, és a 9-es
     * kerületet ilyenkor NEM fűzzük hozzá — ott már másfajta felosztás.
     */
    public function testWithoutLevelEightTheCountyLevelIsTheCity(): void {
        $this->hatart(6, 'Köln');
        $this->hatart(9, 'Innenstadt');

        self::assertSame('Köln', $this->church()->varos);
    }

    /** Nem közigazgatási határ (pl. egyházmegye) nem település. */
    public function testANonAdministrativeBoundaryIsNotACity(): void {
        $this->hatart(8, 'Egyházmegye', 'religious_administration');

        self::assertSame('', $this->church()->varos);
    }

    /**
     * A `toArray()`-ben is ott kell lennie: a kereső-találatok nem a modellt adják a
     * sablonnak, hanem a tömbjét (`searchresultsmasses.php:408`).
     */
    public function testTheArrayFormAlsoCarriesTheCity(): void {
        $this->hatart(8, 'Eger');

        $tomb = $this->church()->toArray();
        self::assertArrayHasKey('varos', $tomb, 'a kereső-találat ezt a kulcsot olvassa');
        self::assertSame('Eger', $tomb['varos']);
    }

    /**
     * EGY lekérdezés templomonként, akárhányszor kérdezzük.
     *
     * A `varos` minden listasorra meghívódik. Ha szintenként külön `SELECT` menne
     * (a javítás előtti állapot), egy 50 soros katalógus 100+ lekérdezést indítana —
     * a régi oszlop kiolvasása ehhez képest ingyen volt.
     */
    public function testTheBoundariesAreQueriedOnlyOnce(): void {
        $this->hatart(8, 'Budapest');
        $this->hatart(9, 'XI. kerület');

        $church = $this->church();

        $kapcsolat = DB::connection();
        $kapcsolat->flushQueryLog();
        $kapcsolat->enableQueryLog();

        $church->varos;
        $church->varos;
        $church->locationCountryName();

        $lekerdezesek = $kapcsolat->getQueryLog();
        $kapcsolat->disableQueryLog();

        $hatarLekerdezesek = array_filter(
            $lekerdezesek,
            fn($l) => str_contains($l['query'], 'lookup_boundary_church')
        );

        self::assertCount(1, $hatarLekerdezesek,
            'A határokat egyszer kérjük le és megjegyezzük; kaptunk: '
            . count($hatarLekerdezesek));
    }

    /** Eager loading esetén lekérdezés sincs — a betöltött relációból dolgozunk. */
    public function testWithEagerLoadingThereIsNoQueryAtAll(): void {
        $this->hatart(8, 'Pécs');

        $church = \Eloquent\Church::with('boundaries')->findOrFail($this->churchId);

        $kapcsolat = DB::connection();
        $kapcsolat->flushQueryLog();
        $kapcsolat->enableQueryLog();

        $varos = $church->varos;

        $lekerdezesek = $kapcsolat->getQueryLog();
        $kapcsolat->disableQueryLog();

        self::assertSame('Pécs', $varos);
        self::assertCount(0, array_filter(
            $lekerdezesek,
            fn($l) => str_contains($l['query'], 'lookup_boundary_church')
        ), 'betöltött relációnál nem kellene lekérdeznünk');
    }
}
