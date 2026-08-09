<?php

use PHPUnit\Framework\TestCase;

/**
 * #89: „Helyszín körüli keresés nem működik helyesen."
 *
 * A jegy 2016 óta nyitva: a kereső beolvasta a `hely` és `tavolsag` paramétert, de SOHA
 * nem alkalmazta — a találatok teljesen figyelmen kívül hagyták a megadott helyet
 * (a jegy példája: Szentendre + 10 km → Micske, Szirmabesenyő).
 *
 * A hiányzó láncszem az indexben volt: a `location` mező geo_point-ként szerepelt a
 * mappingben, de SENKI nem töltötte fel — nulla dokumentumban volt érték.
 *
 * Valódi HTTP-hívások a futó példány ellen. A geokódolás külső szolgáltatás (Nominatim),
 * ezért ha nem érhető el, a teszt kihagyja magát, nem hazudik zöldet.
 */
class GeoSearchTest extends TestCase {

    /** Szentendre koordinátája — a jegyben szereplő példa. */
    private const LAT = 47.6678;
    private const LON = 19.0760;

    /**
     * A CI egy ELŐRE GYÁRTOTT Elasticsearch-pillanatképpel indul, amiben a `location`
     * mező még nincs feltöltve (épp ez a jegy lényege). A teszt ezért maga indexeli újra
     * azt a néhány templomot, amivel dolgozik — így nem függ attól, futott-e már a
     * napi cron.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        try {
            \ExternalApi\ElasticsearchApi::updateChurches(self::TEST_CHURCH_IDS);
            // A frissen beírt dokumentum alapból ~1 másodperc múlva válik kereshetővé;
            // enélkül a lenti mérések hamis „nincs adat" eredményt adnának.
            @file_get_contents('http://elasticsearch:9200/churches/_refresh', false,
                stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true]]));
        } catch (\Throwable $e) {
            // Az egyes tesztek külön kezelik, ha nincs adat.
        }
    }

    /** Szentendre környéki templomok + egy távolabbi budapesti, hogy a sugár számítson. */
    private const TEST_CHURCH_IDS = [1, 114, 2156, 5254, 37];

    private string $baseUrl;

    protected function setUp(): void {
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');
    }

    private function fetch(string $query): string {
        $html = @file_get_contents($this->baseUrl . '/index.php?' . $query);
        $this->assertNotFalse($html, 'A kérés nem sikerült: ' . $query);
        return $html;
    }

