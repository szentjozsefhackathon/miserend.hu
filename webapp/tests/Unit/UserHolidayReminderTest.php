<?php

use PHPUnit\Framework\TestCase;

/**
 * #290: Az ünnep-emlékeztető küldés-döntésének (User::holidayReminderNeeded) lefedése.
 *
 * borazslo spec-je: küldj, ha az ünnep-periódus nincs kitöltve, VAGY a templom
 * frissítése 6 hónapnál régebbi. Tiszta, DB-mentes logika ($today injektálható).
 */
class UserHolidayReminderTest extends TestCase
{
    private const TODAY = '2026-07-17';

    public function testUnfilledPeriodAlwaysSends(): void
    {
        // Nincs kitöltve -> mindig megy, még friss frissítés mellett is.
        $this->assertTrue(\User::holidayReminderNeeded(false, '2026-07-01', self::TODAY));
    }

    public function testFilledButNeverUpdatedSends(): void
    {
        $this->assertTrue(\User::holidayReminderNeeded(true, null, self::TODAY));
        $this->assertTrue(\User::holidayReminderNeeded(true, '', self::TODAY));
    }

    public function testFilledAndRecentlyUpdatedDoesNotSend(): void
    {
        // Kitöltve + 16 napja frissítve -> nem megy.
        $this->assertFalse(\User::holidayReminderNeeded(true, '2026-07-01', self::TODAY));
    }

    public function testFilledButStaleSends(): void
    {
        // Kitöltve, de 7,5 hónapja nincs frissítve -> megy.
        $this->assertTrue(\User::holidayReminderNeeded(true, '2025-12-01', self::TODAY));
    }

    public function testSixMonthBoundary(): void
    {
        // A 6 hónapos küszöb: 2026-07-17 - 6 hó = 2026-01-17.
        // Jan 20 (a küszöb UTÁN) -> friss -> nem megy.
        $this->assertFalse(\User::holidayReminderNeeded(true, '2026-01-20', self::TODAY));
        // Jan 10 (a küszöb ELŐTT) -> régi -> megy.
        $this->assertTrue(\User::holidayReminderNeeded(true, '2026-01-10', self::TODAY));
    }
}
