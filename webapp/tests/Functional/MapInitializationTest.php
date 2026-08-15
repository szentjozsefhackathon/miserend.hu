<?php

use Facebook\WebDriver\WebDriverDimension;

/**
 * #640: a /terkep elsőre hibára futott, ctrl+R-re viszont megjavult.
 *
 * Két, egymást takaró sorrendi hiba volt:
 *  - a script végén állt egy onMapMoved() hívás, ami az auto-geolokációs ágon még nézet
 *    nélküli térképen futott -> "Set map center and zoom first",
 *  - a 'load' esemény viszont a nem-geolokációs ágon túl KORÁN sült el, amikor a rétegek
 *    és az svgIcons még nem léteztek -> "Cannot access 'svgIcons' before initialization".
 * Frissítésre azért működött, mert a gyorsítótárazott pozíció megnyerte a versenyt.
 *
 * Ez a teszt azt őrzi, hogy a térkép ELSŐ betöltésre is hibátlanul álljon fel.
 */
use Tests\Functional\FunctionalTestCase;

final class MapInitializationTest extends FunctionalTestCase {

    private function client() {
        return static::pantherClient();
    }

    /*
     * #653: a térkép LUSTÁN indul — csak akkor épül fel, ha a konténere látótávolságba
     * kerül. A teszt tehát odagörget, ahogy egy valódi látogató is tenné, és megvárja,
     * amíg a térkép felállt.
     */
    private function scrollToMapAndWait($client): void {
        $client->executeScript(
            "var m = document.getElementById('mapid');"
            . " if (m) { m.scrollIntoView({block: 'center'}); }"
        );
        $client->wait(15)->until(static function ($driver) {
            return $driver->executeScript('return !!(window.mymap && window.mymap._loaded);');
        });
    }

    /*
     * A /terkep az egyetlen hely, ahol az auto-geolokáció be van kapcsolva — épp ez az
     * ág szállt el. A böngésző a tesztben nem ad pozíciót, tehát a fallback fut le.
     */
    public function testMapPageLoadsWithoutJavascriptErrorsOnFirstVisit(): void {
        $client = $this->client();
        $client->request('GET', '/terkep');

        // A nézet a geolokációs fallback után áll be (3 mp-es időzítő), várjuk meg.
        $this->scrollToMapAndWait($client);

        $errors = $client->executeScript(
            <<<'JS'
            return (window.__mapErrors || []).slice(0, 10);
            JS
        );
        self::assertSame([], $errors, "JS-hiba a térkép betöltésekor: " . json_encode($errors));

        $hasCenter = $client->executeScript('return !!window.mymap.getCenter();');
        self::assertTrue($hasCenter, 'A térkép nézet nélkül maradt.');
    }

    /*
     * #641: pásztázáskor eddig MINDEN templomot letöröltünk és újrarajzoltunk (villódzás).
     * Most csak a különbséget rajzoljuk — ez a teszt azt őrzi, hogy a képen maradó
     * gombostűk ugyanazok a DOM-elemek maradnak, tehát tényleg nem rajzolódnak újra.
     */
    public function testPanningKeepsTheMarkersThatStayInView(): void {
        $client = $this->client();
        $client->request('GET', '/terkep?map=13/47.4979/19.0402');

        $this->scrollToMapAndWait($client);
        $client->wait(15)->until(static function ($driver) {
            return $driver->executeScript(
                'return document.querySelectorAll(".leaflet-marker-icon").length > 0;'
            );
        });

        // Megjelöljük a jelenlegi gombostűket, hogy felismerjük, ha újak születnek.
        $client->executeScript(
            'document.querySelectorAll(".leaflet-marker-icon").forEach(function (m, i) { m.dataset.mapTestSeen = "1"; });'
        );
        $before = (int) $client->executeScript('return document.querySelectorAll(".leaflet-marker-icon").length;');
        self::assertGreaterThan(0, $before, 'Nem jelent meg egyetlen templom sem a térképen.');

        // Kis elmozdulás: a látható templomok túlnyomó része ugyanaz marad.
        $client->executeScript('window.mymap.panBy([120, 0]); return true;');
        $client->wait(15)->until(static function ($driver) {
            return $driver->executeScript('return !window.mymap._panAnim || !window.mymap._panAnim._inProgress;');
        });
        usleep(1500000); // az AJAX-válasz beérkezése

        $survivors = (int) $client->executeScript(
            'return document.querySelectorAll(".leaflet-marker-icon[data-map-test-seen]").length;'
        );
        self::assertGreaterThan(
            0,
            $survivors,
            'Pásztázás után egyetlen korábbi gombostű sem maradt meg — újrarajzolás történt.'
        );
    }

    /*
     * #653: a lusta indítás lényege — aki csak a miserendet nézi meg és sosem görget le
     * a térképig, annál a Leaflet el se induljon. Ezt a templom-adatlapon tudjuk mérni,
     * ahol a térkép a hajtás alatt van.
     */
    public function testMapDoesNotStartBeforeItScrollsIntoView(): void {
        $client = $this->client();

        // ALACSONY ablak, hogy a térkép biztosan a hajtás ALÁ kerüljön. Enélkül a teszt a
        // futtató ablakméretétől függ: egy magas headless ablakban a templomoldal térképe
        // eleve látszik, és akkor jogosan indul is el (a CI-ban pont ezért bukott).
        $client->manage()->window()->setSize(new WebDriverDimension(1024, 300));
        $client->request('GET', '/templom/1');

        // Megvárjuk, hogy a szkript betöltődjön és lefusson (de NE görgetünk).
        $client->wait(10)->until(static function ($driver) {
            return $driver->executeScript('return typeof window.miserendInitMap === "function";');
        });

        $outOfView = $client->executeScript(
            'var m = document.getElementById("mapid");'
            . ' if (!m) { return false; }'
            . ' var r = m.getBoundingClientRect();'
            . ' return r.top > window.innerHeight + 200;'   // 200 px = az observer ráhagyása
        );
        if (!$outOfView) {
            self::markTestSkipped('A térkép ebben az ablakméretben is látótávolságban van.');
        }

        $started = $client->executeScript('return !!window.mymap;');
        self::assertFalse(
            (bool) $started,
            'A térkép már a görgetés előtt elindult — a lusta indítás nem működik.'
        );

        // Odagörgetve viszont fel kell állnia.
        $this->scrollToMapAndWait($client);
        self::assertTrue((bool) $client->executeScript('return !!window.mymap;'));
    }

    /*
     * A templom-adatlap kis térképe a nem-geolokációs ágat járja (kap `center`-t).
     * Itt a másik hiba (svgIcons a rétegek előtt) jött volna elő.
     */
    public function testChurchPageMapLoadsWithoutJavascriptErrors(): void {
        $client = $this->client();
        $client->request('GET', '/templom/1');

        $this->scrollToMapAndWait($client);

        $errors = $client->executeScript('return (window.__mapErrors || []).slice(0, 10);');
        self::assertSame([], $errors, "JS-hiba a templom-térkép betöltésekor: " . json_encode($errors));
    }
}
