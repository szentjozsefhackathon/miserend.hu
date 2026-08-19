<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #829: a mentés mondja meg, MI a baj — ne csak azt, hogy baj van.
 *
 * A `presave()` néma `false`-t adott vissza, a felület pedig mezőnként egyetlen,
 * mindent lefedő mondatot írt ki:
 *
 *     „Nem megfelelő email cím! Talán már használatban van?"
 *
 * A „kötelező mező üres", a „formailag hibás" és a „már foglalt" tehát pontosan
 * ugyanúgy nézett ki. Aki regisztrálni próbált, találgathatott, mit rontott el — és a
 * kódban ott állt egy `//TODO: szóljon vissza a kötelező`, ami épp erre mutatott.
 *
 * A `user` tábla a #828 óta InnoDB, tehát a tranzakciós takarítás itt tényleg működik.
 */
final class UserPresaveFeedbackTest extends TestCase {

    private string $letezoLogin;
    private string $letezoEmail;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $this->letezoLogin = 'foglaltnev' . random_int(1000, 9999);
        $this->letezoEmail = 'foglalt' . random_int(1000, 9999) . '@example.invalid';

        DB::table('user')->insert([
            'uid'   => (int) DB::table('user')->max('uid') + 1,
            'login' => $this->letezoLogin,
            'nev'   => 'Foglalt Felhasználó',
            'email' => $this->letezoEmail,
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** Új (még nem mentett) felhasználó — a regisztráció esete. */
    private function ujFelhasznalo(): \User {
        return new \User();
    }

    // ---- kötelező mezők ------------------------------------------------------

    public function testAzUresFelhasznalonevMegmondja(): void {
        $user = $this->ujFelhasznalo();

        self::assertFalse($user->presave('username', ''));
        self::assertSame('Ezt a mezőt kötelező kitölteni.', $user->presaveErrorFor('username'));
    }

    public function testAzUresEmailMegmondja(): void {
        $user = $this->ujFelhasznalo();

        self::assertFalse($user->presave('email', ''));
        self::assertSame('Ezt a mezőt kötelező kitölteni.', $user->presaveErrorFor('email'));
    }

    // ---- a lényeg: a három ok KÜLÖNBÖZIK -------------------------------------

    /**
     * Ez a jegy magva. Ha a foglalt név és a hibás formátum ugyanazt az üzenetet adja,
     * a felhasználó nem tudja, új nevet kell-e választania, vagy csak ékezetet vett ki.
     */
    public function testAFoglaltNevMastMondMintAHibasFormatum(): void {
        $foglalt = $this->ujFelhasznalo();
        $foglalt->presave('username', $this->letezoLogin);

        $hibasFormatum = $this->ujFelhasznalo();
        $hibasFormatum->presave('username', 'ékezetes név!');

        self::assertSame('Ez a felhasználónév már foglalt.', $foglalt->presaveErrorFor('username'));
        self::assertNotSame(
            $foglalt->presaveErrorFor('username'),
            $hibasFormatum->presaveErrorFor('username'),
            'A két hiba két különböző teendőt jelent.'
        );
    }

    public function testAHibasFormatumMegmondjaASzabalyt(): void {
        $user = $this->ujFelhasznalo();

        self::assertFalse($user->presave('username', 'ékezetes név!'));
        self::assertStringContainsString('betűt és számot', (string) $user->presaveErrorFor('username'));
    }

    public function testAFoglaltEmailMastMondMintAzErvenytelen(): void {
        $foglalt = $this->ujFelhasznalo();
        $foglalt->presave('email', $this->letezoEmail);

        $ervenytelen = $this->ujFelhasznalo();
        $ervenytelen->presave('email', 'ez-nem-email');

        self::assertSame('Ezzel az email címmel már regisztráltak.', $foglalt->presaveErrorFor('email'));
        self::assertSame('Ez nem érvényes email cím.', $ervenytelen->presaveErrorFor('email'));
    }

    // ---- a duplikátum-ellenőrzés tényleg működik ------------------------------

    /**
     * A régi `//TODO: check duplicate for: logn + email` valójában MEGVOLT — a login
     * ellenőrzése a `checkUsername()`-ben, az e-mailé az `isEmailInUse()`-ban. Ez a két
     * teszt rögzíti, hogy így is marad.
     */
    public function testAFoglaltFelhasznalonevetElutasitja(): void {
        self::assertFalse($this->ujFelhasznalo()->presave('username', $this->letezoLogin));
    }

    public function testAFoglaltEmailtElutasitja(): void {
        self::assertFalse($this->ujFelhasznalo()->presave('email', $this->letezoEmail));
    }

    /** A saját, meglévő címét mindenki megtarthatja — az nem ütközés. */
    public function testASajatEmailUjrakuldeseNemUtkozes(): void {
        $uid = (int) DB::table('user')->where('login', $this->letezoLogin)->value('uid');
        $user = new \User($uid);

        self::assertTrue($user->presave('email', $this->letezoEmail));
    }

    // ---- rendes eset ---------------------------------------------------------

    public function testASikeresMezoNemHagyHibauzenetet(): void {
        $user = $this->ujFelhasznalo();

        self::assertTrue($user->presave('username', 'ujnev' . random_int(1000, 9999)));
        self::assertNull($user->presaveErrorFor('username'));
    }

    /** A felhasználónevet később nem lehet átírni — ezt is meg kell mondani. */
    public function testAKesobbiNevvaltoztatastMegindokolja(): void {
        $uid = (int) DB::table('user')->where('login', $this->letezoLogin)->value('uid');
        $user = new \User($uid);

        self::assertFalse($user->presave('username', 'egeszenmas'));
        self::assertStringContainsString('nem lehet megváltoztatni', (string) $user->presaveErrorFor('username'));
    }
}
