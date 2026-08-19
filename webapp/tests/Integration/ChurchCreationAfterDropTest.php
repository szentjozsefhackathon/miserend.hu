<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #496 / #497 / #498: új templom létrehozása az oszlopok kivezetése után.
 *
 * borazslo kérdése a #802-höz:
 *
 *   „Itt még egy kérdésem: új templom létrehozásánál kiakadunk-e? Mármint gyanús,
 *    hogy a `templomok` tábla nem tűr meg üres orszag, megye, varos mezőt, bár van
 *    mindegyikhez default (0,0,'') a 02-schema.sql. Akkor ez nem okoz gondot?"
 *
 * Jogos kérdés, ezért teszt is van rá. A létrehozás (`Html\Church\Create::create()`)
 * eleve NEM állítja be ezt a három mezőt — az alapértelmezésekre hagyatkozik —,
 * tehát az oszlopok eltűnése nem érinti.
 *
 * A CI friss adatbázist húz fel az `initdb.d`-ből, ahol a drop már lefutott: ott ez
 * a teszt a VALÓDI, oszlopok utáni állapotot méri.
 */
class ChurchCreationAfterDropTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** Ugyanaz a mezőkészlet, amit a Create::create() használ. */
    private function letrehoz(): \Eloquent\Church {
        $church = \Eloquent\Church::create([
            'nev'       => 'Létrehozás teszt',
            'ok'        => 'n',
            'frissites' => date('Y-m-d'),
            'lat'       => 47.5,
            'lon'       => 19.05,
            'osmid'     => null,
            'osmtype'   => null,
        ]);
        $church->save();

        return $church;
    }

    public function testUjTemplomLetrehozhato(): void {
        $church = $this->letrehoz();

        self::assertGreaterThan(0, $church->id);
        self::assertSame('Létrehozás teszt', DB::table('templomok')->where('id', $church->id)->value('nev'));
    }

    /**
     * A frissen létrehozott templomnak még NINCS határa (a szinkron később fut le),
     * tehát a helynév üres. Ez nem romlás: a `varos` oszlop is üresen maradt eddig,
     * mert a létrehozás sosem töltötte ki.
     */
    public function testAFrissTemplomnakMegNincsHelyneve(): void {
        $church = $this->letrehoz();

        self::assertSame('', $church->locationCityName());
        self::assertSame('', $church->locationCountryName());
    }

    /** Az API-válasz is előáll, nem dob hiányzó mezőre. */
    public function testAzApiValaszEloall(): void {
        $tomb = $this->letrehoz()->toAPIArray();

        self::assertSame('', $tomb['varos']);
        self::assertSame('', $tomb['orszag']);
        self::assertArrayHasKey('nev', $tomb);
    }

    /** A koordináta megvan, tehát a boundary-szinkron később meg fogja találni. */
    public function testAKoordinataMegmaradAszinkronnak(): void {
        $church = $this->letrehoz();

        self::assertEquals(47.5, $church->lat);
        self::assertEquals(19.05, $church->lon);
    }
}
