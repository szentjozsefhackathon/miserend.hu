<?php

use PHPUnit\Framework\TestCase;

/**
 * A napi ablakkal futó cronok ne látszódjanak elakadtnak az ablakon KÍVÜL.
 *
 * Sok munka `from`–`until` ablakban fut (tipikusan 1am–6am). Az elakadás-vizsgálat
 * viszont csak a nyers eltelt időt nézte, a `stuckReason()` meg sem kapta az ablakot.
 * Egy 20 perces, 1–6 óra közti munkánál a háromszoros türelem egy óra, az ablak
 * viszont reggel 6-kor bezár — vagyis reggel 7-től másnap hajnalig GARANTÁLTAN
 * „elakadtnak" látszott, holott pontosan a beállítás szerint viselkedett.
 *
 * Az éles /health ezt naponta három hamis riasztásként mutatta
 * (`Email::sendQueued`, `sendInactivityNotification`, `deleteNonActivatedUsers`) —
 * és épp az ilyen állandó piros teszi használhatatlanná az egészség-oldalt.
 */
class CronWindowStuckTest extends TestCase
{
    private const ABLAK_TOL = '1am';
    private const ABLAK_IG = '6am';

    private static function ido(string $datetime): int
    {
        return strtotime($datetime);
    }

    /* ---------- a hamis riasztás esete ---------- */

    /** Az élesben látott helyzet: 05:55-kor futott, 06:43-kor nézzük. */
    public function testJobIsNotStuckJustBecauseItsWindowClosed(): void
    {
        $ok = \Eloquent\Cron::stuckReason(
            '2026-08-15 05:55:01',
            '15 minutes',
            self::ido('2026-08-15 06:43:34'),
            self::ABLAK_TOL,
            self::ABLAK_IG
        );

        self::assertNull($ok, 'az ablak bezárása nem elakadás');
    }

    /** Késő délután sem — pedig nyers időben már 12 óra telt el. */
    public function testStillNotStuckLateInTheDay(): void
    {
        self::assertNull(\Eloquent\Cron::stuckReason(
            '2026-08-15 05:55:01',
            '20 minutes',
            self::ido('2026-08-15 18:00:00'),
            self::ABLAK_TOL,
            self::ABLAK_IG
        ));
    }

    /** Ablak NÉLKÜL ugyanez viszont valódi elakadás — a régi viselkedés megmarad. */
    public function testWithoutAWindowTheSameGapIsStuck(): void
    {
        self::assertNotNull(\Eloquent\Cron::stuckReason(
            '2026-08-15 05:55:01',
            '15 minutes',
            self::ido('2026-08-15 06:43:34')
        ));
    }

    /* ---------- a valódi elakadást továbbra is elkapjuk ---------- */

    /** Két ablak (két hajnal) kihagyása után igenis szólni kell. */
    public function testMissingSeveralWindowsIsStillReported(): void
    {
        $ok = \Eloquent\Cron::stuckReason(
            '2026-08-12 05:00:00',
            '20 minutes',
            self::ido('2026-08-15 06:00:00'),
            self::ABLAK_TOL,
            self::ABLAK_IG
        );

        self::assertNotNull($ok);
        self::assertStringContainsString('ablakán belül számolva', $ok);
    }

    public function testNeverSucceededIsAlwaysReported(): void
    {
        self::assertSame(
            'soha nem futott le sikeresen',
            \Eloquent\Cron::stuckReason('0000-00-00 00:00:00', '1 day', null, self::ABLAK_TOL, self::ABLAK_IG)
        );
    }

    /* ---------- az alkalmas idő számolása ---------- */

    private static function alkalmas(string $tol, string $ig, ?string $ablakTol = self::ABLAK_TOL, ?string $ablakIg = self::ABLAK_IG): int
    {
        return \Eloquent\Cron::eligibleSecondsBetween(self::ido($tol), self::ido($ig), $ablakTol, $ablakIg);
    }

    /** Ablakon kívüli szakasz nem számít bele. */
    public function testTimeOutsideTheWindowDoesNotCount(): void
    {
        self::assertSame(0, self::alkalmas('2026-08-15 07:00:00', '2026-08-15 23:00:00'));
    }

    /** Az ablakon belüli rész igen — pontosan annyi, amennyi átfed. */
    public function testOnlyTheOverlapCounts(): void
    {
        // 05:00–07:00 közül csak az 5-6 óra esik az ablakba.
        self::assertSame(3600, self::alkalmas('2026-08-15 05:00:00', '2026-08-15 07:00:00'));
    }

    /** Több napon át összeadódik: napi 5 óra. */
    public function testWindowsAccumulateAcrossDays(): void
    {
        // 08-13 07:00 → 08-15 07:00: két teljes ablak (14-én és 15-én).
        self::assertSame(2 * 5 * 3600, self::alkalmas('2026-08-13 07:00:00', '2026-08-15 07:00:00'));
    }

    /** Ablak nélkül a teljes eltelt idő számít. */
    public function testWithoutAWindowEverythingCounts(): void
    {
        self::assertSame(7200, self::alkalmas('2026-08-15 10:00:00', '2026-08-15 12:00:00', null, null));
        self::assertSame(7200, self::alkalmas('2026-08-15 10:00:00', '2026-08-15 12:00:00', '', ''));
    }

    /** Éjfélen átnyúló ablak (22:00–02:00) is helyesen számol. */
    public function testWindowCrossingMidnight(): void
    {
        // 23:00 → 01:00: végig az ablakban.
        self::assertSame(7200, self::alkalmas('2026-08-15 23:00:00', '2026-08-16 01:00:00', '10pm', '2am'));
        // 03:00 → 21:00: teljesen kívül.
        self::assertSame(0, self::alkalmas('2026-08-15 03:00:00', '2026-08-15 21:00:00', '10pm', '2am'));
    }

    public function testReversedRangeIsZero(): void
    {
        self::assertSame(0, self::alkalmas('2026-08-15 06:00:00', '2026-08-15 05:00:00'));
    }
}