    /** @return int[] a lapon szereplő templom-azonosítók */
    private function churchIds(string $html): array {
        preg_match_all('#/templom/(\d+)#', $html, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * Az index `location` mezője fel van töltve. Enélkül minden más hiába.
     */
    public function testAzIndexTartalmazKoordinatat(): void {
        $count = $this->esCount(['exists' => ['field' => 'location']]);
        if ($count === null) {
            $this->markTestSkipped('Az Elasticsearch nem érhető el.');
        }
        $this->assertGreaterThan(
            0,
            $count,
            'A churches index `location` mezője üres — a geo_point mapping létezik, de senki nem tölti fel.'
        );
    }

    /** @return int|null a találatok száma, vagy null ha az ES nem érhető el */
    private function esCount(array $query): ?int {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['query' => $query]),
            'timeout' => 15, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents('http://elasticsearch:9200/churches/_count', false, $ctx);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return isset($data['count']) ? (int) $data['count'] : null;
    }

    /**
     * A jegy eredeti panasza: a sugáron kívüli templomok NEM jöhetnek elő.
     */
    public function testCsakASugaronBelulAdTalalatot(): void {
        $html = $this->fetch('q=SearchResultsChurches&kulcsszo=&hely=Szentendre&tavolsag=10');

        if (!str_contains($html, 'Legfeljebb')) {
            $this->markTestSkipped('A geokódoló (Nominatim) nem érhető el ebben a környezetben.');
        }

        $ids = $this->churchIds($html);
        $this->assertNotEmpty($ids, 'Szentendre környékén vannak templomok.');

        // Minden találatnak a körön belül kell lennie — az ES-től kérdezzük vissza.
        $inRadius = $this->esCount(['bool' => ['filter' => [
            ['terms' => ['id' => $ids]],
            ['geo_distance' => ['distance' => '10km', 'location' => ['lat' => self::LAT, 'lon' => self::LON]]],
        ]]]);

        $this->assertSame(
            count($ids),
            $inRadius,
            'A lapon szereplő MINDEN templomnak a 10 km-es körön belül kell lennie.'
        );
    }

    /**
     * Nagyobb sugár nem adhat kevesebb találatot.
     */
    public function testNagyobbSugarNemSzukit(): void {
        $kicsi = $this->esCount(['geo_distance' => ['distance' => '5km',   'location' => ['lat' => self::LAT, 'lon' => self::LON]]]);
        $nagy  = $this->esCount(['geo_distance' => ['distance' => '50km',  'location' => ['lat' => self::LAT, 'lon' => self::LON]]]);
        if ($kicsi === null || $nagy === null) {
            $this->markTestSkipped('Az Elasticsearch nem érhető el.');
        }
        $this->assertGreaterThanOrEqual($kicsi, $nagy, '50 km-en nem lehet KEVESEBB templom, mint 5 km-en.');
        $this->assertGreaterThan(0, $nagy, '50 km-es körben lennie kell legalább egy templomnak.');
    }

    /**
     * Ismeretlen hely: figyelmeztetés, de a keresés a többi szűrővel fusson tovább —
     * ne néma üres oldal, és ne hibaoldal.
     */
    public function testIsmeretlenHelyNemDontiOsszeAKeresest(): void {
        $html = $this->fetch('q=SearchResultsChurches&kulcsszo=Budapest&hely=nemletezohelyseg12345&tavolsag=10');

        $this->assertStringNotContainsString('HIBA!', $html);
        $this->assertNotEmpty(
            $this->churchIds($html),
            'A kulcsszavas keresésnek tovább kell futnia akkor is, ha a helyet nem ismerjük.'
        );
    }

    /**
     * Hely megadva, de nulla sugárral: nincs geo-szűrés (a régi viselkedés).
     */
    public function testNullaSugarnalNincsGeoSzures(): void {
        $html = $this->fetch('q=SearchResultsChurches&kulcsszo=Budapest&hely=Szentendre&tavolsag=0');

        $this->assertStringNotContainsString('Legfeljebb', $html);
        $this->assertNotEmpty($this->churchIds($html));
    }

    /**
     * A mise-kereső is szűr helyre.
     *
     * A helynevet geokódoljuk, onnantól ugyanaz a kör-keresés fut, mint koordinátánál
     * (#608, `Search::nearby`): geo_distance a MISE-indexen, a `church.lat`/`church.lon`
     * mezőkből képzett futásidejű geo_point-tal.
     *
     * Ezért itt a mise-indexet kérdezzük vissza, nem a templom-indexet: a keresés is
     * abból dolgozik. (A templom-index `location` mezője a tesztadatban csak arra a néhány
     * templomra van feltöltve, amit a setUpBeforeClass újraindexel — az ellene mért
     * ellenőrzés a többi találatot tévesen „körön kívülinek" látná.)
     */
    public function testMisekeresoIsSzurHelyre(): void {
        $html = $this->fetch('q=SearchResultsMasses&kulcsszo=&hely=Szentendre&tavolsag=10');

        if (!str_contains($html, 'Legfeljebb')) {
            $this->markTestSkipped('A geokódoló (Nominatim) nem érhető el ebben a környezetben.');
        }

        $this->assertStringNotContainsString('HIBA!', $html);
        $this->assertStringContainsString('Innen: <b>Szentendre', $html, 'A szűrő nevezze meg a helyet.');

        $ids = $this->churchIds($html);
        if ($ids === []) {
            $this->markTestSkipped('Ezen a napon nincs mise a környéken.');
        }

        foreach ($ids as $id) {
            $distance = $this->massIndexDistanceKm($id);
            if ($distance === null) {
                $this->fail("A(z) $id templomnak nincs koordinátája a mise-indexben, mégis bekerült a körre szűrt találatok közé.");
            }
            // Fél kilométer ráhagyás: az ES gömbi, a lenti számítás sík közelítés.
            $this->assertLessThanOrEqual(10.5, $distance, "A(z) $id templom $distance km-re van, kívül a 10 km-es körön.");
        }
    }

    /** A templom távolsága Szentendrétől, a mise-indexben tárolt koordináta alapján. */
    private function massIndexDistanceKm(int $churchId): ?float {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode([
                'size' => 1,
                'query' => ['term' => ['church_id' => $churchId]],
                '_source' => ['includes' => ['church.lat', 'church.lon']],
            ]),
            'timeout' => 15, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents('http://elasticsearch:9200/mass_index/_search', false, $ctx);
        if ($raw === false) {
            return null;
        }

        $source = json_decode($raw, true)['hits']['hits'][0]['_source']['church'] ?? null;
        if (!isset($source['lat'], $source['lon'])) {
            return null;
        }

        $dx = deg2rad($source['lon'] - self::LON) * cos(deg2rad(self::LAT)) * 6371;
        $dy = deg2rad($source['lat'] - self::LAT) * 6371;
        return sqrt($dx * $dx + $dy * $dy);
    }
}
