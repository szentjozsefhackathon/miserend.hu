<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #828: minden tábla InnoDB — mert a MyISAM nem tud tranzakciót.
 *
 * Ez nem elméleti kérdés. Az integrációs tesztek java része `DB::beginTransaction()` /
 * `rollBack()` párral dolgozik, és azt hiszi, hogy tisztán maga után takarít. A MyISAM
 * táblákra viszont a rollback **némán nem hat**: a beszúrt sor ottmarad, hibaüzenet
 * nélkül.
 *
 * A fejlesztői adatbázisban emiatt **5167 felhasználóból 1943 volt teszt-maradék**, és a
 * `teszt_plebanos` login 90-szer szerepelt. Ez nem csak szemét: a duplikátumok miatt a
 * `where('login', ...)` lekérdezések találomra választanak sort, tehát a tesztek egymás
 * adatán is dolgozhatnak — és épp az ilyen, alkalmankénti bukás a legdrágább.
 *
 * Élesben a `user` a legérzékenyebb erre: a MyISAM tábla-szintű zárolást használ (nem
 * sor-szintűt), és összeomlás után nem áll helyre magától.
 */
final class StorageEngineTest extends TestCase {

    /** A séma most csupa InnoDB — ez az őr, hogy vissza ne csússzon. */
    public function testMindenTablaInnoDb(): void {
        $nemInnoDb = DB::select(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND ENGINE IS NOT NULL AND ENGINE <> 'InnoDB'"
        );

        $nevek = array_map(fn($sor) => $sor->TABLE_NAME . ' (' . $sor->ENGINE . ')', $nemInnoDb);

        self::assertSame([], $nevek,
            "Nem InnoDB tábla: a tranzakciós tesztek RÁ NÉZVE NÉMÁN nem takarítanak.\n"
            . implode("\n", $nevek));
    }

    /**
     * A lényegi állítás, nem a motor neve: a `user` táblán tényleg visszagördül-e egy
     * beszúrás? Ez az a viselkedés, amire kilenc tesztfájl épít.
     */
    public function testAUserTablanHatARollback(): void {
        $login = 'motor_proba_' . bin2hex(random_bytes(4));

        DB::beginTransaction();
        DB::table('user')->insert([
            'uid'   => (int) DB::table('user')->max('uid') + 1,
            'login' => $login,
            'nev'   => 'Motor Próba',
            'email' => $login . '@example.invalid',
        ]);
        $tranzakcioban = DB::table('user')->where('login', $login)->count();
        DB::rollBack();

        $utana = DB::table('user')->where('login', $login)->count();

        // Ha ez elbukik, MINDEN felhasználót létrehozó teszt szemetel — és a szemét
        // duplikált loginokat hoz létre, amitől más tesztek is elcsúszhatnak.
        self::assertSame(1, $tranzakcioban, 'a beszúrásnak látszania kell a tranzakcióban');
        self::assertSame(0, $utana, 'a rollback után nem maradhat sor');
    }

    /** Ugyanez a templomokra: erre épül a legtöbb integrációs teszt. */
    public function testATemplomokTablanIsHatARollback(): void {
        $nev = 'Motor Próba ' . bin2hex(random_bytes(4));

        DB::beginTransaction();
        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $minta['id'] = (int) DB::table('templomok')->max('id') + 1;
        $minta['nev'] = $nev;
        DB::table('templomok')->insert($minta);
        DB::rollBack();

        self::assertSame(0, DB::table('templomok')->where('nev', $nev)->count());
    }
}
