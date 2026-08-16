<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Az aktiválás-kérő értesítő ugyanabba a körbe futott bele, mint az inaktivitási
 * (\User::sendInactivityNotification, l. InactivityNotificationTest) — csak itt hetente,
 * és törlés-ág nélkül, ezért kevésbé volt feltűnő.
 *
 * A `sendActivationNotification()` minden körben kiválaszt öt, még soha be nem lépett
 * felhasználót, és ha a legutóbbi értesítő öregebb egy hétnél, küld egy újat. Akinek a
 * címére nem megy ki a levél, annak tehát hetente keletkezik egy újabb `error` sor —
 * örökre, mert a fiók sem tűnik el.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class ActivationNotificationTest extends TestCase {

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
     * A cron RAND()-dal húz ötöt az összes be nem lépett felhasználóból. Hogy a mérés a
     * saját sorunkról szóljon, a meglévőket a mérés idejére „belépettnek" jelöljük — a
     * rollback visszaállítja.
     */
    private function isolateFromExistingUsers(): void {
        DB::table('user')
            ->where('lastlogin', '0000-00-00 00:00:00')
            ->update(['lastlogin' => date('Y-m-d H:i:s')]);
    }

    private function makeNeverLoggedInUser(string $email): int {
        $uid = (int) DB::table('user')->max('uid') + 1;
        DB::table('user')->insert([
            'uid'           => $uid,
            'login'         => 'aktival' . $uid,
            'jelszo'        => 'x',
            'jogok'         => 'user',
            'regdatum'      => date('Y-m-d H:i:s', strtotime('-3 months')),
            'lastlogin'     => '0000-00-00 00:00:00',
            'email'         => $email,
            'becenev'       => 'Aktiválandó',
            'nev'           => 'Aktiválandó Felhasználó',
            'notifications' => 1,
        ]);
        return $uid;
    }

    private function makeAttempt(string $to, string $status, string $when): void {
        DB::table('emails')->insert([
            'type'       => 'user_pleaseactivate',
            'to'         => $to,
            'subject'    => 'Teszt',
            'body'       => 'Teszt törzs',
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s', strtotime($when)),
            'updated_at' => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }

    private function queuedCountFor(string $to): int {
        return DB::table('emails')
            ->where('type', 'user_pleaseactivate')
            ->where('to', $to)
            ->where('status', 'queued')
            ->count();
    }

    /* A kiindulópont: aki elérhető, az továbbra is kap értesítőt. */
    public function testAzElerhetoFelhasznaloKapErtesitot(): void {
        $email = 'elerheto' . uniqid() . '@example.com';
        $this->makeNeverLoggedInUser($email);

        \User::sendActivationNotification();

        $this->assertSame(1, $this->queuedCountFor($email));
    }

    public function testUresCimreNemProbalkozunk(): void {
        $this->makeNeverLoggedInUser('');

        \User::sendActivationNotification();

        $this->assertSame(0, $this->queuedCountFor(''),
            'üres címre nincs mit kiküldeni, mégis minden körben egy újabb hibás sort hagyott');
    }

    public function testHibasCimreNemProbalkozunk(): void {
        $email = 'ez sem cim';
        $this->makeNeverLoggedInUser($email);

        \User::sendActivationNotification();

        $this->assertSame(0, $this->queuedCountFor($email));
    }

    /* A tartósan kézbesíthetetlen cím itt is megállítja a próbálkozást. */
    public function testTartosanKezbesithetetlenCimreNemKuldunkTobbet(): void {
        $email = 'halott' . uniqid() . '@example.com';
        $this->makeNeverLoggedInUser($email);
        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            $this->makeAttempt($email, 'error', '-' . ($i * 2) . ' weeks');
        }

        \User::sendActivationNotification();

        $this->assertSame(0, $this->queuedCountFor($email));
    }

    /* Egyetlen hiba után még próbálkozunk: lehetett átmeneti SMTP-kiesés. */
    public function testEgyetlenHibaUtanMegProbalkozunk(): void {
        $email = 'atmeneti' . uniqid() . '@example.com';
        $this->makeNeverLoggedInUser($email);
        $this->makeAttempt($email, 'error', '-2 weeks');

        \User::sendActivationNotification();

        $this->assertSame(1, $this->queuedCountFor($email));
    }

    /* A friss kísérlet változatlanul véd: egy héten belül nem küldünk újat. */
    public function testFrissKiserletUtanNemKuldunkUjat(): void {
        $email = 'friss' . uniqid() . '@example.com';
        $this->makeNeverLoggedInUser($email);
        $this->makeAttempt($email, 'sent', '-2 days');

        \User::sendActivationNotification();

        $this->assertSame(0, $this->queuedCountFor($email));
    }

    /*
     * A két levéltípus külön számol: az inaktivitási értesítő hibái nem némíthatják el
     * az aktiválás-kérőt.
     */
    public function testAMasikLeveltipusHibaiNemSzamitanak(): void {
        $email = 'kereszt' . uniqid() . '@example.com';
        $this->makeNeverLoggedInUser($email);
        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            DB::table('emails')->insert([
                'type'       => 'user_pleaselogin',
                'to'         => $email,
                'subject'    => 'Teszt',
                'body'       => 'Teszt törzs',
                'status'     => 'error',
                'created_at' => date('Y-m-d H:i:s', strtotime('-' . ($i * 2) . ' weeks')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-' . ($i * 2) . ' weeks')),
            ]);
        }

        \User::sendActivationNotification();

        $this->assertSame(1, $this->queuedCountFor($email));
    }
}
