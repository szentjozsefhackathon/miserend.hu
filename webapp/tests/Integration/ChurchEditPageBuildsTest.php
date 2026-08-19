<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #833: a szerkesztő oldal épüljön fel.
 *
 * A kaszkádos hely-választó kivezetése (#496/#497/#498) az
 * `addFormReligiousAdministration()` FEJLÉCÉT is elvitte, a TÖRZSÉT nem — az beolvadt
 * a szomszédos metódusba, a rá mutató hívás pedig ottmaradt. A fájl így is
 * értelmezhető maradt (a `php -l` nem szólt, a unit-tesztek sem), de a szerkesztő
 * oldal minden jogosult felhasználónak végzetes hibával elszállt:
 *
 *     Call to undefined method Html\Church\Edit::addFormReligiousAdministration()
 *
 * Ez a fajta hiba pontosan azért él túl sokáig, mert a statikus ellenőrzés nem fogja
 * meg (a metódusnév csak futásidőben dől el), a lap pedig jogosultság nélkül el sem
 * jut odáig — a CI funkcionális futása is csak a napló mélyén mutatta.
 *
 * Ezért a legfontosabb állítás egyszerű: a lap ÉPÜLJÖN FEL.
 */
final class ChurchEditPageBuildsTest extends TestCase {

    private $eredetiUser;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        // A szerkesztő lap jogosultságot kér; admin nélkül el sem jut a hibás sorig.
        global $user;
        $this->eredetiUser = $user;
        $admin = DB::table('user')->where('jogok', 'LIKE', '%miserend%')->first();
        if (!$admin) {
            self::markTestSkipped('Nincs admin a teszt-adatbázisban.');
        }
        $user = new \User($admin->uid);
    }

    protected function tearDown(): void {
        global $user;
        $user = $this->eredetiUser;
        DB::rollBack();
        parent::tearDown();
    }

    private function templomId(): int {
        $id = DB::table('templomok')->where('ok', 'i')->min('id');
        if (!$id) {
            self::markTestSkipped('Nincs aktív templom a teszt-adatbázisban.');
        }
        return (int) $id;
    }

    /** A lényeg: nem dob végzetes hibát. */
    public function testASzerkesztoLapFelepul(): void {
        $lap = new \Html\Church\Edit([$this->templomId()]);

        self::assertNotNull($lap->church);
    }

    /**
     * Az egyházmegye MARAD választható: az nem OSM-adat, a koordinátából nem
     * származtatható — épp ezért nem is került a hely-kivezetés hatálya alá.
     */
    public function testAzEgyhazmegyeValasztoMegvan(): void {
        $lap = new \Html\Church\Edit([$this->templomId()]);

        self::assertArrayHasKey('dioceses', $lap->form);
        self::assertNotEmpty($lap->form['dioceses']);
    }

    public function testAzEsperesiKeruletValasztoMegvan(): void {
        $lap = new \Html\Church\Edit([$this->templomId()]);

        self::assertArrayHasKey('deaneries', $lap->form);
    }

    /** Az ellátó plébánia választója szintén a `preparePage()`-ből jön. */
    public function testAzEllatoPlebaniaValasztoMegvan(): void {
        $lap = new \Html\Church\Edit([$this->templomId()]);

        self::assertArrayHasKey('parent_id', $lap->form);
    }

    /** Nem létező templomra beszédes hibát kapunk, nem végzetes hibát. */
    public function testNemLetezoTemplomraBeszedesHiba(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Nincs ilyen templom.');

        new \Html\Church\Edit([999999999]);
    }
}
