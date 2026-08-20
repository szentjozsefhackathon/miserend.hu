<?php

use PHPUnit\Framework\TestCase;

/**
 * #864: reflected XSS a keresőoldalak szűrő-címkéiben.
 *
 * A `filters` tömb elemei HTML-t tartalmaznak (`<b>`, `<i>`), ezért a sablon `|raw`-val
 * írja ki őket. Ez rendben van, amíg csak a MI HTML-ünk kerül bele — csakhogy a
 * fordítandó kulcs a felhasználó kérésparaméteréből jön, a `t()` pedig ISMERETLEN
 * kulcsnál magát a kulcsot adja vissza:
 *
 *     return self::$translations[$key] ?? ($default ?? $key);   // translator.php:91
 *
 * Mérve, azonosítás nélkül, egyetlen megküldött linkkel:
 *
 *     /index.php?q=SearchResultsChurches&rites[should]=<img src=x onerror=alert(1)>
 *     -> a válasz törzsében ott a nyers <img src=x onerror=alert(1)>
 *
 * Nincs CSP-fejléc, tehát a script lefut. Bejelentkezett gondnoknál vagy adminnál ez
 * munkamenet-átvétel.
 *
 * Hat vektor volt: `rites[should]`, `rites[must_not]`, `lang[should]`, `lang[must_not]`
 * mindkét keresőoldalon, plusz a rítusonkénti típus-szűrő és a kategóriák.
 */
final class SearchFilterLabelXssTest extends TestCase {

    private const TAMADAS = '<img src=x onerror=alert(1)>';

    /* ---- A központi segédfüggvény ---- */

    public function testTheHelperEscapesUnknownKeys(): void {
        $ki = \Search::tSafe(self::TAMADAS);

        self::assertStringNotContainsString('<img', $ki);
        self::assertStringContainsString('&lt;img', $ki);
    }

    /** Tömbre is működik — a hívók jellemzően listát fordítanak. */
    public function testTheHelperHandlesArrays(): void {
        $ki = \Search::tSafe([self::TAMADAS, 'ROMAN_CATHOLIC']);

        self::assertIsArray($ki);
        self::assertStringNotContainsString('<img', $ki[0]);
    }

    /**
     * A LÉNYEG: az ISMERT kulcs továbbra is lefordul.
     *
     * Ha a védelem a fordítást is elrontaná, a szűrő-címke olvashatatlan lenne — a
     * javítás akkor csak a tünetet fedné el.
     */
    public function testKnownKeysStillTranslate(): void {
        \Translator::init('hu');

        self::assertSame(t('LANGUAGES.de'), \Search::tSafe('LANGUAGES.de'));
        self::assertNotSame('LANGUAGES.de', \Search::tSafe('LANGUAGES.de'),
            'az ismert kulcsnak le kell fordulnia');
    }

    /** Az aposztróf és az idézőjel is escape-elődik (attribútum-kontextus). */
    public function testQuotesAreEscapedToo(): void {
        $ki = \Search::tSafe('" onmouseover="alert(1)');

        self::assertStringNotContainsString('"', $ki);
        self::assertStringContainsString('&quot;', $ki);
    }

    /* ---- Egyik hívóhely se maradhasson ki ---- */

    /**
     * A szűrő-címkékbe NE kerüljön nyers `t()` eredmény.
     *
     * A `filters[]` sorok `|raw`-val jutnak ki, tehát ott minden fordított értéknek
     * escape-eltnek kell lennie. Ez az őrzés általános: ha valaki új szűrőt vesz fel és
     * elfelejti, itt derül ki — nem a felhasználó böngészőjében.
     */
    public function testNoFilterLabelUsesRawTranslation(): void {
        $fajlok = [
            dirname(__DIR__, 2) . '/classes/html/searchresultschurches.php',
            dirname(__DIR__, 2) . '/classes/html/searchresultsmasses.php',
        ];

        $gyanus = [];
        foreach ($fajlok as $ut) {
            foreach (file($ut) as $i => $sor) {
                // Fordítás nyersen, `t(` alakban, escape nélkül — a `tSafe` és a
                // `htmlspecialchars(t(...))` rendben van.
                if (!preg_match('#\bt\(#', $sor)) {
                    continue;
                }
                if (preg_match('#tSafe|htmlspecialchars#', $sor)) {
                    continue;
                }
                // A `t()`-t máshol is hívjuk (pl. lapcím) — csak a címke-építést nézzük.
                if (!preg_match('#filters\[\]|\$translated|\$tShould|\$tMustNot|CategoryNames#', $sor)) {
                    continue;
                }
                $gyanus[] = basename($ut) . ':' . ($i + 1) . ' ' . trim($sor);
            }
        }

        self::assertSame([], $gyanus,
            "Escape nelkuli forditas szuro-cimkeben:\n" . implode("\n", $gyanus)
            . "\nA filters[] |raw-val jut ki — hasznald a \\Search::tSafe()-et.");
    }
}
