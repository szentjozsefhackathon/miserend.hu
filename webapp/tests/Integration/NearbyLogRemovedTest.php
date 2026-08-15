<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #724: az `/api/v4/nearby` végpont egy `nearby.log` fájlba írta a hívó KOORDINÁTÁJÁT,
 * User-Agentjét és a pontos időt, egy hónapos megőrzéssel, és a /stat hőtérképen meg is
 * jelenítette.
 *
 * Ez szembement a saját adatvédelmi tájékoztatónkkal: „A helyadatot kizárólag a keresés
 * elvégzéséhez használjuk fel, semmilyen formában nem rögzítjük és nem tároljuk – sem
 * azonosítva, sem anonim módon."
 *
 * A helyadat + időpont + User-Agent együtt akkor is azonosíthat valakit, ha nevet nem
 * tárolunk mellé. Ezért a naplózás megszűnt — ezek a tesztek azt őrzik, hogy ne
 * szivárogjon vissza.
 */
class NearbyLogRemovedTest extends TestCase {

    private string $baseUrl;

    protected function setUp(): void {
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');
    }

    /** A naplót író és olvasó metódusok nem létezhetnek. */
    public function testTheLoggingMethodsAreGone(): void {
        $this->assertFalse(method_exists('\Api\NearBy', 'cleanOldLogs'));
        $this->assertFalse(method_exists('\Api\NearBy', 'getLogFileInfo'));
    }

    /** A kódban nem maradhat írás a naplófájlba. */
    public function testNoCodeWritesTheLogFile(): void {
        $forras = (string) file_get_contents(PATH . 'classes/api/nearby.php');

        $this->assertStringNotContainsString('file_put_contents', $forras);
        $this->assertStringNotContainsString('HTTP_USER_AGENT', $forras);
    }

    /**
     * A végpont hívása nem írhat a naplóba.
     *
     * Szándékosan a fájl MÉRETÉT nézzük, nem a létezését: a `nearby.log` régebbi
     * image-ekbe be van építve, tehát egy még nem újraépített konténerben ott lehet a
     * fájl anélkül, hogy bárki írna bele. Az invariáns az írás hiánya.
     */
    public function testCallingTheEndpointDoesNotWriteTheLog(): void {
        $utvonalak = [PATH . '../nearby.log', PATH . 'nearby.log'];
        $elotte = [];
        foreach ($utvonalak as $utvonal) {
            clearstatcache(true, $utvonal);
            $elotte[$utvonal] = file_exists($utvonal) ? filesize($utvonal) : null;
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['lat' => 47.4979, 'lon' => 19.0402]),
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $valasz = @file_get_contents($this->baseUrl . '/api/v4/nearby', false, $ctx);
        if ($valasz === false) {
            $this->markTestSkipped('A futó példány nem érhető el.');
        }

        // A válasz maga rendben van — csak nem naplózunk.
        $this->assertIsArray(json_decode($valasz, true));

        foreach ($utvonalak as $utvonal) {
            clearstatcache(true, $utvonal);
            $utana = file_exists($utvonal) ? filesize($utvonal) : null;
            $this->assertSame($elotte[$utvonal], $utana,
                'A hívás megváltoztatta a naplófájlt: ' . $utvonal);
        }
    }

    /** A takarító cron sem maradhat a registryben. */
    public function testTheCleanupCronIsNoLongerRegistered(): void {
        foreach (\Eloquent\Cron::registry() as $job) {
            $this->assertNotSame('cleanOldLogs', $job['function'] ?? null);
        }
    }

    /**
     * A registryből kivett munka sorát az adatbázisból is el kell takarítani, különben a
     * futtató minden esedékességnél elhasal rajta („Function ... does not exists.").
     */
    public function testRemovedJobsArePrunedFromTheDatabase(): void {
        DB::connection()->beginTransaction();
        try {
            $kisertet = new \Eloquent\Cron();
            $kisertet->class = '\Api\NearBy';
            $kisertet->function = 'cleanOldLogs';
            $kisertet->frequency = '1 day';
            $kisertet->attempts = 0;
            $kisertet->deadline_at = date('Y-m-d H:i:s', time() - 60);
            $kisertet->save();

            $eltavolitott = \Eloquent\Cron::pruneRemoved();

            $this->assertContains('\Api\NearBy->cleanOldLogs()', $eltavolitott);
            $this->assertFalse(\Eloquent\Cron::whereKey($kisertet->id)->exists());

            // A registryben szereplő munkákhoz nem nyúlhat.
            $this->assertTrue(
                \Eloquent\Cron::where('function', 'updateMasses')->exists(),
                'A takarítás élő cron-sort is törölt.'
            );
        } finally {
            DB::connection()->rollBack();
        }
    }

    /**
     * Üres vagy olvashatatlan registrynél NEM törlünk: egy hiányzó fájl miatt nem szabad
     * az összes ütemezést elveszíteni.
     */
    public function testEmptyRegistryPrunesNothing(): void {
        $eredeti = PATH . 'fajlok/crons.php';
        $mentes = $eredeti . '.teszt-mentes';

        if (!is_writable(dirname($eredeti))) {
            $this->markTestSkipped('A registry könyvtára nem írható.');
        }

        rename($eredeti, $mentes);
        try {
            $this->assertSame([], \Eloquent\Cron::registry());
            $this->assertSame([], \Eloquent\Cron::pruneRemoved());
            $this->assertGreaterThan(0, \Eloquent\Cron::count(), 'Üres registrynél is maradnia kell cron-soroknak.');
        } finally {
            rename($mentes, $eredeti);
        }
    }
}
