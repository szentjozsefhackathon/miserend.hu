<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #257: a tábla-export `name` és `alt_name` mezője az OSM-névhalmazból.
 *
 * borazslo kérése a #803-hoz:
 *
 *   „Simán csinálhatjuk, hogy a name-hez az osmból szedett nevek sorából az elsőt
 *    tesszük, az alt_name-hez pedig az alternative_names első elemét tesszük. Az Api
 *    V5-ben #56 pedig mindkét mező helyére egy lista/jsonlista kerülhetne."
 *
 * Mindkettő megvan: a régebbi verziók az ELSŐ nevet kapják (a mező marad string,
 * tehát a meglévő fogyasztóknak nem törik el), a v5 pedig a teljes listát.
 */
class TableApiOsmNamesTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $this->churchId = szabadTemplomId();
        $minta['id'] = $this->churchId;
        $minta['nev'] = 'Helyi név';
        $minta['ismertnev'] = 'Helyi ismertnév';
        $minta['ok'] = 'i';
        DB::table('templomok')->insert($minta);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function attributum(string $kulcs, string $ertek): void {
        DB::table('attributes')->insert([
            'church_id' => $this->churchId, 'key' => $kulcs, 'value' => $ertek, 'fromOSM' => 1,
        ]);
    }

    private function templom(): \Eloquent\Church {
        return \Eloquent\Church::find($this->churchId);
    }

    /** A `names[0]` a name:hu -> name sorrendet követi. */
    public function testAzOsmNevAzElsoANevsorban(): void {
        $this->attributum('name', 'OSM név');
        $this->attributum('name:hu', 'OSM magyar név');

        self::assertSame('OSM magyar név', $this->templom()->names[0]);
    }

    /** OSM-adat nélkül a helyi oszlop marad — a régi érték nem vész el. */
    public function testOsmNevNelkulAHelyiOszlopJon(): void {
        self::assertSame('Helyi név', $this->templom()->names[0]);
    }

    public function testAzAlternativNevIsAzOsmbolJon(): void {
        $this->attributum('official_name:hu', 'Hivatalos OSM név');

        self::assertSame('Hivatalos OSM név', $this->templom()->alternative_names[0]);
    }

    /**
     * A v5-ben lista megy — borazslo külön kérte. A régebbi verziók stringet kapnak,
     * hogy a meglévő fogyasztóknak ne törjön el a szerződés.
     */
    public function testAVerzioDontiElHogyStringVagyLista(): void {
        $this->attributum('name', 'Egyik');
        $this->attributum('name:hu', 'Másik');

        $nevek = $this->templom()->names;

        self::assertIsArray($nevek);
        self::assertGreaterThanOrEqual(2, count($nevek),
            'A v5 a TELJES listát adja, tehát több névnek kell benne lennie.');
        self::assertIsString($nevek[0], 'A régebbi verziók az első nevet kapják, stringként.');
    }

    /** A batch-betöltés miatt fontos: a nevek eager-load-dal is helyesek. */
    public function testAzEagerLoadUgyanaztAdja(): void {
        $this->attributum('name:hu', 'OSM magyar név');

        $eager = \Eloquent\Church::with('attributes')->where('id', $this->churchId)->first();

        self::assertSame($this->templom()->names[0], $eager->names[0]);
    }
}
