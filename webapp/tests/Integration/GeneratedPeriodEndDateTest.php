<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Carbon\Carbon;

/**
 * A `cal_generated_periods.end_date` KIZÁRÓ: az időszak `[start_date, end_date)`.
 *
 * A #832-ben ezt beleértendőnek olvastam, és eszerint írtam át a mise-generátort.
 * Rosszul: minden időszak egy nappal hosszabb lett. A bizonyíték magában az
 * adatban van, ezért ezek a tesztek a VALÓDI törzsadaton mérnek, nem fixtúrán.
 *
 * A tárolási forma a `CalPeriod::generateCalGeneratedPeriods()`-ból jön:
 *
 *     if ($endDate->equalTo($startDate) || $period->all_inclusive) {
 *         $endDate->addDay();
 *     }
 *
 * Ez az `addDay()` PONTOSAN az a lépés, ami a „beleértendő" szándékot kizáró
 * tárolási formára fordítja. Ha az `end_date` beleértendő volna, ez a sor egy
 * nappal túlnyújtaná az időszakot — az egynapos időszak kettő lenne.
 */
final class GeneratedPeriodEndDateTest extends TestCase {

    /** Egy adott év adott nevű időszaka. */
    private function idoszak(string $nev, int $ev): ?object {
        return DB::table('cal_generated_periods')
            ->where('name', $nev)
            ->whereYear('start_date', $ev)
            ->first();
    }

    /**
     * A Szenteste egyetlen nap. A `cal_periods`-ban 12-24 → 12-24, tehát a
     * generátor `addDay()`-t ad rá — a tárolt vég 12-25.
     */
    public function testAzEgynaposIdoszakTaroltVegeAKovetkezoNap(): void {
        $szenteste = $this->idoszak('Szenteste', 2026);
        if (!$szenteste) {
            self::markTestSkipped('Nincs 2026-os Szenteste a törzsadatban.');
        }

        self::assertSame('2026-12-24', Carbon::parse($szenteste->start_date)->toDateString());
        self::assertSame('2026-12-25', Carbon::parse($szenteste->end_date)->toDateString());

        // Kizáró olvasattal ez egy nap. Beleértendővel kettő lenne — és a Szenteste
        // nem tart karácsony első napjáig.
        $napok = Carbon::parse($szenteste->start_date)->diffInDays(Carbon::parse($szenteste->end_date));
        self::assertSame(1, (int) $napok, 'a Szenteste egyetlen nap');
    }

    /**
     * A naptári hónapok a legbeszédesebbek: a „Május" tárolt vége június 1.
     * Beleértendő olvasattal június 1. is május volna.
     */
    public function testAHonapTaroltVegeAKovetkezoHonapElsejeu(): void {
        $majus = $this->idoszak('Május', 2026);
        if (!$majus) {
            self::markTestSkipped('Nincs 2026-os Május a törzsadatban.');
        }

        self::assertSame('2026-05-01', Carbon::parse($majus->start_date)->toDateString());
        self::assertSame('2026-06-01', Carbon::parse($majus->end_date)->toDateString());

        $napok = Carbon::parse($majus->start_date)->diffInDays(Carbon::parse($majus->end_date));
        self::assertSame(31, (int) $napok, 'a május 31 napos');
    }

    /**
     * Az `all_inclusive = 0` a másik irány: a záró dátum NEM tartozik bele.
     * A húsvétig tartó nagyböjtbe a húsvét ne essen bele — borazslo példája.
     */
    public function testANemBeleertendoIdoszakVegeNemTartozikBele(): void {
        $sor = DB::table('cal_generated_periods AS g')
            ->join('cal_periods AS p', 'p.id', '=', 'g.period_id')
            ->where('p.all_inclusive', 0)
            ->whereYear('g.start_date', 2026)
            ->select('g.start_date', 'g.end_date', 'p.name')
            ->first();

        if (!$sor) {
            self::markTestSkipped('Nincs all_inclusive=0 időszak a 2026-os adatban.');
        }

        // A generátor ilyenkor NEM ad hozzá napot: a tárolt vég maga a nem
        // beleértendő nap. Kizáró olvasattal ez pont jó.
        self::assertTrue(
            Carbon::parse($sor->end_date)->gt(Carbon::parse($sor->start_date)),
            "a(z) {$sor->name} vége a kezdete után van"
        );
    }

    /**
     * A tényleges bizonyíték: a naptári hónapok tárolt hossza pontosan a hónap
     * naptári hossza. Ha az `end_date` beleértendő volna, mind eggyel több lenne.
     */
    public function testMindenNaptariHonapPontosanAnnyiNapAmennyi(): void {
        $honapok = [
            'Január' => 31, 'Február' => 28, 'Március' => 31, 'Április' => 30,
            'Május' => 31, 'Június' => 30, 'Július' => 31, 'Augusztus' => 31,
            'Szeptember' => 30, 'Október' => 31, 'November' => 30, 'December' => 31,
        ];

        $merve = [];
        foreach ($honapok as $nev => $vart) {
            $sor = $this->idoszak($nev, 2026);
            if (!$sor) {
                continue;
            }
            $merve[$nev] = (int) Carbon::parse($sor->start_date)
                ->diffInDays(Carbon::parse($sor->end_date));
        }

        if (!$merve) {
            self::markTestSkipped('Nincsenek naptári hónapok a törzsadatban.');
        }

        $vart = array_intersect_key($honapok, $merve);
        self::assertSame($vart, $merve, 'a tárolt hossz a kizáró olvasat szerinti');
    }
}
