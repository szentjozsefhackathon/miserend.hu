<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

class NaprakeszTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function createChurch(): int {
        return DB::table('templomok')->insertGetId([
            'nev'       => 'Teszt Templom',
            'frissites' => '2020-01-01',
            'ok'        => 'i',
            'plebania'  => '',
            'leiras'    => '',
            'megjegyzes'=> '',
            'misemegj'  => '',
            'bucsu'     => '',
            'adminmegj' => '',
            'log'       => '',
        ]);
    }

    private function createToken(int $churchId, string $batchId, ?int $overrideChurchId = -1): string {
        $token = bin2hex(random_bytes(32));
        DB::table('church_update_tokens')->insert([
            'token'          => $token,
            'uid'            => 2,
            'church_id'      => $overrideChurchId === -1 ? $churchId : $overrideChurchId,
            'email_batch_id' => $batchId,
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+2 weeks')),
            'used_at'        => null,
        ]);
        return $token;
    }

    public function testValidTokenUpdatesFrissites() {
        $churchId = $this->createChurch();
        $token = $this->createToken($churchId, 'batch1');

        $result = \Eloquent\ChurchUpdateToken::redeem($token);

        $this->assertTrue($result['success']);
        $church = DB::table('templomok')->where('id', $churchId)->first();
        $this->assertEquals(date('Y-m-d'), $church->frissites);
    }

    public function testValidTokenMarksAsUsed() {
        $churchId = $this->createChurch();
        $token = $this->createToken($churchId, 'batch1');

        \Eloquent\ChurchUpdateToken::redeem($token);

        $record = DB::table('church_update_tokens')->where('token', $token)->first();
        $this->assertNotNull($record->used_at);
    }

    public function testExpiredTokenFails() {
        $churchId = $this->createChurch();
        $token = bin2hex(random_bytes(32));
        DB::table('church_update_tokens')->insert([
            'token'          => $token,
            'uid'            => 2,
            'church_id'      => $churchId,
            'email_batch_id' => 'batch_expired',
            'expires_at'     => date('Y-m-d H:i:s', strtotime('-1 day')),
            'used_at'        => null,
        ]);

        $result = \Eloquent\ChurchUpdateToken::redeem($token);

        $this->assertFalse($result['success']);
        $church = DB::table('templomok')->where('id', $churchId)->first();
        $this->assertEquals('2020-01-01', $church->frissites);
    }

    public function testAlreadyUsedTokenFails() {
        $churchId = $this->createChurch();
        $token = bin2hex(random_bytes(32));
        DB::table('church_update_tokens')->insert([
            'token'          => $token,
            'uid'            => 2,
            'church_id'      => $churchId,
            'email_batch_id' => 'batch_used',
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+2 weeks')),
            'used_at'        => date('Y-m-d H:i:s', strtotime('-1 hour')),
        ]);

        $result = \Eloquent\ChurchUpdateToken::redeem($token);

        $this->assertFalse($result['success']);
    }

    public function testNonExistentTokenFails() {
        $result = \Eloquent\ChurchUpdateToken::redeem('nemletezikez00000000000000000000000000000000000000000000000000');

        $this->assertFalse($result['success']);
    }

    public function testUpdateAllTokenRefreshesAllChurchesInBatch() {
        $church1 = $this->createChurch();
        $church2 = $this->createChurch();
        $batchId = 'batch_all_' . uniqid();

        $this->createToken($church1, $batchId);
        $this->createToken($church2, $batchId);
        $allToken = $this->createToken(0, $batchId, null);

        $result = \Eloquent\ChurchUpdateToken::redeem($allToken);

        $this->assertTrue($result['success']);
        $c1 = DB::table('templomok')->where('id', $church1)->first();
        $c2 = DB::table('templomok')->where('id', $church2)->first();
        $this->assertEquals(date('Y-m-d'), $c1->frissites);
        $this->assertEquals(date('Y-m-d'), $c2->frissites);
    }

    public function testUpdateAllTokenReturnsChurchesList() {
        $church1 = $this->createChurch();
        $church2 = $this->createChurch();
        $batchId = 'batch_churches_' . uniqid();

        $this->createToken($church1, $batchId);
        $this->createToken($church2, $batchId);
        $allToken = $this->createToken(0, $batchId, null);

        $result = \Eloquent\ChurchUpdateToken::redeem($allToken);

        $this->assertArrayHasKey('churches', $result);
        $this->assertCount(2, $result['churches']);
        $ids = array_column($result['churches'], 'id');
        $this->assertContains($church1, $ids);
        $this->assertContains($church2, $ids);
    }

    public function testSingleTokenDoesNotReturnChurchesList() {
        $churchId = $this->createChurch();
        $token = $this->createToken($churchId, 'batch_single_' . uniqid());

        $result = \Eloquent\ChurchUpdateToken::redeem($token);

        $this->assertTrue($result['success']);
        $this->assertEmpty($result['churches'] ?? []);
    }

    public function testUpdateAllTokenInvalidatesEntireBatch() {
        $churchId = $this->createChurch();
        $batchId  = 'batch_inv_' . uniqid();

        $singleToken = $this->createToken($churchId, $batchId);
        $allToken    = $this->createToken(0, $batchId, null);

        \Eloquent\ChurchUpdateToken::redeem($allToken);

        $single = DB::table('church_update_tokens')->where('token', $singleToken)->first();
        $this->assertNotNull($single->used_at);
    }

    public function testNaprakeszPageWorksWhenLoggedInSessionCookieSharesNameWithLinkToken() {
        $churchId = $this->createChurch();
        $batchId  = 'batch_loggedin_' . uniqid();
        $this->createToken($churchId, $batchId);
        $allToken = $this->createToken(0, $batchId, null);

        // A bejelentkezési session-cookie neve is 'token' (webapp/classes/token.php),
        // ami ütközik az emailben küldött link query-paraméterének nevével. Az "összes
        // templom naprakész" linket használjuk, mert az sikeres beváltás esetén nem
        // redirectel (exit-tel), hanem a churches listát rendereli.
        $_COOKIE['token'] = 'valamilyen-session-token-ami-nem-egyezik-a-link-tokennel';
        $_GET['token'] = $allToken;

        try {
            $page = new \Html\Naprakesz([]);
        } finally {
            unset($_COOKIE['token'], $_GET['token']);
        }

        $this->assertNotEquals('exception.twig', $page->template);
        $church = DB::table('templomok')->where('id', $churchId)->first();
        $this->assertEquals(date('Y-m-d'), $church->frissites);
    }
}
