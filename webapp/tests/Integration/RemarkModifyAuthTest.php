<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #868: bárki, bejelentkezés nélkül átírhatta bármely észrevétel állapotát.
 *
 * A `Html\Remark::pageList()` a `?remark=modify` ágban betöltötte az észrevételt,
 * állította az `allapot`-ot, az `admindatum`-ot, megjegyzést fűzött hozzá és MENTETT —
 * mindezt azelőtt, hogy a `writeAccess` őr lefutott volna. Az őr csak lentebb, a lista
 * megjelenítése előtt állt.
 *
 * Mérve, sima GET-tel, süti nélkül:
 *
 *   /index.php?q=remark/list/1&remark=modify&rid=3&state=f&adminmegj=ANONIM-INJEKCIO
 *   -> HTTP 200, és az adatbázisban: allapot 'u' -> 'f', admindatum frissült,
 *      az adminmegj-be bekerült a támadó szövege.
 *
 * Két kár egyszerre: a bejelentés eltűnik a gondnokok szeme elől (az „f" =
 * feldolgozva), és a támadó tetszőleges tartalmat írhat az admin-megjegyzésbe, amit a
 * lista a gondnok böngészőjében jelenít meg.
 */
final class RemarkModifyAuthTest extends TestCase {

    private int $churchId;
    private int $remarkId;

    protected function setUp(): void {
        DB::beginTransaction();

        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Észrevétel teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);

        $this->remarkId = (int) DB::table('remarks')->insertGetId([
            'church_id' => $this->churchId,
            'nev' => 'Teszt Bejelentő',
            'login' => '',
            'email' => 'bejelento@example.invalid',
            'megbizhato' => '?',
            'admin' => '',
            'leiras' => 'Teszt bejelentés',
            'allapot' => 'u',
            'admindatum' => '0000-00-00 00:00:00',
        ]);

        // A vendég felhasználó: se bejelentkezés, se jogosultság.
        $GLOBALS['user'] = new \User();
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        DB::rollBack();
        $_REQUEST = [];
    }

    private function allapot(): string {
        return (string) DB::table('remarks')->where('id', $this->remarkId)->value('allapot');
    }

    private function megjegyzes(): ?string {
        return DB::table('remarks')->where('id', $this->remarkId)->value('adminmegj');
    }

    private function modositasKiserlet(string $state = 'f', string $megj = 'ANONIM-INJEKCIO'): void {
        $_REQUEST = [
            'remark' => 'modify',
            'rid' => (string) $this->remarkId,
            'state' => $state,
            'adminmegj' => $megj,
        ];

        try {
            new \Html\Remark(['list', (string) $this->churchId]);
        } catch (\Throwable $e) {
            // A jogosultság hiánya kivétellel is végződhet — a lényeg, hogy ne mentsen.
        }
    }

    /** A LÉNYEG: a vendég ne tudja átállítani az állapotot. */
    public function testAGuestCannotChangeTheState(): void {
        $this->modositasKiserlet();

        self::assertSame('u', $this->allapot(),
            'bejelentkezes nelkul nem lehet a bejelentest feldolgozottra allitani');
    }

    /** ...és ne tudjon az admin-megjegyzésbe írni. */
    public function testAGuestCannotInjectIntoTheAdminNote(): void {
        $this->modositasKiserlet('f', 'ANONIM-INJEKCIO');

        self::assertStringNotContainsString('ANONIM-INJEKCIO', (string) $this->megjegyzes(),
            'a vendeg nem irhat az admin-megjegyzesbe, amit a gondnok bongeszoje jelenit meg');
    }

    /** Az `admindatum` sem mozdulhat — a lista abból számol „mikor foglalkoztak vele". */
    public function testAGuestCannotTouchTheAdminTimestamp(): void {
        $elotte = DB::table('remarks')->where('id', $this->remarkId)->value('admindatum');

        $this->modositasKiserlet();

        self::assertSame($elotte, DB::table('remarks')->where('id', $this->remarkId)->value('admindatum'));
    }

    /**
     * Az URL-ből jövő `tid` NEM dönthet a jogosultságról.
     *
     * A régi kód a mentés UTÁN igazította a templomot („hogy ne lehessen csalni") —
     * csak épp későn: a `save()` addigra megtörtént.
     */
    public function testTheChurchComesFromTheRemarkNotTheUrl(): void {
        $masikChurch = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Másik templom', 'ok' => 'i', 'lat' => 47.1, 'lon' => 19.1,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);

        $_REQUEST = [
            'remark' => 'modify',
            'rid' => (string) $this->remarkId,
            'state' => 'f',
            'adminmegj' => 'MASIK-TEMPLOMON-AT',
        ];

        try {
            // A támadó a MÁSIK templom útvonalán próbálkozik.
            new \Html\Remark(['list', (string) $masikChurch]);
        } catch (\Throwable $e) {
            // rendben
        }

        self::assertSame('u', $this->allapot());
    }

    /** Nemlétező azonosítóra ne szálljon el `null`-on. */
    public function testAMissingRemarkDoesNotCrash(): void {
        $_REQUEST = [
            'remark' => 'modify',
            'rid' => '99999999',
            'state' => 'f',
            'adminmegj' => 'nincs ilyen',
        ];

        try {
            new \Html\Remark(['list', (string) $this->churchId]);
            $hiba = null;
        } catch (\Throwable $e) {
            $hiba = $e;
        }

        self::assertNotInstanceOf(\Error::class, $hiba,
            'a nemletezo eszrevetel ne okozzon null-on hivast');
    }

    /** Az állapot fehérlistás — az oszlop enum, az ismeretlen érték nem kerülhet bele. */
    public function testTheStateIsWhitelisted(): void {
        self::assertSame(['u', 'f', 'j'], \Html\Remark::ALLAPOTOK);
    }
}
