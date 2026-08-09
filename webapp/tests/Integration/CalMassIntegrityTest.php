<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #646: a `cal_masses` hivatkozási épsége.
 *
 * Ez nem kódot tesztel, hanem az ADATOT — pontosan azt a két hibát, ami a #636-ban
 * HTTP 500-zal levitte a misekeresés találati oldalát:
 *
 *   - `period_id = 0`: nem NULL, hanem nulla szentinel. Az `isset()` igaznak látja,
 *     ezért a kód elindul lekérni a nem létező időszakot -> `toArray() on null`.
 *   - nem létező templomra hivatkozó mise: a `Church::find()` null-t ad rá.
 *
 * A seedet a `06-data-integrity.sql` javítja betöltés után; ez a teszt őrzi, hogy egy
 * új dump ne hozza vissza csendben ugyanezt.
 */
final class CalMassIntegrityTest extends TestCase
{
    public function testNoMassUsesZeroAsPeriodId(): void
    {
        $count = DB::table('cal_masses')->where('period_id', 0)->count();

        self::assertSame(
            0,
            $count,
            "$count mise hivatkozik period_id=0-ra. A 'nincs időszak' jelölése NULL, "
            . "a 0-t viszont az isset() igaznak látja, és a kód nem létező időszakot keres."
        );
    }

    public function testEveryMassPointsToAnExistingChurch(): void
    {
        $orphans = DB::table('cal_masses as m')
            ->leftJoin('templomok as t', 't.id', '=', 'm.church_id')
            ->whereNull('t.id')
            ->count();

        self::assertSame(
            0,
            $orphans,
            "$orphans mise hivatkozik nem létező templomra. A Church::find() ezekre null-t "
            . "ad, amitől a találati lista összeállítása elszáll."
        );
    }

    public function testEveryMassPeriodExists(): void
    {
        $dangling = DB::table('cal_masses as m')
            ->leftJoin('cal_periods as p', 'p.id', '=', 'm.period_id')
            ->whereNotNull('m.period_id')
            ->whereNull('p.id')
            ->count();

        self::assertSame(0, $dangling, "$dangling mise hivatkozik nem létező időszakra.");
    }
}
