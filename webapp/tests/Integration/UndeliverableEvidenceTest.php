<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #823: a kézbesíthetetlen címek elleni védelem két résen szivárgott el.
 *
 * A `User::isUndeliverable()` abból tudja, hogy egy címre nem érdemes tovább
 * próbálkozni, hogy az `emails` táblában három hibás sor áll rá. Ez az EGYETLEN
 * bizonyíték — nincs külön „ez a cím halott" jelölés.
 *
 * **Első rés:** a `Crons::cleanNotificationEmails()` 90 nap után törölte ezeket a
 * sorokat. A bizonyíték eltűnt, az értesítő cron pedig újrakezdte: három kísérlet,
 * majd megint 90 nap, megint három — örökre. A védelem tehát csak 90 napig védett.
 *
 * **Második rés:** a `sendUpdateNotification()` (`user_pleaseupdate`, élesben 152
 * kiküldött levél) egyáltalán nem kérdezte meg, hogy elérhető-e a cím. A másik két
 * értesítő már igen. Ott a beépített `NOT EXISTS` csak KÉT HÉTIG véd, utána újraindul
 * a kör — és minden hiábavaló próbálkozás egy-egy tokent is beír a
 * `church_update_tokens` táblába.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class UndeliverableEvidenceTest extends TestCase {

    private const CIM = 'halott.cim@example.invalid';

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** @param string $status  @param string $mikor strtotime-kifejezés */
    private function level(string $type, string $status, string $mikor, string $cim = self::CIM): void {
        $idopont = date('Y-m-d H:i:s', strtotime($mikor));
        DB::table('emails')->insert([
            'type'       => $type,
            'to'         => $cim,
            'subject'    => 'teszt',
            'body'       => 'teszt',
            'status'     => $status,
            'created_at' => $idopont,
            'updated_at' => $idopont,
        ]);
    }

    private function hibasSorokSzama(string $cim = self::CIM): int {
        return DB::table('emails')->where('to', $cim)->where('status', 'error')->count();
    }

    // ---- a takarítás megőrzi a bizonyítékot ----------------------------------

    /**
     * Ez a lényeg: a három hibás sor a takarítás után is megmarad, különben a
     * következő futás elölről kezdi a próbálkozást.
     */
    public function testATakaritasNemTorliAHibasLeveleket(): void {
        $this->level('user_pleaselogin', 'error', '-200 days');
        $this->level('user_pleaselogin', 'error', '-150 days');
        $this->level('user_pleaselogin', 'error', '-120 days');

        \Crons::cleanNotificationEmails();

        self::assertSame(3, $this->hibasSorokSzama(),
            'A hibás sorok az egyetlen bizonyítékok arra, hogy a cím halott.');
    }

    /** A sikeres, régi levelek viszont ugyanúgy takarodnak, mint eddig. */
    public function testASikeresRegiLevelekTovabbraIsTorlodnek(): void {
        $this->level('user_pleaselogin', 'sent', '-200 days');

        \Crons::cleanNotificationEmails();

        self::assertSame(0, DB::table('emails')->where('to', self::CIM)->where('status', 'sent')->count());
    }

    /** A friss levelekhez nem nyúlunk, se sikereshez, se hibáshoz. */
    public function testAFrissLevelekMaradnak(): void {
        $this->level('user_pleaselogin', 'sent', '-3 days');
        $this->level('user_pleaselogin', 'error', '-3 days');

        \Crons::cleanNotificationEmails();

        self::assertSame(2, DB::table('emails')->where('to', self::CIM)->count());
    }

    /** A nem takarítható típusokat továbbra sem bántjuk. */
    public function testANemTakarithatoTipusMarad(): void {
        $this->level('remark_admin', 'sent', '-200 days');

        \Crons::cleanNotificationEmails();

        self::assertSame(1, DB::table('emails')->where('to', self::CIM)->count());
    }

    // ---- a bizonyítékból következő döntés ------------------------------------

    /**
     * A takarítás UTÁN is kézbesíthetetlennek kell látszania — ez köti össze a két
     * felet, és ezt vesztettük el eddig.
     */
    public function testATakaritasUtanIsKezbesithetetlenMarad(): void {
        $this->level('user_pleaselogin', 'error', '-200 days');
        $this->level('user_pleaselogin', 'error', '-150 days');
        $this->level('user_pleaselogin', 'error', '-120 days');

        \Crons::cleanNotificationEmails();

        self::assertTrue($this->kezbesithetetlen('user_pleaselogin', self::CIM));
    }

    /** Három hiba alatt még próbálkozunk — a küszöb nem csúszhat el. */
    public function testKetHibaUtanMegProbalkozunk(): void {
        $this->level('user_pleaselogin', 'error', '-10 days');
        $this->level('user_pleaselogin', 'error', '-5 days');

        self::assertFalse($this->kezbesithetetlen('user_pleaselogin', self::CIM));
    }

    /**
     * Ha a cím közben mégis működni kezdett, a régi hibák nem számítanak — enélkül
     * egy időszakos postafiók-hiba örökre kizárná a felhasználót az értesítőkből.
     */
    public function testASikeresLevelUtanUjraProbalkozunk(): void {
        $this->level('user_pleaselogin', 'error', '-30 days');
        $this->level('user_pleaselogin', 'error', '-29 days');
        $this->level('user_pleaselogin', 'error', '-28 days');
        $this->level('user_pleaselogin', 'sent',  '-2 days');

        self::assertFalse($this->kezbesithetetlen('user_pleaselogin', self::CIM));
    }

    /** A típusok nem keverednek: a bejelentkezős hibák nem tiltják a frissítőset. */
    public function testAHibakTipusonkentSzamitanak(): void {
        $this->level('user_pleaselogin', 'error', '-10 days');
        $this->level('user_pleaselogin', 'error', '-9 days');
        $this->level('user_pleaselogin', 'error', '-8 days');

        self::assertTrue($this->kezbesithetetlen('user_pleaselogin', self::CIM));
        self::assertFalse($this->kezbesithetetlen('user_pleaseupdate', self::CIM));
    }

    /**
     * A `sendUpdateNotification` eddig nem kérdezte meg ezt — most már igen, tehát a
     * `user_pleaseupdate` típusnak is működnie kell.
     */
    public function testAFrissitesiErtesitoreIsErvenyes(): void {
        $this->level('user_pleaseupdate', 'error', '-10 days');
        $this->level('user_pleaseupdate', 'error', '-9 days');
        $this->level('user_pleaseupdate', 'error', '-8 days');

        self::assertTrue($this->kezbesithetetlen('user_pleaseupdate', self::CIM));
    }

    /** A privát metódust reflexióval hívjuk: a döntés maga a mérendő állítás. */
    private function kezbesithetetlen(string $type, string $cim): bool {
        $metodus = new \ReflectionMethod(\User::class, 'isUndeliverable');
        $metodus->setAccessible(true);

        return (bool) $metodus->invoke(null, $type, $cim);
    }
}
