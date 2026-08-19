<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A kizárt időszak a tartománya MINDEN napján kizár — az elsőn és az utolsón is.
 *
 * A `generateMassPeriodInstancesForYears()` ugyanazt az `end_date` oszlopot
 * KÉTFÉLEKÉPPEN olvasta: a mise saját időszakánál a nap végéig, a kizárt időszaknál
 * viszont eggyel korábbig. A kódban ott is állt rá a jelzés: „az átfedésre nem
 * biztos hogy ez jó!".
 *
 * A #832-ben a rossz irányba egyenlítettem ki: a `subDay()`-t vettem ki, holott az
 * volt a helyes. A tárolt `end_date` KIZÁRÓ — l. `GeneratedPeriodEndDateTest`, ami
 * ezt a valódi törzsadaton méri. A fixtúrákat itt magam írtam beleértendő alakban,
 * tehát a saját feltevésemet mértem vissza; a helper most már elvégzi a fordítást.
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

    /**
     * @param string $ig az UTOLSÓ NAP, ami még beletartozik
     *
     * A tárolt `end_date` KIZÁRÓ, ezért egy nappal későbbre kerül — pontosan úgy,
     * ahogy a `CalPeriod::generateCalGeneratedPeriods()` is `addDay()`-t ad. Ezt a
     * fordítást eredetileg nem végeztem el itt, és így a fixtúra a saját téves
     * feltevésemet rögzítette a rendszer tárolási formája helyett.
     */
    private function idoszak(string $nev, int $suly, string $tol, string $ig): int {
        $id = DB::table('cal_periods')->insertGetId(['name' => $nev, 'weight' => $suly]);
        DB::table('cal_generated_periods')->insert([
            'period_id' => $id, 'name' => $nev, 'weight' => $suly,
            'start_date' => $tol,
            'end_date' => \Carbon\Carbon::parse($ig)->addDay()->toDateString(),
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
     * Egynapos kizárt időszak. A tárolt alakja `06-20 → 06-21`, ahogy a valódi
     * egynapos időszakoké (Szenteste: `12-24 → 12-25`).
     */
    public function testAzEgynaposKizarasIsMukodik(): void {
        $egynapos = $this->idoszak('TESZT egynapos', 7, '2026-06-20', '2026-06-20');

        self::assertSame(['2026-06-20'], $this->kizartNapok([$egynapos]));
    }

    /** @return array{0:string,1:string} a mise LEFEDETT tartománya [első nap, utolsó nap] */
    private function lefedettTartomany(int $periodId): array {
        $mise = new \Eloquent\CalMass();
        $mise->church_id = 1;
        $mise->period_id = $periodId;
        $mise->title = 'Teszt';
        $mise->types = [];
        $mise->rite = 'ROMAN_CATHOLIC';
        $mise->lang = 'hu';
        $mise->comment = '';
        $mise->start_date = '2026-01-01';
        $mise->duration = 60;
        $mise->rrule = ['freq' => 'daily', 'dtstart' => '2026-01-01T07:00:00'];
        $mise->experiod = [];
        $mise->save();

        $peldanyok = \Eloquent\CalMass::generateMassPeriodInstancesForYears([$mise], [], [2026]);
        $elso = reset($peldanyok);

        return [$elso['start_date'], $elso['end_date']];
    }

    /**
     * A mise SAJÁT időszaka is kizáró végű.
     *
     * Ez volt a súlyosabbik ág: beleértendő olvasattal MINDEN időszak egy nappal
     * hosszabb lett, tehát a májusi miserend június 1-jén is generálódott volna. A
     * legbeszédesebb eset az egynapos időszak — abból kettő nap lett.
     */
    public function testAzEgynaposIdoszakEgyetlenNapotFedLe(): void {
        $egynapos = $this->idoszak('TESZT egynapos saját', 9, '2026-06-20', '2026-06-20');

        self::assertSame(['2026-06-20', '2026-06-20'], $this->lefedettTartomany($egynapos));
    }

    /** Tíznapos időszak a tizedik nappal bezárólag tart. */
    public function testATizNaposIdoszakAzUtolsoNappalBezarolagTart(): void {
        $tiznapos = $this->idoszak('TESZT tíznapos', 9, '2026-06-01', '2026-06-10');

        self::assertSame(['2026-06-01', '2026-06-10'], $this->lefedettTartomany($tiznapos));
    }
}
