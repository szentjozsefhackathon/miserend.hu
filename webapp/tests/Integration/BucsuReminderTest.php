<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #568: búcsú-emlékeztető a templomgondnokoknak.
 *
 * borazslo spec-je:
 *
 *   „A várható dátum előtt mondjuk 21 nappal küldjük ki az emailt a
 *    templomgondnokoknak (nem kell egyházmegye felelős, se általános admin), hogy
 *    »A hozzád tartozó ilyen és ilyen templomnak a megadott adatok szerint közeleg
 *    a búcsúja…«"
 *
 * A dátum a szabad szöveges `bucsu` mezőből jön, tehát lehet benne pontatlanság —
 * ezt borazslo kifejezetten megengedte: „nem baj, ha +/- pár nap […] hiszen az
 * értesítés nem kell pontosan menjen".
 */
class BucsuReminderTest extends TestCase {

    private const TIPUS = 'holder_bucsu_reminder';

    private int $sorszam = 0;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** @param string $bucsu a szabad szöveges mező tartalma */
    private function templom(string $bucsu): int {
        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $id = (int) DB::table('templomok')->max('id') + 1 + $this->sorszam++;

        DB::table('templomok')->insert(array_merge($minta, [
            'id' => $id, 'nev' => 'Búcsú Teszt ' . $id, 'ok' => 'i', 'bucsu' => $bucsu,
        ]));

        return $id;
    }

    /** @return int a gondnok felhasználó azonosítója */
    private function gondnok(int $churchId, string $statusz = 'allowed', int $ertesites = 1): int {
        $uid = (int) DB::table('user')->max('uid') + 1 + $this->sorszam++;
        DB::table('user')->insert([
            'uid' => $uid,
            'login' => 'bucsuteszt' . $uid,
            'jelszo' => '',
            'jogok' => '',
            'email' => 'bucsuteszt' . $uid . '@example.invalid',
            'notifications' => $ertesites,
            'nev' => 'Teszt Gondnok',
        ]);
        DB::table('church_holders')->insert([
            'user_id' => $uid, 'church_id' => $churchId, 'status' => $statusz, 'description' => '',
        ]);
        return $uid;
    }

    private function levelek(int $uid): int {
        $email = DB::table('user')->where('uid', $uid)->value('email');
        return DB::table('emails')->where('type', self::TIPUS)->where('to', $email)->count();
    }

    /** @param int $napMulva hány nap múlva legyen a búcsú */
    private function bucsuSzoveg(int $napMulva): string {
        $d = strtotime("+$napMulva days");
        $honapok = [1 => 'január', 'február', 'március', 'április', 'május', 'június',
                    'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
        return 'Búcsú: ' . $honapok[(int) date('n', $d)] . ' ' . (int) date('j', $d) . '.';
    }

    /** A lényeg: pontosan 21 nappal előtte megy ki az értesítés. */
    public function testHuszonegyNappalElotteKuld(): void {
        $tid = $this->templom($this->bucsuSzoveg(21));
        $uid = $this->gondnok($tid);

        \User::sendBucsuReminder();

        self::assertSame(1, $this->levelek($uid));
    }

    public function testHuszNappalElotteMegNemKuld(): void {
        $tid = $this->templom($this->bucsuSzoveg(20));
        $uid = $this->gondnok($tid);

        \User::sendBucsuReminder();

        self::assertSame(0, $this->levelek($uid));
    }

    public function testHuszonkettoNappalElotteMegNemKuld(): void {
        $tid = $this->templom($this->bucsuSzoveg(22));
        $uid = $this->gondnok($tid);

        \User::sendBucsuReminder();

        self::assertSame(0, $this->levelek($uid));
    }

    /** Napi cron: kétszer lefutva se menjen két levél ugyanannak. */
    public function testKetszerFuttatvaSemKuldKettot(): void {
        $tid = $this->templom($this->bucsuSzoveg(21));
        $uid = $this->gondnok($tid);

        \User::sendBucsuReminder();
        \User::sendBucsuReminder();

        self::assertSame(1, $this->levelek($uid));
    }

    /** Csak az ENGEDÉLYEZETT gondnok kap — a függőben lévő nem. */
    public function testFuggobenLevoGondnokNemKap(): void {
        $tid = $this->templom($this->bucsuSzoveg(21));
        $uid = $this->gondnok($tid, 'asked');

        \User::sendBucsuReminder();

        self::assertSame(0, $this->levelek($uid));
    }

    /** Aki kikapcsolta az értesítéseket, annak nem küldünk. */
    public function testErtesitestKikapcsoloNemKap(): void {
        $tid = $this->templom($this->bucsuSzoveg(21));
        $uid = $this->gondnok($tid, 'allowed', 0);

        \User::sendBucsuReminder();

        self::assertSame(0, $this->levelek($uid));
    }

    /** Értelmezhetetlen búcsú-mezőnél nincs dátum, tehát nincs értesítés sem. */
    public function testErtelmezhetetlenBucsunalNemKuld(): void {
        $tid = $this->templom('Búcsú: Szent György vértanú ünnepéhez közelebbi vasárnap');
        $uid = $this->gondnok($tid);

        \User::sendBucsuReminder();

        self::assertSame(0, $this->levelek($uid));
    }

    public function testUresBucsunalNemKuld(): void {
        $tid = $this->templom('');
        $uid = $this->gondnok($tid);

        \User::sendBucsuReminder();

        self::assertSame(0, $this->levelek($uid));
    }

    /** A levél a búcsú dátumát és a templom nevét is tartalmazza. */
    public function testALevelTartalmazzaATemplomotEsADatumot(): void {
        $tid = $this->templom($this->bucsuSzoveg(21));
        $uid = $this->gondnok($tid);

        \User::sendBucsuReminder();

        $email = DB::table('emails')->where('type', self::TIPUS)
            ->where('to', DB::table('user')->where('uid', $uid)->value('email'))->first();

        self::assertNotNull($email);
        self::assertStringContainsString('Búcsú Teszt ' . $tid, $email->body);
        self::assertStringContainsString(date('Y-m-d', strtotime('+21 days')), $email->body);
    }

    /** A visszatérési érték a kiküldött levelek száma — a cron-napló ezt írja ki. */
    public function testVisszaadjaAKuldottLevelekSzamat(): void {
        $tid = $this->templom($this->bucsuSzoveg(21));
        $this->gondnok($tid);

        self::assertGreaterThanOrEqual(1, \User::sendBucsuReminder());
    }
}
