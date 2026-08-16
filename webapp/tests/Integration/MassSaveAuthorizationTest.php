<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A miseszerkesztő mentése eddig CSAK az útvonalban szereplő templomra ellenőrizte a
 * jogosultságot, közben viszont a beküldött adat `church_id`-jét írta ki:
 *
 *     POST /ajax/calendar/masses/{A}     →  writeAccess ellenőrzés A-ra
 *     { "masses": [ { "churchId": B, ... } ] }  →  a mise B-hez került
 *
 * Aki tehát egyetlen templomhoz kapott jogot, az a saját mentésébe más templom
 * azonosítóját írva bárhova felvihetett misét. A törlés még ennyit sem nézett:
 *
 *     CalMass::whereIn('id', $deletedMasses)->delete();
 *
 * — azonosító alapján BÁRMELYIK templom bármelyik miséje törölhető volt.
 *
 * Ez a #506 (több templom egyszerre szerkesztése) előfeltétele is: ott a kérés
 * szándékosan több templomot érint, tehát a jogosultságot misénként kell nézni.
 *
 * A szabály: oda írhatsz és onnan törölhetsz, AHOL írásjogod van. Egy templomnál ez
 * pontosan a mai viselkedés.
 */
class MassSaveAuthorizationTest extends TestCase {

    private const UTVONAL_TEMPLOM = 1;
    private const MASIK_TEMPLOM = 2;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** @param array<int,array> $masses @param int[] $deleted */
    private function erintett(array $masses, array $deleted = []): array {
        $ids = \Html\Ajax\Calendar\Masses::affectedChurchIds($masses, $deleted, self::UTVONAL_TEMPLOM);
        sort($ids);
        return $ids;
    }

    /* A mai, egy templomos eset: minden az útvonal templomához tartozik. */
    public function testASajatTemplomAzEgyetlenErintett(): void {
        $erintett = $this->erintett([
            ['churchId' => self::UTVONAL_TEMPLOM, 'title' => 'Szentmise'],
            ['churchId' => self::UTVONAL_TEMPLOM, 'title' => 'Igeliturgia'],
        ]);

        self::assertSame([self::UTVONAL_TEMPLOM], $erintett);
    }

    /** Hiányzó azonosítónál az útvonal templomát vesszük — ez a régi viselkedés. */
    public function testHianyzoAzonositoEseténAzUtvonalTemplomaSzamit(): void {
        self::assertSame([self::UTVONAL_TEMPLOM], $this->erintett([['title' => 'Szentmise']]));
    }

    /**
     * A lyuk magja: az útvonal az egyik templomra mutat, a beküldött mise a másikra.
     * Ezt a kérést MINDKÉT templomot érintőnek kell látni, különben a második
     * ellenőrzés nélkül íródna ki.
     */
    public function testAMasikTemplomraKuldottMiseIsErintettnekSzamit(): void {
        $erintett = $this->erintett([
            ['churchId' => self::UTVONAL_TEMPLOM, 'title' => 'Szentmise'],
            ['churchId' => self::MASIK_TEMPLOM, 'title' => 'Belopott mise'],
        ]);

        self::assertSame([self::UTVONAL_TEMPLOM, self::MASIK_TEMPLOM], $erintett);
    }

    /** A snake_case alak is számít: a kliens mindkettőt küldheti. */
    public function testASnakeCaseAlakotIsFelismerjuk(): void {
        self::assertSame([self::MASIK_TEMPLOM],
            $this->erintett([['church_id' => self::MASIK_TEMPLOM, 'title' => 'Szentmise']]));
    }

    /**
     * A törlésnél a mise TÉNYLEGES templomát nézzük, nem a kliens szavát: a kérés csak
     * azonosítót küld, és a hovatartozást nem bízhatjuk rá.
     */
    public function testATorlendoMiseTemplomatAzAdatbazisbolNezzuk(): void {
        $miseId = $this->makeMass(self::MASIK_TEMPLOM);

        self::assertSame([self::MASIK_TEMPLOM], $this->erintett([], [$miseId]));
    }

    /** A nem létező azonosítót átugorjuk: közben más már törölhette. */
    public function testANemLetezoTorlendoAzonositoNemErintSemmit(): void {
        self::assertSame([], $this->erintett([], [999999999]));
    }

    /** Mentés és törlés együtt: mindkét oldal érintett templomai összeadódnak. */
    public function testAMentesEsATorlesErintettjeiOsszeadodnak(): void {
        $miseId = $this->makeMass(self::MASIK_TEMPLOM);

        $erintett = $this->erintett(
            [['churchId' => self::UTVONAL_TEMPLOM, 'title' => 'Szentmise']],
            [$miseId]
        );

        self::assertSame([self::UTVONAL_TEMPLOM, self::MASIK_TEMPLOM], $erintett);
    }

    /** Ugyanaz a templom többször is szerepelhet — egyszer soroljuk fel. */
    public function testAzIsmetlodesekOsszevonodnak(): void {
        $erintett = $this->erintett([
            ['churchId' => self::MASIK_TEMPLOM, 'title' => 'Egyik'],
            ['churchId' => self::MASIK_TEMPLOM, 'title' => 'Másik'],
        ]);

        self::assertSame([self::MASIK_TEMPLOM], $erintett);
    }

    private function makeMass(int $churchId): int {
        return DB::table('cal_masses')->insertGetId([
            'church_id'  => $churchId,
            'title'      => 'Teszt mise',
            'rite'       => 'ROMAN_CATHOLIC',
            'lang'       => 'hu',
            'start_date' => date('Y-m-d\TH:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
