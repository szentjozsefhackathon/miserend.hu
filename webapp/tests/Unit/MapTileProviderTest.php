<?php

use PHPUnit\Framework\TestCase;

/**
 * #817: senki ne írhasson be újra direkt OpenStreetMap-csempét.
 *
 * Az OSM önkéntes csempeszervere produkciós forgalomra tiltott (Tile Usage Policy), és
 * a blokkolást NEM hibakóddal jelzi. Mérve (2026-08-20):
 *
 *   curl https://a.tile.openstreetmap.org/16/36000/22800.png
 *     -> HTTP 200, 6987 B, fejléc: x-blocked: Access denied
 *
 * Az a 6987 bájt egy valódi 256×256-os PNG „403 — Access blocked" felirattal. A Leaflet
 * ezt nem tudja megkülönböztetni egy csempétől: kirakja. A térkép tehát nem hibázik,
 * hanem FOLTOS lesz — se konzolhiba, se `tileerror`. Ilyet futásidőben nem lehet
 * elkapni, ezért őrizzük forrás-szinten.
 *
 * A #376 ezt a döntést a főtérképnél már meghozta, de három másik hívóhelyen nem futott
 * át: `nearby-map-search.js`, `search/resultsmasses.twig`, és a naptár helyszínválasztója
 * (#816/#817). Ez a teszt azt őrzi, hogy ne lehessen negyedik.
 */
class MapTileProviderTest extends TestCase
{
    private const CSEMPE_FAJL = 'js/map-tiles.js';

    private static function webapp(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Minden saját forrásfájl, ahol csempeforrás megjelenhet.
     *
     * A `js/mcal` KIMARAD: az a naptár lefordított csomagja, nem szerkesztjük — a
     * forrása a `calendar/` alatt van, és annak saját Angular-tesztje van
     * (`location-picker.component.spec.ts`, „csempeforrás (#817)").
     *
     * @return string[]
     */
    private static function forrasok(): array
    {
        $ki = [];

        foreach (['templates', 'js'] as $konyvtar) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::webapp() . '/' . $konyvtar)
            );

            foreach ($iterator as $fajl) {
                if (!$fajl->isFile()) {
                    continue;
                }
                $ut = (string) $fajl->getPathname();
                if (str_contains($ut, '/js/mcal/')) {
                    continue;
                }
                if (str_ends_with($ut, '.twig') || str_ends_with($ut, '.js')) {
                    $ki[] = $ut;
                }
            }
        }

        return $ki;
    }

    private static function rovid(string $ut): string
    {
        return str_replace(self::webapp() . '/', '', $ut);
    }

    /**
     * A tiltott forrás sehol nem szerepelhet — HASZNÁLATKÉNT.
     *
     * A puszta említés (komment, magyarázat, mérési kimenet) rendben van, sőt kell:
     * a döntés indoklása pont ezekben a kommentekben él. Ezért idézőjelbe zárt
     * URL-literált keresünk, nem a hosztnevet.
     */
    public function testNoSourceUsesTheDirectOsmTileServer(): void
    {
        $talalatok = [];

        foreach (self::forrasok() as $ut) {
            $tartalom = (string) file_get_contents($ut);
            if (preg_match('#[\'"`]https?://[^\'"`]*tile\.openstreetmap\.org#', $tartalom)) {
                $talalatok[] = self::rovid($ut);
            }
        }

        self::assertSame([], $talalatok,
            "Direkt OSM-csempeszerver: " . implode(', ', $talalatok)
            . ". Az OSM a blokkolt csempét HTTP 200-zal és valódi PNG-vel adja vissza, "
            . "amit a Leaflet kirak — a térkép foltos lesz, hibaüzenet nélkül. "
            . "Használd a " . self::CSEMPE_FAJL . " kanonikus konfigurációját.");
    }

