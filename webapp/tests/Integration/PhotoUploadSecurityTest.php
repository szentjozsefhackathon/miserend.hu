<?php

use PHPUnit\Framework\TestCase;

/**
 * #709: kép helyett feltöltött .php fájl a közvetlen URL-jén LEFUTOTT.
 *
 * A lánc három hibából állt:
 *   1. a típusellenőrzés a kliens által küldött Content-Type-ot nézte,
 *   2. a kiterjesztés a kliens fájlnevéből jött, változtatás nélkül,
 *   3. a fájl a web által kiszolgált kepek/ könyvtárba került.
 *
 * A védelem lelke a Photo::safeExtensionFor(): a kiterjesztést KIZÁRÓLAG a fájl
 * tartalmából képezzük. Ez adatbázis és képkönyvtár nélkül tesztelhető, ezért az
 * alábbi állítások mindenhol lefutnak — nem múlnak azon, hogy a futtató
 * környezetben írható-e a kepek/ könyvtár. (A CI-ban nem az.)
 *
 * A kiszolgálás oldalát (a kepek/.htaccess fehérlistája) Apache-konfiguráció,
 * azt itt nem tudjuk futtatni — de a kettő EGYÜTT véd, egyik sem hagyható el.
 */
final class PhotoUploadSecurityTest extends TestCase {

    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) @unlink($file);
        }
        $this->tempFiles = [];
    }

    private function temp(string $contents): string {
        $file = tempnam(sys_get_temp_dir(), 'phototest');
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;
        return $file;
    }

    private function tempImage(string $format, int $w = 60, int $h = 40): string {
        $file = tempnam(sys_get_temp_dir(), 'phototest');
        $image = imagecreatetruecolor($w, $h);
        match ($format) {
            'jpeg' => imagejpeg($image, $file),
            'png'  => imagepng($image, $file),
            'gif'  => imagegif($image, $file),
        };
        imagedestroy($image);
        $this->tempFiles[] = $file;
        return $file;
    }

    /* A bejelentett támadás: a fájl nem kép, akármit is állít a kliens. */
    public function testPlainPhpFileIsRejected(): void {
        $file = $this->temp("<?php echo 'rce'; ?>");

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('nem kép');

        \Eloquent\Photo::safeExtensionFor($file);
    }

    /* Üres fájl sem kép. */
    public function testEmptyFileIsRejected(): void {
        $this->expectException(\Exception::class);

        \Eloquent\Photo::safeExtensionFor($this->temp(''));
    }

    /*
     * A LÉNYEG: a kiterjesztés a tartalomból jön. A kliens fájlneve — legyen az
     * shell.php, kep.jpg.php vagy bármi — nem számít, mert bele sem nézünk.
     */
    public function testExtensionIsDerivedFromContent(): void {
        self::assertSame('.jpg', \Eloquent\Photo::safeExtensionFor($this->tempImage('jpeg')));
        self::assertSame('.png', \Eloquent\Photo::safeExtensionFor($this->tempImage('png')));
        self::assertSame('.gif', \Eloquent\Photo::safeExtensionFor($this->tempImage('gif')));
    }

    /* Futtatható kiterjesztés soha nem jöhet ki. */
    public function testNeverReturnsAnExecutableExtension(): void {
        foreach (['jpeg', 'png', 'gif'] as $format) {
            $extension = \Eloquent\Photo::safeExtensionFor($this->tempImage($format));

            self::assertDoesNotMatchRegularExpression(
                '/\.(php|phtml|phar|php\d|inc)$/i',
                $extension,
                "a $format formátumnál futtatható kiterjesztés jött ki"
            );
        }
    }

    /*
     * Polyglot: érvényes GIF-fejléc, mögötte PHP-kód. Ilyet a getimagesize() és a
     * GD is átenged — érvényes GIF, csak van mögötte szemét.
     *
     * NEM azt várjuk el, hogy elutasítsuk (nem is tudnánk megbízhatóan), hanem
     * azt, ami a támadást ténylegesen megállítja: kép-kiterjesztéssel mentődik,
     * amit az Apache nem futtat, és amit a kepek/.htaccess fehérlistája is enged.
     */
    public function testPolyglotGetsAnImageExtensionNotAnExecutableOne(): void {
        $file = $this->tempImage('gif', 20, 20);
        file_put_contents($file, "\n<?php echo 'polyglot'; ?>", FILE_APPEND);

        self::assertSame('.gif', \Eloquent\Photo::safeExtensionFor($file));
    }

    /* Nem engedett képformátum (pl. BMP) sem csúszhat át. */
    public function testUnsupportedImageFormatIsRejected(): void {
        $file = tempnam(sys_get_temp_dir(), 'phototest');
        $image = imagecreatetruecolor(10, 10);
        imagebmp($image, $file);
        imagedestroy($image);
        $this->tempFiles[] = $file;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Nem támogatott képformátum');

        \Eloquent\Photo::safeExtensionFor($file);
    }

    /*
     * A kicsinyítés régen FIXEN imagecreatefromjpeg()-et hívott, ezért GIF/PNG
     * feltöltés belső GD-hibával hasalt el. Most a forrás tényleges típusa dönt.
     */
    public function testResizeHandlesEveryAcceptedFormat(): void {
        foreach (['jpeg' => '.jpg', 'png' => '.png', 'gif' => '.gif'] as $format => $extension) {
            $source = $this->tempImage($format, 200, 150);
            $target = tempnam(sys_get_temp_dir(), 'phototest') . $extension;
            $this->tempFiles[] = $target;

            \Eloquent\Photo::kicsinyites($source, $target, 60);

            self::assertFileExists($target, "a $format kicsinyítése nem készült el");
            $info = getimagesize($target);
            self::assertNotFalse($info, "a kicsinyített $format nem olvasható képként");
            self::assertLessThanOrEqual(60, max($info[0], $info[1]));
        }
    }

    /* A kicsinyítés ugyanabban a formátumban írjon vissza, mint a forrás. */
    public function testResizeKeepsTheSourceFormat(): void {
        $source = $this->tempImage('png', 100, 100);
        $target = tempnam(sys_get_temp_dir(), 'phototest') . '.png';
        $this->tempFiles[] = $target;

        \Eloquent\Photo::kicsinyites($source, $target, 50);

        $info = getimagesize($target);
        self::assertSame(IMAGETYPE_PNG, $info[2], 'a PNG-ből nem lehet JPEG');
    }

    /* Olvashatatlan tartalom a kicsinyítésnél tiszta hibát adjon, ne GD-belsőt. */
    public function testResizeGivesAClearErrorOnBrokenImage(): void {
        $file = $this->temp("GIF89a" . str_repeat("\x00", 40));

        $this->expectException(\Exception::class);

        \Eloquent\Photo::kicsinyites($file, $file . '.out', 50);
    }
}
