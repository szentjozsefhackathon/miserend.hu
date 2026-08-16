<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #496 / #497 / #498: a koordináta nélküli templomok helyadatának átmentése.
 *
 * A három jegy a `templomok.orszag`, `.megye`, `.varos` kivezetéséről szól — a helyet
 * ezután a koordináta és az OSM-határok adják. Csakhogy néhány misézőhelynek nincs
 * koordinátája, tehát sosem lesz boundary-ja: nekik ezek az oszlopok az EGYETLEN
 * helymegjelölésük. Élesben 47 ilyen van.
 *
 * borazslo a #496-ban két lehetőséget adott (törlés vagy megjegyzés mező). Ez a
 * nem-destruktív ág.
 */
class ArchiveLocationWithoutCoordinatesTest extends TestCase {

    private array $letrehozott = [];

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * @param array<string,mixed> $mezok felülírandó oszlopok
     * @return int az új templom azonosítója
     */
    private function templom(array $mezok): int {
        $minta = (array) DB::table('templomok')->first();
        $id = (int) DB::table('templomok')->max('id') + 1 + count($this->letrehozott);
        $this->letrehozott[] = $id;

        DB::table('templomok')->insert(array_merge($minta, $mezok, ['id' => $id]));
        return $id;
    }

    private function megjegyzes(int $id): string {
        return (string) DB::table('templomok')->where('id', $id)->value('megjegyzes');
    }

    /** @return int|null egy létező ország azonosítója, ha van */
    private function letezoOrszag(): ?int {
        $sor = DB::table('orszagok')->whereNotNull('nev')->where('nev', '!=', '')->first();
        return $sor ? (int) $sor->id : null;
    }

    public function testAKoordinataNelkuliTemplomHelyadataAtkerul(): void {
        $id = $this->templom(['lat' => 0, 'lon' => 0, 'varos' => 'Bótrágy', 'megjegyzes' => '']);

        \Crons::archiveLocationOfChurchesWithoutCoordinates();

        $megjegyzes = $this->megjegyzes($id);
        self::assertStringContainsString('Bótrágy', $megjegyzes);
        self::assertStringContainsString(\Crons::HELYADAT_JELOLO, $megjegyzes);
    }

    /** A meglévő megjegyzés nem veszhet el — hozzáfűzünk, nem felülírunk. */
    public function testAMeglevoMegjegyzesMegmarad(): void {
        $id = $this->templom(['lat' => 0, 'lon' => 0, 'varos' => 'Bótrágy', 'megjegyzes' => 'Régi feljegyzés']);

        \Crons::archiveLocationOfChurchesWithoutCoordinates();

        self::assertStringContainsString('Régi feljegyzés', $this->megjegyzes($id));
        self::assertStringContainsString('Bótrágy', $this->megjegyzes($id));
    }

    /** Deploy közben előfordul, hogy egy job kétszer fut le. */
    public function testTobbszorFuttatvaSemDuplikal(): void {
        $id = $this->templom(['lat' => 0, 'lon' => 0, 'varos' => 'Bótrágy', 'megjegyzes' => '']);

        \Crons::archiveLocationOfChurchesWithoutCoordinates();
        \Crons::archiveLocationOfChurchesWithoutCoordinates();
        \Crons::archiveLocationOfChurchesWithoutCoordinates();

        self::assertSame(1, substr_count($this->megjegyzes($id), 'Bótrágy'),
            'A jelölő ellenére duplikálódott a helyadat.');
    }

    /** Akinek van koordinátája, annak lesz boundary-ja — nincs mit menteni. */
    public function testAKoordinatavalRendelkezoTemplomotNemBantja(): void {
        $id = $this->templom(['lat' => 47.5, 'lon' => 19.05, 'varos' => 'Budapest', 'megjegyzes' => 'Érintetlen']);

        \Crons::archiveLocationOfChurchesWithoutCoordinates();

        self::assertSame('Érintetlen', $this->megjegyzes($id));
    }

    /** Üres helyadatból nincs mit átmenteni — ne szemeteljünk a megjegyzésbe. */
    public function testUresHelyadatnalNemIrSemmit(): void {
        $id = $this->templom(['lat' => 0, 'lon' => 0, 'varos' => '', 'megye' => 0, 'orszag' => 0, 'megjegyzes' => '']);

        \Crons::archiveLocationOfChurchesWithoutCoordinates();

        self::assertSame('', $this->megjegyzes($id));
    }

    /**
     * Az azonosítókat nevekre kell feloldani: egy megjegyzésben az "orszag=25"
     * semmit nem mond annak, aki később elolvassa.
     */
    public function testAzAzonositokHelyettNevekKerulnekBe(): void {
        $orszagId = $this->letezoOrszag();
        if ($orszagId === null) {
            self::markTestSkipped('Nincs ország a teszt-adatbázisban.');
        }
        $orszagNev = DB::table('orszagok')->where('id', $orszagId)->value('nev');

        $id = $this->templom(['lat' => 0, 'lon' => 0, 'orszag' => $orszagId, 'varos' => 'Bótrágy', 'megjegyzes' => '']);

        \Crons::archiveLocationOfChurchesWithoutCoordinates();

        $megjegyzes = $this->megjegyzes($id);
        self::assertStringContainsString((string) $orszagNev, $megjegyzes);
        self::assertStringNotContainsString('orszag=', $megjegyzes);
    }

    public function testVisszaadjaHanyTemplomotErintett(): void {
        $this->templom(['lat' => 0, 'lon' => 0, 'varos' => 'Egyik', 'megjegyzes' => '']);
        $this->templom(['lat' => 0, 'lon' => 0, 'varos' => 'Másik', 'megjegyzes' => '']);

        $elso = \Crons::archiveLocationOfChurchesWithoutCoordinates();
        $masodik = \Crons::archiveLocationOfChurchesWithoutCoordinates();

        self::assertGreaterThanOrEqual(2, $elso);
        self::assertSame(0, $masodik, 'A második futásnak már nem kell dolgoznia.');
    }
}
