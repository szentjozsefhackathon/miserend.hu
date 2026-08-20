<?php

use PHPUnit\Framework\TestCase;

/**
 * #855: két, egymástól független 404-forrás a statisztikában.
 *
 * borazslo: „elég sok lekérés érkezik az apple-touch-icon.png és az
 * apple-touch-icon-precomposed.png iránt. Amik persze nincsenek. […] (Egyébként elég sok
 * /poi.php?id=1234 formájú kérés is érkezik.)"
 *
 * Egyik sem rejtély, és egyik sem a régi iOS-alkalmazás maradéka:
 *
 *  1. Az APPLE magától keresi. Ha nincs `<link rel="apple-touch-icon">`, a Safari a
 *     dokumentum gyökeréből találgat, több névvel egymás után.
 *  2. A `poi.php` a SAJÁT SÚGÓNKBÓL jön. Ott a `turistautak.hu` példa-URL-jét egy `<b>`
 *     tag vágta ketté a kérdőjel után; aki a szöveget automatikusan linkesíti, a tagek
 *     kiszedése után egy `poi.php?id=6515` töredéket kap, és azt RELATÍV útvonalként a
 *     mi domainünkre kéri le.
 */
final class AppleTouchIconTest extends TestCase
{
    private static function webapp(): string
    {
        return dirname(__DIR__, 2);
    }

    /* ---- 1. Az ikon ---- */

    public function testTheIconFileExists(): void
    {
        self::assertFileExists(self::webapp() . '/apple-touch-icon.png');
    }

    /** 180×180: ezt kéri az iOS elsőként. */
    public function testTheIconHasTheSizeAppleAsksFor(): void
    {
        $meret = getimagesize(self::webapp() . '/apple-touch-icon.png');

        self::assertSame(180, $meret[0]);
        self::assertSame(180, $meret[1]);
        self::assertSame(IMAGETYPE_PNG, $meret[2]);
    }

    /**
     * NINCS átlátszóság — az Apple a transzparens képpontokat FEKETÉRE festi.
     *
     * Ez a fajta hiba csak eszközön látszik: a fejlesztő gépén szép az ikon, a
     * kezdőképernyőn viszont fekete négyzet lesz belőle.
     */
    public function testTheIconIsOpaque(): void
    {
        $kep = imagecreatefrompng(self::webapp() . '/apple-touch-icon.png');
        self::assertNotFalse($kep);

        // A négy sarok és a közép: egyik sem lehet átlátszó.
        foreach ([[0, 0], [179, 0], [0, 179], [179, 179], [90, 90]] as [$x, $y]) {
            $szin = imagecolorat($kep, $x, $y);
            $alpha = ($szin >> 24) & 0x7F;
            self::assertSame(0, $alpha, "atlatszo keppont ($x,$y) — az Apple ezt feketere festi");
        }
    }

    /** A sablon be is köti — enélkül a Safari továbbra is találgat. */
    public function testTheLayoutDeclaresTheIcon(): void
    {
        $layout = file_get_contents(self::webapp() . '/templates/layout.twig');

        self::assertStringContainsString('rel="apple-touch-icon"', $layout);
        self::assertStringContainsString('/apple-touch-icon.png', $layout);
    }

    /* ---- 2. A súgó törött URL-je ---- */

    /**
     * URL-t NE vágjon ketté HTML-tag.
     *
     * A `poi.php?<b>id=6515</b>` pontosan ezt tette. Az őrzés általános: bármelyik
     * súgószövegben ugyanez a hiba előfordulhat.
     */
    public function testNoHelpTextSplitsAnUrlWithATag(): void
    {
        /*
         * Csak a TÉNYLEGES szövegeket nézzük, a kommenteket nem.
         *
         * Enélkül a teszt a saját magyarázatát kapná el: a `help.php`-ban ott áll
         * kommentben a rossz példa is, hogy a következő fejlesztő értse, mitől kell
         * óvakodnia. A `token_get_all()` pontosan azt adja, ami a felhasználóhoz kimegy.
         */
        $tokenek = token_get_all(file_get_contents(self::webapp() . '/classes/help.php'));

        $rosszak = [];
        foreach ($tokenek as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            // Séma + hoszt után, még az URL belsejében nyíló tag.
            if (preg_match('#https?://[^\s\'"<]*<[a-z]#i', $token[1], $m)) {
                $rosszak[] = 'sor ' . $token[2] . ': ' . $m[0];
            }
        }

        self::assertSame([], $rosszak,
            'Egy URL-t HTML-tag vag ketté: ' . implode(', ', $rosszak)
            . '. Aki a szoveget linkesiti, a tagek kiszedese utan egy RELATIV toredeket kap, '
            . 'es azt a MI domainunkre keri le (l. #855, poi.php).');
    }

    /** A példa-URL rendes hivatkozás, teljes címmel. */
    public function testTheTuristautakExampleIsAProperLink(): void
    {
        $help = file_get_contents(self::webapp() . '/classes/help.php');

        self::assertStringContainsString(
            '<a href="http://turistautak.hu/poi.php?id=6515"',
            $help
        );
    }
}
