<?php

use PHPUnit\Framework\TestCase;

/**
 * #391: a nyers `$_REQUEST` kiváltása a validáló `\Request::` osztállyal.
 *
 * A maradék előfordulások átnézésekor két valódi hiba került elő:
 *
 *  - Az `/ajax` végpont a TELJES kérést visszaechózta JSON-ként
 *    (`json_encode($_REQUEST)`), tehát tetszőleges bemenetet tükrözött vissza.
 *  - A `/ajax/churchrelationshipsinbbox` isset-ellenőrzés nélkül olvasta a
 *    `$_REQUEST['bbox']`-ot, így hiányzó paraméternél PHP-figyelmeztetést hagyott
 *    a naplóban — pedig pontosan erre való a `\Request::Bbox()`.
 *
 * Valódi HTTP-hívások a futó példány ellen.
 */
class AjaxEndpointsTest extends TestCase {

    private string $baseUrl;

    protected function setUp(): void {
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');
    }

    private function fetch(string $path): array {
        $json = @file_get_contents($this->baseUrl . $path);
        $this->assertNotFalse($json, 'A kérés nem sikerült: ' . $path);
        $data = json_decode($json, true);
        $this->assertIsArray($data, 'A válasznak JSON-nak kell lennie: ' . $path);
        return $data;
    }

    /**
     * A lényeg: amit beküldök, az NE jöjjön vissza.
     */
    public function testAzAjaxGyokerNemTukroziVisszaAKerest(): void {
        $data = $this->fetch('/ajax?titok=erzekeny-ertek');

        $this->assertArrayNotHasKey('titok', $data, 'A végpont nem tükrözhet vissza tetszőleges paramétert.');
        $this->assertStringNotContainsString(
            'erzekeny-ertek',
            json_encode($data, JSON_UNESCAPED_UNICODE),
            'A beküldött érték nem jelenhet meg a válaszban.'
        );
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * A naptár-végpontok bázisa ugyanígy tükrözött. Ez elérhető URL: a
     * `/calendar/calendarapi` és az ismeretlen `/calendar/*` is ide fut be.
     */
    public function testANaptarBazisSemTukroz(): void {
        foreach (['/calendar/calendarapi', '/calendar/nincsilyenvegpont'] as $path) {
            $data = $this->fetch($path . '?titok=masik-ertek');

            $this->assertStringNotContainsString(
                'masik-ertek',
                json_encode($data, JSON_UNESCAPED_UNICODE),
                'A beküldött érték nem jelenhet meg a válaszban: ' . $path
            );
            $this->assertArrayHasKey('error', $data);
        }
    }

    /**
     * Hiányzó és hibás `bbox` esetén üres lista, hiba nélkül.
     */
    public function testBboxNelkulUresLista(): void {
        $data = $this->fetch('/ajax/churchrelationshipsinbbox');
        $this->assertSame(['relationships' => []], $data);
    }

    public function testErvenytelenBboxEseténUresLista(): void {
        $this->assertSame(['relationships' => []], $this->fetch('/ajax/churchrelationshipsinbbox?bbox=abc'));
        $this->assertSame(['relationships' => []], $this->fetch('/ajax/churchrelationshipsinbbox?bbox=1;2;3'));
    }

    /**
     * ...de érvényes bbox-ra tényleg ad adatot — nem elég, hogy „nem hibázik".
     */
    public function testErvenyesBboxraAdKapcsolatokat(): void {
        $data = $this->fetch('/ajax/churchrelationshipsinbbox?bbox=45.7;17.1;48.0;21.8');

        $this->assertArrayHasKey('relationships', $data);
        if (empty($data['relationships'])) {
            $this->markTestSkipped('Ebben az adatbázisban nincs kapcsolat a vizsgált területen.');
        }

        $first = $data['relationships'][0];
        $this->assertArrayHasKey('parent', $first);
        $this->assertArrayHasKey('child', $first);
        $this->assertArrayHasKey('lat', $first['parent']);
    }

    /**
     * #391: a lapozó eddig NYERSEN vette át a `page`/`take` értéket, a hívók pedig
     * szoroznak vele — `?page=abc` és `?page[]=1` HTTP 500-at adott MINDEN keresőoldalon:
     *
     *   PHP Fatal error: Uncaught TypeError: Unsupported operand types: int * string
     *     in searchresultschurches.php:108
     *
     * @dataProvider rosszLapszamok
     */
    public function testRosszLapszamNemDontiOsszeAKeresest(string $query): void {
        $url = $this->baseUrl . '/index.php?q=SearchResultsChurches&kulcsszo=Budapest' . $query;
        $html = @file_get_contents($url);

        $this->assertNotFalse($html, 'Az oldalnak be kell töltenie: ' . $query);
        $this->assertMatchesRegularExpression(
            '#/templom/\d+#',
            $html,
            'Hibás lapszámnál is találatokat kell mutatni (az első lapot): ' . $query
        );
    }

    public static function rosszLapszamok(): array {
        return [
            'szöveg'    => ['&page=abc'],
            'tömb'      => ['&page[]=1'],
            'negatív'   => ['&page=-5'],
            'tört'      => ['&page=1.5'],
            'rossz take'=> ['&take=abc'],
        ];
    }

    /**
     * ...de az érvényes `take` továbbra is hasson.
     */
    public function testErvenyesTakeHat(): void {
        $html = @file_get_contents(
            $this->baseUrl . '/index.php?q=SearchResultsChurches&kulcsszo=Budapest&take=5'
        );
        $this->assertNotFalse($html);

        preg_match_all('#/templom/(\d+)#', $html, $m);
        $ids = array_unique($m[1]);
        $this->assertLessThanOrEqual(5, count($ids), 'take=5 esetén legfeljebb 5 templom.');
        $this->assertGreaterThan(0, count($ids), 'De legyen találat.');
    }

}
