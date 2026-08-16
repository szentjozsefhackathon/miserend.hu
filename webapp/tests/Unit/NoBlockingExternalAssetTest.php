<?php

use PHPUnit\Framework\TestCase;

/**
 * Egyetlen oldal betöltése se múljon idegen hoszton.
 *
 * A `<script src>` és a `<link rel=stylesheet>` BLOKKOLJA a betöltést: amíg a fájl meg
 * nem jön, a lap nem készül el. Ha a kiszolgáló lassú vagy elérhetetlen, a látogató üres
 * vagy félkész oldalt néz — a böngészős tesztek pedig időtúllépésig várnak. A master CI-ja
 * pontosan így halt el: 12 perc alatt egyetlen teszteredmény sem született.
 *
 * Négy ilyen hivatkozás volt:
 *   - `_map.twig` → openlayers.org (770 KB); a sablon ráadásul HALOTT KÓD volt, törölve
 *   - `search/resultsmasses.twig` → unpkg.com Leaflet 1.9.4; helyben ugyanaz a verzió van
 *   - `layout.twig` → oss.maxcdn.com IE8-shimek; a kiszolgáló évek óta megszűnt
 *   - `church/_panelfacebookpageplugin.twig` → connect.facebook.net
 *
 * Az utolsó `async defer` volt, tehát a feldolgozást nem állította meg — a `load`
 * eseményt viszont a parser által beszúrt script akkor is halasztja, amíg a válasz meg
 * nem jön. A böngészős teszt pontosan erre vár, ezért az `async` itt nem mentség: azt is
 * betöltés utánra tettük, JS-ből injektálva.
 *
 * A `<img>` és az `<iframe>` nem tartozik ide, és a JS-ből, `load` után injektált script
 * sem: az már nem tartja vissza a lap elkészültét.
 */
class NoBlockingExternalAssetTest extends TestCase
{
    /** @return string[] minden Twig-sablon */
    private static function sablonok(): array
    {
        $konyvtar = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates')
        );

        $ki = [];
        foreach ($konyvtar as $fajl) {
            if ($fajl->isFile() && str_ends_with((string) $fajl->getFilename(), '.twig')) {
                $ki[] = $fajl->getPathname();
            }
        }

        self::assertNotEmpty($ki, 'nem találom a sablonokat');
        return $ki;
    }

    public function testNoTemplateEmbedsAnExternalScriptOrStylesheet(): void
    {
        $talalatok = [];
        foreach (self::sablonok() as $utvonal) {
            $tartalom = (string) file_get_contents($utvonal);

            if (preg_match_all('#<(script|link)\b[^>]*\b(src|href)\s*=\s*"https?://[^"]+"#i', $tartalom, $egyezesek)) {
                foreach ($egyezesek[0] as $egyezes) {
                    $talalatok[] = basename($utvonal) . ': ' . substr($egyezes, 0, 90);
                }
            }
        }

        self::assertSame([], $talalatok,
            "Sablonba írt külső script/stíluslap. Tedd helyivé (node_modules / webapp/js), "
            . "vagy — ha idegen szolgáltatás kell — injektáld JS-ből a `load` esemény után. "
            . "Különben a lap elkészülte idegen hoszt elérhetőségén múlik:\n  "
            . implode("\n  ", $talalatok));
    }

    /**
     * A Leaflet helyi példánya tényleg ott van, ahova a sablon mutat — enélkül a fenti
     * állítás úgy is teljesülne, hogy közben a térkép egyszerűen nem tölt be.
     */
    public function testTheLocalLeafletIsWhereTheTemplatePointsAt(): void
    {
        $sablon = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/search/resultsmasses.twig'
        );

        self::assertStringContainsString('/node_modules/leaflet/dist/leaflet.js', $sablon);
        self::assertFileExists(dirname(__DIR__, 2) . '/node_modules/leaflet/dist/leaflet.js');
        self::assertFileExists(dirname(__DIR__, 2) . '/node_modules/leaflet/dist/leaflet.css');
    }

    /**
     * A `_map.twig` törölve maradjon: halott kód volt (egyetlen sablon sem include-olta),
     * és ez hozta a legnagyobb külső blokkoló scriptet.
     */
    public function testTheDeadOpenLayersTemplateStaysDeleted(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/templates/_map.twig');
    }

    /**
     * A Facebook-plugin megmarad — csak betöltés után jön. Enélkül a fenti állítást úgy
     * is ki lehetne elégíteni, hogy egyszerűen kivesszük a funkciót.
     */
    public function testTheFacebookSdkIsStillLoadedButOnlyAfterPageLoad(): void
    {
        $sablon = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/church/_panelfacebookpageplugin.twig'
        );

        self::assertStringContainsString('connect.facebook.net', $sablon,
            'a Facebook-plugin eltűnt');
        self::assertStringContainsString("addEventListener('load'", $sablon,
            'a Facebook SDK nem a betöltés után jön');
        self::assertDoesNotMatchRegularExpression('#<script\b[^>]*\bsrc\s*=#i', $sablon,
            'a sablon még mindig közvetlenül ágyaz be scriptet');
    }
}
