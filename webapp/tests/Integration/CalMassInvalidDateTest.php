<?php

use PHPUnit\Framework\TestCase;

final class CalMassInvalidDateTest extends TestCase
{
    public function testInvalidRruleStartDoesNotAbortMassGeneration(): void
    {
        $invalid = new \Eloquent\CalMass([
            'church_id' => 4553,
            'period_id' => null,
            'title' => 'Hibás időpont',
            'rite' => 'ROMAN_CATHOLIC',
            'start_date' => '2026-01-01TNaN:NaN:NaN',
            'rrule' => [
                'freq' => 'monthly',
                'dtstart' => '2026-01-01TNaN:NaN:NaN',
                'until' => '2026-12-31T23:59:00',
                'bysetpos' => 1,
                'byweekday' => ['SU'],
            ],
        ]);
        $invalid->id = 107996;

        $result = \Eloquent\CalMass::generateMassPeriodInstancesForYears(
            [$invalid],
            [4553 => 'Europe/Budapest'],
            [2026]
        );

        $this->assertSame([], $result);
    }
}
