<?php

use PHPUnit\Framework\TestCase;

/**
 * A /health eddig csak az attempts oszlopot színezte, ami egyetlen bukott futástól is
 * pirosodik — emiatt évekig nem tűnt fel, hogy vannak cronok, amik hónapok óta nem
 * futottak le sikeresen. A stuckReason() ezt a "régóta nem sikerült" állapotot ismeri fel.
 */
class CronStuckDetectionTest extends TestCase {

    private int $now;

    protected function setUp(): void {
        parent::setUp();
        // Fix időpont, hogy a teszt ne legyen naptárfüggő.
        $this->now = strtotime('2026-08-09 12:00:00');
    }

    public function testFrissenLefutottMunkaNemElakadt(): void {
        $this->assertNull(
            \Eloquent\Cron::stuckReason('2026-08-09 06:00:00', '1 day', $this->now)
        );
    }

    public function testEgyKimaradtFutasMegBelefer(): void {
        // 1 napos gyakoriság, 2 napja futott: a 3x-os türelmi határon belül van.
        $this->assertNull(
            \Eloquent\Cron::stuckReason('2026-08-07 12:00:00', '1 day', $this->now)
        );
    }

    public function testHaromszorosPeriodusUtanElakadtnakSzamit(): void {
        $reason = \Eloquent\Cron::stuckReason('2026-08-05 11:00:00', '1 day', $this->now);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('nem futott le sikeresen', $reason);
    }

    /**
     * Az éles adatbázisban ténylegesen előfordult esetek.
     */
    public function testElesbenTalaltElakadtMunkak(): void {
        // \User::deleteNonActivatedUsers — 20 perces gyakoriság, 2026-03-27 óta áll.
        $reason = \Eloquent\Cron::stuckReason('2026-03-27 05:50:01', '20 minutes', $this->now);
        $this->assertSame('135 napja nem futott le sikeresen', $reason);

        // \ExternalApi\szentsegimadasApi::cron — napi, 2025-12-31 óta áll.
        $reason = \Eloquent\Cron::stuckReason('2025-12-31 07:06:03', '1 day', $this->now);
        $this->assertSame('221 napja nem futott le sikeresen', $reason);
    }

    public function testSohaNemFutottLe(): void {
        $expected = 'soha nem futott le sikeresen';
        $this->assertSame($expected, \Eloquent\Cron::stuckReason(null, '1 day', $this->now));
        $this->assertSame($expected, \Eloquent\Cron::stuckReason('', '1 day', $this->now));
        // A régi sorokban a "soha" nulla-dátumként szerepel — ez az éles
        // \ExternalCalendarImporter és \Crons::rollPeriodYears esete.
        $this->assertSame(
            $expected,
            \Eloquent\Cron::stuckReason('0000-00-00 00:00:00', '1 day', $this->now)
        );
    }

    public function testOranyiElteresOraban(): void {
        // 15 perces gyakoriság, 2 órája futott: 3x15 perc = 45 perc, tehát elakadt.
        $reason = \Eloquent\Cron::stuckReason('2026-08-09 10:00:00', '15 minutes', $this->now);
        $this->assertSame('2 órája nem futott le sikeresen', $reason);
    }

    public function testErtelmezhetetlenGyakorisagraNemTalalgat(): void {
        $this->assertNull(
            \Eloquent\Cron::stuckReason('2020-01-01 00:00:00', 'ez nem gyakoriság', $this->now)
        );
    }

    public function testErtelmezhetetlenDatumotJelez(): void {
        $reason = \Eloquent\Cron::stuckReason('nem dátum', '1 day', $this->now);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('értelmezhetetlen', $reason);
    }
}
