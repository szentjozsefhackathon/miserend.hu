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

    private static function covers($generated, $coverId, $innerId, int $year): bool
    {
        $m = new \ReflectionMethod(\Eloquent\CalMass::class, 'periodCoversInYear');
        $m->setAccessible(true);
        return $m->invoke(null, $generated, $coverId, $innerId, $year);
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
        self::assertTrue(self::covers(self::summerPeriods(), 8, 13, 2025));
    }

    public function testTheSummerBreakDoesNotCoverSummerTime(): void
    {
        self::assertFalse(self::covers(self::summerPeriods(), 13, 8, 2025));
    }

    public function testPartialOverlapIsNotCoverage(): void
    {
        $periods = [
            1 => [self::gen('2025-03-05', '2025-04-17')], // Nagyböjt
            2 => [self::gen('2025-03-01', '2025-03-31')], // Március
        ];
        self::assertFalse(self::covers($periods, 1, 2, 2025), 'A részleges átfedés nem lefedés.');
    }

    /** Konzervatív: hiányzó generált tartománynál marad a régi viselkedés. */
    public function testMissingGeneratedPeriodsMeanNoCoverage(): void
    {
        $periods = [1 => [self::gen('2025-01-01', '2025-12-31')]];
        self::assertFalse(self::covers($periods, 1, 99, 2025));
        self::assertFalse(self::covers($periods, 99, 1, 2025));
        self::assertFalse(self::covers($periods, 1, 1, 2025), 'Önmagát nem fedi le.');
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
    private static function runAvoidance(int $year = 2026): array
    {
        $break = self::mass(101, 13);   // Nyári szünet, súly 3
        $summer = self::mass(102, 8);   // Nyári időszámítás, súly 5

        $m = new \ReflectionMethod(\Eloquent\CalMass::class, 'applyCollisionAvoidance');
        $m->setAccessible(true);
        $m->invoke(null, [$break, $summer], $year);

        return [$break, $summer];
    }

    /**
     * A Nagyböjt/Március páros a TÉNYLEGES kizárásban is évenként dől el: 2026-ban
     * megfordul, 2025-ben és 2027-ben marad a súly szerinti sorrend.
     */
    private static function runLentAvoidance(int $year): array
    {
        $march = self::mass(201, 35);   // Március, súly 10
        $lent  = self::mass(202, 26);   // Nagyböjt, súly 12

        $m = new \ReflectionMethod(\Eloquent\CalMass::class, 'applyCollisionAvoidance');
        $m->setAccessible(true);
        $m->invoke(null, [$march, $lent], $year);

        return [$march, $lent];
    }

    public function testLentSwallowsMarchOnlyInTheYearWhereItDoesNotCoverIt(): void
    {
        [$march2025] = self::runLentAvoidance(2025);
        self::assertContains(26, $march2025->experiod ?? [], '2025-ben részleges az átfedés, marad a súly szerinti kizárás.');

        [$march2027] = self::runLentAvoidance(2027);
        self::assertContains(26, $march2027->experiod ?? [], '2027-ben is részleges.');
    }

    public function testInTheFullyCoveredYearTheDirectionIsInverted(): void
    {
        [$march2026, $lent2026] = self::runLentAvoidance(2026);

        self::assertNotContains(26, $march2026->experiod ?? [], '2026-ban a Nagyböjt teljesen lefedi a Márciust — a márciusi mise eltűnne.');
        self::assertContains(35, $lent2026->experiod ?? [], '2026-ban a Nagyböjtnek kell hátralépnie a Március tartományában.');
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

    /* ---------- évente változó lefedés (borazslo felvetése a #753-ban) ---------- */

    /**
     * A lefedés ÉVENTE változik. Élő adat: a Nagyböjt (súly 12) a három generált
     * évből csak 2026-ban fedi le teljesen a Márciust (súly 10) —
     *   2025: 03-05–04-17 vs 03-01–04-01  → nem fedi (márc. 1-4 kimarad)
     *   2026: 02-18–04-02 vs 03-01–04-01  → LEFEDI
     *   2027: 02-10–03-25 vs 03-01–04-01  → nem fedi (márc. 25-31 kimarad)
     */
    private static function lentAndMarch(): array
    {
        return [
            26 => [ // Nagyböjt, súly 12
                self::gen('2025-03-05', '2025-04-17'),
                self::gen('2026-02-18', '2026-04-02'),
                self::gen('2027-02-10', '2027-03-25'),
            ],
            35 => [ // Március, súly 10
                self::gen('2025-03-01', '2025-04-01'),
                self::gen('2026-03-01', '2026-04-01'),
                self::gen('2027-03-01', '2027-04-01'),
            ],
        ];
    }

    /** Ez a lényeg: évente külön dől el, nem mindent-vagy-semmit. */
    public function testCoverageIsDecidedPerYear(): void
    {
        $p = self::lentAndMarch();

        self::assertFalse(self::covers($p, 26, 35, 2025), '2025-ben márc. 1-4 kimarad.');
        self::assertTrue(self::covers($p, 26, 35, 2026), '2026-ban a Nagyböjt teljesen lefedi a Márciust.');
        self::assertFalse(self::covers($p, 26, 35, 2027), '2027-ben márc. 25-31 kimarad.');
    }

    /** Olyan évre, amire nincs generált tartomány, nem találgatunk. */
    public function testYearWithoutGeneratedRangeIsNotCoverage(): void
    {
        self::assertFalse(self::covers(self::lentAndMarch(), 26, 35, 2030));
    }

    /** A nyári páros minden generált évben teljes. */
    public function testSummerCoverageHoldsInEveryGeneratedYear(): void
    {
        $periods = [
            8 => [
                self::gen('2025-03-30', '2025-10-26'),
                self::gen('2026-03-29', '2026-10-25'),
                self::gen('2027-03-28', '2027-10-31'),
            ],
            13 => [
                self::gen('2025-06-30', '2025-09-01'),
                self::gen('2026-06-30', '2026-08-31'),
                self::gen('2027-06-30', '2027-09-01'),
            ],
        ];

        foreach ([2025, 2026, 2027] as $year) {
            self::assertTrue(self::covers($periods, 8, 13, $year), "A $year nem lefedett.");
        }
    }
}
