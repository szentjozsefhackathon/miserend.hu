<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #845: a levélhiba OKA legyen visszakereshető, és a saját összeomlásunk ne minősüljön
 * kézbesíthetetlen címnek.
 *
 * Az éles /health 117 hibás `user_pleaselogin` levelet mutat 45 sikeres mellett — és
 * egyetlen egyről sem tudtuk megmondani, MIÉRT. Az `emails` táblában csak az állt, hogy
 * `status='error'`; a valódi ok az `error_log`-ba ment, ráadásul `[miserend]` előtag
 * nélkül, tehát a dokumentált naplókeresés sem találta meg.
 *
 * A másik fele még rosszabb: a `requeueStuck()` a MI megszakadt folyamatunk miatt
 * beragadt sorokat is 'error'-ba tette, és az `isUndeliverable()` ezeket is számolta.
 * Egy háromnapos leállás után három ilyen sor egy TÖKÉLETESEN MŰKÖDŐ címre is örökre
 * elnémította az értesítőt — csendben, a felhasználó tudta nélkül.
 */
final class EmailFailureEvidenceTest extends TestCase {

    private const CIM = 'hibaok-teszt@example.com';
    private const TIPUS = 'user_pleaselogin';

    protected function setUp(): void {
        DB::connection()->beginTransaction();
    }

    protected function tearDown(): void {
        DB::connection()->rollBack();
    }

    /** @return int a beszúrt sor azonosítója */
    private function sor(string $status, string $created, string $updated): int {
        return (int) DB::table('emails')->insertGetId([
            'type' => self::TIPUS,
            'to' => self::CIM,
            'subject' => 'teszt',
            'body' => 'teszt',
            'status' => $status,
            'created_at' => $created,
            'updated_at' => $updated,
        ]);
    }

    /* ---- 1. A hiba oka kerüljön a sorba, ne csak a naplóba ---- */

    public function testTheFailureReasonIsStoredOnTheRow(): void {
        $email = new HibaztathatoEmail();
        $email->to = self::CIM;
        $email->type = self::TIPUS;
        $email->subject = 'teszt';
        $email->body = 'teszt';

        $email->hibazz('SMTP Error: Could not authenticate.');

        self::assertSame('error', $email->status);
        self::assertNotNull($email->error_reason, 'a hiba okát el kell tenni');
        self::assertStringContainsString('Could not authenticate', $email->error_reason);
        self::assertNotNull($email->failed_at, 'a hiba időpontját is');

        // És tényleg a SORBAN legyen, ne csak a példányon.
        $sor = DB::table('emails')->where('to', self::CIM)->first();
        self::assertStringContainsString('Could not authenticate', (string) $sor->error_reason);
    }

    /** A túl hosszú SMTP-üzenet ne dobja el a mentést. */
    public function testAVeryLongReasonIsTruncated(): void {
        $email = new HibaztathatoEmail();
        $email->to = self::CIM;
        $email->type = self::TIPUS;
        $email->subject = 'teszt';
        $email->body = 'teszt';

        $email->hibazz(str_repeat('x', 5000));

        self::assertLessThanOrEqual(1000, mb_strlen((string) $email->error_reason));
    }

    /* ---- 2. A saját összeomlásunk külön státusz ---- */

    public function testAStuckRowBecomesCrashedNotError(): void {
        $regi = date('Y-m-d H:i:s', strtotime('-5 days'));
        $this->sor('sending', $regi, $regi);

        \Eloquent\Email::requeueStuck();

        $sor = DB::table('emails')->where('to', self::CIM)->first();

        self::assertSame(\Eloquent\Email::STATUS_CRASHED, $sor->status);
        self::assertStringContainsString('Nem a címzett hibája', (string) $sor->error_reason,
            'a napló és a /health is mondja meg, kinek a hibája');
    }

    /**
     * ...és ettől a cím NEM lesz kézbesíthetetlen.
     *
     * Ez a teszt a régi kódon megbukna: három 'crashed' sor pontosan elérte volna az
     * UNDELIVERABLE_ATTEMPTS küszöböt.
     */
    public function testOurOwnCrashesDoNotSilenceAWorkingAddress(): void {
        $regi = date('Y-m-d H:i:s', strtotime('-5 days'));
        for ($i = 0; $i < 3; $i++) {
            $this->sor('sending', $regi, $regi);
        }

        \Eloquent\Email::requeueStuck();

        self::assertSame(3, DB::table('emails')->where('to', self::CIM)
            ->where('status', \Eloquent\Email::STATUS_CRASHED)->count());

        self::assertFalse(\User::isUndeliverable(self::TIPUS, self::CIM),
            'a mi leallasunk nem bizonyit semmit a cimrol');
    }

    /** A valódi visszautasítás viszont továbbra is számít. */
    public function testRealRejectionsStillCount(): void {
        $regi = date('Y-m-d H:i:s', strtotime('-5 days'));
        for ($i = 0; $i < 3; $i++) {
            $this->sor('error', $regi, $regi);
        }

        self::assertTrue(\User::isUndeliverable(self::TIPUS, self::CIM));
    }

    /**
     * A 'crashed' attól még „megpróbáltuk": a következő futás ne küldje ki azonnal újra.
     *
     * A két fogalom KÜLÖN — ezért van két lista.
     */
    public function testACrashedRowStillCountsAsAnAttempt(): void {
        self::assertContains(\Eloquent\Email::STATUS_CRASHED, \Eloquent\Email::attemptedStatuses());
        self::assertNotContains(\Eloquent\Email::STATUS_CRASHED, \Eloquent\Email::rejectedStatuses());
    }

    /* ---- 3. A friss sor ne minősüljön beragadtnak ---- */

    public function testAFreshSendingRowIsLeftAlone(): void {
        $most = date('Y-m-d H:i:s');
        $this->sor('sending', $most, $most);

        \Eloquent\Email::requeueStuck();

        self::assertSame('sending', DB::table('emails')->where('to', self::CIM)->value('status'));
    }
}

/**
 * A `fail()` védett — a hívói mind az SMTP-ágon vannak, amit tesztben nem akarunk
 * megjárni. Itt nyitjuk ki, hogy a hibakezelést hálózat nélkül lehessen mérni.
 */
class HibaztathatoEmail extends \Eloquent\Email {

    /** Az Eloquent a leszármazott nevéből találná ki a táblát; ugyanaz a tábla kell. */
    protected $table = 'emails';

    public function hibazz(string $reason): bool {
        return $this->fail($reason);
    }
}
