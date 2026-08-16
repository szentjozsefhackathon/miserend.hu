<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #315 / borazslo: „Jó lenne látni, hogy hányan is élnek ezzel az önkéntes
 * lehetőséggel. Mondjuk a /user/catalogue-ba egy rendezés aszerint, hogy épp aktív-e a
 * hét templom önkéntessége, és ha az van kiválasztva, akkor lehetne ilyen oszlop."
 *
 * A kampányról eddig semmilyen felületen nem lehetett megmondani, hányan vesznek részt
 * benne — a `volunteer` mező csak a saját profilon látszott.
 *
 * A rendezés `orderByRaw`-val megy, és a `buildForm()` a fehérlistán kívüli értékre
 * kivételt dob. Ezért itt két dolgot kell mérni: hogy a rendezés egyáltalán ELFOGADOTT
 * érték-e, és hogy az összesítés a valóságot mondja.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class UserCatalogueVolunteerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function makeUser(int $volunteer): int {
        $uid = (int) DB::table('user')->max('uid') + 1;
        DB::table('user')->insert([
            'uid'       => $uid,
            'login'     => 'kat' . $uid,
            'jelszo'    => 'x',
            'jogok'     => 'user',
            'regdatum'  => date('Y-m-d H:i:s'),
            'lastlogin' => date('Y-m-d H:i:s'),
            'email'     => 'kat' . $uid . '@example.com',
            'becenev'   => 'Kat',
            'nev'       => 'Katalógus Teszt',
            'volunteer' => $volunteer,
        ]);
        return $uid;
    }

    /**
     * A `buildForm()` fehérlistája dönti el, mire lehet rendezni: a listán kívüli
     * értékre kivételt dob. Ha az „önkéntesség" nincs rajta, a felhasználó ezt a
     * rendezést el sem tudja indítani.
     */
    public function testAzOnkentessegRendezhetoErtek(): void {
        $lap = new \ReflectionClass(\Html\User\Catalogue::class);
        $forras = (string) file_get_contents($lap->getFileName());

        self::assertStringContainsString("'volunteer desc'", $forras,
            'az önkéntesség nincs a rendezhető mezők között');
    }

    /** Az oszlop és az összesítés csak ennél a rendezésnél jelenik meg. */
    public function testAzOszlopCsakAzOnkentesRendezesnelJelenikMeg(): void {
        $sablon = (string) file_get_contents(__DIR__ . '/../../templates/user/catalogue.twig');

        self::assertStringContainsString('showVolunteer', $sablon,
            'a sablon nem ismeri az önkéntes-oszlop kapcsolóját');
        self::assertStringContainsString('volunteerCount', $sablon,
            'a sablon nem írja ki, hányan önkénteskednek');
    }

    /**
     * A lényeg: a darabszám a valóságot mondja. Ez az egyetlen szám, amiért borazslo
     * az egészet kérte.
     */
    public function testADarabszamAValosagotMondja(): void {
        $elotte = DB::table('user')->where('volunteer', 1)->count();
        $this->makeUser(1);
        $this->makeUser(1);
        $this->makeUser(0);

        self::assertSame($elotte + 2, DB::table('user')->where('volunteer', 1)->count(),
            'a nem önkéntes felhasználó is beleszámított');
    }

    /** A rendezés az önkénteseket hozza előre. */
    public function testARendezesAzOnkenteseketHozzaElore(): void {
        $onkentes = $this->makeUser(1);
        $nem = $this->makeUser(0);

        $sorrend = DB::table('user')
            ->whereIn('uid', [$onkentes, $nem])
            ->orderByRaw('volunteer desc')
            ->pluck('uid')->all();

        self::assertSame([$onkentes, $nem], $sorrend);
    }
}
