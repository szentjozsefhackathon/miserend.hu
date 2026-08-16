<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #496: a határ nélkül maradt templomok újra sorba állítása.
 *
 * A `checkBoundaries()` sora `boundaries_checked_at` szerint halad, és a #570/#700
 * óta helyesen megkülönbözteti a HIBÁT a „lekérdeztük, de nincs határ" esettől:
 * hibánál nem bélyegez, tehát a templom a sor elején marad. A második eset viszont
 * bélyeget kap — és ott is marad, amíg a teljes sor körbe nem ér.
 *
 * Ez akkor fáj, ha a „nincs határ" nem az OSM valósága volt, hanem a MI oldalunk
 * változott azóta. Pontosan ez történt Szlovákiában: a szlovák minta 23%-ának
 * egyáltalán nincs határa, és a #699 óta a lekérdezés a 4-es szintet is behúzza —
 * a régen bélyegzett templomok viszont ettől még nem próbálkoznak újra.
 */
class RequeueChurchesWithoutBoundaryTest extends TestCase {

    private int $sorszam = 0;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * @param string|null $ellenorizve a boundaries_checked_at értéke
     * @param bool $vanHatar kapjon-e administratív határt
     * @param array<string,mixed> $mezok egyéb felülírandó oszlopok
     */
    private function templom(?string $ellenorizve, bool $vanHatar, array $mezok = []): int {
        $minta = (array) DB::table('templomok')->first();
        $id = (int) DB::table('templomok')->max('id') + 1 + $this->sorszam++;

        DB::table('templomok')->insert(array_merge($minta, [
            'id' => $id, 'ok' => 'i', 'lat' => 48.1, 'lon' => 17.1,
            'boundaries_checked_at' => $ellenorizve,
        ], $mezok));

        if ($vanHatar) {
            $bid = DB::table('boundaries')->insertGetId([
                'boundary' => 'administrative', 'admin_level' => 8,
                'name' => 'Teszt település ' . $id, 'osmtype' => 'relation', 'osmid' => 600000 + $id,
            ]);
            DB::table('lookup_boundary_church')->insert(['boundary_id' => $bid, 'church_id' => $id]);
        }

        return $id;
    }

    private function bélyeg(int $id): ?string {
        return DB::table('templomok')->where('id', $id)->value('boundaries_checked_at');
    }

    /** A lényeg: a régen ellenőrzött, határ nélküli templom visszakerül a sor elejére. */
    public function testARegenEllenorzottHatarNelkuliVisszakerul(): void {
        $id = $this->templom(date('Y-m-d H:i:s', strtotime('-60 days')), false);

        \Crons::requeueChurchesWithoutBoundary();

        self::assertNull($this->bélyeg($id));
    }

    /**
     * A 30 napos korlát szándékos: enélkül azok a templomok, amiknek tényleg nincs
     * határuk, minden futásban visszakerülnének a sor elejére, és kiszorítanák a
     * valóban ellenőrizendőket.
     */
    public function testAFrissenEllenorzottetNemBantja(): void {
        $id = $this->templom(date('Y-m-d H:i:s', strtotime('-3 days')), false);

        \Crons::requeueChurchesWithoutBoundary();

        self::assertNotNull($this->bélyeg($id));
    }

    public function testAHatarralRendelkezotNemBantja(): void {
        $id = $this->templom(date('Y-m-d H:i:s', strtotime('-60 days')), true);

        \Crons::requeueChurchesWithoutBoundary();

        self::assertNotNull($this->bélyeg($id), 'Akinek van határa, annak nincs mit újrapróbálni.');
    }

    /** Koordináta nélkül sosem lesz határ — ne pörgessük fölöslegesen a sort. */
    public function testAKoordinataNelkulitNemAllitjaSorba(): void {
        $id = $this->templom(date('Y-m-d H:i:s', strtotime('-60 days')), false, ['lat' => 0, 'lon' => 0]);

        \Crons::requeueChurchesWithoutBoundary();

        self::assertNotNull($this->bélyeg($id));
    }

    public function testANemAktivTemplomotNemBantja(): void {
        $id = $this->templom(date('Y-m-d H:i:s', strtotime('-60 days')), false, ['ok' => 'n']);

        \Crons::requeueChurchesWithoutBoundary();

        self::assertNotNull($this->bélyeg($id));
    }

    /** A még sosem ellenőrzött már eleve a sor elején van. */
    public function testASosemEllenorzottetNemSzamolja(): void {
        $id = $this->templom(null, false);

        $erintett = \Crons::requeueChurchesWithoutBoundary();

        self::assertNull($this->bélyeg($id));
        self::assertGreaterThanOrEqual(0, $erintett);
    }

    // ---- a /health számlálója -----------------------------------------------

    public function testASzamlaloBeleveszAHatarNelkulit(): void {
        $elotte = \Crons::churchesWithoutBoundaryCount();
        $this->templom(null, false);

        self::assertSame($elotte + 1, \Crons::churchesWithoutBoundaryCount());
    }

    public function testASzamlaloNemVesziBeAHatarralRendelkezot(): void {
        $elotte = \Crons::churchesWithoutBoundaryCount();
        $this->templom(null, true);

        self::assertSame($elotte, \Crons::churchesWithoutBoundaryCount());
    }
}
