<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Az éles /health szerint az elmúlt 30 napban 89 levél ragadt 'sending' státuszban
 * (mind user_pleaselogin, mellette 48 'error' és csak 47 'sent').
 *
 * Két, egymást erősítő hiba volt mögötte:
 *
 *  1. A send() legelső dolga 'sending'-re állítani a sort, és csak utána próbálkozik az
 *     SMTP-vel. Ha a folyamat közben hal meg, a sor örökre 'sending' marad — a
 *     sendQueued() csak a 'queued' sorokat szedi elő, tehát soha többé nem próbálkozik
 *     vele senki.
 *  2. Az értesítő cronok dedup-lekérdezése csak a 'queued'/'sent' sorokat nézte, így egy
 *     beragadt levél láthatatlan volt: a következő futás újraküldte ugyanazt.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class EmailStuckRequeueTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function makeEmail(string $status, string $createdAt, string $updatedAt): int {
        return DB::table('emails')->insertGetId([
            'type'       => 'teszt_tipus',
            'to'         => 'teszt@example.com',
            'subject'    => 'Teszt',
            'body'       => 'Teszt törzs',
            'status'     => $status,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function statusOf(int $id): string {
        return (string) DB::table('emails')->where('id', $id)->value('status');
    }

    public function testABeragadtLevelVisszakerulASorba(): void {
        $id = $this->makeEmail(
            'sending',
            date('Y-m-d H:i:s', strtotime('-2 hours')),
            date('Y-m-d H:i:s', strtotime('-2 hours'))
        );

        $result = \Eloquent\Email::requeueStuck();

        $this->assertSame('queued', $this->statusOf($id));
        $this->assertGreaterThanOrEqual(1, $result['requeued']);
    }

    public function testAzEppKuldesAlattLevoLevelhezNemNyulunk(): void {
        $id = $this->makeEmail(
            'sending',
            date('Y-m-d H:i:s', strtotime('-2 minutes')),
            date('Y-m-d H:i:s', strtotime('-2 minutes'))
        );

        \Eloquent\Email::requeueStuck();

        $this->assertSame(
            'sending',
            $this->statusOf($id),
            'Egy percek óta küldés alatt álló levelet nem szabad kettéküldeni.'
        );
    }

    /**
     * #845: a feladás státusza 'crashed', nem 'error'.
     *
     * Ez a sor azért ragadt be, mert a MI folyamatunk halt meg küldés közben — nem a
     * címzett utasította vissza. Amíg a kettő ugyanaz a státusz volt, az
     * `User::isUndeliverable()` a saját leállásunkat is a cím rovására írta: három ilyen
     * sor egy tökéletesen működő címre is örökre elnémította az értesítőt.
     */
    public function testARegotaProbalkozoLevelHibaraKerul(): void {
        // Négy napja jött létre, azóta is 'sending' — ezzel már nem próbálkozunk tovább,
        // különben egy önmagában végzetes levél a végtelenségig pörögne a sorban.
        $id = $this->makeEmail(
            'sending',
            date('Y-m-d H:i:s', strtotime('-4 days')),
            date('Y-m-d H:i:s', strtotime('-4 days'))
        );

        $result = \Eloquent\Email::requeueStuck();

        $this->assertSame(\Eloquent\Email::STATUS_CRASHED, $this->statusOf($id));
        $this->assertGreaterThanOrEqual(1, $result['failed']);
    }

    public function testMasStatuszokatNemBantja(): void {
        $sent   = $this->makeEmail('sent',   date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d H:i:s', strtotime('-2 days')));
        $error  = $this->makeEmail('error',  date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d H:i:s', strtotime('-2 days')));
        $queued = $this->makeEmail('queued', date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d H:i:s', strtotime('-2 days')));

        \Eloquent\Email::requeueStuck();

        $this->assertSame('sent', $this->statusOf($sent));
        $this->assertSame('error', $this->statusOf($error));
        $this->assertSame('queued', $this->statusOf($queued));
    }

    /**
     * A dedup a 'sending' és 'error' sorokat is megpróbált értesítésnek tekinti, különben
     * ugyanannak a felhasználónak minden futásban újra kimenne a levél.
     */
    public function testADedupASendingEsErrorSorokatIsSzamolja(): void {
        $statuses = \Eloquent\Email::attemptedStatuses();
        $this->assertContains('sending', $statuses);
        $this->assertContains('error', $statuses);
        $this->assertContains('queued', $statuses);
        $this->assertContains('sent', $statuses);
    }

    /**
     * Ez a teszt a valós élettörténetet játssza le: a felhasználó kapott egy levelet, ami
     * 'sending'-ben ragadt. A régi dedup ('queued','sent') nem látta, tehát a cron
     * újraküldte volna.
     */
    public function testABeragadtLevelUtanNemMegyKiUjra(): void {
        DB::table('emails')->insert([
            'type'       => 'user_pleaselogin',
            'to'         => 'ragadt@example.com',
            'subject'    => 'Teszt',
            'body'       => 'Teszt törzs',
            'status'     => 'sending',
            'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'updated_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
        ]);

        $regi = DB::table('emails')
            ->where('type', 'user_pleaselogin')
            ->where('to', 'ragadt@example.com')
            ->whereIn('status', ['queued', 'sent'])
            ->first();
        $this->assertNull($regi, 'A régi dedup tényleg nem látta a beragadt levelet.');

        $uj = DB::table('emails')
            ->where('type', 'user_pleaselogin')
            ->where('to', 'ragadt@example.com')
            ->whereIn('status', \Eloquent\Email::attemptedStatuses())
            ->first();
        $this->assertNotNull($uj, 'Az új dedupnak látnia kell, hogy már próbálkoztunk.');
    }
}
