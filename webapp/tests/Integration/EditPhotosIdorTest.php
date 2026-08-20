<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #870: bármelyik gondnok bármelyik templom fotóit átírhatta és VÉGLEG törölhette.
 *
 * A `Html\Church\EditPhotos::modify()` csak azt nézte, hogy a `church[id]` egyezik-e a
 * szerkesztett templommal — a FOTÓ azonosítóját viszont szűkítés nélkül kereste:
 *
 *     $origPhoto = \Eloquent\Photo::find($modPhoto['id']);
 *
 * Egy tetszőleges templom gondnoka tehát a SAJÁT templomára POST-olva idegen fotó-id-t
 * adhatott meg: átnevezhette, elrejthette, sorrendjét átírhatta, és a `delete` jelzővel
 * véglegesen törölhette. A `photos` táblán NINCS SoftDeletes — a törlés
 * visszaállíthatatlan.
 */
final class EditPhotosIdorTest extends TestCase {

    private int $sajatChurch;
    private int $idegenChurch;
    private int $idegenPhoto;

    protected function setUp(): void {
        DB::beginTransaction();

        $this->sajatChurch = $this->templom('Saját templom');
        $this->idegenChurch = $this->templom('Idegen templom');

        $this->idegenPhoto = (int) DB::table('photos')->insertGetId([
            'church_id' => $this->idegenChurch,
            'title' => 'IDEGEN FOTÓ',
            'flag' => 'i',
            'weight' => 1,
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    private function templom(string $nev): int {
        return (int) DB::table('templomok')->insertGetId([
            'nev' => $nev, 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);
    }

    /**
     * A LÉNYEG: a fotó-keresés a szerkesztett templomra szűkít.
     *
     * A `modify()` teljes lefuttatásához bejelentkezett gondnok és OSM-hívások kellenének;
     * a hibát viszont pontosan EZ az egy lekérdezés hordozta, tehát azt mérjük.
     */
    public function testAForeignPhotoIsNotFoundThroughAnotherChurch(): void {
        $talalt = \Eloquent\Photo::where('church_id', $this->sajatChurch)->find($this->idegenPhoto);

        self::assertNull($talalt,
            'a masik templom fotoja nem erheto el a sajat templomon keresztul');
    }

    /** A saját fotó viszont továbbra is megtalálható. */
    public function testTheOwnPhotoIsStillFound(): void {
        $sajatPhoto = (int) DB::table('photos')->insertGetId([
            'church_id' => $this->sajatChurch,
            'title' => 'Saját fotó',
            'flag' => 'i',
            'weight' => 1,
        ]);

        self::assertNotNull(\Eloquent\Photo::where('church_id', $this->sajatChurch)->find($sajatPhoto));
    }

    /**
     * A szerkesztő NE használja a szűkítetlen `Photo::find()`-ot.
     *
     * Ez az őrzés a jegy lényege: ha valaki visszaírja, a hiba is visszatér — és
     * visszaállíthatatlan törlést enged.
     */
    public function testTheEditorScopesThePhotoLookup(): void {
        $forras = file_get_contents(dirname(__DIR__, 2) . '/classes/html/church/editphotos.php');

        self::assertMatchesRegularExpression(
            '#Photo::where\(\s*[\'"]church_id[\'"]\s*,\s*\$this->tid\s*\)->find\(#',
            $forras,
            'a foto-kereses szukitsen a szerkesztett templomra');

        self::assertDoesNotMatchRegularExpression(
            '#\\\\Eloquent\\\\Photo::find\(#',
            $forras,
            'a szukitetlen Photo::find() idegen foto torleset engedi');
    }

    /**
     * A törlés VÉGLEGES — ezért súlyos.
     *
     * Ha valaha bekerül a SoftDeletes, ez a teszt szól, hogy a kockázat megváltozott.
     */
    public function testPhotoDeletionIsPermanent(): void {
        $forras = file_get_contents(dirname(__DIR__, 2) . '/classes/eloquent/photo.php');

        self::assertStringNotContainsString('SoftDeletes', $forras,
            'ha mar van SoftDeletes, ez a komment es a kockazat-becsles felulvizsgalando');
    }
}
