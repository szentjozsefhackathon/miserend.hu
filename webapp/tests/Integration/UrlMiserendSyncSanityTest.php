<?php

use PHPUnit\Framework\TestCase;

/**
 * #840: a napi OSM-szinkron ne tudjon NÉMÁN hiányos adatból dolgozni.
 *
 * Élesben másfél hónapon át ezt írta ki minden éjjel, és a cron zöld maradt:
 *
 *   OSM url:miserend: 1 elem átkötve, 0 hibás értékű, 0 ismeretlen templomra mutat.
 *   Cron job \OSM->syncUrlMiserendFromOSM() completed in 0.15 seconds.
 *
 * Nem elhasalt: LEFUTOTT, egyetlen elemmel. Semmi nem szólt, mert a rendszernek nem
 * volt fogalma arról, mennyit KELLENE kapnia. Itt kap egyet.
 */
final class UrlMiserendSyncSanityTest extends TestCase {

    /**
     * Az elvárást a saját adatunkból származtatjuk, nem konstansból: ahány
     * misézőhelyünkhöz OSM-azonosító tartozik, annyi elem környékének kell jönnie.
     */
    public function testTheExpectationComesFromOurOwnData(): void {
        $osmAzonositoval = $this->osmAzonositoval();

        self::assertSame((int) floor($osmAzonositoval / 5), \OSM::expectedUrlMiserendElements());
    }

    /**
     * A küszöb elég magas ahhoz, hogy az éles hibát elkapja: a svájci tükör EGY elemet
     * adott vissza.
     */
    public function testOneElementIsBelowTheThreshold(): void {
        if (\OSM::expectedUrlMiserendElements() === 0) {
            self::markTestSkipped('ebben az adatbázisban nincs OSM-azonosítós templom');
        }

        self::assertGreaterThan(1, \OSM::expectedUrlMiserendElements());
    }

    /**
     * ...és elég alacsony ahhoz, hogy a valódi ingadozásba ne szóljon bele.
     *
     * A hoszton mérve 5035 `url:miserend` elem van az OSM-ben, nálunk ~5000 templomnak
     * van OSM-azonosítója — a küszöb ennek az ötöde. Füstjelző, nem mérőműszer.
     */
    public function testTheThresholdLeavesRoomForRealChange(): void {
        $osmAzonositoval = $this->osmAzonositoval();

        if ($osmAzonositoval === 0) {
            self::markTestSkipped('ebben az adatbázisban nincs OSM-azonosítós templom');
        }

        self::assertLessThan($osmAzonositoval / 2, \OSM::expectedUrlMiserendElements());
    }

    /**
     * A HIÁNYOS válaszból a szinkron NE írjon adatot — dobjon, hogy a cron pirosra váltson.
     *
     * Pontosan az éles eset: egyetlen elem érkezik, ami formailag hibátlan és egy létező
     * templomra mutat. A régi kód ezt feldolgozta és sikert jelentett.
     */
    public function testTheSyncRefusesAnImplausiblyShortAnswer(): void {
        $overpass = new SzinkronOverpassDouble();
        $overpass->valasz = '{"elements":[{"type":"way","id":94545227,"tags":{"url:miserend":"https://miserend.hu/templom/5024"}}]}';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/sem adott használható választ/');

        (new \OSM())->syncUrlMiserendFromOSM($overpass);
    }

    /**
     * Ha viszont elég elem jön, a szinkron dolgozzon — a védelem ne akadjon be.
     *
     * A küszöb fölé annyi elemet gyártunk, amennyit az adatbázis megkövetel; mind
     * ugyanarra a nemlétező templomra mutat, tehát adatot nem írunk, csak azt nézzük,
     * hogy a futás VÉGIGMEGY.
     */
    public function testTheSyncRunsWhenTheAnswerIsPlausible(): void {
        $kell = \OSM::expectedUrlMiserendElements();
        if ($kell === 0) {
            self::markTestSkipped('ebben az adatbázisban nincs OSM-azonosítós templom');
        }

        $elemek = [];
        for ($i = 0; $i < $kell + 1; $i++) {
            $elemek[] = [
                'type' => 'way',
                'id' => 900000000 + $i,
                // Szándékosan nemlétező templom: a futás végigmegy, de nem ír adatot.
                'tags' => ['url:miserend' => 'https://miserend.hu/templom/99' . str_pad((string) $i, 6, '0', STR_PAD_LEFT)],
            ];
        }

        $overpass = new SzinkronOverpassDouble();
        $overpass->valasz = json_encode(['elements' => $elemek]);

        ob_start();
        (new \OSM())->syncUrlMiserendFromOSM($overpass);
        $kimenet = ob_get_clean();

        self::assertStringContainsString((string) count($elemek), $kimenet,
            'a futás írja ki, hány elem érkezett — a mérsékelt visszaesés így is látszik');
    }

    private function osmAzonositoval(): int {
        return \Eloquent\Church::whereNotNull('osmid')
            ->where('osmid', '<>', '')
            ->whereNotNull('osmtype')
            ->where('osmtype', '<>', '')
            ->count();
    }
}

/** Hálózat nélküli Overpass: előre megadott válasz, minden más az eredeti kód. */
class SzinkronOverpassDouble extends \ExternalApi\OverpassApi {

    public $valasz = '{"elements":[]}';

    function __construct() {
        parent::__construct();
        // Egyetlen végpont: a lánc ne mossa el, mi történt.
        $this->fallbackUrls = ['https://pelda.example/api/interpreter'];
        $this->cache = false;
    }

    function downloadData() {
        $this->rawData = $this->valasz;
        $this->responseCode = 200;
        $this->jsonData = json_decode($this->rawData);
    }
}
