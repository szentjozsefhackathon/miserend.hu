<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #800: az API v5 sqlite-exportjának mise-formátuma.
 *
 * borazslo kérése:
 *
 *   „minden mise a generált periódussal sokszorozva (vagyis konkrét dátumtól dátumig)
 *    és az exdates azok konkrét dátumok […] Így egyszerű táblázat, de rrule-al könnyen
 *    sokszorosítható és kerestethető."
 *
 * A v4-ig minden EGYES előfordulás külön sor volt (`datumtol = datumig = egy nap`,
 * "Ezen a napon: …"), fél évre előre felszorozva. A v5 ehelyett periódusonként egy
 * sort ad, a szabállyal és a kivételekkel — a fejlesztői adatbázis első templomán
 * mérve ez két évre 43 sor 1229 előfordulás helyett.
 */
class SqliteV5MassFormatTest extends TestCase {

    /** @return array<string,mixed> egy generált periódus-példány sqlite-sora */
    private function sor(array $periodus, array $idoszakNevek = []): array {
        $r = new ReflectionClass(\Api\Sqlite::class);
        $api = $r->newInstanceWithoutConstructor();

        $verzio = $r->getProperty('version');
        $verzio->setAccessible(true);
        $verzio->setValue($api, 5);

        $metodus = $r->getMethod('massPeriodToRow');
        $metodus->setAccessible(true);

        return $metodus->invoke($api, $periodus, $idoszakNevek);
    }

    /** @return array<string,mixed> tipikus generált periódus */
    private function periodus(array $felulir = []): array {
        return array_merge([
            'mass_id' => 97874,
            'generated_period_id' => 792,
            'church_id' => 1,
            'start_date' => '2026-11-29',
            'end_date' => '2026-12-24',
            'types' => [],
            'duration_minutes' => 45,
            'lang' => ['hu'],
            'comment' => 'rorate',
            'rrule' => [
                'freq' => 'weekly',
                'until' => '2026-12-24T23:59:59+01:00',
                'dtstart' => '2026-11-29T07:00:00+01:00',
                'byweekday' => ['MO', 'WE'],
            ],
        ], $felulir);
    }

    /** A lényeg: KONKRÉT dátumtól dátumig, nem "ezen a napon". */
    public function testAPeriodusKonkretDatumtartomanytAd(): void {
        $sor = $this->sor($this->periodus());

        self::assertSame('2026-11-29', $sor['datumtol']);
        self::assertSame('2026-12-24', $sor['datumig']);
        self::assertNotSame($sor['datumtol'], $sor['datumig'],
            'A v4 egyetlen napra szűkített minden sort — a v5 épp ezt váltja ki.');
    }

    public function testAzIsmetlodesRruleKentMegy(): void {
        self::assertSame('FREQ=WEEKLY;UNTIL=20261224T225959Z;BYDAY=MO,WE', $this->sor($this->periodus())['rrule']);
    }

    public function testAzIdopontADtstartbolJon(): void {
        self::assertSame('07:00:00', $this->sor($this->periodus())['ido']);
    }

    public function testAKivetelekKonkretDatumok(): void {
        $periodus = $this->periodus();
        $periodus['rrule']['exdate'] = ['2026-12-08T07:00:00+01:00', '2026-12-01'];

        self::assertSame('2026-12-01,2026-12-08', $this->sor($periodus)['exdate']);
    }

    /**
     * Egy mise több periódussal is szerepel (téli/nyári/adventi), ezért a
     * fogyasztónak tudnia kell, hogy ugyanarról a miséről van szó.
     */
    public function testAMiseAzonositojaMegmarad(): void {
        self::assertSame(97874, $this->sor($this->periodus())['mise_id']);
    }

    public function testAzIdoszakNeveKikerul(): void {
        self::assertSame('Advent', $this->sor($this->periodus(), [792 => 'Advent'])['idoszak']);
    }

    public function testIsmeretlenIdoszaknalUresANev(): void {
        self::assertSame('', $this->sor($this->periodus())['idoszak']);
    }

    public function testAHosszPercbenMegy(): void {
        self::assertSame(45, $this->sor($this->periodus())['hossz']);
    }

    public function testATobbNyelvVesszovelMegy(): void {
        self::assertSame('hu,de', $this->sor($this->periodus(['lang' => ['hu', 'de']]))['nyelv']);
    }

    /** Hiányos periódusnál se szálljon el az export. */
    public function testHianyosPeriodusnalSemDob(): void {
        $sor = $this->sor(['church_id' => 1, 'mass_id' => 5]);

        self::assertSame(1, $sor['tid']);
        self::assertSame('', $sor['rrule']);
        self::assertNull($sor['ido']);
    }

    // ---- a valódi adat ------------------------------------------------------

    /**
     * A generált szerkezet MINDEN mezője megvan, amire a leképezés épít. Ha a
     * CalMass kimenete változik, ez a teszt fogja meg, nem az éles export.
     */
    public function testAGeneraltSzerkezetTartalmazzaAVartMezoket(): void {
        $mise = \Eloquent\CalMass::first();
        if ($mise === null) {
            self::markTestSkipped('Nincs mise a teszt-adatbázisban.');
        }

        $masses = \Eloquent\CalMass::where('church_id', $mise->church_id)->get()->all();
        $periodusok = \Eloquent\CalMass::generateMassPeriodInstancesForYears($masses, [], [(int) date('Y')]);

        self::assertNotEmpty($periodusok);

        foreach (['mass_id', 'generated_period_id', 'church_id', 'start_date', 'end_date',
                  'duration_minutes', 'lang', 'comment', 'rrule'] as $mezo) {
            self::assertArrayHasKey($mezo, reset($periodusok), "Hiányzó mező: $mezo");
        }
    }

    /** A v5 táblája tartalmazza az új oszlopokat. */
    public function testAzOtosVerzioTablajaTartalmazzaARruleOszlopot(): void {
        $forras = file_get_contents(PATH . 'classes/api/sqlite.php');

        self::assertMatchesRegularExpression('/\$this->version >= 5/', $forras);
        foreach (['[rrule]', '[exdate]', '[mise_id]', '[hossz]'] as $oszlop) {
            self::assertStringContainsString($oszlop, $forras, "Hiányzó v5 oszlop: $oszlop");
        }
    }
}
