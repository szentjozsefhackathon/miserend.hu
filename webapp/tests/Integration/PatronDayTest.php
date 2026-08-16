<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #568: az OSM `patron_day` tagje mint a búcsú strukturált forrása.
 *
 * borazslo vetette fel a jegyben:
 *
 *   „(Amúgy ez a búcsús mező nem búcsús, hanem megjegyzés mező. Hosszú távon akkor
 *    külön mező lehetne a búcsúnak. Sőt OSM ismeri az egyáltalán nem elterjed
 *    patron_day kulcsot.)"
 *
 * Külön oszlop és külön szerkesztő-mező NÉLKÜL is meg lehet csinálni: az
 * OSM-szinkron MINDEN taget elment az `attributes` táblába (`osm.php`:
 * `foreach($element->tags as $key => $value)`), tehát ha egy templomnál ki van
 * töltve a `patron_day`, az adat már nálunk van — csak olvasni kell.
 */
class PatronDayTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $this->churchId = (int) DB::table('templomok')->max('id') + 1;
        $minta['id'] = $this->churchId;
        $minta['nev'] = 'PatronDay Teszt';
        /*
         * A búcsú a MEGJEGYZÉS mezőből jön (#809), ezért a fixtúrának azt kell
         * beállítania. A `bucsu` oszlopot kiürítjük, különben a másolt mintatemplom
         * régi értéke szólna bele — és pont az derülne ki, amit nem akarunk mérni.
         */
        $minta['megjegyzes'] = 'Búcsú: március 19.';
        $minta['bucsu'] = '';
        DB::table('templomok')->insert($minta);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function patronDay(string $ertek): void {
        DB::table('attributes')->insert([
            'church_id' => $this->churchId,
            'key' => 'patron_day',
            'value' => $ertek,
            'fromOSM' => 1,
        ]);
    }

    private function templom(): \Eloquent\Church {
        return \Eloquent\Church::find($this->churchId);
    }

    // ---- az érték értelmezése -----------------------------------------------

    /** ISO 8601 ismétlődő dátum — ez a legpontosabb alak egy évente visszatérő ünnepre. */
    public function testIsoIsmetlodoDatum(): void {
        $a = \Bucsu::parsePatronDay('--08-15');

        self::assertSame(['type' => 'fixed', 'month' => 8, 'day' => 15], $a);
    }

    public function testHonapNapAlak(): void {
        self::assertSame(['type' => 'fixed', 'month' => 8, 'day' => 15], \Bucsu::parsePatronDay('08-15'));
    }

    /** Teljes dátumnál az évet eldobjuk: a búcsú évente ismétlődik. */
    public function testTeljesDatumnalAzEvetEldobjuk(): void {
        self::assertSame(['type' => 'fixed', 'month' => 8, 'day' => 15], \Bucsu::parsePatronDay('2026-08-15'));
    }

    /** A kulcs nem elterjedt, ezért a magyar szöveget is elfogadjuk. */
    public function testMagyarSzovegetIsErtelmez(): void {
        self::assertSame(['type' => 'fixed', 'month' => 8, 'day' => 15], \Bucsu::parsePatronDay('augusztus 15.'));
    }

    public function testMozgoUnnepetIsErtelmez(): void {
        $a = \Bucsu::parsePatronDay('Szentháromság vasárnap');

        self::assertSame('moveable', $a['type']);
        self::assertSame('2026-05-31', \Bucsu::resolve($a, 2026));
    }

    /** Amit nem értünk, azt elengedjük — a szabad szöveges mező ott van tartaléknak. */
    public function testErtelmezhetetlenErtekNull(): void {
        self::assertNull(\Bucsu::parsePatronDay('valami hülyeség'));
        self::assertNull(\Bucsu::parsePatronDay(''));
        self::assertNull(\Bucsu::parsePatronDay(null));
    }

    public function testLehetetlenNapotNemFogadEl(): void {
        self::assertNull(\Bucsu::parsePatronDay('02-45'));
    }

    // ---- elsőbbség a szabad szöveggel szemben --------------------------------

    /**
     * A strukturált adat megbízhatóbb, mint amit egy húsz éve gyűlő megjegyzés-mezőből
     * ki tudunk olvasni — ezért az OSM-tag nyer.
     */
    public function testAPatronDayFelulirjaASzabadSzoveget(): void {
        $this->patronDay('--08-15');

        $eredmeny = $this->templom()->bucsuOccasions();

        self::assertSame(8, $eredmeny['bucsu']['month'], 'A patron_day (augusztus) nyer a mező (március) ellen.');
        self::assertSame('patron_day', $eredmeny['forras']);
    }

    /** patron_day nélkül marad a szabad szöveg. */
    public function testPatronDayNelkulASzabadSzovegErvenyes(): void {
        $eredmeny = $this->templom()->bucsuOccasions();

        self::assertSame(3, $eredmeny['bucsu']['month']);
        self::assertSame('bucsu_mezo', $eredmeny['forras']);
    }

    /** Értelmezhetetlen tag esetén sem veszítjük el a szabad szöveget. */
    public function testErtelmezhetetlenTagnalMaradASzabadSzoveg(): void {
        $this->patronDay('ez nem dátum');

        $eredmeny = $this->templom()->bucsuOccasions();

        self::assertSame(3, $eredmeny['bucsu']['month']);
        self::assertSame('bucsu_mezo', $eredmeny['forras']);
    }

    // ---- a /health számlálója ------------------------------------------------

    /**
     * borazslo kérése: „ha a /health megmutatja, hogy még mennyi régi módi búcsú
     * adatot találtunk, és akkor ha az egyszer csak elfogy, akkor kiírhatja, hogy
     * »Megszűnt ennek a búcsú szöveget feldolgozó scriptnek a létjogosultsága.«"
     */
    public function testAstatisztikaSzetvalasztjaAKetForrast(): void {
        $elotte = \Bucsu::forrasStatisztika();

        $this->patronDay('--08-15');
        $utana = \Bucsu::forrasStatisztika();

        self::assertSame($elotte['patron_day'] + 1, $utana['patron_day']);
        self::assertSame($elotte['szoveges'] - 1, $utana['szoveges'],
            'A templom átkerül a szövegesből a strukturáltba, nem duplikálódik.');
    }

    /** Az értelmezhetetlen mező külön kategória — az javítható adat, nem forrás. */
    public function testAzErtelmezhetetlenMezoKulonSzamit(): void {
        // #809: a forrás a MEGJEGYZÉS mező, tehát ott kell elhelyezni a szöveget.
        DB::table('templomok')->where('id', $this->churchId)
            ->update(['megjegyzes' => 'Búcsú: Szent György vértanú ünnepéhez közelebbi vasárnap',
                      'bucsu' => '']);

        $stat = \Bucsu::forrasStatisztika();

        self::assertArrayHasKey('ertelmezhetetlen', $stat);
        self::assertGreaterThan(0, $stat['ertelmezhetetlen']);
    }

    /** A következő dátum számítása is a patron_day-ből megy. */
    public function testAKovetkezoDatumAPatronDaybolJon(): void {
        $this->patronDay('--08-15');

        self::assertSame('2026-08-15', $this->templom()->nextBucsuDate('2026-01-01'));
    }
}
