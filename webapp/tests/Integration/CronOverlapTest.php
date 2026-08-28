<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * Az élesen látott tünet: a `\ExternalApi\ElasticsearchApi->updateMasses()` sora
 * attempts = 10-en állt, miközben egy nappal korábban még sikeresen lefutott.
 *
 * Az ok nem hiba, hanem átfedés: az updateMasses fél óráig is futhat (500 ezer+
 * liturgikus esemény; helyben, 5051 templommal 15 perc volt), a hoszt crontabja
 * viszont 5 percenként kopogtat. Minden kopogás megnövelte az attempts-et és
 * elindított egy újabb futást a még dolgozó mellé; 10 fölött pedig a scopeNextJobs
 * 12 órára kizárta a munkát.
 *
 * A konténerbeli cron-loop.sh sorosan fut, ezért ott ez sosem látszott.
 */
class CronOverlapTest extends TestCase {

    /** Külön kapcsolat, hogy a zárat tényleg MÁSIK futásként tartsuk. */
    private static function masikKapcsolat(): \Illuminate\Database\Connection {
        global $config;
        $c = $config['connection'];
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $c['host'],
            'database' => $c['database'],
            'username' => $c['user'],
            'password' => $c['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            // #890: ez a kapcsolat megkerüli a `dbconnect()`-et, tehát külön kell
            // megkapnia a zónát — enélkül a szerver SYSTEM (=UTC) zónáján ülne, és
            // egy ide tévedő időbélyeg-összehasonlítás némán két órát tévedne.
            'timezone' => date_default_timezone_get(),
        ], 'masik');

        return $capsule->getConnection('masik');
    }

    protected function setUp(): void {
        parent::setUp();
        DB::connection()->beginTransaction();
        // Biztonsági háló: ha az őr egyszer kikerülne a kódból, ez a teszt akkor se
        // indítson el egy valódi, félórás újraindexelést — csak bukjon el.
        DB::table('crons')->update(['deadline_at' => date('Y-m-d H:i:s', strtotime('+1 day'))]);
    }

    protected function tearDown(): void {
        DB::connection()->rollBack();
        parent::tearDown();
    }

    public function testRunningJobIsNotStartedAgainByTheNextTick(): void {
        $masik = self::masikKapcsolat();

        // Egy "épp futó" cron a másik kapcsolaton.
        $fogott = $masik->selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['miserend_cron']);
        $this->assertSame(1, (int) $fogott->acquired, 'A tesztzárat nem sikerült megszerezni.');

        try {
            $job = \Eloquent\Cron::where('function', 'updateMasses')->firstOrFail();
            $elotte = (int) $job->attempts;

            // Most kopog a következő kör.
            ob_start();
            new \Html\Cron();
            $kimenet = ob_get_clean();

            $this->assertStringContainsString('Már fut egy cron-munka', $kimenet);

            // A lényeg: a párhuzamos kopogás nem égeti el az attempts-et.
            $job->refresh();
            $this->assertSame($elotte, (int) $job->attempts);
        } finally {
            $masik->selectOne('SELECT RELEASE_LOCK(?)', ['miserend_cron']);
            $masik->disconnect();
        }
    }

    /** Ha senki nem tartja a zárat, a cron a szokásos módon dolgozik. */
    public function testFreeLockLetsTheCronRun(): void {
        $masik = self::masikKapcsolat();
        try {
            $szabad = $masik->selectOne('SELECT IS_FREE_LOCK(?) AS free', ['miserend_cron']);
            $this->assertSame(1, (int) $szabad->free, 'A zár nem szabadult fel egy korábbi futás után.');
        } finally {
            $masik->disconnect();
        }
    }
}
