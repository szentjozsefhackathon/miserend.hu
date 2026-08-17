<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #832: a kizárt időszak UTOLSÓ NAPJA is kizárt.
 *
 * A `CalMass::generateMassPeriodInstancesForYears()` ugyanazt az `end_date` oszlopot
 * KÉTFÉLEKÉPPEN olvasta: a mise saját időszakánál `endOfDay()` (a nap beleértve), a
 * kizárt időszaknál viszont `subDay()->endOfDay()`, vagyis egy nappal korábban. A
 * kódban ott is állt rá a jelzés: „az átfedésre nem biztos hogy ez jó!".
 *
 * Megmérve: egy 06-01..06-10 kizárt időszak csak 06-09-ig zárt ki, tehát a mise a
 * kizárás utolsó napján megjelent. Élesben ez azt jelenti, hogy pl. a nyári szünet
 * záró napján a tanévi miserend is kiírásra került — és senki nem szólt érte, mert nem
 * hiba, csak egy fölösleges sor a listában. Épp ezért érdemes tesztelni.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
final class ExcludedPeriodBoundaryTest extends TestCase {

    private int $evesPeriodId;
    private int $kizartPeriodId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $this->evesPeriodId   = $this->idoszak('TESZT egész év', 1, '2026-01-01', '2026-12-31');
        $this->kizartPeriodId = $this->idoszak('TESZT kizárt', 5, '2026-06-01', '2026-06-10');
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function idoszak(string $nev, int $suly, string $tol, string $ig): int {
        $id = DB::table('cal_periods')->insertGetId(['name' => $nev, 'weight' => $suly]);
        DB::table('cal_generated_periods')->insert([
            'period_id' => $id, 'name' => $nev, 'weight' => $suly,
            'start_date' => $tol, 'end_date' => $ig,
        ]);

        return $id;
    }

    /** @return string[] a kizárt dátumok */
    private function kizartNapok(array $experiod): array {
        $mise = new \Eloquent\CalMass();
        $mise->church_id = 1;
        $mise->period_id = $this->evesPeriodId;
        $mise->title = 'Teszt';
        $mise->types = [];
        $mise->rite = 'ROMAN_CATHOLIC';
        $mise->lang = 'hu';
        $mise->comment = '';
        $mise->start_date = '2026-01-01';
        $mise->duration = 60;
        $mise->rrule = ['freq' => 'daily', 'dtstart' => '2026-01-01T07:00:00'];
        $mise->experiod = $experiod;
        $mise->save();

        $peldanyok = \Eloquent\CalMass::generateMassPeriodInstancesForYears([$mise], [], [2026]);
        $elso = reset($peldanyok);

        return array_values(array_filter(
            $elso['rrule']['exdate'] ?? [],
            fn($nap) => str_starts_with((string) $nap, '2026-06')
        ));
    }

    /** A hiba magva: az utolsó nap kimaradt a kizárásból. */
    public function testAKizartIdoszakUtolsoNapjaIsKizart(): void {
        $napok = $this->kizartNapok([$this->kizartPeriodId]);

        self::assertContains('2026-06-10', $napok,
            'A 06-01..06-10 időszak utolsó napján is szünetel a mise.');
    }

    public function testAKizartIdoszakElsoNapjaIsKizart(): void {
        self::assertContains('2026-06-01', $this->kizartNapok([$this->kizartPeriodId]));
    }

    /** Pontosan a tartomány, se több, se kevesebb. */
    public function testAKizarasPontosanATartomany(): void {
        $napok = $this->kizartNapok([$this->kizartPeriodId]);

        self::assertCount(10, $napok);
        self::assertNotContains('2026-05-31', $napok, 'a kezdet előtti nap nem kizárt');
        self::assertNotContains('2026-06-11', $napok, 'a vég utáni nap sem');
    }

    /** Kizárás nélkül júniusban egyetlen nap sem esik ki. */
    public function testKizarasNelkulNincsKihagyottNap(): void {
        self::assertSame([], $this->kizartNapok([]));
    }

    /**
     * Egynapos kizárt időszak: a régi, `subDay()`-es olvasat ilyenkor NULLA napot
     * zárt ki — vagyis a kizárás teljesen hatástalan volt.
     */
    public function testAzEgynaposKizarasIsMukodik(): void {
        $egynapos = $this->idoszak('TESZT egynapos', 7, '2026-06-20', '2026-06-20');

        self::assertSame(['2026-06-20'], $this->kizartNapok([$egynapos]));
    }
}
