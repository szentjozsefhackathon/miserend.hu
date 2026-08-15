<?php

use PHPUnit\Framework\TestCase;

/**
 * #642: az OSM-ben sok helyen a régi `?templom=1254` alakú link szerepel. Erre
 * kattintva a látogató egy elérhetetlen címen kötött ki:
 *
 *   http://miserend.hu:8000/templom/1254?templom=1254   (ERR_CONNECTION_TIMED_OUT)
 *
 * Az Apache a 8000-es porton figyel (előtte a Caddy a 443-on), és a relatív
 * átirányítási célból abszolút URL-t építve odatette a saját fizikai portját;
 * a séma http-re esett vissza; a query stringet pedig megkettőzte.
 *
 * Valódi HTTP-hívásokkal ellenőrizzük, mert ez .htaccess-viselkedés — PHP-ből
 * nem látszik.
 */
final class LegacyChurchUrlRedirectTest extends TestCase {

    private string $baseUrl;

    protected function setUp(): void {
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');
    }

    /**
     * @return array{status:int, location:?string}
     */
    private function head(string $path, array $headers = []): array {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => $headers,
                /*
                 * A tesztek többsége átirányítást kér, az 1 ezredmásodperc alatt
                 * megvan. Egy viszont TELJES templomlapot tölt (annak a lényege,
                 * hogy perjel nélkül NINCS átirányítás), és az hidegen 9-10
                 * másodperc, mert a lap külső API-kat hív. A korábbi 10 másodperc
                 * épp a mért időn ült, ezért a CI-ban véletlenszerűen elbukott
                 * "HTTP kérés nem sikerült"-tel.
                 *
                 * A gyökérok a külső API-függés, azt a #695 rendezi; addig itt
                 * bőven a mért idő fölé emelem a korlátot.
                 */
                'timeout' => 45,
            ],
        ]);

        $body = @file_get_contents($this->baseUrl . $path, false, $context);
        $this->assertNotFalse($body, 'HTTP kérés nem sikerült: ' . $path);

        $status = 0;
        $location = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, strlen('Location:')));
            }
        }

        return ['status' => $status, 'location' => $location];
    }

    /* A bejelentett eset: https-proxy mögül, valódi hosztnévvel. */
    public function testLegacyQueryUrlRedirectsToCleanHttpsUrl(): void {
        $response = $this->head('/?templom=1254', [
            'Host: miserend.hu',
            'X-Forwarded-Proto: https',
        ]);

        self::assertSame(301, $response['status']);
        self::assertSame('https://miserend.hu/templom/1254', $response['location']);
    }

    /* A belső port SOHA nem szivároghat ki az átirányításba. */
    public function testRedirectNeverLeaksTheInternalPort(): void {
        $response = $this->head('/?templom=1254', ['Host: miserend.hu']);

        self::assertSame(301, $response['status']);
        self::assertStringNotContainsString(':8000', (string) $response['location']);
    }

    /* A query string nem kettőződhet meg (`?templom=1254` a végén). */
    public function testRedirectDoesNotDuplicateTheQueryString(): void {
        $response = $this->head('/?templom=1254', ['Host: miserend.hu']);

        self::assertStringNotContainsString('templom=', (string) $response['location']);
    }

    /* A többi paramétert viszont meg kell tartani. */
    public function testOtherQueryParametersSurvive(): void {
        $response = $this->head('/?templom=1254&foo=bar', [
            'Host: miserend.hu',
            'X-Forwarded-Proto: https',
        ]);

        self::assertSame('https://miserend.hu/templom/1254?foo=bar', $response['location']);
    }

    /* A www→nem-www átirányítás se ejtse vissza https-ről http-re. */
    public function testWwwRedirectKeepsHttps(): void {
        $response = $this->head('/templom/1254', [
            'Host: www.miserend.hu',
            'X-Forwarded-Proto: https',
        ]);

        self::assertSame(301, $response['status']);
        self::assertSame('https://miserend.hu/templom/1254', $response['location']);
    }

    /* Fejlesztői környezetben a port MARAD — ott az a helyes. */
    public function testDevelopmentHostKeepsItsPort(): void {
        $response = $this->head('/?templom=1254', ['Host: localhost:8000']);

        self::assertSame('http://localhost:8000/templom/1254', $response['location']);
    }

    /*
     * #658: a záró perjelet levágó szabály ugyanebbe a csapdába esett, csak a
     * #642-es javítás nem terjedt ki rá — relatív cél maradt, ráadásul a PROTO
     * beállítása ELŐTT. Így minden záró perjeles URL (nem csak a templomlapok)
     * a kívülről elérhetetlen http://miserend.hu:8000/... címre irányított.
     */
    public static function trailingSlashPathProvider(): array {
        return [
            'templomlap'  => ['/templom/1254/', 'https://miserend.hu/templom/1254'],
            'kereses'     => ['/kereses/',      'https://miserend.hu/kereses'],
            'mely utvonal' => ['/templom/1254/naptar/', 'https://miserend.hu/templom/1254/naptar'],
        ];
    }

    /** @dataProvider trailingSlashPathProvider */
    public function testTrailingSlashRedirectsToCleanHttpsUrl(string $path, string $expected): void {
        $response = $this->head($path, [
            'Host: miserend.hu',
            'X-Forwarded-Proto: https',
        ]);

        self::assertSame(301, $response['status']);
        self::assertSame($expected, $response['location']);
    }

    /** @dataProvider trailingSlashPathProvider */
    public function testTrailingSlashRedirectNeverLeaksTheInternalPort(string $path): void {
        $response = $this->head($path, ['Host: miserend.hu', 'X-Forwarded-Proto: https']);

        self::assertStringNotContainsString(':8000', (string) $response['location']);
    }

    /* A puszta gyökeret nem szabad átirányítani (különben végtelen ciklus). */
    public function testRootIsNotRedirected(): void {
        $response = $this->head('/', ['Host: miserend.hu', 'X-Forwarded-Proto: https']);

        self::assertSame(200, $response['status']);
        self::assertNull($response['location']);
    }

    /* Perjel nélkül nincs átirányítás — a lapnak egyből ki kell szolgálódnia. */
    public function testPathWithoutTrailingSlashIsServedDirectly(): void {
        $response = $this->head('/templom/1254', ['Host: miserend.hu', 'X-Forwarded-Proto: https']);

        self::assertSame(200, $response['status']);
    }

    /* Fejlesztői környezetben itt is marad a port. */
    public function testTrailingSlashKeepsDevelopmentPort(): void {
        $response = $this->head('/kereses/', ['Host: localhost:8000']);

        self::assertSame('http://localhost:8000/kereses', $response['location']);
    }
}
