<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #890: ami most keletkezik, azt EGY óra írja.
 *
 * A `dbconnect()` a kapcsolatot `+05:00`-ra állítja, a PHP viszont `Europe/Budapest`
 * szerint jár — nyáron három óra a különbség. Az adatbázisban ezért kétféle időbélyeg
 * van, aszerint hogy melyik oldal írta:
 *
 *   - amit a PHP ír (`date('Y-m-d H:i:s')`), az a PHP órája szerinti;
 *   - amit a MySQL `CURRENT_TIMESTAMP` alapértéke tölt, az három órával előrébb.
 *
 * A történelmi adat rendbetétele külön kérdés (és külön kockázat). Ez a teszt csak azt
 * őrzi, hogy ÚJ sor ne keletkezzen a MySQL órájával ott, ahol már átálltunk.
 *
 * A hiba, ami ellen véd, csendes: a `created_at` nem volt a modell `$fillable`-jében,
 * tehát a `create()`-nek átadott értéket a Laravel NÉMÁN eldobta volna, és marad a
 * MySQL alapértéke. Pontosan ez történt a #898-ban az `adminmegj`-jel.
 */
final class EgyOraTest extends TestCase {

    /**
     * Ennyi másodpercen belül kell lennie a PHP órájához képest.
     *
     * Bőven a futásidő fölött, és nagyságrendekkel a három óra (10800 mp) alatt: ha a
     * MySQL órája írja a mezőt, ez az állítás nem lehet igaz véletlenül sem.
     */
    private const TURES_MP = 120;

    private int $churchId;
    private int $uid;

    protected function setUp(): void {
        DB::beginTransaction();

        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Egy óra teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);

        $login = '1ora' . bin2hex(random_bytes(3));
        $this->uid = (int) DB::table('user')->insertGetId([
            'login'  => $login,
            'jelszo' => password_hash('x', PASSWORD_DEFAULT),
            'jogok'  => '',
            'email'  => $login . '@example.invalid',
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    private function elteresMasodpercben(?string $belyeg): int {
        self::assertNotNull($belyeg, 'nem íródott időbélyeg');

        return abs(strtotime($belyeg) - time());
    }

    /**
     * A LÉNYEG: a token keletkezési ideje a PHP órája szerinti.
     *
     * Ugyanennek a sornak az `expires_at`-jét a PHP írja. Amíg a `created_at` a MySQL
     * alapértékéből jött, a kettő között három óra tátongott — pedig egyszerre születnek.
     */
    public function testTheTokenCreationTimeComesFromThePhpClock(): void {
        $token = \Eloquent\ChurchUpdateToken::create([
            'token'          => bin2hex(random_bytes(16)),
            'uid'            => $this->uid,
            'church_id'      => $this->churchId,
            'email_batch_id' => 'teszt',
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+3 weeks')),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $sor = DB::table('church_update_tokens')->where('token', $token->token)->first();

        self::assertLessThanOrEqual(
            self::TURES_MP,
            $this->elteresMasodpercben($sor->created_at ?? null),
            'a created_at a MySQL órájából jön (a $fillable némán eldobta az értéket?)'
        );
    }

    /** A keletkezés és a lejárat ugyanabból az órából — a különbségük pontosan 3 hét. */
    public function testCreationAndExpiryAreMeasuredOnTheSameClock(): void {
        $token = \Eloquent\ChurchUpdateToken::create([
            'token'          => bin2hex(random_bytes(16)),
            'uid'            => $this->uid,
            'church_id'      => $this->churchId,
            'email_batch_id' => 'teszt',
            'expires_at'     => date('Y-m-d H:i:s', strtotime('+3 weeks')),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $sor = DB::table('church_update_tokens')->where('token', $token->token)->first();
        $elteres = strtotime($sor->expires_at) - strtotime($sor->created_at);

        self::assertEqualsWithDelta(
            21 * 24 * 3600,
            $elteres,
            self::TURES_MP,
            'a lejárat és a keletkezés között nem pontosan három hét van — két külön óra írta'
        );
    }

    /*
     * A `confessions.timestamp`-re NINCS itt teszt, és ez tudatos.
     *
     * Ott a `lorawan.php` közvetlen értékadással ír (`$confession->timestamp = …`), amit
     * a `$fillable` nem szűr — tehát nincs mit elkapni: egy ilyen teszt csak azt mérné,
     * hogy a MySQL visszaadja-e, amit beírtunk. A `create()`-es út a veszélyes, azt
     * őrzi a fenti két eset.
     */
}
