<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #667: a templomkeresőben a rítus-szűrő (római katolikus / görögkatolikus / régi rítusú)
 * eddig néma no-op volt. A keresőűrlap gombjai (`rites[should]` / `rites[must_not]`)
 * megvoltak, csak a templomkereső nem olvasta őket — és nem is tudta volna, mert a
 * rítus a MISE tulajdonsága, a templomnak nem volt ilyen adata.
 *
 * A `ritusok` most származtatott mező, pontosan úgy, ahogy a `nyelvek`: mely rítusokban
 * van (bármikor) liturgia az adott templomban.
 */
class ChurchRitesTest extends TestCase {

    private array $createdChurchIds = [];

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function createChurch(): int {
        $id = DB::table('templomok')->insertGetId([
            'nev'        => 'Rítus teszt',
            'varos'      => 'Budapest',
            'frissites'  => '2020-01-01',
            'ok'         => 'i',
            'plebania'   => '', 'leiras' => '', 'megjegyzes' => '', 'misemegj' => '',
            'bucsu'      => '', 'adminmegj' => '', 'log' => '',
            'lat'        => 47.5, 'lon' => 19.0,
        ]);
        $this->createdChurchIds[] = $id;
        return $id;
    }

    private function addMass(int $churchId, string $rite, string $lang = 'hu'): void {
        DB::table('cal_masses')->insert([
            'church_id'  => $churchId,
            'title'      => 'Szentmise',
            'rite'       => $rite,
            'start_date' => '2026-01-04T09:00:00',
            'lang'       => $lang,
            'comment'    => '',
        ]);
    }

    public function testEgyRitusuTemplom(): void {
        $id = $this->createChurch();
        $this->addMass($id, 'GREEK_CATHOLIC');

        $church = \Eloquent\Church::find($id);
        $this->assertSame(['GREEK_CATHOLIC'], $church->ritusok);
    }

    /**
     * A lényeg: egy templomban több rítus is lehet, és mindegyikre meg kell találni.
     */
    public function testTobbRitusuTemplomMindegyikkelSzerepel(): void {
        $id = $this->createChurch();
        $this->addMass($id, 'ROMAN_CATHOLIC');
        $this->addMass($id, 'GREEK_CATHOLIC');
        $this->addMass($id, 'ROMAN_CATHOLIC');

        $ritusok = \Eloquent\Church::find($id)->ritusok;
        sort($ritusok);
        $this->assertSame(['GREEK_CATHOLIC', 'ROMAN_CATHOLIC'], $ritusok);
    }

    public function testMiseNelkuliTemplomnakNincsRitusa(): void {
        $id = $this->createChurch();
        $this->assertSame([], \Eloquent\Church::find($id)->ritusok);
    }

    /**
     * A mező tényleg bekerül abba a tömbbe, amit az Elasticsearchbe küldünk — enélkül
     * a szűrő megint néma no-op lenne.
     */
    public function testARitusokBekerulAzIndexeltDokumentumba(): void {
        $id = $this->createChurch();
        $this->addMass($id, 'TRADITIONAL');

        $doc = \Eloquent\Church::find($id)->toElasticArray();

        $this->assertArrayHasKey('ritusok', $doc);
        $this->assertSame(['TRADITIONAL'], $doc['ritusok']);
    }

    /**
     * A `nyelvek` mintáját követi — ha az egyik elromlik, a másik is gyanús.
     */
    public function testUgyanugyViselkedikMintANyelvek(): void {
        $id = $this->createChurch();
        $this->addMass($id, 'GREEK_CATHOLIC', 'hu');
        $this->addMass($id, 'GREEK_CATHOLIC', 'ua');

        $church = \Eloquent\Church::find($id);
        $this->assertSame(['GREEK_CATHOLIC'], $church->ritusok, 'Egy rítus, kétszer — egyszer szerepel.');

        $nyelvek = $church->languages;
        sort($nyelvek);
        $this->assertSame(['hu', 'ua'], $nyelvek);
    }
}
