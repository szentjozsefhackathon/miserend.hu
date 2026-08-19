<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #431: az alkalom saját helyszíne, ha nem a templomban van.
 *
 * vlacko0930 kérése: „Jó lenne, ha templomtól távoleső szabadtéri alkalmakat lehetne
 * templom nélkül is felvenni pl koordinátákkal." A használati eset: „Röszke plébánia
 * biciklitúrát szervez időnként, és van mise valami random pusztai helyen."
 *
 * A helyet ezért az ALKALOMHOZ kötjük, nem új misézőhelyhez. Ez válaszol borazslo
 * nyitott kérdéseire is: a mise a szervező plébániáé marad (van gazdája és gondnoka),
 * és nem keletkezik minden szabadtéri alkalomból egy örökre ottmaradó, mise nélküli
 * pont a térképen.
 */
class MassOwnLocationTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $this->churchId = szabadTemplomId();
        $minta['id'] = $this->churchId;
        $minta['nev'] = 'Röszkei plébánia';
        $minta['lat'] = 46.20;
        $minta['lon'] = 20.04;
        $minta['ok'] = 'i';
        DB::table('templomok')->insert($minta);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** @param array<string,mixed> $mezok felülírandó mezők */
    private function mise(array $mezok = []): \Eloquent\CalMass {
        $mise = new \Eloquent\CalMass();
        $mise->church_id = $this->churchId;
        $mise->period_id = null;
        $mise->title = 'Szabadtéri szentmise';
        $mise->types = [];
        $mise->rite = 'ROMAN_CATHOLIC';
        $mise->start_date = date('Y-m-d', strtotime('+30 days'));
        $mise->duration = 60;
        $mise->lang = 'hu';
        $mise->comment = '';
        $mise->rrule = ['freq' => 'daily', 'count' => 1,
                        'dtstart' => date('Y-m-d', strtotime('+30 days')) . 'T10:00:00'];

        foreach ($mezok as $k => $v) {
            $mise->$k = $v;
        }
        $mise->save();

        return $mise;
    }

    // ---- a modell ------------------------------------------------------------

    public function testSajatHelyszinNelkulATemplombanVan(): void {
        $mise = $this->mise();

        self::assertFalse($mise->hasOwnLocation());
        $hely = $mise->effectiveLocation();
        self::assertFalse($hely['sajat']);
        self::assertEquals(46.20, $hely['lat']);
    }

    public function testSajatHelyszinnelAzAlkalomHelyeSzamit(): void {
        $mise = $this->mise(['location_lat' => 46.18, 'location_lon' => 20.03,
                             'location_name' => 'Röszkei puszta, kereszt']);

        self::assertTrue($mise->hasOwnLocation());
        $hely = $mise->effectiveLocation();
        self::assertTrue($hely['sajat']);
        self::assertEquals(46.18, $hely['lat']);
        self::assertSame('Röszkei puszta, kereszt', $hely['name']);
    }

    /**
     * Fél koordinátával nem lehet térképre tenni — a féligkész adat rosszabb, mint a
     * hiányzó, mert a felületen úgy néz ki, mintha tudnánk a helyet.
     */
    public function testFelKoordinataNemSzamitSajatHelyszinnek(): void {
        self::assertFalse($this->mise(['location_lat' => 46.18])->hasOwnLocation());
        self::assertFalse($this->mise(['location_lon' => 20.03])->hasOwnLocation());
    }

    public function testANullaKoordinataNemSajatHelyszin(): void {
        self::assertFalse($this->mise(['location_lat' => 0, 'location_lon' => 0])->hasOwnLocation());
    }

    /** Név nélkül is működik: a koordináta önmagában is elég a térképhez. */
    public function testNevNelkulIsSajatHelyszin(): void {
        $mise = $this->mise(['location_lat' => 46.18, 'location_lon' => 20.03]);

        self::assertTrue($mise->hasOwnLocation());
        self::assertNull($mise->effectiveLocation()['name']);
    }

    // ---- a generált példány --------------------------------------------------

    /**
     * A generált periódus-példány viszi tovább a helyszínt — enélkül az iCal, az API
     * és a kereső mind a templomot mutatná.
     */
    public function testAGeneraltPeldanyViszAHelyszint(): void {
        $mise = $this->mise(['location_lat' => 46.18, 'location_lon' => 20.03,
                             'location_name' => 'Röszkei puszta']);

        $p = \Eloquent\CalMass::generateMassPeriodInstancesForYears([$mise], [], [(int) date('Y')]);

        self::assertNotEmpty($p);
        $elso = reset($p);
        self::assertEquals(46.18, $elso['location_lat']);
        self::assertSame('Röszkei puszta', $elso['location_name']);
    }

    public function testHelyszinNelkulAMezokUresek(): void {
        $mise = $this->mise();

        $p = \Eloquent\CalMass::generateMassPeriodInstancesForYears([$mise], [], [(int) date('Y')]);
        $elso = reset($p);

        self::assertArrayHasKey('location_lat', $elso);
        self::assertNull($elso['location_lat']);
    }

    // ---- egynapos alkalom ----------------------------------------------------

    /**
     * A használati eset egyszeri alkalom: a biciklitúra nem ismétlődik. Periódus
     * NÉLKÜL, egyetlen előfordulással kell működnie.
     */
    public function testAzEgynaposAlkalomEgyElofordulastAd(): void {
        $mise = $this->mise(['location_lat' => 46.18, 'location_lon' => 20.03]);

        $p = \Eloquent\CalMass::generateMassPeriodInstancesForYears([$mise], [], [(int) date('Y')]);

        self::assertCount(1, $p);
        $rrule = new \SimpleRRule(reset($p)['rrule']);
        self::assertCount(1, $rrule->getOccurrences(),
            'Egyszeri alkalomból nem lehet sorozat.');
    }

    // ---- ami az API-ba kikerül -----------------------------------------------

    /**
     * A `toAPIArray` a miselistát az ELASTICSEARCH-BŐL szedi, egy frissen felvett
     * teszttemplom pedig nincs indexelve — a teljes úton tehát üres lenne a lista, és
     * a mérés semmit nem érne. Ezért a beillesztő lépést közvetlenül hívjuk.
     *
     * @param array<int,array<string,mixed>> $misek
     * @return array<int,array<string,mixed>>
     */
    private function apiMisek(array $misek): array {
        $templom = \Eloquent\Church::find($this->churchId);
        $metodus = new \ReflectionMethod(\Eloquent\Church::class, 'attachOwnLocations');
        $metodus->setAccessible(true);

        return $metodus->invoke($templom, $misek);
    }

    /**
     * borazslo kérdése a #813-ra: „API-t nem érinti? (v5-ben talán és akkor ha eltér
     * a templom alap adatától)".
     */
    public function testAzApiKiadjaASajatHelyszint(): void {
        $mise = $this->mise([
            'location_lat' => 46.18, 'location_lon' => 20.03,
            'location_name' => 'Röszkei puszta',
        ]);

        $valasz = $this->apiMisek([['mise_id' => (int) $mise->id]]);

        self::assertArrayHasKey('helyszin', $valasz[0]);
        self::assertSame([46.18, 20.03], $valasz[0]['helyszin']['koordinatak']);
        self::assertSame('Röszkei puszta', $valasz[0]['helyszin']['nev']);
    }

    /**
     * A templomi misére a `templom.koordinatak` a válasz — nem ismételjük meg
     * misénként. A kulcs MEGLÉTE mondja meg a kliensnek, hogy „ez máshol lesz", így
     * nem kell lebegőpontos számokat összehasonlítania.
     */
    public function testATemplomiMiseNemKapHelyszint(): void {
        $mise = $this->mise();

        $valasz = $this->apiMisek([['mise_id' => (int) $mise->id]]);

        self::assertArrayNotHasKey('helyszin', $valasz[0]);
    }

    public function testFelKoordinatanalNincsHelyszinAzApibanSem(): void {
        $mise = $this->mise(['location_lat' => 46.18]);

        $valasz = $this->apiMisek([['mise_id' => (int) $mise->id]]);

        self::assertArrayNotHasKey('helyszin', $valasz[0]);
    }

    public function testNevNelkulIsKimegyAKoordinata(): void {
        $mise = $this->mise(['location_lat' => 46.18, 'location_lon' => 20.03]);

        $valasz = $this->apiMisek([['mise_id' => (int) $mise->id]]);

        self::assertSame([46.18, 20.03], $valasz[0]['helyszin']['koordinatak']);
        self::assertSame('', $valasz[0]['helyszin']['nev']);
    }

    /** Vegyes listánál csak az érintett sor kapja meg a helyszínt. */
    public function testCsakASajatHelyszinesMiseKapKulcsot(): void {
        $szabadteri = $this->mise(['location_lat' => 46.18, 'location_lon' => 20.03]);
        $templomi   = $this->mise();

        $valasz = $this->apiMisek([
            ['mise_id' => (int) $templomi->id],
            ['mise_id' => (int) $szabadteri->id],
        ]);

        self::assertArrayNotHasKey('helyszin', $valasz[0]);
        self::assertArrayHasKey('helyszin', $valasz[1]);
    }
}
