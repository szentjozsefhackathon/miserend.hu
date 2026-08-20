<?php

use PHPUnit\Framework\TestCase;

/**
 * #840: egy rossz válasz ne éghessen be egy hétre a cache-be.
 *
 * Az ExternalApi csak a 429/503/504 válaszokat hagyta ki a cache-írásból. Az Overpass
 * viszont a szerveroldali időtúllépésre is HTTP 200-at ad (`remark` mezővel), egy
 * földrajzilag korlátozott tükör pedig hiánytalan JSON-t a világ töredékéről —
 * mindkettő „sikeresen" befért a cache-be, és onnantól a napi szinkron heteken át
 * ugyanazt az egy rossz választ olvasta vissza, hálózat nélkül, 0,15 másodperc alatt.
 *
 * Két dolgot rögzítünk itt:
 *   1. a visszautasított válasz NEM kerül a cache-be;
 *   2. ha a cache-BŐL jött a visszautasított válasz, a fájlt el is dobjuk — különben a
 *      javítás a cache teljes élettartamáig nem érne semmit.
 */
final class OverpassCachePoisoningTest extends TestCase {

    private const NEV = 'teszt_overpass_840';

    private function takarit(): void {
        foreach (glob(PATH . 'fajlok/tmp/' . self::NEV . '_*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function setUp(): void {
        $this->takarit();
    }

    protected function tearDown(): void {
        $this->takarit();
    }

    private function api(string $valasz): OverpassApiDouble {
        $api = new OverpassApiDouble();
        $api->name = self::NEV;
        $api->valasz = $valasz;
        // Egyetlen végpont, hogy a lánc ne mossa el, mi történt.
        $api->fallbackUrls = ['https://pelda.example/api/interpreter'];

        return $api;
    }

    /** A `remark`-os válasz nem siker — és nem is kerül a cache-be. */
    public function testARemarkResponseIsNotCached(): void {
        $api = $this->api('{"elements":[],"remark":"runtime error: Query timed out"}');
        $api->buildSimpleQuery('["url:miserend"]');
        $api->run();

        self::assertTrue($api->hasError(), 'a remark-os válasz hiba');
        self::assertFileDoesNotExist($api->cacheFilePath, 'hibás választ nem mentünk cache-be');
    }

    /** A hiányos válasz sem. Ez a konkrét éles eset: 1 elem ~2500 helyett. */
    public function testAnImplausiblyShortResponseIsNotCached(): void {
        $api = $this->api('{"elements":[{"type":"way","id":94545227}]}');
        $api->minElements = 2500;
        $api->buildSimpleQuery('["url:miserend"]');
        $api->run();

        self::assertTrue($api->hasError());
        self::assertFileDoesNotExist($api->cacheFilePath);
    }

    /**
     * A LÉNYEG: a MÁR BENT LÉVŐ mérgezett cache-t is el kell dobni.
     *
     * Enélkül a javítás után is a régi, rossz válasz jönne vissza — élesben egy hétig.
     */
    public function testAPoisonedCacheEntryIsDiscardedOnRead(): void {
        $api = $this->api('{"elements":[],"remark":"runtime error: Query timed out"}');
        $api->buildSimpleQuery('["url:miserend"]');
        $api->loadCacheFilePath();

        // Kézzel beírjuk a mérgezett bejegyzést, mintha egy korábbi futás hagyta volna.
        file_put_contents($api->cacheFilePath, '{"elements":[{"type":"way","id":94545227}]}');
        self::assertFileExists($api->cacheFilePath);

        $friss = $this->api('{"elements":[],"remark":"runtime error: Query timed out"}');
        $friss->minElements = 2500;
        $friss->buildSimpleQuery('["url:miserend"]');
        $friss->run();

        self::assertTrue($friss->hasError());
        self::assertFileDoesNotExist($friss->cacheFilePath,
            'a mérgezett cache-bejegyzést el kell dobni, különben a javítás egy hétig nem ér semmit');
    }

    /** A jó válasz viszont menjen a cache-be — a #766 nyeresége maradjon meg. */
    public function testAGoodResponseIsStillCached(): void {
        $api = $this->api('{"elements":[{"id":1},{"id":2},{"id":3}]}');
        $api->minElements = 3;
        $api->buildSimpleQuery('["url:miserend"]');
        $api->run();

        self::assertFalse($api->hasError(), $api->getErrorMessage());
        self::assertFileExists($api->cacheFilePath);
        self::assertSame('https://pelda.example/api/interpreter', $api->usedUrl);
    }

    /**
     * Ha minden végpont elhasal, a `jsonData` is ürüljön ki.
     *
     * Eddig az utolsó — visszautasított — végpont hiányos válasza ottmaradt benne, és a
     * hívók egy része csak azt nézte, van-e `elements` (l. `OSM::syncUrlMiserendFromOSM`).
     * Így egy elutasított válaszból mégis adatot írtunk volna.
     */
    public function testTheRejectedPayloadDoesNotSurviveTheFailure(): void {
        $api = $this->api('{"elements":[{"type":"way","id":94545227}]}');
        $api->minElements = 2500;
        $api->buildSimpleQuery('["url:miserend"]');
        $api->run();

        self::assertTrue($api->hasError());
        self::assertSame([], (array) ($api->jsonData->elements ?? null),
            'elutasított válasz ne maradjon feldolgozható állapotban');
    }
}

/**
 * Hálózat nélküli dublőr: a `downloadData()` helyére egy előre megadott válasz kerül,
 * minden más (cache, ellenőrzés, fallback-ciklus) az eredeti kód.
 */
class OverpassApiDouble extends \ExternalApi\OverpassApi {

    public $valasz = '{"elements":[]}';

    function downloadData() {
        $this->rawData = $this->valasz;
        $this->responseCode = 200;
        $this->jsonData = json_decode($this->rawData);
    }
}
