<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Az éles /health szerint a `user_pleaselogin` típusnál 30 nap alatt 131 hibás levél
 * jutott 48 sikeresre — messze a legrosszabb arány, a többi típus gyakorlatilag tiszta.
 *
 * Az ok a \User::sendInactivityNotification() életciklusában van: a törlés ága KIZÁRÓLAG
 * `sent` státuszra fut. Akinek a címére nem megy ki a levél, az tehát sosem jut el a
 * törlésig — viszont az `else` ág háromhetente újra küld neki. Örök kör, minden
 * fordulóban egy újabb `error` sorral.
 *
 * Két, már a próbálkozás előtt látható esetet érdemes külön is megfogni:
 *
 *  - üres cím: az `Email::send()` `isset($this->to)` feltétele üres sztringre IGAZ, így
 *    a levél eljut a PHPMailerig, ami "Invalid address"-szel dob;
 *  - formailag hibás cím: ugyanez, csak a validáláson bukik el.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class InactivityNotificationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
        $this->isolateFromExistingUsers();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * A cron RAND()-dal húz ötöt az ÖSSZES inaktív felhasználóból, tehát a teszt
     * adatbázisában meglévő 18 sor mellett a sajátunk simán kimaradhatna a mintából.
     * Ezért a meglévőket a mérés idejére aktívvá tesszük — a rollback visszaállítja.
     */
    private function isolateFromExistingUsers(): void {
        DB::table('user')
            ->where('lastlogin', '<', date('Y-m-d H:i:s', strtotime('-5 years')))
            ->update(['lastlogin' => date('Y-m-d H:i:s')]);
    }

    /** Öt éve inaktív felhasználó — a cron pont ilyeneket keres. */
    private function makeInactiveUser(string $email): int {
        $uid = (int) DB::table('user')->max('uid') + 1;
        DB::table('user')->insert([
            'uid'       => $uid,
            'login'     => 'inaktiv' . $uid,
            'jelszo'    => 'x',
            'jogok'     => 'user',
            'regdatum'  => date('Y-m-d H:i:s', strtotime('-8 years')),
            'lastlogin' => date('Y-m-d H:i:s', strtotime('-6 years')),
            'email'     => $email,
            'becenev'   => 'Inaktív',
            'nev'       => 'Inaktív Felhasználó',
        ]);
        return $uid;
    }

    private function makeAttempt(string $to, string $status, string $when): void {
        DB::table('emails')->insert([
            'type'       => 'user_pleaselogin',
            'to'         => $to,
            'subject'    => 'Teszt',
            'body'       => 'Teszt törzs',
            'status'     => $status,
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    private function queuedCountFor(string $to): int {
        return DB::table('emails')
            ->where('type', 'user_pleaselogin')
            ->where('to', $to)
            ->where('status', 'queued')
            ->count();
    }

    /*
     * A kiindulópont: aki elérhető, az továbbra is kap értesítőt. Ha ez elbukik, a
     * többi állítás semmit nem ér — attól is zöldek lennének, hogy egyáltalán nem megy
     * ki levél.
     */
    public function testAzElerhetoFelhasznaloKapErtesitot(): void {
        $email = 'elerheto' . uniqid() . '@example.com';
        $this->makeInactiveUser($email);

        \User::sendInactivityNotification();

        $this->assertSame(1, $this->queuedCountFor($email),
            'a használható címre ki kell mennie az értesítőnek');
    }

    public function testUresCimreNemProbalkozunk(): void {
        $this->makeInactiveUser('');

        \User::sendInactivityNotification();

        $this->assertSame(0, $this->queuedCountFor(''),
            'üres címre nincs mit kiküldeni, mégis minden körben egy újabb hibás sort hagyott');
    }

    public function testHibasCimreNemProbalkozunk(): void {
        $email = 'ez nem cim';
        $this->makeInactiveUser($email);

        \User::sendInactivityNotification();

        $this->assertSame(0, $this->queuedCountFor($email));
    }

    /*
     * A lényegi kör: a cím formailag rendben van, csak épp nem kézbesíthető. Ezt csak a
     * korábbi kísérletekből tudjuk — és pont ezt nem nézte eddig senki.
     */
    public function testTartosanKezbesithetetlenCimreNemKuldunkTobbet(): void {
        $email = 'halott' . uniqid() . '@example.com';
        $this->makeInactiveUser($email);
        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            $this->makeAttempt($email, 'error', date('Y-m-d H:i:s', strtotime('-' . ($i * 4) . ' weeks')));
        }

        \User::sendInactivityNotification();

        $this->assertSame(0, $this->queuedCountFor($email),
            'a sokadik sikertelen kísérlet után nem szabad újra sorba tenni');
    }

    /* Egyetlen hiba még lehet átmeneti SMTP-kiesés: azért nem adjuk fel a címet. */
    public function testEgyetlenHibaUtanMegProbalkozunk(): void {
        $email = 'atmeneti' . uniqid() . '@example.com';
        $this->makeInactiveUser($email);
        $this->makeAttempt($email, 'error', date('Y-m-d H:i:s', strtotime('-4 weeks')));

        \User::sendInactivityNotification();

        $this->assertSame(1, $this->queuedCountFor($email),
            'egy hiba nem bizonyítja, hogy a cím halott');
    }

    /*
     * A friss kísérlet változatlanul véd: három héten belül nem küldünk újat. Ezt az
     * ágat a kézbesíthetetlenség-vizsgálat nem írhatja felül.
     */
    public function testFrissKiserletUtanNemKuldunkUjat(): void {
        $email = 'friss' . uniqid() . '@example.com';
        $this->makeInactiveUser($email);
        $this->makeAttempt($email, 'sent', date('Y-m-d H:i:s', strtotime('-2 days')));

        \User::sendInactivityNotification();

        $this->assertSame(0, $this->queuedCountFor($email));
    }

    /*
     * A kézbesíthetetlenség levéltípusonként külön áll: az aktiválás-kérő hibái nem
     * némíthatják el az inaktivitási értesítőt, és fordítva.
     */
    public function testAKezbesithetetlensegTipusonkentKulonAll(): void {
        $email = 'tipusfuggo' . uniqid() . '@example.com';

        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            DB::table('emails')->insert([
                'type'       => 'user_pleaseactivate',
                'to'         => $email,
                'subject'    => 'Teszt',
                'body'       => 'Teszt törzs',
                'status'     => 'error',
                'created_at' => date('Y-m-d H:i:s', strtotime('-' . ($i * 4) . ' weeks')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-' . ($i * 4) . ' weeks')),
            ]);
        }

        $this->assertTrue(\User::isUndeliverable('user_pleaseactivate', $email));
        $this->assertFalse(\User::isUndeliverable('user_pleaselogin', $email));
    }

    /*
     * A sikeres kézbesítés nullázza a számlálót. Ez a törlés miatt fontos: az csak
     * akkor fut le, ha a felhasználó előbb átmegy a kézbesíthetőség-vizsgálaton.
     */
    public function testASikeresKikuldesNullazzaAKorabbiHibakat(): void {
        $email = 'ujraelo' . uniqid() . '@example.com';
        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            $this->makeAttempt($email, 'error', date('Y-m-d H:i:s', strtotime('-' . ($i + 3) . ' months')));
        }
        $this->assertTrue(\User::isUndeliverable('user_pleaselogin', $email),
            'a hibák után kézbesíthetetlennek kell látszania');

        $this->makeAttempt($email, 'sent', date('Y-m-d H:i:s', strtotime('-1 month')));

        $this->assertFalse(\User::isUndeliverable('user_pleaselogin', $email),
            'a bizonyítottan működő címet nem szabad kézbesíthetetlennek tekinteni');
    }

    /*
     * A régen működő, de azóta bedőlt cím viszont igen: a siker UTÁNI hibák számítanak.
     */
    public function testASikerUtaniHibakSzamitanak(): void {
        $email = 'bedolt' . uniqid() . '@example.com';
        $this->makeAttempt($email, 'sent', date('Y-m-d H:i:s', strtotime('-1 year')));
        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            $this->makeAttempt($email, 'error', date('Y-m-d H:i:s', strtotime('-' . $i . ' months')));
        }

        $this->assertTrue(\User::isUndeliverable('user_pleaselogin', $email));
    }

    /*
     * A törlés ága változatlanul lefut: a kézbesíthetőség-vizsgálat nem tehet keresztbe
     * neki egy olyan címnél, ahova a levél kiment.
     */
    public function testARegiSikeresErtesitesUtanTorlodikAFelhasznalo(): void {
        $email = 'torlendo' . uniqid() . '@example.com';
        $uid = $this->makeInactiveUser($email);
        // Régi hibasorozat, ami után a cím láthatóan újra működött: a törlésnek ettől
        // még le kell futnia.
        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            $this->makeAttempt($email, 'error', date('Y-m-d H:i:s', strtotime('-' . ($i + 6) . ' months')));
        }
        $this->makeAttempt($email, 'sent', date('Y-m-d H:i:s', strtotime('-2 months')));

        \User::sendInactivityNotification();

        $this->assertSame(0, DB::table('user')->where('uid', $uid)->count(),
            'a régen értesített, továbbra is inaktív felhasználót törölni kell');
    }

    /** @dataProvider hasznalhatatlanCimek */
    public function testAHasznalhatatlanCimeketFelismerjuk(?string $email): void {
        $this->assertFalse(\User::isEmailUsable($email));
    }

    public static function hasznalhatatlanCimek(): array {
        return [
            'null'        => [null],
            'üres'        => [''],
            'csak szóköz' => ['   '],
            'nincs kukac' => ['valaki.example.com'],
            'nincs domain'=> ['valaki@'],
            'szóköz benne'=> ['ez nem cim'],
        ];
    }

    public function testAHasznalhatoCimetElfogadjuk(): void {
        $this->assertTrue(\User::isEmailUsable('valaki@example.com'));
        // A körülötte lévő szóköz nem teszi használhatatlanná.
        $this->assertTrue(\User::isEmailUsable('  valaki@example.com  '));
    }
}
