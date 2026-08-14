<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #724: „hogyan lehetne a legtöbb használható adatot gyűjteni anélkül, hogy ehhez sokat
 * kéne papírozni?"
 *
 * Úgy, hogy semmit nem tárolunk, amiből személy azonosítható. Ezek a tesztek pontosan ezt
 * őrzik: az útvonal normalizált (nem nyers URL), a robotok kimaradnak, és a táblákban
 * nincs se IP, se süti, se User-Agent oszlop.
 */
class UsageStatsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::connection()->beginTransaction();
    }

    protected function tearDown(): void {
        DB::connection()->rollBack();
        parent::tearDown();
    }

    /**
     * A legfontosabb: a tábla NEM tartalmazhat személyhez köthető oszlopot. Ha valaki
     * később IP-t vagy User-Agentet akarna felvenni, ez a teszt megállítja.
     */
    public function testTablesCarryNothingPersonal(): void {
        foreach (['stats_pageviews', 'stats_searches'] as $table) {
            $oszlopok = array_map(
                static fn($c) => strtolower($c->Field),
                DB::select('SHOW COLUMNS FROM ' . $table)
            );
            foreach (['ip', 'ip_address', 'user_agent', 'useragent', 'session', 'session_id',
                      'user_id', 'cookie', 'uid', 'client_id'] as $tiltott) {
                $this->assertNotContains($tiltott, $oszlopok,
                    "A(z) $table táblába bekerült egy személyhez köthető oszlop: $tiltott");
            }
            // Idő csak NAPRA pontosan — a percre pontos időbélyeg már ujjlenyomat lenne.
            $this->assertContains('date', $oszlopok);
            $this->assertNotContains('created_at', $oszlopok);
        }
    }

    /** @dataProvider utvonalak */
    public function testRouteIsNormalized(string $nyers, string $vart): void {
        $this->assertSame($vart, \Stats::normalizeRoute($nyers));
    }

    public static function utvonalak(): array {
        return [
            'üres' => ['', 'home'],
            'templom azonosítóval' => ['templom/5444', 'templom/{id}'],
            'másik templom ugyanaz a sor' => ['templom/1', 'templom/{id}'],
            'aloldal' => ['church/5444/edit', 'church/{id}/edit'],
            'api' => ['api/v4/nearby', 'api/v4/nearby'],
            'per jelek' => ['/templom/12/', 'templom/{id}'],
            'szabad szöveg kiszűrve' => ['templom/<script>', 'templom/script'],
            'nagyon mély út levágva' => ['a/b/c/d/e/f', 'a/b/c/d'],
        ];
    }

    /**
     * Ez tartja a táblát kicsiben: 5000 templom NEM jelent 5000 sort naponta.
     */
    public function testDifferentChurchesShareOneRow(): void {
        $this->assertSame(
            \Stats::normalizeRoute('templom/1'),
            \Stats::normalizeRoute('templom/5444')
        );
    }

    /** @dataProvider botok */
    public function testBotsAreNotCounted(?string $userAgent, bool $bot): void {
        $this->assertSame($bot, \Stats::isBot($userAgent));
    }

    public static function botok(): array {
        return [
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true],
            'curl' => ['curl/8.7.1', true],
            'uptime-figyelő' => ['Better Uptime Bot', true],
            'hiányzó UA' => [null, true],
            'üres UA' => ['', true],
            'valódi böngésző' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0.0.0 Safari/537.36', false],
            'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1', false],
        ];
    }

    /** @dataProvider kulcsszavak */
    public function testKeywordIsNormalized(?string $nyers, ?string $vart): void {
        $this->assertSame($vart, \Stats::normalizeKeyword($nyers));
    }

    public static function kulcsszavak(): array {
        return [
            'kisbetűsítve' => ['Szent István', 'szent istván'],
            'szóközök összevonva' => ["  Szent   István \n", 'szent istván'],
            'túl rövid kimarad' => ['a', null],
            'üres kimarad' => ['', null],
            'null kimarad' => [null, null],
            'túl hosszú kimarad' => [str_repeat('a', 101), null],
        ];
    }

    public function testCountingIsIdempotentPerDayAndAggregates(): void {
        // A record* a szűrők nélküli írás; a szűrést (CLI, robot) a count* végzi, azt a
        // shouldCount()/isBot() tesztek fedik. A teszt maga CLI-ből fut, tehát a count*
        // itt szándékosan nem írna semmit.
        $utvonal = 'teszt/{id}';
        DB::table('stats_pageviews')->where('route', $utvonal)->delete();

        \Stats::recordPageview('teszt/1');
        \Stats::recordPageview('teszt/2');
        \Stats::recordPageview('teszt/3');

        $sorok = DB::table('stats_pageviews')->where('route', $utvonal)->get();
        $this->assertCount(1, $sorok, 'Napi bontásban útvonalanként EGY sor keletkezhet.');
        $this->assertSame(3, (int) $sorok[0]->count);
        $this->assertSame(date('Y-m-d'), (string) $sorok[0]->date);
    }

    public function testSearchesAreSplitByWhetherAnythingWasFound(): void {
        DB::table('stats_searches')->where('keyword', 'teszt kifejezés')->delete();

        \Stats::recordSearch('Teszt Kifejezés', 12);
        \Stats::recordSearch('teszt kifejezés', 0);
        \Stats::recordSearch('teszt  kifejezés', 0);

        $talalt = DB::table('stats_searches')->where('keyword', 'teszt kifejezés')->where('hits', 1)->first();
        $nemTalalt = DB::table('stats_searches')->where('keyword', 'teszt kifejezés')->where('hits', 0)->first();

        $this->assertSame(1, (int) $talalt->count);
        $this->assertSame(2, (int) $nemTalalt->count, 'A nulla találatos keresés a legértékesebb adat.');
    }

    public function testBotTrafficDoesNotReachTheCounter(): void {
        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
        DB::table('stats_pageviews')->where('route', 'robot/{id}')->delete();

        \Stats::countPageview('robot/9');

        $this->assertFalse(\Stats::shouldCount());
        $this->assertSame(0, DB::table('stats_pageviews')->where('route', 'robot/{id}')->count());
    }

    /** A cron- és CLI-futás nem használat: azt sem szabad beleszámolni. */
    public function testCliRequestsAreNotCounted(): void {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/150';
        DB::table('stats_pageviews')->where('route', 'cli/{id}')->delete();

        \Stats::countPageview('cli/1');

        $this->assertSame('cli', PHP_SAPI);
        $this->assertFalse(\Stats::shouldCount());
        $this->assertSame(0, DB::table('stats_pageviews')->where('route', 'cli/{id}')->count());
    }

    /** A takarító cron a registryben van, különben sosem futna le. */
    public function testCleanupCronIsRegistered(): void {
        $registry = \Eloquent\Cron::registry();
        $megvan = array_filter($registry, static fn($job) =>
            ($job['class'] ?? '') === '\Crons' && ($job['function'] ?? '') === 'cleanUsageStats');

        $this->assertCount(1, $megvan, 'A cleanUsageStats hiányzik a cron-registryből.');
    }

    public function testCleanupDropsOldRowsOnly(): void {
        $regi = date('Y-m-d', strtotime('-' . \Stats::MEGORZES . ' -1 day'));
        $uj = date('Y-m-d');
        DB::table('stats_pageviews')->insert([
            ['date' => $regi, 'route' => 'regi/{id}', 'kind' => 'html', 'count' => 5],
            ['date' => $uj, 'route' => 'uj/{id}', 'kind' => 'html', 'count' => 5],
        ]);

        \Crons::cleanUsageStats();

        $this->assertSame(0, DB::table('stats_pageviews')->where('route', 'regi/{id}')->count());
        $this->assertSame(1, DB::table('stats_pageviews')->where('route', 'uj/{id}')->count());
    }
}
