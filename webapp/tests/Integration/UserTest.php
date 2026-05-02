<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

class UserTest extends TestCase {

    private array $createdUserUids = [];

    private function uniqueLogin(): string {
        return 'pu' . bin2hex(random_bytes(4));
    }

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        if (!empty($this->createdUserUids)) {
            DB::table('user')->whereIn('uid', $this->createdUserUids)->delete();
            $this->createdUserUids = [];
        }
        DB::rollBack();
        parent::tearDown();
    }

    public function testLoginThrowsExceptionForNonExistentUser() {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('There is no such user.');

        User::login($this->uniqueLogin(), 'anypassword');
    }

    public function testLoginThrowsExceptionForWrongPassword() {
        $login = $this->uniqueLogin();
        $uid = DB::table('user')->insertGetId([
            'login'      => $login,
            'jelszo'     => password_hash('correct_password', PASSWORD_DEFAULT),
            'email'      => 'phpunit_test@example.com',
            'nev'        => 'PHPUnit Test User',
            'regdatum'   => date('Y-m-d H:i:s'),
            'lastlogin'  => '0000-00-00 00:00:00',
            'jogok'      => '',
        ]);
        $this->createdUserUids[] = $uid;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid password.');

        User::login($login, 'wrong_password');
    }

    public function testLoginReturnsUserIdForValidCredentials() {
        $login = $this->uniqueLogin();
        $uid = DB::table('user')->insertGetId([
            'login'      => $login,
            'jelszo'     => password_hash('valid_password', PASSWORD_DEFAULT),
            'email'      => 'validuser@example.com',
            'nev'        => 'Valid User',
            'regdatum'   => date('Y-m-d H:i:s'),
            'lastlogin'  => '0000-00-00 00:00:00',
            'jogok'      => '',
        ]);
        $this->createdUserUids[] = $uid;

        $userId = User::login($login, 'valid_password');

        $this->assertIsInt($userId);
        $this->assertGreaterThan(0, $userId);
    }

    public function testLoginUpdatesLastLoginTimestamp() {
        $login = $this->uniqueLogin();
        $insertedId = DB::table('user')->insertGetId([
            'login'      => $login,
            'jelszo'     => password_hash('valid_password', PASSWORD_DEFAULT),
            'email'      => 'timestampuser@example.com',
            'nev'        => 'Timestamp User',
            'regdatum'   => date('Y-m-d H:i:s'),
            'lastlogin'  => '0000-00-00 00:00:00',
            'jogok'      => '',
        ]);
        $this->createdUserUids[] = $insertedId;

        User::login($login, 'valid_password');

        $updated = DB::table('user')->where('uid', $insertedId)->first();

        $this->assertNotNull($updated);
        $this->assertNotEquals('0000-00-00 00:00:00', $updated->lastlogin);
    }
}