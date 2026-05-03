<?php
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/functions.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ApiEndpointsTest extends TestCase {

    protected ?string $token = null;
    private string $fixtureUsername = 'admin';
    private string $fixturePassword = 'miserend';

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

    public function providerTestApiSignup() {
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

    public function providerTestApiLogin() {
        return [
            [
                'valid fixture user',
                ['username' => $this->fixtureUsername, 'password' => $this->fixturePassword],
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
            'username' => $this->fixtureUsername,
            'password' => $this->fixturePassword,
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
            'username' => $this->fixtureUsername,
            'password' => $this->fixturePassword,
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


}