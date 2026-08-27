<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #893: a képfeltöltés és a könyvtárai.
 *
 * Egy gondnok azt jelezte, hogy nem tud képet feltölteni: „Upload directory is not
 * writable". A vizsgálat két hibát hozott ki, és mindkettő itt van rögzítve:
 *
 *   1. A `kicsi/` alkönyvtár csak akkor jött létre, ha a templom könyvtára is épp
 *      akkor született. Ha a könyvtár már megvolt (dockerizálás előtti képfa, mentés
 *      visszaállítása), de a `kicsi/` nem, a feltöltés SIKERT jelentett — bélyegkép
 *      nélkül. A `smallUrl` onnantól törött képre mutatott, és senki nem tudott róla.
 *
 *   2. A nem írható könyvtár a fájl MOZGATÁSA UTÁN derült ki, és a felhasználó egy
 *      angol belső mondatot kapott.
 *
 * A képkönyvtár helye itt felül van írva egy ideiglenes könyvtárra: a teszt nem nyúl a
 * valódi `kepek/` fához, és nem múlik azon, hogy az írható-e. (A CI-ban nem az — l. a
 * PhotoUploadSecurityTest megjegyzését.)
 */
final class PhotoUploadDirectoryTest extends TestCase {

    private string $gyoker;
    private int $churchId;

    protected function setUp(): void {
        // A jogosultság-ellenőrzésnek nincs értelme rootként: a root a 0555-be is ír.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('rootként a jogosultságok nem korlátoznak semmit');
        }

        DB::beginTransaction();

        // A `photos.church_id` idegen kulcs, tehát kell egy valódi templom-sor.
        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Képfeltöltés teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);

        $this->gyoker = sys_get_temp_dir() . '/miserend-kepek-' . bin2hex(random_bytes(6));
        mkdir($this->gyoker, 0775, true);
        TesztKepkonyvtarPhoto::$gyoker = $this->gyoker;

        // A Photo::uploadFile() ezt megköveteli.
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    }

    protected function tearDown(): void {
        DB::rollBack();
        $this->torol($this->gyoker);
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    /** A LÉNYEG (1): a `kicsi/` akkor is elkészül, ha a templom könyvtára már megvolt. */
    public function testKicsiIsCreatedWhenTheChurchDirectoryAlreadyExists(): void {
        $konyvtar = $this->gyoker . '/' . $this->churchId;
        mkdir($konyvtar, 0775, true);   // `kicsi/` szándékosan nincs

        $photo = $this->feltolt();

        self::assertFileExists($konyvtar . '/' . $photo->filename, 'a nagy kép');
        self::assertFileExists($konyvtar . '/kicsi/' . $photo->filename, 'a bélyegkép');
        self::assertGreaterThan(0, filesize($konyvtar . '/kicsi/' . $photo->filename));
    }

    /** Ha egyik könyvtár sincs meg, mindkettőt létrehozzuk. */
    public function testBothDirectoriesAreCreatedFromScratch(): void {
        $photo = $this->feltolt();

        $konyvtar = $this->gyoker . '/' . $this->churchId;
        self::assertFileExists($konyvtar . '/' . $photo->filename);
        self::assertFileExists($konyvtar . '/kicsi/' . $photo->filename);
    }

    /** A LÉNYEG (2): nem írható könyvtárnál érthető magyar mondat jön, nem belső szöveg. */
    public function testUnwritableChurchDirectoryFailsWithAReadableMessage(): void {
        $konyvtar = $this->gyoker . '/' . $this->churchId;
        mkdir($konyvtar . '/kicsi', 0775, true);
        chmod($konyvtar, 0555);

        try {
            $this->feltolt();
            self::fail('a nem írható könyvtárnál kivételt vártunk');
        } catch (\Exception $e) {
            self::assertStringContainsString('nem írható', $e->getMessage());
            self::assertStringNotContainsString('Upload directory', $e->getMessage());
        } finally {
            chmod($konyvtar, 0775);
        }
    }

    /**
     * A nem írható `kicsi/` MÉG A MOZGATÁS ELŐTT kiderül.
     *
     * Ez a régi sorrend valódi hibája volt: a nagy kép már kint volt a lemezen, amikor a
     * bélyegkép írása elhasalt. Itt azt állítjuk, hogy a templom könyvtárában egyetlen
     * fájl sem marad.
     */
    public function testUnwritableKicsiFailsBeforeTheFileIsMoved(): void {
        $konyvtar = $this->gyoker . '/' . $this->churchId;
        mkdir($konyvtar . '/kicsi', 0775, true);
        chmod($konyvtar . '/kicsi', 0555);

        try {
            $this->feltolt();
            self::fail('a nem írható kicsi/ könyvtárnál kivételt vártunk');
        } catch (\Exception $e) {
            self::assertStringContainsString('nem írható', $e->getMessage());
            self::assertSame(
                [],
                glob($konyvtar . '/*.png'),
                'a nagy képnek nem szabad kint maradnia'
            );
        } finally {
            chmod($konyvtar . '/kicsi', 0775);
        }
    }

    /** Egy valódi PNG feltöltése a Photo::uploadFile()-on keresztül. */
    private function feltolt(): \Eloquent\Photo {
        $forras = tempnam(sys_get_temp_dir(), 'miserend-teszt-kep');
        $kep = imagecreatetruecolor(40, 30);
        imagepng($kep, $forras);
        imagedestroy($kep);

        $photo = new TesztKepkonyvtarPhoto();
        $photo->church_id = $this->churchId;
        $photo->flag = 'n';
        $photo->weight = 0;

        try {
            $photo->uploadFile([
                'name'     => 'teszt.png',
                'type'     => 'image/png',
                'size'     => filesize($forras),
                'error'    => UPLOAD_ERR_OK,
                'tmp_name' => $forras,
            ]);
        } finally {
            // A sikeres ág átnevezi (elmozgatja), a hibás ág ott hagyja.
            if (is_file($forras)) @unlink($forras);
        }

        return $photo;
    }

    private function torol(string $ut): void {
        if (!is_dir($ut)) {
            return;
        }
        @chmod($ut, 0775);
        foreach (scandir($ut) as $bejegyzes) {
            if ($bejegyzes === '.' || $bejegyzes === '..') continue;
            $teljes = $ut . '/' . $bejegyzes;
            is_dir($teljes) ? $this->torol($teljes) : @unlink($teljes);
        }
        @rmdir($ut);
    }
}

/** A képkönyvtár helyét felülíró változat — a valódi `kepek/` fához nem nyúlunk. */
final class TesztKepkonyvtarPhoto extends \Eloquent\Photo {
    public static string $gyoker = '';

    public function getPathToPhotosAttribute($value) {
        return self::$gyoker;
    }
}
