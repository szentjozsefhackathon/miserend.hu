<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #874: a (church_id, key) pár EGYEDI.
 *
 * Az `updateOrCreate((church_id, key), …)` mindenhol úgy viselkedik, mintha a pár egyedi
 * lenne — de eddig semmi nem garantálta: a `key_church` index létezett, csak nem UNIQUE.
 * Két párhuzamos írás (az éjszakai OSM-szinkron és egy /edit mentés) tehát két sort tudott
 * létrehozni ugyanarra a párra; onnantól az `updateOrCreate` találomra az egyiket frissíti,
 * a /josm statisztikája pedig duplán számol.
 *
 * borazslo az éles adatbázison ellenőrizte, hogy nincs duplikátum — tehát a megszorítás
 * adatvesztés nélkül bevezethető (#847).
 */
final class AttributeUniqueTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        DB::beginTransaction();

        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Attribútum teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    /** A LÉNYEG: ugyanarra a párra nem lehet két sor. */
    public function testTheSameChurchAndKeyCannotBeInsertedTwice(): void {
        DB::table('attributes')->insert([
            'church_id' => $this->churchId, 'key' => 'teszt:kulcs', 'value' => 'a', 'fromOSM' => 1,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('attributes')->insert([
            'church_id' => $this->churchId, 'key' => 'teszt:kulcs', 'value' => 'b', 'fromOSM' => 1,
        ]);
    }

    /** Ugyanaz a kulcs MÁS templomnál viszont rendben van. */
    public function testTheSameKeyOnAnotherChurchIsFine(): void {
        $masik = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Másik', 'ok' => 'i', 'lat' => 47.1, 'lon' => 19.1,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);

        DB::table('attributes')->insert([
            'church_id' => $this->churchId, 'key' => 'wheelchair', 'value' => 'yes', 'fromOSM' => 1,
        ]);
        DB::table('attributes')->insert([
            'church_id' => $masik, 'key' => 'wheelchair', 'value' => 'limited', 'fromOSM' => 1,
        ]);

        self::assertSame(2, DB::table('attributes')->where('key', 'wheelchair')
            ->whereIn('church_id', [$this->churchId, $masik])->count());
    }

    /** Az `updateOrCreate` továbbra is FRISSÍT, nem ütközik. */
    public function testUpdateOrCreateStillUpdates(): void {
        \Eloquent\Attribute::updateOrCreate(
            ['church_id' => $this->churchId, 'key' => 'church:type'],
            ['value' => 'parish', 'fromOSM' => 1]
        );
        \Eloquent\Attribute::updateOrCreate(
            ['church_id' => $this->churchId, 'key' => 'church:type'],
            ['value' => 'filial', 'fromOSM' => 1]
        );

        self::assertSame(1, DB::table('attributes')
            ->where('church_id', $this->churchId)->where('key', 'church:type')->count());
        self::assertSame('filial', DB::table('attributes')
            ->where('church_id', $this->churchId)->where('key', 'church:type')->value('value'));
    }

    /**
     * A megszorítás tényleg EGYEDI, és az oszlopsorrend (church_id, key).
     *
     * A sorrend nem közömbös: a lekérdezéseink templomonként szűrnek, tehát a
     * `church_id` az első oszlop — így az index önmagában is használható előtagként.
     */
    public function testTheIndexIsUniqueAndChurchFirst(): void {
        $sorok = DB::select("SHOW INDEX FROM attributes WHERE Key_name = 'church_key'");

        self::assertCount(2, $sorok, 'ket oszlopos index');
        self::assertSame(0, (int) $sorok[0]->Non_unique, 'EGYEDI-nek kell lennie');
        self::assertSame('church_id', $sorok[0]->Column_name);
        self::assertSame('key', $sorok[1]->Column_name);
    }
}
