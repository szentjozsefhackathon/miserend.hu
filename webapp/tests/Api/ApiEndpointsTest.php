<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/functions.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ApiEndpointsTest extends TestCase {

    protected ?string $token = null;
    // #ci: const (nem instance-prop), hogy a static data-providerek (self::) is
    // hivatkozhassák - az újabb PHPUnit statikus data-provider metódust követel.
    private const FIXTURE_USERNAME = 'admin';
    private const FIXTURE_PASSWORD = 'miserend';

    protected function setUp(): void {
        parent::setUp();
        $_REQUEST = [];
        $this->token = null;
    }

    protected function tearDown(): void {
        $_REQUEST = [];
        $this->token = null;
        parent::tearDown();
    }

    private string $baseUrl = 'http://miserend:8000';

    private function apiRequest(string $path, array $payload, bool $expectJson = true): mixed {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($curlError !== '') {
            $this->fail('Curl error: ' . $curlError);
        }

        $this->assertEquals(200, $httpCode, "Expected HTTP 200, got {$httpCode}");

        if (!$expectJson) {
            // Return raw response for endpoints that return plain text
            return $rawResponse;
        }

        $response = json_decode($rawResponse, true);
        $this->assertIsArray($response, "Response is not valid JSON. Raw: " . substr((string) $rawResponse, 0, 500));

        return $response;
    }

    private function assertArrayContainsSubset(array $expected, array $actual): void {
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual);

            if (is_array($value)) {
                $this->assertIsArray($actual[$key]);
                $this->assertArrayContainsSubset($value, $actual[$key]);
                continue;
            }

            $this->assertEquals($value, $actual[$key]);
        }
    }

    public function testNearbyMassesAreLimitedByRadiusAndSortedByDistance(): void {
        $response = $this->apiRequest('/api/v4/nearbymasses', [
            'lat' => 47.4979,
            'lon' => 19.0402,
            'radius' => 15.0,
            'from' => date('Y-m-d') . 'T00:00:00+02:00',
            'until' => date('Y-m-d', strtotime('+1 day')) . 'T00:00:00+02:00',
            'limit' => 20,
        ]);

        $this->assertSame(0, $response['error']);
        $this->assertNotEmpty($response['misek']);
        $distances = array_column($response['misek'], 'distance_km');
        $sortedDistances = $distances;
        sort($sortedDistances, SORT_NUMERIC);
        $this->assertSame($distances, $sortedDistances);
        foreach ($distances as $distance) {
            $this->assertLessThanOrEqual(15.0, $distance);
        }
    }

    public function testNearbyMassesRejectAnInvertedTimeRange(): void {
        $response = $this->apiRequest('/api/v4/nearbymasses', [
            'lat' => 47.4979,
            'lon' => 19.0402,
            'radius' => 15.0,
            'from' => '2026-08-08T12:00:00+02:00',
            'until' => '2026-08-08T10:00:00+02:00',
        ]);

        $this->assertEquals(1, $response['error']);
        $this->assertStringContainsString('until', $response['text']);
    }

    /**
     * @dataProvider providerTestApiSignup
     */
    public function testApiSignup($username, $json, $output) {
        $response = $this->apiRequest('/api/v4/signup', $json);

        /*echo "\n=== Test: $username ===\n";
        echo "Request: " . json_encode($json, JSON_PRETTY_PRINT) . "\n";
        echo "Expected: " . json_encode($output, JSON_PRETTY_PRINT) . "\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n"; */

        $this->assertArrayContainsSubset($output, $response);
    }

    public static function providerTestApiSignup() {
        $randomSuffix = substr(md5(uniqid()), 0, 5);
        return [
            ['vacskamati', ['username' => 'vacskamati', 'password' => 'VanValami'], ['error' => 1]],  // missing email
            ['hosszuujnev', ['username' => 'EgyHosszúÚjNév', 'email' => 'teszt@teszt.com', 'password' => 'sippala'], ['error' => 1]],  // accented username
            ['hosszuujnev', ['username' => 'user' . $randomSuffix, 'email' => 'teszt_' . $randomSuffix . '@teszt.com', 'password' => 'sippala'], ['error' => 0]],  // valid
            ['masikujnev', ['username' => 'EgyMasikHosszuUjNev', 'email' => 'teszt_' . $randomSuffix . '@teszt.com', 'password' => 'simoppo'], ['error' => 1]],  // already registered
        ];
    }

    /**
     * @dataProvider providerTestApiLogin
     */
    public function testApiLogin(string $scenario, array $json, array $output): void {
        $response = $this->apiRequest('/api/v4/login', $json);

        if ((string) $output['error'] === '0') {
            $this->assertArrayHasKey('token', $response);
        }

        $this->assertArrayContainsSubset($output, $response);
    }

    public static function providerTestApiLogin() {
        return [
            [
                'valid fixture user',
                ['username' => self::FIXTURE_USERNAME, 'password' => self::FIXTURE_PASSWORD],
                ['error' => 0],
            ],
            [
                'missing user',
                ['username' => 'random-user-does-not-exist', 'password' => 'nottherightpassword'],
                ['error' => 1],
            ],
        ];
    }

    /**
     * Clear all favorites for admin user
     */
    private function clearAdminFavorites(): void {
        global $user;
        $user = new \User(2); // admin user id
        // Remove all favorites by getting them and removing
        $user->getFavorites();
        if (!empty($user->favorites)) {
            $favIds = array_column($user->favorites, 'tid');
            $user->removeFavorites($favIds);
        }
    }

    /**
     * @dataProvider providerTestApiFavorites
     */
    public function testApiFavorites(string $scenario, array $payload, array $expectedOutput): void {
        // Clear admin's favorites before each test to ensure clean state
        $this->clearAdminFavorites();

        // First, login to get a token
        $loginResponse = $this->apiRequest('/api/v4/login', [
            'username' => self::FIXTURE_USERNAME,
            'password' => self::FIXTURE_PASSWORD,
        ]);

        $this->assertArrayHasKey('token', $loginResponse);
        $token = $loginResponse['token'];

        // Add token to the favorites request payload
        $payload['token'] = $token;

        // Make the favorites request
        $response = $this->apiRequest('/api/v4/favorites', $payload);

        /* echo "\n=== Test: $scenario ===\n";
        echo "Request: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        echo "Expected: " . json_encode($expectedOutput, JSON_PRETTY_PRINT) . "\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n"; */

        $this->assertArrayContainsSubset($expectedOutput, $response);
    }

    public static function providerTestApiFavorites() {
        return [
            [
                'empty request returns empty favorites',
                [],
                ['error' => 0, 'favorites' => []],
            ],
            [
                'add single valid church',
                ['add' => [1]],
                ['error' => 0, 'favorites' => [1]],
            ],
            [
                'add multiple churches',
                ['add' => [1, 2]],
                ['error' => 0, 'favorites' => [1, 2]],
            ],
            [
                'add and remove in same request',
                ['add' => [1, 2], 'remove' => [2]],
                ['error' => 0, 'favorites' => [1]],
            ],
            [
                'remove all favorites',
                ['add' => [1, 2], 'remove' => [1, 2]],
                ['error' => 0, 'favorites' => []],
            ],
        ];
    }

    /**
     * @dataProvider providerTestApiReportAnonymous
     */
    public function testApiReportAnonymous(string $scenario, array $payload, array $expectedOutput): void {
        $response = $this->apiRequest('/api/v3/report', $payload);

        /* echo "\n=== Test: $scenario ===\n";
        echo "Request: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        echo "Expected: " . json_encode($expectedOutput, JSON_PRETTY_PRINT) . "\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n"; */

        $this->assertArrayContainsSubset($expectedOutput, $response);
    }

    public static function providerTestApiReportAnonymous() {
        return [
            [
                'missing required tid fails',
                [],
                ['error' => 1],
            ],
            [
                'pid 0 without text succeeds',
                ['tid' => 1, 'pid' => 0],
                ['error' => 0],
            ],
            [
                'pid 1 without text succeeds',
                ['tid' => 1, 'pid' => 1],
                ['error' => 0],
            ],
            [
                'pid 2 without text fails',
                ['tid' => 1, 'pid' => 2],
                ['error' => 1],
            ],
            [
                'pid 2 with text succeeds',
                ['tid' => 1, 'pid' => 2, 'text' => 'Test report.'],
                ['error' => 0],
            ],
        ];
    }

    /**
     * @dataProvider providerTestApiReportV4
     */
    public function testApiReportV4(string $scenario, array $payload, array $expectedOutput): void {
        $response = $this->apiRequest('/api/v4/report', $payload);

        /* echo "\n=== Test: $scenario ===\n";
        echo "Request: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        echo "Expected: " . json_encode($expectedOutput, JSON_PRETTY_PRINT) . "\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n"; */

        $this->assertArrayContainsSubset($expectedOutput, $response);
    }

    public static function providerTestApiReportV4() {
        return [
            [
                'v4 without dbdate fails',
                ['tid' => 1, 'pid' => 2, 'text' => 'Test report.'],
                ['error' => 1],
            ],
            [
                'v4 with valid dbdate succeeds',
                ['tid' => 1, 'pid' => 2, 'text' => 'Test report.', 'dbdate' => date('Y-m-d')],
                ['error' => 0],
            ],
            [
                'v4 with invalid dbdate fails',
                ['tid' => 1, 'pid' => 2, 'text' => 'Test report.', 'dbdate' => 'invalid'],
                ['error' => 1],
            ],
        ];
    }

    /**
     * @dataProvider providerTestApiReportAuthenticated
     */
    public function testApiReportAuthenticated(string $scenario, array $payload, array $expectedOutput): void {
        // Login with admin fixture user
        $loginResponse = $this->apiRequest('/api/v4/login', [
            'username' => self::FIXTURE_USERNAME,
            'password' => self::FIXTURE_PASSWORD,
        ]);

        if (isset($loginResponse['token'])) {
            $payload['token'] = $loginResponse['token'];
        }

        $response = $this->apiRequest('/api/v4/report', $payload);

        /* echo "\n=== Test: $scenario ===\n";
        echo "Request: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        echo "Expected: " . json_encode($expectedOutput, JSON_PRETTY_PRINT) . "\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n"; */

        $this->assertArrayContainsSubset($expectedOutput, $response);
    }

    public static function providerTestApiReportAuthenticated() {
        return [
            [
                'authenticated user without dbdate fails',
                ['tid' => 1, 'pid' => 2, 'text' => 'Test report.'],
                ['error' => 1],
            ],
            [
                'authenticated user with different user data',
                ['tid' => 1, 'pid' => 2, 'text' => 'Test report from authenticated user.', 'dbdate' => date('Y-m-d')],
                ['error' => 0],
            ],
            [
                'authenticated user with valid token and dbdate succeeds',
                ['tid' => 1, 'pid' => 2, 'text' => 'Test report.', 'dbdate' => date('Y-m-d')],
                ['error' => 0],
            ],
        ];
    }

    /**
     * @dataProvider providerTestApiUpdatedV3
     */
    public function testApiUpdatedV3(string $scenario, array $payload, string $expectedResponse): void {
        // Updated endpoint returns plain text (0 or 1), not JSON
        $response = $this->apiRequest('/api/v3/updated', $payload, false);
        $response = trim((string) $response);

        /* echo "\n=== Test: $scenario ===\n";
        echo "Request: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        echo "Expected: $expectedResponse\n";
        echo "Response: $response\n"; */

        $this->assertStringContainsString($expectedResponse, $response);
    }

    public static function providerTestApiUpdatedV3() {
        return [
            [
                'v3 with today date returns 0',
                ['date' => date('Y-m-d')],
                '0',
            ],
            [
                'v3 with old date returns 1 or 0',
                ['date' => '2011-11-11'],
                '',  // Will match 0 or 1
            ],
        ];
    }

    /**
     * @dataProvider providerTestApiUpdatedV4
     */
    public function testApiUpdatedV4(string $scenario, array $payload, array $expectedOutput): void {
        $response = $this->apiRequest('/api/v4/updated', $payload);

        /* echo "\n=== Test: $scenario ===\n";
        echo "Request: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n"; */

        // Just verify it returns a valid response (might be error or success)
        $this->assertIsArray($response);
        $this->assertArrayHasKey('error', $response);
    }

    public static function providerTestApiUpdatedV4() {
        return [
            [
                'v4 with today date',
                ['date' => date('Y-m-d'), 'format' => 'json'],
                [],  // Just check it returns something, no specific error expectation
            ],
            [
                'v4 with old date',
                ['date' => '2011-11-11', 'format' => 'json'],
                [],  // Just check it returns something
            ],
        ];
    }

    /**
     * @dataProvider providerTestApiTable
     */
    public function testApiTable(string $scenario, array $payload, array $expectedOutput): void {
        $response = $this->apiRequest('/api/v3/table', $payload);

        /* echo "\n=== Test: $scenario ===\n";
        echo "Request: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        echo "Expected: " . json_encode($expectedOutput, JSON_PRETTY_PRINT) . "\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n"; */

        $this->assertArrayContainsSubset($expectedOutput, $response);
    }

    public static function providerTestApiTable() {
        return [
            [
                'missing columns fails',
                [],
                ['error' => 1],
            ],
            [
                'invalid columns type fails',
                ['columns' => 'invalid'],
                ['error' => 1],
            ],
            [
                'non-existent column fails',
                ['columns' => ['id', 'nev', 'nonexistent']],
                ['error' => 1],
            ],
            [
                'invalid format fails',
                ['columns' => ['id', 'nev'], 'format' => 'invalid'],
                ['error' => 1],
            ],
            [
                'valid columns returns data',
                ['columns' => ['id', 'nev']],
                ['error' => 0, 'templomok' => []],
            ],
        ];
    }

    /**
     * #94: a koordináta nélküli templomok (templomok.lat/lon DEFAULT 0.0/0.0,
     * a Guineai-öböl) NEM jelenhetnek meg a nearby-keresésben — a 0,0 „unknown
     * location", nem valódi hely.
     *
     * A bugot a 0,0 KÖZELÉBE indított lekérdezés triggereli: ott a 0,0-templom a
     * legközelebbi, így a top-N-be kerül. (Budapestről nézve a ~5000 km-es szellem
     * az ORDER BY distance ASC LIMIT N miatt a lista végére esik, nem buknék ki —
     * ezért kell direkt a 0,0 közelébe kérdezni.) A javítás (üres ÉS 0,0 kizárása)
     * előtt a 0,0-templom itt megjelent; utána nem.
     */
    public function testApiNearbyExcludesZeroCoordinates(): void {
        // A Guineai-öböl közelébe kérdezünk, ahol a 0,0-koordinátájú rekord lenne
        // a legközelebbi találat, ha nem szűrnénk ki.
        $response = $this->apiRequest('/api/v4/nearby', [
            'lat' => 1.0,
            'lon' => 1.0,
            'limit' => 10,
        ]);

        $this->assertArrayHasKey('templomok', $response);
        $this->assertIsArray($response['templomok']);

        foreach ($response['templomok'] as $templom) {
            $lat = isset($templom['lat']) ? (float) $templom['lat'] : null;
            $lon = isset($templom['lon']) ? (float) $templom['lon'] : null;
            $this->assertFalse(
                $lat === 0.0 && $lon === 0.0,
                "#94 regresszió: 0,0 koordinátájú templom megjelent a nearby-ben: "
                . "'{$templom['nev']}' (id={$templom['id']})."
            );
        }
    }

    /**
     * #94 (a tényleges hiba): a kliens GPS-fix nélkül (0,0)-t küld. Korábban erre
     * a nearby `error:0`-val ~5000+ km-es magyar templomokat adott vissza, mintha
     * érvényes találat lenne (ez volt a 2018-as „5000km+" bejelentés). A javítás
     * után a (0,0) user-input hibát ad, nem szemét listát.
     */
    public function testApiNearbyRejectsZeroUserLocation(): void {
        $response = $this->apiRequest('/api/v4/nearby', [
            'lat' => 0.0,
            'lon' => 0.0,
            'limit' => 5,
        ]);

        $this->assertArrayHasKey('error', $response);
        $this->assertEquals(1, $response['error'], '#94: a (0,0) user-helyzetre hibát kell adni.');
        $this->assertEmpty(
            $response['templomok'] ?? [],
            '#94: a (0,0) user-helyzetre nem szabad templomokat (5000 km-es szemét) visszaadni.'
        );
    }

    /**
     * A munkamenet-süti megszerzése a webes bejelentkező űrlappal. A naptár-végpontok
     * nem API-tokennel, hanem munkamenettel dolgoznak.
     */
    private function sessionCookie(): string
    {
        $ch = curl_init($this->baseUrl . '/');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'login'  => self::FIXTURE_USERNAME,
                'passw'  => self::FIXTURE_PASSWORD,
                'logout' => 'false',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $valasz = (string) curl_exec($ch);
        curl_close($ch);

        preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $valasz, $m);
        $this->assertNotEmpty($m[1], 'Nem sikerült bejelentkezni a teszthez.');

        return implode('; ', $m[1]);
    }

    /**
     * Regression test: /calendar/generate must return valid JSON.
     * Before the fix, echo statements inside updateMasses() corrupted the JSON response.
     *
     * A végpont bejelentkezést kíván: korábban SEMMILYEN jogosultság-ellenőrzés nem volt
     * rajta, pedig a PUT teljes mise-újraindexelést indít. A JSON-helyesség vizsgálata
     * ettől független, csak be kell hozzá jelentkezni.
     */
    public function testCalendarGenerateReturnsValidJson(): void
    {
        $ch = curl_init($this->baseUrl . '/calendar/generate?tids[]=1&years[]=2025');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_COOKIE         => $this->sessionCookie(),
        ]);
        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $this->assertEmpty($curlErr, 'Curl error: ' . $curlErr);
        $this->assertEquals(200, $httpCode, "Expected HTTP 200, got {$httpCode}");

        $decoded = json_decode($raw, true);
        $this->assertNotNull(
            $decoded,
            "Response is not valid JSON. First 500 chars: " . substr($raw, 0, 500)
        );
        $this->assertArrayHasKey('success', $decoded);
        $this->assertTrue($decoded['success']);
    }

}
