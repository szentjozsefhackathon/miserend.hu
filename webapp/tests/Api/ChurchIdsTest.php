<?php

use PHPUnit\Framework\TestCase;

/**
 * #297: az api/church egyszerre több azonosítót is fogad.
 *
 * A jegyben eredetileg koordinátalista fogadása szerepelt az api/nearby-on, de a
 * megbeszélés oda jutott, hogy az app a logoláskor egy nearby-jal kitalálja a
 * templomot, és utána már csak azonosítókkal dolgozik — így itt elég a lista.
 *
 * Valódi HTTP-hívásokkal megyünk, mert a validáció és a válasz alakja számít.
 */
final class ChurchIdsTest extends TestCase {

    private string $baseUrl;

    protected function setUp(): void {
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');
    }

    private function post(array $payload): array {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode($payload),
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);

        $body = @file_get_contents($this->baseUrl . '/api/v4/church', false, $context);
        self::assertNotFalse($body, 'HTTP kérés nem sikerült');

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'a válasz nem JSON: ' . substr((string) $body, 0, 200));

        return $decoded;
    }

    /* A régi, egy-azonosítós hívás alakja NEM változhat. */
    public function testSingleIdStillReturnsASingleObject(): void {
        $response = $this->post(['id' => 1]);

        self::assertArrayNotHasKey('templomok', $response, 'egy id-nél nem listát adunk');
        self::assertArrayHasKey('id', $response);
        self::assertSame(1, (int) $response['id']);
    }

    /* Több azonosító -> lista, a kért sorrendben. */
    public function testMultipleIdsReturnAListInTheRequestedOrder(): void {
        $response = $this->post(['ids' => [1254, 1]]);

        self::assertArrayHasKey('templomok', $response);
        self::assertCount(2, $response['templomok']);
        self::assertSame(1254, (int) $response['templomok'][0]['id']);
        self::assertSame(1, (int) $response['templomok'][1]['id']);
    }

    /* Nem létező azonosító nem buktatja el a kérést, csak felsoroljuk. */
    public function testMissingIdsAreReportedInsteadOfFailing(): void {
        $response = $this->post(['ids' => [1, 99999999]]);

        self::assertCount(1, $response['templomok']);
        self::assertSame(1, (int) $response['templomok'][0]['id']);
        self::assertSame([99999999], $response['hianyzo']);
    }

    /* Ismétlődő azonosítót egyszer adunk vissza. */
    public function testDuplicateIdsAreCollapsed(): void {
        $response = $this->post(['ids' => [1, 1, 1]]);

        self::assertCount(1, $response['templomok']);
    }

    /* A response_length a listás ágon is érvényesül. */
    public function testResponseLengthAppliesToTheList(): void {
        $minimal = $this->post(['ids' => [1], 'response_length' => 'minimal']);
        $full    = $this->post(['ids' => [1], 'response_length' => 'full']);

        self::assertLessThan(
            count($full['templomok'][0]),
            count($minimal['templomok'][0]),
            'a minimal válasznak kevesebb mezőt kell tartalmaznia'
        );
    }

    /* Pontosan az egyiket kell megadni. */
    public function testMissingBothIdAndIdsIsAnError(): void {
        $response = $this->post(['response_length' => 'medium']);

        self::assertSame('1', (string) $response['error']);
        self::assertStringContainsString('ids', (string) $response['text']);
    }

    public function testIdAndIdsTogetherIsAnError(): void {
        $response = $this->post(['id' => 1, 'ids' => [2]]);

        self::assertSame('1', (string) $response['error']);
        self::assertStringContainsString('together', (string) $response['text']);
    }

    public function testEmptyIdsIsAnError(): void {
        $response = $this->post(['ids' => []]);

        self::assertSame('1', (string) $response['error']);
    }

    /* Nem egész elem a listában -> tiszta hibaüzenet, nem 500. */
    public function testNonIntegerItemIsRejected(): void {
        $response = $this->post(['ids' => [1, 'abc']]);

        self::assertSame('1', (string) $response['error']);
        self::assertStringContainsString('integer', (string) $response['text']);
    }

    /* A felső korlát véd a túl nagy kéréstől. */
    public function testTooManyIdsAreRejected(): void {
        $response = $this->post(['ids' => range(1, \Api\Church::MAX_IDS + 1)]);

        self::assertSame('1', (string) $response['error']);
        self::assertStringContainsString('more than', (string) $response['text']);
    }
}
