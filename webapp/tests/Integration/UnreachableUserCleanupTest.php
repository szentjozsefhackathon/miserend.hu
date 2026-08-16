<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A soha be nem lépett fiókok törlésének feltétele eddig egy SIKERESEN kiküldött
 * `user_pleaseactivate` volt (\User::deleteNonActivatedUsers, EXISTS ... status='sent').
 * Ezért pont azok maradtak bent örökre, akiknek a levele sosem ment ki.
 *
 * Élesben ez a robot-regisztrációk halmaza: hamis címmel jönnek, a levél elhasal,
 * belépni sosem lépnek be. Ezek adják a hibás levelek zömét, és közben szemetelik az
 * adatbázist.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class UnreachableUserCleanupTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
        $this->isolateFromExistingUsers();
    }

    /**
     * A takarítás RAND()-dal húz húszat a jelöltekből. Amíg kevés a be nem lépett fiók,
     * a sajátunk mindig belefér — de ez a teszt adatbázisának a mérete, nem a mi
     * állításunk. Ezért a meglévőket a mérés idejére frissnek jelöljük (a türelmi idő
     * kizárja őket), a rollback pedig visszaállítja.
     */
    private function isolateFromExistingUsers(): void {
        DB::table('user')
            ->where('lastlogin', '0000-00-00 00:00:00')
            ->update(['regdatum' => date('Y-m-d H:i:s')]);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** Soha be nem lépett fiók. A '0000-00-00' a "még sosem lépett be" jelölése. */
    private function makeNeverLoggedInUser(string $email, string $regdatum = '-3 months'): int {
        $uid = (int) DB::table('user')->max('uid') + 1;
        DB::table('user')->insert([
            'uid'       => $uid,
            'login'     => 'ujonc' . $uid,
            'jelszo'    => 'x',
            'jogok'     => 'user',
            'regdatum'  => date('Y-m-d H:i:s', strtotime($regdatum)),
            'lastlogin' => '0000-00-00 00:00:00',
            'email'     => $email,
            'becenev'   => 'Újonc',
            'nev'       => 'Újonc Felhasználó',
        ]);
        return $uid;
    }

    private function makeAttempt(string $to, string $status, string $when = '-2 months'): void {
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

    private function exists(int $uid): bool {
        return DB::table('user')->where('uid', $uid)->exists();
    }

    /* A tipikus robot-regisztráció: hamis cím, amire ki se lehet küldeni semmit. */
    public function testAHasznalhatatlanCimuUjoncotToroljuk(): void {
        $uid = $this->makeNeverLoggedInUser('ez nem cim');

        \User::deleteUnreachableNonActivatedUsers();

        $this->assertFalse($this->exists($uid));
    }

    public function testAzUresCimuUjoncotIsToroljuk(): void {
        $uid = $this->makeNeverLoggedInUser('');

        \User::deleteUnreachableNonActivatedUsers();

        $this->assertFalse($this->exists($uid));
    }

    /* A cím formailag jó, csak épp nem kézbesíthető — ezt a kísérletekből tudjuk. */
    public function testATobbszorSikertelenulMegcimzettUjoncotToroljuk(): void {
        $email = 'halott' . uniqid() . '@example.com';
        $uid = $this->makeNeverLoggedInUser($email);
        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            $this->makeAttempt($email, 'error', '-' . $i . ' months');
        }

        \User::deleteUnreachableNonActivatedUsers();

        $this->assertFalse($this->exists($uid));
    }

    /*
     * Ha valaha kiment neki levél, a cím működött: az ilyen fiók a másik ág dolga
     * (deleteNonActivatedUsers), nem ezé. Enélkül ez a takarítás elvenné előle a
     * búcsúlevelet.
     */
    public function testAkinekValahaKimentLevelAzNemEzenAzAgonTorlodik(): void {
        $email = 'kiment' . uniqid() . '@example.com';
        $uid = $this->makeNeverLoggedInUser($email);
        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            $this->makeAttempt($email, 'error', '-' . ($i + 6) . ' months');
        }
        $this->makeAttempt($email, 'sent', '-1 month');

        \User::deleteUnreachableNonActivatedUsers();

        $this->assertTrue($this->exists($uid));
    }

    /* Egy-két hiba még lehet átmeneti SMTP-kiesés: azért nem törlünk. */
    public function testEgyetlenHibaMiattNemTorlunk(): void {
        $email = 'atmeneti' . uniqid() . '@example.com';
        $uid = $this->makeNeverLoggedInUser($email);
        $this->makeAttempt($email, 'error');

        \User::deleteUnreachableNonActivatedUsers();

        $this->assertTrue($this->exists($uid));
    }

    /* A türelmi idő véd: a frissen regisztrált fiókhoz hozzá sem nyúlunk. */
    public function testAFrissRegisztraciotBekenHagyjuk(): void {
        $uid = $this->makeNeverLoggedInUser('ez nem cim', '-2 days');

        \User::deleteUnreachableNonActivatedUsers();

        $this->assertTrue($this->exists($uid));
    }

    /* Aki már belépett, az soha nem tartozik ide — akármilyen a címe. */
    public function testAkiMarBelepettAztNemBantjuk(): void {
        $uid = $this->makeNeverLoggedInUser('ez nem cim');
        DB::table('user')->where('uid', $uid)
            ->update(['lastlogin' => date('Y-m-d H:i:s', strtotime('-2 years'))]);

        \User::deleteUnreachableNonActivatedUsers();

        $this->assertTrue($this->exists($uid));
    }

    /*
     * Búcsúlevél nem megy: oda küldenénk, ahova az előző néhány sem jutott el, csak
     * újabb hibás sorokat termelve.
     */
    public function testNemKuldunkBucsulevelet(): void {
        $email = 'halott' . uniqid() . '@example.com';
        $this->makeNeverLoggedInUser($email);
        for ($i = 1; $i <= \User::UNDELIVERABLE_ATTEMPTS; $i++) {
            $this->makeAttempt($email, 'error', '-' . $i . ' months');
        }

        \User::deleteUnreachableNonActivatedUsers();

        $this->assertSame(0, DB::table('emails')
            ->where('type', 'user_youhavebeendeleted')
            ->where('to', $email)
            ->count());
    }

    /* A takarítás a fő cron-belépési pontból is lefut, nem csak közvetlenül hívva. */
    public function testAFoBelepesiPontIsElvegzi(): void {
        $uid = $this->makeNeverLoggedInUser('ez nem cim');

        \User::deleteNonActivatedUsers();

        $this->assertFalse($this->exists($uid));
    }
}
