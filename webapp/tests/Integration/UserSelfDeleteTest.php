<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #110: a felhasználó törölhesse magát.
 *
 * Eddig csak a `user` joggal rendelkező adminisztrátor törölhetett bárkit — magát senki,
 * és a saját adatlapról semmilyen út nem vezetett a törléshez.
 *
 * Az issue nyitott kérdése az volt, mi legyen az észrevételekkel. A \User::delete() már
 * eddig is névtelenítette őket (a törlés helyett), és ez a helyes: a beküldött
 * észrevételekre és miserend-javaslatokra a templomok felelőseinek szükségük lehet, a
 * személyes adat viszont lekerül róluk. Az itteni tesztek ezt rögzítik.
 */
class UserSelfDeleteTest extends TestCase {

    private array $createdUids = [];

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function createUser(string $password = 'Titk0sJelsz0'): \User {
        $login = 'sdt' . bin2hex(random_bytes(4));
        $uid = DB::table('user')->insertGetId([
            'login'     => $login,
            'jelszo'    => password_hash($password, PASSWORD_DEFAULT),
            'jogok'     => '',
            'email'     => $login . '@example.invalid',
            'nev'       => 'Teszt Elek',
            'becenev'   => 'teszt',
            'regdatum'  => date('Y-m-d H:i:s'),
            'lastlogin' => date('Y-m-d H:i:s'),
        ]);
        $this->createdUids[] = $uid;
        return new \User($uid);
    }

    public function testAJoJelszotElfogadja(): void {
        $user = $this->createUser('Titk0sJelsz0');
        $this->assertTrue($user->verifyPassword('Titk0sJelsz0'));
    }

    public function testARosszJelszotElutasitja(): void {
        $user = $this->createUser('Titk0sJelsz0');
        $this->assertFalse($user->verifyPassword('masik'));
        $this->assertFalse($user->verifyPassword(''));
        $this->assertFalse($user->verifyPassword(null));
    }

    public function testNemLetezoFelhasznaloraSosemIgaz(): void {
        $guest = new \User();
        $this->assertSame(0, (int) $guest->uid);
        $this->assertFalse($guest->verifyPassword('barmi'));
    }

    public function testATorlesElviszAKedvenceketEsAGondnoksagot(): void {
        $user = $this->createUser();
        $churchId = DB::table('templomok')->value('id');

        DB::table('favorites')->insert(['uid' => $user->uid, 'tid' => $churchId]);
        DB::table('church_holders')->insert([
            'church_id'  => $churchId,
            'user_id'    => $user->uid,
            'status'     => 'allowed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $uid = $user->uid;
        $user->delete();

        $this->assertSame(0, DB::table('user')->where('uid', $uid)->count());
        $this->assertSame(0, DB::table('favorites')->where('uid', $uid)->count());
        // A ChurchHolder soft-delete-es: a sima delete() csak deleted_at-et írna, és a
        // sor a user_id-vel együtt ottmaradna egy már nem létező felhasználóra mutatva.
        $this->assertSame(
            0,
            DB::table('church_holders')->where('user_id', $uid)->count(),
            'A gondnokság sorának tényleg el kell tűnnie, nem csak deleted_at-et kapnia.'
        );
    }

    /**
     * Az issue nyitott kérdése: az észrevétel NEM tűnik el, csak névtelenné válik.
     */
    public function testAzEszrevetelMegmaradDeNevtelenLesz(): void {
        $user = $this->createUser();
        $churchId = DB::table('templomok')->value('id');

        $remarkId = DB::table('remarks')->insertGetId([
            'church_id' => $churchId,
            'login'     => $user->username,
            'nev'       => 'Teszt Elek',
            'email'     => $user->email,
            'leiras'    => 'Ez az észrevétel maradjon meg.',
        ]);

        $user->delete();

        $remark = DB::table('remarks')->where('id', $remarkId)->first();
        $this->assertNotNull($remark, 'Az észrevételt nem szabad törölni.');
        $this->assertSame('Ez az észrevétel maradjon meg.', $remark->leiras);
        $this->assertSame('deleted_user', $remark->login);
        $this->assertSame('*törölt felhasználó*', $remark->nev);
        $this->assertStringNotContainsString('@example.invalid', (string) $remark->email);
    }

    public function testAMiserendJavaslatIsNevtelenLesz(): void {
        $user = $this->createUser();

        $packageId = DB::table('cal_suggestion_packages')->insertGetId([
            'church_id'      => DB::table('templomok')->value('id'),
            'sender_user_id' => $user->uid,
            'sender_email'   => $user->email,
            'sender_name'    => 'Teszt Elek',
            'state'          => 'PENDING',
        ]);

        $user->delete();

        $package = DB::table('cal_suggestion_packages')->where('id', $packageId)->first();
        $this->assertNotNull($package, 'A javaslatot nem szabad törölni.');
        $this->assertSame(0, (int) $package->sender_user_id);
        $this->assertSame('*törölt felhasználó*', $package->sender_name);
    }
}
