<?php

use PHPUnit\Framework\TestCase;

/**
 * #747: „Átmásolom és az eredeti eltűnik."
 *
 * Az `applyCollisionAvoidance()` eddig kizárólag SÚLY alapján döntött: minden W
 * súlyú miséhez hozzáadta a periódusát az összes W-nél kisebb súlyú mise
 * `experiod`-jához, dátum-átfedés vizsgálata nélkül. Ha a nagyobb súlyú időszak
 * TELJESEN LEFEDI a kisebbét, ez nem felülírás, hanem kiürítés: a kisebb súlyú
 * mise sehol nem marad látható.
 *
 * Élő adattal pontosan egy ilyen pár van:
 *   Nyári szünet        (súly 3)  06-30 – 09-01
 *   Nyári időszámítás   (súly 5)  03-30 – 10-26
 *
 * A tesztek a privát statikus metódusokat Reflectionnel hívják; a fixture-ök
 * stdClass-ok, tehát nincs DB-írás.
 */
class CalMassPeriodCoverageTest extends TestCase
{
    private static function gen(string $start, string $end): stdClass
    {
        $g = new \stdClass();
        $g->start_date = $start;
        $g->end_date = $end;
        return $g;
    }

    private static function covers($generated, $coverId, $innerId): bool
    {
        $m = new \ReflectionMethod(\Eloquent\CalMass::class, 'periodCovers');
        $m->setAccessible(true);
        return $m->invoke(null, $generated, $coverId, $innerId);
    }

    /** A valós Nyári időszámítás / Nyári szünet páros. */
    private static function summerPeriods(): array
    {
        return [
            8 => [ // Nyári időszámítás, súly 5
                self::gen('2025-03-30', '2025-10-26'),
                self::gen('2026-03-29', '2026-10-25'),
            ],
            13 => [ // Nyári szünet, súly 3
                self::gen('2025-06-30', '2025-09-01'),
                self::gen('2026-06-30', '2026-08-31'),
            ],
        ];
    }

    public function testSummerTimeCoversTheSummerBreak(): void
    {
        self::assertTrue(self::covers(self::summerPeriods(), 8, 13));
    }

    public function testTheSummerBreakDoesNotCoverSummerTime(): void
    {
        self::assertFalse(self::covers(self::summerPeriods(), 13, 8));
    }

    public function testPartialOverlapIsNotCoverage(): void
    {
        $periods = [
            1 => [self::gen('2025-03-05', '2025-04-17')], // Nagyböjt
            2 => [self::gen('2025-03-01', '2025-03-31')], // Március
        ];
        self::assertFalse(self::covers($periods, 1, 2), 'A részleges átfedés nem lefedés.');
    }

    /** Konzervatív: hiányzó generált tartománynál marad a régi viselkedés. */
    public function testMissingGeneratedPeriodsMeanNoCoverage(): void
    {
        $periods = [1 => [self::gen('2025-01-01', '2025-12-31')]];
        self::assertFalse(self::covers($periods, 1, 99));
        self::assertFalse(self::covers($periods, 99, 1));
        self::assertFalse(self::covers($periods, 1, 1), 'Önmagát nem fedi le.');
    }

    /* ---------- a tényleges kizárás iránya ---------- */

    private static function mass(int $id, int $periodId): stdClass
    {
        $m = new \stdClass();
        $m->id = $id;
        $m->church_id = 1;
        $m->period_id = $periodId;
        $m->experiod = null;
        return $m;
    }

    /**
     * @return array{0: stdClass, 1: stdClass} [szüneti mise, nyári-időszámítás mise]
     */
    private static function runAvoidance(): array
    {
        $break = self::mass(101, 13);   // Nyári szünet, súly 3
        $summer = self::mass(102, 8);   // Nyári időszámítás, súly 5

        $m = new \ReflectionMethod(\Eloquent\CalMass::class, 'applyCollisionAvoidance');
        $m->setAccessible(true);
        $m->invoke(null, [$break, $summer]);

        return [$break, $summer];
    }

    /**
     * A hiba magja: a szüneti mise `experiod`-jába NEM kerülhet be a nyári
     * időszámítás, mert az a teljes tartományát lefedi — a mise eltűnne.
     */
    public function testTheFullyCoveredMassIsNotExcluded(): void
    {
        [$break] = self::runAvoidance();

        self::assertNotContains(
            8,
            $break->experiod ?? [],
            'A szüneti misét kizártuk a nyári időszámításból — így sehol nem látszana.'
        );
    }

    /** Duplázás sem lehet: a lefedő időszak miséje adja át a helyet a szűkebbnek. */
    public function testTheCoveringMassIsExcludedFromTheNarrowerPeriodInstead(): void
    {
        [, $summer] = self::runAvoidance();

        self::assertContains(
            13,
            $summer->experiod ?? [],
            'A nyári időszámítás miséje nem lépett hátra a szünet tartományában — duplán jelenne meg.'
        );
    }
}
