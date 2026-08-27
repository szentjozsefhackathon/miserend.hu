<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #391: a `user/edit.php` a nyers `$_REQUEST`-ből olvassa az `edituser` mezőcsoportot.
 *
 * Ez a fájl a regisztrációt, az adatmódosítást és a JOGOSULTSÁG-KEZELÉST is intézi, ezért
 * nem szabad tesztek nélkül hozzányúlni. Ezek a tesztek a MOSTANI viselkedést rögzítik —
 * a refaktor előtt és után is zöldnek kell lenniük.
 *
 * Valódi HTTP-hívások a futó példány ellen (az űrlapot a routing dolgozza fel).
 */
class UserEditFormTest extends TestCase {

    private CsrfFormClient $client;
    private array $createdLogins = [];

    protected function setUp(): void {
        $this->client = new CsrfFormClient();
    }

    protected function tearDown(): void {
        // Nem tranzakcióban vagyunk: a HTTP-kérést másik folyamat szolgálja ki, tehát a
        // rollback nem érné el. Ezért kézzel takarítunk.
        if ($this->createdLogins) {
            DB::table('user')->whereIn('login', $this->createdLogins)->delete();
            $this->createdLogins = [];
        }
    }

    private function uniqueLogin(): string {
        $login = 'ue' . bin2hex(random_bytes(4));
        $this->createdLogins[] = $login;
        return $login;
    }

    /**
     * POST az űrlap-feldolgozóra; a válasz HTML-je jön vissza.
     *
     * #873: a beküldés úgy megy, mint a böngészőből — előbb betöltjük a regisztrációs
     * lapot (onnan jön a `csrf` süti és a token), és azzal együtt küldünk. Nyers POST-tól
     * ma már — helyesen — nem történne semmi.
     */
    private function post(string $path, array $fields): string {
        return $this->client->post($path, $fields, true, $path);
    }

    private function userRow(string $login) {
        return DB::table('user')->where('login', $login)->first();
    }

    /** A regisztrációs űrlap minimális, érvényes kitöltése. */
    private function registrationFields(string $login): array {
        return [
            'q'                     => 'user/new',
            'submit'                => 'Létrehoz',
            'terms'                 => '1',
            'robot'                 => 'MKPK',
            'edituser[username]'    => $login,
            'edituser[nickname]'    => 'Teszt Bece',
            'edituser[name]'        => 'Teszt Elek',
            'edituser[email]'       => $login . '@example.invalid',
            'edituser[password1]'   => 'Pr0baJelsz0!',
            'edituser[password2]'   => 'Pr0baJelsz0!',
            'edituser[notifications]' => '1',
        ];
    }

    public function testSikeresRegisztracioLetrehozzaAFelhasznalot(): void {
        $login = $this->uniqueLogin();
        $this->post('/user/new', $this->registrationFields($login));

        $row = $this->userRow($login);
        $this->assertNotNull($row, 'A felhasználónak létre kellett volna jönnie.');
        $this->assertSame('Teszt Elek', $row->nev);
        $this->assertSame($login . '@example.invalid', $row->email);
    }

    /**
     * A házirend elfogadása nélkül NEM jöhet létre felhasználó.
     */
    public function testHazirendNelkulNincsRegisztracio(): void {
        $login = $this->uniqueLogin();
        $fields = $this->registrationFields($login);
        unset($fields['terms']);

        $html = $this->post('/user/new', $fields);

        $this->assertNull($this->userRow($login), 'Házirend nélkül nem jöhet létre felhasználó.');
        $this->assertStringContainsString('Házirendet', $html);
    }

    /**
     * A robot-kérdés rossz válasza sem engedi át.
     */
    public function testRosszRobotValaszNelkulNincsRegisztracio(): void {
        $login = $this->uniqueLogin();
        $fields = $this->registrationFields($login);
        $fields['robot'] = 'rossz';

        $html = $this->post('/user/new', $fields);

        $this->assertNull($this->userRow($login), 'Rossz robot-válasszal nem jöhet létre felhasználó.');
        $this->assertStringContainsString('robotnak', $html);
    }

    public function testHianyzoRobotValaszSemEleg(): void {
        $login = $this->uniqueLogin();
        $fields = $this->registrationFields($login);
        unset($fields['robot']);

        $this->post('/user/new', $fields);

        $this->assertNull($this->userRow($login));
    }

    /**
     * A LÉNYEG: aki nem `user` jogú, az nem adhat magának jogosultságot.
     *
     * A regisztrációs űrlap kiteszi a `roles` jelölőnégyzeteket; egy vendég beküldhetné
     * őket. A kódnak vissza kell vonnia, és figyelmeztetnie.
     */
    public function testVendegNemAdhatMagananakJogosultsagot(): void {
        $login = $this->uniqueLogin();
        $fields = $this->registrationFields($login);
        $fields['edituser[roles][miserend]'] = 'miserend';

        $html = $this->post('/user/new', $fields);

        $row = $this->userRow($login);
        $this->assertNotNull($row, 'A felhasználó létrejön, csak a jogosultság nélkül.');
        $this->assertStringNotContainsString(
            'miserend',
            (string) $row->jogok,
            'A vendég NEM kaphat miserend jogosultságot az űrlapról.'
        );
        $this->assertStringContainsString('jogosultság megadásához', $html);
    }

    /**
     * Az űrlap újramegjelenítése a beküldött értékekkel (preparePage ága): hibás
     * beküldés után a már kitöltött mezők ne vesszenek el.
     */
    public function testHibasBekuldesUtanMegmaradnakAzErtekek(): void {
        $login = $this->uniqueLogin();
        $fields = $this->registrationFields($login);
        unset($fields['terms']);   // szándékos hiba

        $html = $this->post('/user/new', $fields);

        $this->assertStringContainsString($login, $html, 'A beírt felhasználónévnek meg kell maradnia az űrlapon.');
        $this->assertStringContainsString('Teszt Elek', $html);
    }
}
