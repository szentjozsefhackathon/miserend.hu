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
        // Start transaction to isolate test data
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        if (!empty($this->createdUserUids)) {
            DB::table('user')->whereIn('uid', $this->createdUserUids)->delete();
            $this->createdUserUids = [];
        }
        // Roll back transaction after each test
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
        // Insert test user with known password
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
        // Insert test user
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
        // Insert test user
        $insertedId = DB::table('user')->insertGetId([
            'login'      => $login,
            'jelszo'     => password_hash('password123', PASSWORD_DEFAULT),
            'email'      => 'timestamp@example.com',
            'nev'        => 'Timestamp Test',
            'regdatum'   => date('Y-m-d H:i:s'),
            'lastlogin'  => '0000-00-00 00:00:00',
            'jogok'      => '',
        ]);
        $this->createdUserUids[] = $insertedId;

        $userId = User::login($login, 'password123');

        $user = DB::table('user')->where('uid', $userId)->first();
        
        $this->assertNotEquals('0000-00-00 00:00:00', $user->lastlogin);
    }

    public function testUserConstructorLoadsGuestForInvalidUid() {
        $user = new User(999999999);

        $this->assertEquals(0, $user->uid);
        $this->assertEquals('*vendeg*', $user->username);
        $this->assertFalse($user->loggedin);
    }

    public function testUserConstructorLoadsUserByUid() {
        $login = $this->uniqueLogin();
        // Insert test user
        $insertedId = DB::table('user')->insertGetId([
            'login'      => $login,
            'jelszo'     => password_hash('pass', PASSWORD_DEFAULT),
            'email'      => 'loadbyuid@example.com',
            'nev'        => 'Load By UID Test',
            'regdatum'   => date('Y-m-d H:i:s'),
            'lastlogin'  => date('Y-m-d H:i:s'),
            'jogok'      => '',
        ]);
        $this->createdUserUids[] = $insertedId;

        $user = new User($insertedId);

        $this->assertEquals($insertedId, $user->uid);
        $this->assertEquals($login, $user->username);
    }

    public function testUserConstructorLoadsUserByUsername() {
        $login = $this->uniqueLogin();
        // Insert test user
        $uid = DB::table('user')->insertGetId([
            'login'      => $login,
            'jelszo'     => password_hash('pass', PASSWORD_DEFAULT),
            'email'      => 'loadbyname@example.com',
            'nev'        => 'Load By Name Test',
            'regdatum'   => date('Y-m-d H:i:s'),
            'lastlogin'  => date('Y-m-d H:i:s'),
            'jogok'      => '',
        ]);
        $this->createdUserUids[] = $uid;

        $user = new User($login);

        $this->assertEquals($login, $user->username);
        $this->assertGreaterThan(0, $user->uid);
    }
}
