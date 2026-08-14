<?php

use PHPUnit\Framework\TestCase;

/**
 * #722: az „Egyéni közeli keresés" és a „Hely közelében" ugyanaz a szűrő volt, csak az
 * egyik helynévvel, a másik koordinátával kérdezett. Egyesítve.
 *
 * Amit ez a teszt őriz:
 *  - a koordináta a TEMPLOMKERESÉSRE is hat (eddig a mezők elmentek a kérésben, de az
 *    oldal nem is olvasta be őket, tehát a szűrő némán hatástalan volt);
 *  - a helynév és a belőle geokódolt koordináta UGYANAZT az eredményt adja;
 *  - a közös távolság-választó (`tavolsag`) a koordinátás keresésnél is sugárként hat;
 *  - a helynév → koordináta ajax-végpont működik.
 */
class UnifiedNearbySearchTest extends TestCase {

    /** Szentendre — a #89 óta ez a példa mindenhol. */
    private const LAT = 47.6678;
    private const LON = 19.0760;

    private string $baseUrl;

    protected function setUp(): void {
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');
    }

    private function fetch(string $query): string {
        $html = @file_get_contents($this->baseUrl . '/index.php?' . $query);
        $this->assertNotFalse($html, 'A kérés nem sikerült: ' . $query);
        return $html;
    }

    /** @return int[] */
    private function churchIds(string $html): array {
        preg_match_all('#/templom/(\d+)#', $html, $m);
        $ids = array_values(array_unique(array_map('intval', $m[1])));
        sort($ids);
        return $ids;
    }

    /**
     * A koordinátás keresés MINDEN találata essen a sugáron belülre.
     *
     * Ez a teszt korábban a szűrt és a szűretlen találatok DARABSZÁMÁT hasonlította
     * össze. Az összehasonlítás megtévesztő: mindkét lekérdezés egy találati OLDALT ad
     * vissza (20 elem), tehát amint a szűrt keresés is megtelt egy oldalnyival, a
     * „kevesebb lett" feltétel hamis lett — pedig a szűrés hibátlanul működött. Így a
     * teszt épp akkor bukott meg, amikor a funkció helyreállt (a churches index
     * `location` mezőjének pótlása után).
     *
     * Amit ténylegesen tudni akarunk: a visszakapott templomok tényleg a megadott
     * ponthoz közel vannak-e. Ezt a saját koordinátáikból ellenőrizzük.
     */
    public function testCoordinatesNowFilterTheChurchSearch(): void {
        $sugarKm = 10;
        $szurt = $this->churchIds($this->fetch(
            'q=SearchResultsChurches&kulcsszo=&nearby_lat=' . self::LAT . '&nearby_lon=' . self::LON
            . '&nearby_radius=' . $sugarKm
        ));

        if ($this->churchIds($this->fetch('q=SearchResultsChurches&kulcsszo=')) === []) {
            $this->markTestSkipped('A templom-index üres, nincs mit szűrni.');
        }

        $this->assertNotEmpty($szurt, 'A koordinátás szűrés mindent kiszűrt.');

        $tavoli = [];
        foreach (\Eloquent\Church::whereIn('id', $szurt)->get(['id', 'lat', 'lon']) as $templom) {
            $tav = self::tavolsagKm(self::LAT, self::LON, (float) $templom->lat, (float) $templom->lon);
            // Az ES geo_distance légvonalban mér, ahogy mi is; a tizedes kerekítésre
            // hagyunk egy kevés ráhagyást.
            if ($tav > $sugarKm + 0.5) {
                $tavoli[] = '#' . $templom->id . ' (' . round($tav, 1) . ' km)';
            }
        }

        $this->assertSame(
            [],
            $tavoli,
            'A koordináta nem szűrt: a sugáron kívüli templomok is bejöttek — ' . implode(', ', $tavoli)
        );
    }

    /** Légvonalbeli távolság kilométerben (haversine). */
    private static function tavolsagKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function testPlaceNameAndItsCoordinatesGiveTheSameChurches(): void {
        $helynevvel = $this->churchIds($this->fetch(
            'q=SearchResultsChurches&kulcsszo=&hely=Szentendre&tavolsag=10'
        ));
        $koordinataval = $this->churchIds($this->fetch(
            'q=SearchResultsChurches&kulcsszo=&nearby_lat=' . self::LAT . '&nearby_lon=' . self::LON . '&nearby_radius=10'
        ));

        if ($helynevvel === []) {
            $this->markTestSkipped('A geokódoló vagy az index nem érhető el.');
        }

        $this->assertSame($helynevvel, $koordinataval);
    }

    /** A közös távolság-választó a koordinátás keresésnél is sugárként hat. */
    public function testTavolsagActsAsRadiusForCoordinates(): void {
        $szuk = $this->churchIds($this->fetch(
            'q=SearchResultsChurches&kulcsszo=&nearby_lat=' . self::LAT . '&nearby_lon=' . self::LON . '&tavolsag=2'
        ));
        $tag = $this->churchIds($this->fetch(
            'q=SearchResultsChurches&kulcsszo=&nearby_lat=' . self::LAT . '&nearby_lon=' . self::LON . '&tavolsag=50'
        ));

        if ($tag === []) {
            $this->markTestSkipped('A templom-index üres.');
        }
        $this->assertLessThanOrEqual(count($tag), count($szuk));
        $this->assertNotSame($szuk, $tag, 'A tavolsag nem hat a koordinátás keresésre.');
    }

    public function testGeocodeAjaxEndpointReturnsCoordinates(): void {
        $raw = $this->fetch('q=ajax/Geocode&hely=Szentendre');
        $data = json_decode($raw, true);

        $this->assertIsArray($data, 'A végpont nem JSON-t adott: ' . substr($raw, 0, 120));
        if (empty($data['found'])) {
            $this->markTestSkipped('A geokódoló (Nominatim) nem érhető el.');
        }

        $this->assertEqualsWithDelta(self::LAT, $data['lat'], 0.05);
        $this->assertEqualsWithDelta(self::LON, $data['lon'], 0.05);
        $this->assertStringContainsString('Szentendre', $data['name']);
    }

    public function testGeocodeAjaxEndpointRejectsTooShortInput(): void {
        $data = json_decode($this->fetch('q=ajax/Geocode&hely=ab'), true);

        $this->assertIsArray($data);
        $this->assertFalse($data['found']);
    }

    /** @dataProvider ervenytelenPontok */
    public function testInvalidPointsAreRejected($lat, $lon, $radius): void {
        $this->assertNull(\Html\SearchResultsChurches::validNearbyPoint($lat, $lon, $radius));
    }

    public static function ervenytelenPontok(): array {
        return [
            'hiányzó hosszúság' => ['47.5', '', 10],
            'hiányzó szélesség' => ['', '19.0', 10],
            'nem szám' => ['negyvenhét', '19.0', 10],
            'tizedesvessző' => ['47,5', '19.0', 10],
            'tartományon kívüli szélesség' => ['91', '19.0', 10],
            'tartományon kívüli hosszúság' => ['47.5', '181', 10],
            'nulla sugár' => ['47.5', '19.0', 0],
            'túl nagy sugár' => ['47.5', '19.0', 201],
        ];
    }

    public function testValidPointIsAccepted(): void {
        $this->assertSame(
            ['lat' => 47.5, 'lon' => 19.0, 'radius' => 10.0],
            \Html\SearchResultsChurches::validNearbyPoint('47.5', '19.0', 10)
        );
    }
}
