<?php

use PHPUnit\Framework\TestCase;

/**
 * #842: az egyházmegye-lekérdezés doboza rácsra igazítva.
 *
 * borazslo: „hiába nyomjuk a cache-t, azért pontosan ugyan akkor bbox ritkán van."
 * Pontosan erről van szó: a cache-kulcs a lekérdezés szövegéből képződik, a lekérdezés
 * pedig a nyers lebegőpontos bbox-ot tartalmazza — két szomszédos térképnézet két külön
 * kulcs. A cache be volt kapcsolva, csak sosem talált.
 *
 * A kerekítésnek egyetlen kemény szabálya van: KIFELÉ. Ha a rácsra igazított doboz
 * kisebb lenne a kértnél, a térkép széléről némán eltűnnének egyházmegyék — az sokkal
 * rosszabb, mint pár felesleges találat.
 */
final class DioceseBboxGridTest extends TestCase
{
    private static function snap(array $bbox): array
    {
        return \Html\Ajax\DiocesesInBBox::snapToGrid($bbox);
    }

    /** A LÉNYEG: a rácsdoboz mindig lefedi a kértet. */
    public function testTheSnappedBoxAlwaysCoversTheRequestedOne(): void
    {
        $kertek = [
            [47.4212, 18.9134, 47.6031, 19.2288],   // Budapest
            [46.2, 20.04, 46.28, 20.19],            // Szeged
            [45.5, 16.0, 49.6, 23.0],               // Kárpát-medence
            [47.5, 19.05, 47.5, 19.05],             // elfajult: egyetlen pont
            [-1.2, -0.3, 0.4, 0.9],                 // negatív tartomány, nulla átlépésével
        ];

        foreach ($kertek as $kert) {
            $racs = self::snap($kert);

            self::assertLessThanOrEqual($kert[0], $racs[0], 'dél nem csúszhat feljebb');
            self::assertLessThanOrEqual($kert[1], $racs[1], 'nyugat nem csúszhat keletebbre');
            self::assertGreaterThanOrEqual($kert[2], $racs[2], 'észak nem csúszhat lejjebb');
            self::assertGreaterThanOrEqual($kert[3], $racs[3], 'kelet nem csúszhat nyugatabbra');
        }
    }

    /** A negatív koordináták is kifelé kerekednek — a `floor`/`ceil` erre való. */
    public function testNegativeCoordinatesAlsoRoundOutwards(): void
    {
        $racs = self::snap([-1.2, -0.3, -0.1, -0.05]);

        self::assertSame(-1.5, $racs[0]);
        self::assertSame(-0.5, $racs[1]);
        self::assertSame(0.0, $racs[2] + 0.0);
        self::assertSame(0.0, $racs[3] + 0.0);
    }

    /**
     * ...és a szomszédos nézetek TÉNYLEG ugyanarra a kulcsra esnek.
     *
     * Ez az egész célja. Négy, egymáshoz közeli budapesti nézet — pásztázás közben
     * pontosan ilyenek keletkeznek — ugyanazt a rácsdobozt kapja.
     */
    public function testNeighbouringViewsCollapseOntoOneKey(): void
    {
        $nezetek = [
            [47.42, 18.91, 47.60, 19.22],
            [47.43, 18.93, 47.61, 19.24],
            [47.41, 18.90, 47.59, 19.21],
            [47.45, 18.95, 47.62, 19.23],
        ];

        $kulcsok = array_unique(array_map(
            fn($b) => implode(',', self::snap($b)),
            $nezetek
        ));

        self::assertCount(1, $kulcsok,
            'a szomszedos nezetek egy cache-kulcsra kell essenek');
    }

    /** Két távoli nézet viszont NE mosódjon össze — a rács ne legyen túl durva. */
    public function testDistantViewsStayApart(): void
    {
        $budapest = self::snap([47.42, 18.91, 47.60, 19.22]);
        $szeged   = self::snap([46.20, 20.04, 46.28, 20.19]);

        self::assertNotSame(implode(',', $budapest), implode(',', $szeged));
    }

    /** A rácsdoboz nem nőhet aránytalanul: legfeljebb egy rácsnyival minden irányban. */
    public function testTheSnappedBoxDoesNotGrowUnreasonably(): void
    {
        $kert = [47.4212, 18.9134, 47.6031, 19.2288];
        $racs = self::snap($kert);
        $racsmeret = \Html\Ajax\DiocesesInBBox::GRID;

        self::assertLessThan($racsmeret, $kert[0] - $racs[0]);
        self::assertLessThan($racsmeret, $kert[1] - $racs[1]);
        self::assertLessThan($racsmeret, $racs[2] - $kert[2]);
        self::assertLessThan($racsmeret, $racs[3] - $kert[3]);
    }
}
