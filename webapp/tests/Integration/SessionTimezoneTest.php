<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #890: a PHP és a MySQL egy órán járjon.
 *
 * A hiba az volt, hogy a `dbconnect()` a munkamenetet `+05:00`-ra állította, a PHP
 * viszont `Europe/Budapest`-ben ír (load.php:12). A PHP által írt falióra-érték így
 * öt órával a valódi pillanat mögé került a helyes egy-kettő helyett, és mindig
 * pontosan annyival, amennyi az évszaktól függött — nyáron három, télen négy óra.
 * Amíg ugyanaz a rossz zóna olvasta vissza, nem látszott; minden vegyes
 * PHP↔MySQL összehasonlítás viszont csúszott (`DATEDIFF(NOW(), ...)`, cron-határidők,
 * a beragadt levelek detektora).
 *
 * Két dolgot őriz ez a teszt, és a második a fontosabb:
 *   1. a munkamenet-zóna megegyezik a PHP zónájával;
 *   2. TÚLÉLI AZ ÚJRACSATLAKOZÁST. A régi megoldás egyetlen `DB::statement`-tel
 *      állította a zónát a bootstrapkor — azt a Laravel automatikus reconnectje
 *      (Connection::reconnectIfMissingConnection()) elveszítette, és a munkamenet
 *      némán SYSTEM (=UTC) zónára esett vissza. Egy harmadik időrendszer,
 *      hibaüzenet nélkül, tipikusan a fél órás `updateMasses()` közepén.
 */
final class SessionTimezoneTest extends TestCase {

    public function testTheSessionTimezoneMatchesThePhpTimezone(): void {
        self::assertSame(
            date_default_timezone_get(),
            DB::connection()->selectOne('SELECT @@session.time_zone AS tz')->tz,
            'a MySQL munkamenet-zónájának a PHP zónájával kell egyeznie (#890)'
        );
    }

    public function testThePhpClockAndTheMysqlClockShowTheSameWallTime(): void {
        $mysql = strtotime(DB::connection()->selectOne('SELECT NOW() AS n')->n);

        /*
         * Két másodperc a tűrés: a két olvasás nem ugyanabban a pillanatban történik.
         * A javítandó hiba nagyságrendje 3-4 ÓRA, tehát ez bőven megkülönbözteti őket.
         */
        self::assertLessThanOrEqual(2, abs(time() - $mysql),
            'a PHP és a MySQL faliórája legfeljebb másodpercekkel térhet el (#890)');
    }

    /**
     * Ez az a teszt, amit a régi `DB::statement`-es megoldás megbukott.
     */
    public function testTheTimezoneSurvivesAReconnect(): void {
        $connection = DB::connection();
        $connection->reconnect();

        self::assertSame(
            date_default_timezone_get(),
            $connection->selectOne('SELECT @@session.time_zone AS tz')->tz,
            'az újracsatlakozott kapcsolatnak is a PHP zónáján kell lennie (#890)'
        );
        self::assertLessThanOrEqual(2, abs(time() - strtotime($connection->selectOne('SELECT NOW() AS n')->n)),
            'újracsatlakozás után sem csúszhat el a két óra (#890)');
    }

    /**
     * A nevesített zóna nem elég, ha a MySQL nem ismeri: a `CONVERT_TZ` ilyenkor NEM
     * hibázik, hanem NULL-t ad — a #890 migrációja pedig ebből néma adatvesztést
     * csinálna. A migráció saját őre ezt elkapja, de a hiányt itt is meg akarjuk
     * tudni, mielőtt bárki élesben futtat bármit.
     */
    public function testTheNamedTimezoneTableIsLoaded(): void {
        $sor = DB::connection()->selectOne(
            "SELECT CONVERT_TZ('2026-07-15 10:00:00','Europe/Budapest','+05:00') AS nyar,
                    CONVERT_TZ('2026-01-15 10:00:00','Europe/Budapest','+05:00') AS tel"
        );

        self::assertSame('2026-07-15 13:00:00', $sor->nyar,
            'nyári időszámítás: +02:00 → a +05:00-s falióra három órával későbbi');
        self::assertSame('2026-01-15 14:00:00', $sor->tel,
            'téli időszámítás: +01:00 → a +05:00-s falióra négy órával későbbi');
    }

    /**
     * A megjelenítés a PHP zónáján keresztül megy, tehát ami TIMESTAMP oszlopba
     * PHP-vel bemegy, annak változatlanul kell visszajönnie — az óraátállítás
     * két határán is. Ez a #890 migrációjának a szemantikai szerződése.
     */
    public function testAWallClockValueSurvivesARoundTrip(): void {
        $ertekek = [
            'tel'                => '2026-01-15 10:00:00',
            'nyar'               => '2026-07-15 10:00:00',
            'tavaszi-atallitas'  => '2026-03-29 03:30:00',
            'oszi-atallitas'     => '2026-10-25 03:30:00',
        ];

        DB::connection()->statement(
            'CREATE TEMPORARY TABLE tz890_probakor (id INT PRIMARY KEY, ts TIMESTAMP NULL)'
        );
        try {
            $i = 0;
            foreach ($ertekek as $ertek) {
                DB::connection()->insert('INSERT INTO tz890_probakor VALUES (?, ?)', [++$i, $ertek]);
            }

            $i = 0;
            foreach ($ertekek as $cimke => $ertek) {
                $vissza = DB::connection()
                    ->selectOne('SELECT ts FROM tz890_probakor WHERE id = ?', [++$i])->ts;
                self::assertSame($ertek, $vissza, "a(z) $cimke sor faliórája megváltozott (#890)");
            }
        } finally {
            DB::connection()->statement('DROP TEMPORARY TABLE IF EXISTS tz890_probakor');
        }
    }
}
