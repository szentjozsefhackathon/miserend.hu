<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #497: a koordináta nélküli templomok kivétele a megjelenésből.
 *
 * borazslo kérése:
 *
 *   „A koordináta nélküli templomokat lehet egyszerűen kiiktatjuk. Egyelőre »nem
 *    jelenhet meg« részre. Annak a pár templomnak aminek még az elhelyezkedését sem
 *    tudjuk, annak az adatait sem fogjuk tudni igazán frissen tartani."
 *
 * FIGYELEM: ez publikus tartalmat rejt el — élesben 47 templomot. Visszafordítható
 * (az `ok` mező kézzel átállítható), de látható hatása van.
 */
class HideChurchesWithoutCoordinatesTest extends TestCase {


    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** @param array<string,mixed> $mezok felülírandó oszlopok */
    private function templom(array $mezok): int {
        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $id = szabadTemplomId();

        DB::table('templomok')->insert(array_merge($minta, $mezok, ['id' => $id]));
        return $id;
    }

    private function allapot(int $id): string {
        return (string) DB::table('templomok')->where('id', $id)->value('ok');
    }

    public function testKoordinataNelkulKikerulAMegjelenesbol(): void {
        $id = $this->templom(['ok' => 'i', 'lat' => 0, 'lon' => 0]);

        \Crons::hideChurchesWithoutCoordinates();

        self::assertSame(\Crons::KOORDINATA_NELKUL_ALLAPOT, $this->allapot($id));
        self::assertNotSame('i', $this->allapot($id));
    }

    public function testHianyzoHosszusagIsEleg(): void {
        $id = $this->templom(['ok' => 'i', 'lat' => 47.5, 'lon' => 0]);

        \Crons::hideChurchesWithoutCoordinates();

        self::assertNotSame('i', $this->allapot($id));
    }

    /** A koordinátás templomhoz nem nyúlunk — ez a többség. */
    public function testKoordinatavalRendelkezotNemBantja(): void {
        $id = $this->templom(['ok' => 'i', 'lat' => 47.5, 'lon' => 19.05]);

        \Crons::hideChurchesWithoutCoordinates();

        self::assertSame('i', $this->allapot($id));
    }

    /** Aki már nem jelenik meg, annak az állapotát nem írjuk felül. */
    public function testMarKivettTemplomotNemIrFelul(): void {
        $id = $this->templom(['ok' => 'n', 'lat' => 0, 'lon' => 0]);

        \Crons::hideChurchesWithoutCoordinates();

        self::assertSame('n', $this->allapot($id), 'A letiltott állapot nem válhat "áttekintésre vár"-rá.');
    }

    /** Nyoma marad, hogy miért került ki — különben senki nem tudja, mi történt. */
    public function testAzAdminMegjegyzesbenNyomaMarad(): void {
        $id = $this->templom(['ok' => 'i', 'lat' => 0, 'lon' => 0, 'adminmegj' => '']);

        \Crons::hideChurchesWithoutCoordinates();

        self::assertStringContainsString('#497', (string) DB::table('templomok')->where('id', $id)->value('adminmegj'));
    }

    public function testAMeglevoAdminMegjegyzesMegmarad(): void {
        $id = $this->templom(['ok' => 'i', 'lat' => 0, 'lon' => 0, 'adminmegj' => 'Régi feljegyzés']);

        \Crons::hideChurchesWithoutCoordinates();

        $megj = (string) DB::table('templomok')->where('id', $id)->value('adminmegj');
        self::assertStringContainsString('Régi feljegyzés', $megj);
        self::assertStringContainsString('#497', $megj);
    }

    /** Napi cron: kétszer lefutva se duplikálja a megjegyzést. */
    public function testKetszerFuttatvaSemDuplikal(): void {
        $id = $this->templom(['ok' => 'i', 'lat' => 0, 'lon' => 0, 'adminmegj' => '']);

        \Crons::hideChurchesWithoutCoordinates();
        \Crons::hideChurchesWithoutCoordinates();

        $megj = (string) DB::table('templomok')->where('id', $id)->value('adminmegj');
        self::assertSame(1, substr_count($megj, '#497'),
            'A második futás már nem találja meg (nem "i"), tehát nem írhat újra.');
    }

    public function testVisszaadjaAzErintettekSzamat(): void {
        $this->templom(['ok' => 'i', 'lat' => 0, 'lon' => 0]);
        $this->templom(['ok' => 'i', 'lat' => 0, 'lon' => 0]);

        self::assertGreaterThanOrEqual(2, \Crons::hideChurchesWithoutCoordinates());
    }
}