    /**
     * Csempeforrás-URL csak a közös fájlban lehet beégetve.
     *
     * Enélkül maradna négy igazságforrás, és a következő módosító megint csak az egyiket
     * írná át — pontosan ez történt a #376 után.
     */
    public function testTileUrlsLiveInOneFileOnly(): void
    {
        $talalatok = [];

        foreach (self::forrasok() as $ut) {
            if (str_ends_with($ut, self::CSEMPE_FAJL)) {
                continue;
            }

            $tartalom = (string) file_get_contents($ut);
            // Beégetett csempe-URL: olyan literál, amiben ott a Leaflet {z}/{x}/{y} mintája.
            if (preg_match('#[\'"]https?://[^\'"]*\{z\}/\{x\}/\{y\}#', $tartalom)) {
                $talalatok[] = self::rovid($ut);
            }
        }

        self::assertSame([], $talalatok,
            'Beégetett csempe-URL: ' . implode(', ', $talalatok)
            . '. A konfiguráció helye a ' . self::CSEMPE_FAJL . '.');
    }

    /** A közös fájl a kanonikus CARTO Voyager forrást adja. */
    public function testTheSharedFileHoldsTheCanonicalProvider(): void
    {
        $tartalom = (string) file_get_contents(self::webapp() . '/' . self::CSEMPE_FAJL);

        self::assertStringContainsString(
            'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
            $tartalom);
        self::assertStringContainsString("subdomains: 'abcd'", $tartalom);
    }

    /** Az ODbL-attribúció mindkét kötelező linkje legyen ott. */
    public function testTheAttributionCreditsBothParties(): void
    {
        $tartalom = (string) file_get_contents(self::webapp() . '/' . self::CSEMPE_FAJL);

        self::assertStringContainsString('openstreetmap.org/copyright', $tartalom,
            'az OSM-adat forrását fel kell tüntetni');
        self::assertStringContainsString('carto.com/attributions', $tartalom,
            'a csempeszolgáltatót is fel kell tüntetni');
    }

    /**
     * Aki a közös konstanst használja, annak be is kell töltenie.
     *
     * Ha kimarad a `<script src>`, a `window.MISEREND_CSEMPE` undefined, és a térkép
     * FEHÉR marad — az rosszabb, mint a foltos. Ezért itt kötjük össze a kettőt.
     */
    public function testEveryUserOfTheSharedConfigAlsoLoadsIt(): void
    {
        // Melyik JS-fájl használja a globálist?
        $hasznalok = [];
        foreach (self::forrasok() as $ut) {
            if (str_ends_with($ut, self::CSEMPE_FAJL) || !str_ends_with($ut, '.js')) {
                continue;
            }
            if (str_contains((string) file_get_contents($ut), 'MISEREND_CSEMPE')) {
                $hasznalok[] = basename($ut);
            }
        }

        self::assertNotEmpty($hasznalok, 'legalább egy térkép használja a közös konfigurációt');

        // Minden ilyen JS-hez tartozzon olyan sablon, ami MINDKETTŐT betölti.
        foreach ($hasznalok as $js) {
            $megvan = false;

            foreach (self::forrasok() as $ut) {
                if (!str_ends_with($ut, '.twig')) {
                    continue;
                }
                $tartalom = (string) file_get_contents($ut);
                if (str_contains($tartalom, '/js/' . $js) && str_contains($tartalom, '/' . self::CSEMPE_FAJL)) {
                    $megvan = true;
                    break;
                }
            }

            self::assertTrue($megvan,
                $js . ' használja a MISEREND_CSEMPE globálist, de nincs olyan sablon, '
                . 'ami mellé a ' . self::CSEMPE_FAJL . '-t is betöltené.');
        }
    }

    /** A közös konstanst használó SABLONOK is töltsék be a fájlt. */
    public function testEveryTemplateUsingTheConfigLoadsIt(): void
    {
        $hianyzik = [];

        foreach (self::forrasok() as $ut) {
            if (!str_ends_with($ut, '.twig')) {
                continue;
            }
            $tartalom = (string) file_get_contents($ut);
            if (!str_contains($tartalom, 'MISEREND_CSEMPE')) {
                continue;
            }
            if (!str_contains($tartalom, '/' . self::CSEMPE_FAJL)) {
                $hianyzik[] = self::rovid($ut);
            }
        }

        self::assertSame([], $hianyzik,
            'Ezek a sablonok használják a MISEREND_CSEMPE globálist, de nem töltik be: '
            . implode(', ', $hianyzik));
    }
}
