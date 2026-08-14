<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * Élesben a `\User::deleteNonActivatedUsers()` 135 napja nem futott le sikeresen:
 * 65 próbálkozás, egyetlen siker nélkül, a deadline_at pedig 2026-03-27-en állt.
 *
 * Két, egymást erősítő ok:
 *
 *  1. A sorrend `attempts ASC` volt, a futtató pedig körönként EGY munkát indít. Amelyik
 *     egyszer felhalmozott néhány próbálkozást, az a sor végére került, és csak akkor
 *     jutott szóhoz, ha épp semmi más nem volt esedékes.
 *  2. A `deadline_at` CSAK sikerre újult meg. Egy bukó munka örökre „esedékes" maradt,
 *     minden kopogás újrapróbálta, az attempts percek alatt átlépte a 10-et — onnantól
 *     pedig a scopeNextJobs 12 órára kizárta.
 */
class CronStarvationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::connection()->beginTransaction();
    }

    protected function tearDown(): void {
        DB::connection()->rollBack();
        parent::tearDown();
    }

    private function job(string $function, string $deadline, int $attempts, ?string $updatedAt = null): \Eloquent\Cron {
        $cron = new \Eloquent\Cron();
        $cron->class = '\Crons';
        $cron->function = $function;
        $cron->frequency = '1 day';
        $cron->attempts = $attempts;
        $cron->deadline_at = $deadline;
        $cron->save();

        if ($updatedAt !== null) {
            // Az Eloquent minden mentésnél a mostani időt írja az updated_at-be, a
            // scopeNextJobs viszont pont ezt nézi — kézzel kell visszaállítani.
            DB::table('crons')->where('id', $cron->id)->update(['updated_at' => $updatedAt]);
            $cron->refresh();
        }

        return $cron;
    }

    /**
     * A lényeg: a régóta esedékes, sokat bukott munka MEGELŐZI a frissen esedékes,
     * hibátlan munkát. Korábban pont fordítva volt, és ezért nem került soha sorra.
     */
    public function testTheLongestOverdueJobComesFirstEvenWithManyAttempts(): void {
        DB::table('crons')->update(['deadline_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        // Az élesen látott állapot: 135 napja esedékes, 65 bukott próbálkozás, és a
        // 10-es korlát miatt csak 12 óránként egyszer láthatja meg a futtató.
        $ehezo = $this->job('tesztEhezo', date('Y-m-d H:i:s', strtotime('-135 days')), 65,
            date('Y-m-d H:i:s', strtotime('-13 hours')));
        $friss = $this->job('tesztFriss', date('Y-m-d H:i:s', strtotime('-1 minute')), 0);

        $sorrend = \Eloquent\Cron::nextJobs()->get()
            ->map(static fn($j) => $j->function)->all();

        $this->assertContains('tesztEhezo', $sorrend, 'A 65 próbálkozásos munka ki sem került a listába.');
        $this->assertSame('tesztEhezo', $sorrend[0],
            'A régóta esedékes munkának kell elöl lennie, különben újra kiéhezik.');
        $this->assertContains('tesztFriss', $sorrend);
        unset($ehezo, $friss);
    }

    /** Azonos esedékességnél viszont a kevesebbszer bukott menjen előbb. */
    public function testAttemptsBreakTheTieAtEqualDeadlines(): void {
        DB::table('crons')->update(['deadline_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        $mikor = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        $this->job('tesztSokBukas', $mikor, 9);
        $this->job('tesztKevesBukas', $mikor, 0);

        $sorrend = \Eloquent\Cron::nextJobs()->get()
            ->map(static fn($j) => $j->function)->all();

        $this->assertSame(['tesztKevesBukas', 'tesztSokBukas'], array_slice($sorrend, 0, 2));
    }

    /**
     * Bukás után a következő próbálkozás a szokásos ritmus szerint jöjjön — ne minden
     * ötperces kopogásnál.
     */
    public function testFailureMovesTheDeadlineForward(): void {
        $job = $this->job('tesztBukik', date('Y-m-d H:i:s', strtotime('-1 hour')), 3);

        $job->backOff();
        $job->refresh();

        $this->assertGreaterThan(time() + 3600, strtotime((string) $job->deadline_at),
            'Bukás után a napi munkának nem szabad azonnal újra esedékesnek lennie.');
        // A bukás nem siker: a lastsuccess_at nem mozdulhat.
        $this->assertNull($job->lastsuccess_at);
    }

    /**
     * A 10-es korlát fölött a munka 12 óránként EGYSZER látszik. Ez a fék önmagában
     * rendben van; a baj az volt, hogy a bukó munka percek alatt átlépte a korlátot,
     * mert a deadline_at sosem mozdult. A backOff() óta ez lassan gyűlik.
     */
    public function testTooManyAttemptsThrottleButDoNotBlockForever(): void {
        DB::table('crons')->update(['deadline_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        $frissenProbalt = $this->job('tesztFrissenProbalt', date('Y-m-d H:i:s', strtotime('-1 hour')), 65,
            date('Y-m-d H:i:s', strtotime('-1 minute')));
        $sorrend = \Eloquent\Cron::nextJobs()->get()->map(static fn($j) => $j->function)->all();
        $this->assertNotContains('tesztFrissenProbalt', $sorrend, 'A most próbált munka várjon a fékkel.');

        DB::table('crons')->where('id', $frissenProbalt->id)
            ->update(['updated_at' => date('Y-m-d H:i:s', strtotime('-13 hours'))]);

        $sorrend = \Eloquent\Cron::nextJobs()->get()->map(static fn($j) => $j->function)->all();
        $this->assertContains('tesztFrissenProbalt', $sorrend, '12 óra után újra sorra kell kerülnie.');
    }

    /**
     * A siker viszont nullázza a számlálót — így egy korábban elakadt munka
     * magától visszatér a normál kerékvágásba.
     */
    public function testSuccessClearsTheAttemptPenalty(): void {
        $job = $this->job('tesztSikerul', date('Y-m-d H:i:s', strtotime('-1 hour')), 65);

        $job->success();
        $job->refresh();

        $this->assertSame(0, (int) $job->attempts);
        $this->assertNotNull($job->lastsuccess_at);
    }
}
