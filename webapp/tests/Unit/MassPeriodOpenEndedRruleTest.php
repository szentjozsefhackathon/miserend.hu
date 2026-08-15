<?php

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Élesben az újraindexelés két darabja bukott el ezzel:
 *
 *   2 darab újraindexelése hibázott:
 *   3. darab (templomok: 233, 234, …): Call to a member function toIso8601String() on null
 *   16. darab (templomok: 1629, 1630, …): Call to a member function toIso8601String() on null
 *
 * A hiba a period nélküli, RRULE-os misék ágán volt (ezek a külső naptárból importált
 * sorozatok). A `$until` az `if (isset($mass->rrule['until']))` blokkON BELÜL keletkezett,
 * a `foreach` viszont nem nullázta körönként:
 *
 *   - az ELSŐ olyan misénél, aminek nincs UNTIL-je, a `$until` nem létezett → null →
 *     `toIso8601String()` on null → az egész darab elszállt;
 *   - a további ilyen miséknél az ELŐZŐ mise záródátumát kapta meg — némán, rossz adattal.
 *
 * A nyitott végű szabály (`FREQ=WEEKLY;BYDAY=SU` UNTIL nélkül) a Google-naptárakban a
 * leggyakoribb alak, tehát ez nem szélsőséges eset.
 */
class MassPeriodOpenEndedRruleTest extends TestCase {

    private const EV = 2025;

    private function mise(int $id, array $rrule): \Eloquent\CalMass {
        $mass = new \Eloquent\CalMass();
        $mass->id = $id;
        $mass->church_id = 1;
        $mass->title = 'Szentmise';
        $mass->start_date = self::EV . '-03-02T10:00:00';
        $mass->rite = 'ROMAN_CATHOLIC';
        $mass->lang = 'hu';
        $mass->period_id = null;
        $mass->comment = ExternalCalendarImporter::IMPORT_MARKER;
        $mass->rrule = $rrule;
        return $mass;
    }

    /** @return array<int, array> a period nélküli (import) ágon keletkezett sorok */
    private function generate(array $masses): array {
        $eredmeny = \Eloquent\CalMass::generateMassPeriodInstancesForYears(
            $masses,
            [1 => 'Europe/Budapest'],
            [self::EV]
        );
        return array_values(array_filter($eredmeny, static fn($sor) => $sor['period_id'] === null));
    }

    /**
     * A reprodukció: UNTIL nélküli, period nélküli mise. Javítás előtt itt szállt el
     * a teljes darab.
     */
    public function testOpenEndedRuleDoesNotCrashTheWholeChunk(): void {
        $sorok = $this->generate([
            $this->mise(135166, ['freq' => 'weekly', 'byweekday' => ['SU'], 'dtstart' => self::EV . '-03-02T10:00:00']),
        ]);

        $this->assertCount(1, $sorok, 'A nyitott végű szabálynak be kell kerülnie az indexbe.');
        // Nyitott vég → az indexelt év vége a záródátum.
        $this->assertSame(
            Carbon::create(self::EV, 12, 31)->endOfDay()->toIso8601String(),
            $sorok[0]['rrule']['until']
        );
    }

    /**
     * A csendesebb, de rosszabb fele: az UNTIL nélküli mise NE az előző mise
     * záródátumát örökölje.
     */
    public function testOpenEndedRuleDoesNotInheritThePreviousMassUntil(): void {
        $sorok = $this->generate([
            $this->mise(1, ['freq' => 'weekly', 'byweekday' => ['SU'],
                            'dtstart' => self::EV . '-01-05T10:00:00',
                            'until' => self::EV . '-04-30T10:00:00']),
            $this->mise(2, ['freq' => 'weekly', 'byweekday' => ['SU'],
                            'dtstart' => self::EV . '-01-05T18:00:00']),
        ]);

        $this->assertCount(2, $sorok);
        $szerint = [];
        foreach ($sorok as $sor) {
            $szerint[$sor['mass_id']] = $sor['rrule']['until'];
        }

        $this->assertStringStartsWith(self::EV . '-04-30', $szerint[1], 'A megadott UNTIL maradjon.');
        $this->assertStringStartsWith(self::EV . '-12-31', $szerint[2],
            'A nyitott végű szabály nem örökölheti az előző mise záródátumát.');
    }

    /**
     * A `$periods` ugyanúgy átszivárgott a cikluson, mint az `$until`: az import-ág nem
     * állítja be (a misét a `$massesFromImport`-ba teszi, és külön dolgozza fel lentebb),
     * a rá következő `foreach ($periods ...)` viszont utána is lefut.
     *
     * Következmény: az importált mise az ELŐZŐ mise generált időszakaival is legenerálódott,
     * tehát **kétszer** került az indexbe — duplikált miseidőpontokat adva a keresésben.
     * Mérve: javítás nélkül a 11-es mise két sort kap, egyet a sajátjából, egyet a 10-eséből.
     *
     * Itt az importált misének szándékosan VAN `until`-je: enélkül a fenti null-hiba
     * előbb elszállna, és ez az ág el sem érhető.
     */
    public function testImportedMassDoesNotInheritThePreviousMassPeriods(): void {
        // Olyan periódus kell, aminek TÉNYLEG van generált időszaka az indexelt évben —
        // különben a `$periods` üresen szivárogna át, és a teszt vakon menne át.
        $periodId = \Eloquent\CalGeneratedPeriod::where('start_date', '<=', self::EV . '-12-31')
            ->where('end_date', '>', self::EV . '-01-01')
            ->value('period_id');
        if ($periodId === null) {
            $this->markTestSkipped('Nincs generált időszak ' . self::EV . '-re a teszt-adatbázisban.');
        }

        $periodusos = $this->mise(10, ['freq' => 'weekly', 'byweekday' => ['SU'],
                                       'dtstart' => self::EV . '-01-05T08:00:00']);
        $periodusos->period_id = $periodId;

        $importalt = $this->mise(11, ['freq' => 'weekly', 'byweekday' => ['SU'],
                                      'dtstart' => self::EV . '-01-05T18:00:00',
                                      'until' => self::EV . '-06-30T18:00:00']);

        $mind = \Eloquent\CalMass::generateMassPeriodInstancesForYears(
            [$periodusos, $importalt], [1 => 'Europe/Budapest'], [self::EV]
        );

        $importaltSorok = array_values(array_filter($mind, static fn($s) => $s['mass_id'] === 11));
        $this->assertCount(1, $importaltSorok,
            'Az importált misének pontosan egy sora lehet — a sajátja, nem a szomszédjáé.');
        $this->assertNull($importaltSorok[0]['period_id']);
    }

    /** Az éven kívülre eső szabályok továbbra sem kerülnek be. */
    public function testRulesOutsideTheIndexedYearAreStillSkipped(): void {
        $sorok = $this->generate([
            // Már véget ért az év előtt.
            $this->mise(3, ['freq' => 'weekly', 'byweekday' => ['SU'],
                            'dtstart' => (self::EV - 3) . '-01-05T10:00:00',
                            'until' => (self::EV - 2) . '-04-30T10:00:00']),
            // Csak az év után kezdődik.
            $this->mise(4, ['freq' => 'weekly', 'byweekday' => ['SU'],
                            'dtstart' => (self::EV + 2) . '-01-05T10:00:00']),
        ]);

        $this->assertSame([], $sorok);
    }
}
