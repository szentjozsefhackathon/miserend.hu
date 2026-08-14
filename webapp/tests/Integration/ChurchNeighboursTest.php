<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #748: a szomszédos templomok listája egy részüket kétszer mutatta.
 *
 * A `distances` táblát a cron templomonként tölti: minden templomra lefut a
 * `Distance::MupdateChurch()`, ami CSAK `from = az adott templom` irányban ír
 * sort. Így ugyanaz a pár előbb-utóbb MINDKÉT irányban benne van (A->B és B->A).
 * A `getNeighboursAttribute()` mindkét irányban keres (#103), tehát ugyanazt a
 * szomszédot két külön sorból is megtalálta.
 */
class ChurchNeighboursTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function createChurch(string $name, float $lat, float $lon): int {
        return DB::table('templomok')->insertGetId([
            'nev'        => $name,
            'varos'      => 'Budapest',
            'frissites'  => '2020-01-01',
            'ok'         => 'i',
            'plebania'   => '',
            'leiras'     => '',
            'megjegyzes' => '',
            'misemegj'   => '',
            'bucsu'      => '',
            'adminmegj'  => '',
            'log'        => '',
            'lat'        => $lat,
            'lon'        => $lon,
        ]);
    }

    private function addDistance(float $fromLat, float $fromLon, float $toLat, float $toLon, int $distance): void {
        DB::table('distances')->insert([
            'fromLat'  => $fromLat,
            'fromLon'  => $fromLon,
            'toLat'    => $toLat,
            'toLon'    => $toLon,
            'distance' => $distance,
            'toupdate' => 0,
        ]);
    }

    /** A hibás viselkedés magja: oda-vissza sor ugyanarra a párra. */
    public function testBidirectionalDistanceRowsYieldTheNeighbourOnlyOnce(): void {
        $aId = $this->createChurch('748 A', 47.111111, 19.111111);
        $bId = $this->createChurch('748 B', 47.222222, 19.222222);

        $a = \Eloquent\Church::find($aId);
        $b = \Eloquent\Church::find($bId);

        // Ahogy a cron írja: előbb A-ból, majd B-ből is lefut.
        $this->addDistance($a->lat, $a->lon, $b->lat, $b->lon, 1200);
        $this->addDistance($b->lat, $b->lon, $a->lat, $a->lon, 1200);

        $neighbours = $a->neighbours;

        self::assertCount(1, $neighbours, 'Az oda-vissza sorpár ugyanazt a szomszédot adta kétszer.');
        self::assertSame($bId, $neighbours->first()->id);
    }

    /** Több szomszéd esetén is pontosan egyszer szerepeljen mindegyik. */
    public function testEveryNeighbourAppearsExactlyOnce(): void {
        $center = $this->createChurch('748 Közép', 47.300000, 19.300000);
        $c = \Eloquent\Church::find($center);

        $ids = [];
        foreach ([[47.310000, 19.300000, 900], [47.320000, 19.300000, 1800], [47.330000, 19.300000, 2700]] as $i => $spec) {
            [$lat, $lon, $dist] = $spec;
            $id = $this->createChurch('748 Szomszéd ' . $i, $lat, $lon);
            $n = \Eloquent\Church::find($id);
            $ids[] = $id;
            $this->addDistance($c->lat, $c->lon, $n->lat, $n->lon, $dist);
            $this->addDistance($n->lat, $n->lon, $c->lat, $c->lon, $dist);
        }

        $neighbourIds = $c->neighbours->pluck('id')->all();

        self::assertSame($ids, $neighbourIds, 'A szomszédok duplán vagy rossz sorrendben jöttek vissza.');
        self::assertSame(count($neighbourIds), count(array_unique($neighbourIds)));
    }

    /** A templom saját maga soha nem lehet a saját szomszédja. */
    public function testChurchIsNeverItsOwnNeighbour(): void {
        $id = $this->createChurch('748 Önmaga', 47.400000, 19.400000);
        $church = \Eloquent\Church::find($id);

        $this->addDistance($church->lat, $church->lon, $church->lat, $church->lon, 0);

        self::assertCount(0, $church->neighbours);
    }

    /** A duplikátumok nem ehetik el a 10-es keretet. */
    public function testDuplicateRowsDoNotEatTheTenItemLimit(): void {
        $center = $this->createChurch('748 Keret', 47.500000, 19.500000);
        $c = \Eloquent\Church::find($center);

        for ($i = 1; $i <= 12; $i++) {
            $id = $this->createChurch('748 Keret szomszéd ' . $i, 47.500000 + ($i / 1000), 19.500000);
            $n = \Eloquent\Church::find($id);
            $this->addDistance($c->lat, $c->lon, $n->lat, $n->lon, $i * 100);
            $this->addDistance($n->lat, $n->lon, $c->lat, $c->lon, $i * 100);
        }

        $neighbours = $c->neighbours;

        self::assertCount(10, $neighbours, 'A duplikátumok miatt 10-nél kevesebb szomszéd fért a listába.');
        self::assertSame(10, $neighbours->pluck('id')->unique()->count());
    }
}
