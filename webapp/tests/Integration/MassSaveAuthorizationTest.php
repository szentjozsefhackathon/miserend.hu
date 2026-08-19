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

    // ---- a mise ELVITELE egyik templomtól a másikhoz ------------------------------

    /**
     * A szabály másik fele: „oda írhatsz, ahol jogod van" kimondatlanul azt is jelenti,
     * hogy ONNAN ELVENNI is csak joggal szabad.
     *
     * Enélkül aki a B templomhoz kapott jogot, egy létező mise azonosítójával és
     * `churchId => B`-vel elvihette volna a misét az A templomtól, amihez semmi joga
     * nincs. A szerver ugyanis csak a beküldött (cél) templomra ellenőrzött.
     */
    public function testAMiseEredetiTemplomaIsErintett(): void {
        $miseId = $this->makeMass(self::MASIK_TEMPLOM);

        $erintett = $this->erintett([
            ['id' => $miseId, 'churchId' => self::UTVONAL_TEMPLOM, 'title' => 'Elvitt mise'],
        ]);

        self::assertSame([self::UTVONAL_TEMPLOM, self::MASIK_TEMPLOM], $erintett,
            'A forrás templomra is kell jogosultság, különben elvehető tőle a mise.');
    }

    /**
     * borazslo észrevétele: „ha a teljes ismétlődő sorozatot módosítottam egyik
     * templomból a másikra, akkor csak egy helyen jelent meg a módosítási javaslat."
     * A forrás gondnoka így nem értesült arról, hogy egy misét elvisznek tőle — pedig
     * ez az ő miserendjét változtatja meg.
     */
    public function testAHelybenMaradoMiseNemDuplazzaAzErintetteket(): void {
        $miseId = $this->makeMass(self::MASIK_TEMPLOM);

        $erintett = $this->erintett([
            ['id' => $miseId, 'churchId' => self::MASIK_TEMPLOM, 'title' => 'Marad'],
        ]);

        self::assertSame([self::MASIK_TEMPLOM], $erintett);
    }

    /** Új misénél (még nincs azonosító) nincs eredeti templom, amit hozzá kellene venni. */
    public function testAzUjMiseNemHozMagavalEredetiTemplomot(): void {
        $erintett = $this->erintett([
            ['churchId' => self::MASIK_TEMPLOM, 'title' => 'Új mise'],
        ]);

        self::assertSame([self::MASIK_TEMPLOM], $erintett);
    }

    /**
     * A szerkesztő ideiglenes azonosítója NEGATÍV — az nem létező mise, nem szabad
     * adatbázisban keresni rá.
     */
    public function testAzIdeiglenesNegativAzonositoNemErintSemmiTovabbit(): void {
        $erintett = $this->erintett([
            ['id' => -3, 'churchId' => self::MASIK_TEMPLOM, 'title' => 'Még nem mentett'],
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
