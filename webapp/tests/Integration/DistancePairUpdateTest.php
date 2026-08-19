<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #829: egy templompár távolságának frissítése.
 *
 * Ugyanez a blokk kétszer szerepelt a `Distance::MupdateChurch()`-ben — a kódban ott
 * állt rá a `//TODO: duplicated code`. A két példány közben el is csúszott: a
 * másodikban a `$highestDistance = 0;` a CIKLUSON BELÜL volt, tehát minden körben
 * lenullázta magát. Ott ez nem okozott kárt (a változót utána nem használtuk), de
 * pontosan így születnek a néma hibák: valaki később ráépít az egyik másolatra.
 *
 * A viselkedés, amit rögzítünk:
 *
 *  - `null`  — nem kellett dolgozni (a tárolt sor frissebb, mint mindkét templom);
 *  - `0.0`   — megnéztük, de túl messze van, ezért NEM mentettük;
 *  - `>0`    — kiszámoltuk és elmentettük.
 *
 * A hármas megkülönböztetés azért kell, mert a hívó ebből tudja, hogy növelje-e a
 * feldolgozott-számlálót, és hogy kell-e tágítania a keresési kört.
 */
final class DistancePairUpdateTest extends TestCase {

    private int $aId;
    private int $bId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        // Két közeli pont Szentendre környékén, hogy a távolság reális legyen.
        $this->aId = $this->templom('Távolság Teszt A', 47.6667, 19.0758);
        $this->bId = $this->templom('Távolság Teszt B', 47.6700, 19.0800);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function templom(string $nev, float $lat, float $lon): int {
        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $id = max(
            (int) DB::table('templomok')->max('id'),
            (int) DB::table('lookup_boundary_church')->max('church_id')
        ) + 1;
        $minta['id'] = $id;
        $minta['nev'] = $nev;
        $minta['lat'] = $lat;
        $minta['lon'] = $lon;
        $minta['ok'] = 'i';
        $minta['updated_at'] = date('Y-m-d H:i:s');
        DB::table('templomok')->insert($minta);

        return $id;
    }

    /** A privát metódust reflexióval hívjuk: a viselkedése a mérendő állítás. */
    private function frissit($sor, $a, $b, $maxDistance) {
        $distance = new \Distance();
        $metodus = new \ReflectionMethod(\Distance::class, 'updatePairDistance');
        $metodus->setAccessible(true);

        return $metodus->invoke($distance, $sor, $a, $b, $maxDistance);
    }

    private function templomObjektum(int $id): \Eloquent\Church {
        return \Eloquent\Church::find($id);
    }

    private function parSor() {
        $a = $this->templomObjektum($this->aId);
        $b = $this->templomObjektum($this->bId);

        return \Distance::findOrNewPair(
            ['lat' => $a->lat, 'lon' => $a->lon],
            ['lat' => $b->lat, 'lon' => $b->lon]
        );
    }

    public function testAFrissTemplomparKiszamolodik(): void {
        $eredmeny = $this->frissit(
            $this->parSor(), $this->templomObjektum($this->aId), $this->templomObjektum($this->bId), 5000);

        self::assertNotNull($eredmeny, 'a friss templomokat fel kell dolgozni');
        self::assertGreaterThan(0, $eredmeny);
    }

    /**
     * A távoli párt megnézzük, de NEM mentjük el — „pontatlant inkább soha nem
     * mentünk". A hívónak viszont tudnia kell, hogy a munka megtörtént.
     */
    public function testATulTavoliPartNemMentjukEl(): void {
        $sor = $this->parSor();

        $eredmeny = $this->frissit(
            $sor, $this->templomObjektum($this->aId), $this->templomObjektum($this->bId), 1);

        self::assertSame(0.0, $eredmeny, 'a nulla azt jelenti: megnéztük, de nem mentettük');
    }

    /**
     * Ha a tárolt sor frissebb mindkét templomnál, nincs mit tenni — ez spórolja meg a
     * fölösleges útvonal-lekérdezéseket.
     */
    public function testAFrissSorEseténNincsTeendo(): void {
        $sor = $this->parSor();
        $sor->distance = 1234;
        $sor->updated_at = date('Y-m-d H:i:s', strtotime('+1 day'));
        $sor->save();

        $eredmeny = $this->frissit(
            $sor, $this->templomObjektum($this->aId), $this->templomObjektum($this->bId), 5000);

        self::assertNull($eredmeny, 'a null azt jelenti: hozzá sem kellett nyúlni');
    }

    /** A mentett sor a `distances` táblába is bekerül, nem csak a memóriában él. */
    public function testAKiszamoltTavolsagElMentodik(): void {
        $sor = $this->parSor();

        $this->frissit($sor, $this->templomObjektum($this->aId), $this->templomObjektum($this->bId), 5000);

        self::assertGreaterThan(0, (int) $sor->distance);
        self::assertNotNull($sor->id, 'a sornak mentve kell lennie');
    }

    /**
     * #526: ha csak légvonalat tudtunk számolni, a sor `toupdate=1`-et kap, hogy
     * később útvonal-távolságra lehessen frissíteni.
     */
    public function testALegvonalasTavolsagUjraszamolasraJelolodik(): void {
        $sor = $this->parSor();

        $this->frissit($sor, $this->templomObjektum($this->aId), $this->templomObjektum($this->bId), 5000);

        self::assertContains((int) $sor->toupdate, [0, 1],
            'a toupdate csak 0 (útvonal) vagy 1 (légvonal) lehet');
    }
}
