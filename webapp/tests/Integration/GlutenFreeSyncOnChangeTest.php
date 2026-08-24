<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #876: a /edit minden mentésnél OSM API olvasást indított.
 *
 * A `GlutenFreeCommunion::save()` akkor adott vissza értéket — és a hívó akkor hívta a
 * `syncToOsm()`-et —, ha a bemenetben BENNE VOLT a gluténmentes mező. Az űrlap viszont
 * MINDIG elküldi (legördülők), tehát a feltétel minden mentésnél igaz volt: aki a templom
 * nevét írta át, az is elindított egy teljes OSM-entitás letöltést.
 *
 * borazslo javaslata a #847-ben:
 *
 *   „Azt esetleg lehet, hogy csak akkor legyen syncToOsm() a church/:id/edit oldalon,
 *    ha van communion:gluten_free változás."
 *
 * Az /editosm-en a teljes letöltés SZÁNDÉKOS marad — ott az egész entitást visszaírjuk,
 * tehát a legfrissebb adatból kell dolgozni. Ez a javítás csak a /edit oldalt érinti.
 */
final class GlutenFreeSyncOnChangeTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        DB::beginTransaction();

        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Glutén teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    private function ment(string $unnep, string $hetkoznap): ?string {
        return \GlutenFreeCommunion::save($this->churchId, [
            \GlutenFreeCommunion::HOLIDAYS_KEY => $unnep,
            \GlutenFreeCommunion::WEEKDAYS_KEY => $hetkoznap,
        ]);
    }

    /** Az ELSŐ beállítás változás — fel kell küldeni. */
    public function testTheFirstSettingIsAChange(): void {
        self::assertNotNull($this->ment('always', 'always'),
            'az elso beallitas valtozas, fel kell kuldeni');
    }

    /**
     * A LÉNYEG: a VÁLTOZATLAN újramentés ne indítson OSM-hívást.
     *
     * Ez a gyakori eset: a szerkesztő a templom nevét vagy a miserendet írja át, a
     * gluténmentes legördülők pedig változatlanul mennek vele.
     */
    public function testAnUnchangedResaveDoesNotTriggerSync(): void {
        $this->ment('always', 'always');

        self::assertNull($this->ment('always', 'always'),
            'valtozatlan ertekre nem kell OSM-hivas');
    }

    /** A VALÓDI változást viszont fel kell küldeni. */
    public function testARealChangeStillTriggersSync(): void {
        $this->ment('always', 'always');           // -> OSM: yes

        self::assertNotNull($this->ment('no', 'no'),   // -> OSM: no
            'a valodi valtozast fel kell kuldeni');
    }

    /**
     * Ami az OSM-ben UGYANAZ, arra nem megy hívás — akkor sem, ha a helyi beállítás
     * változott.
     *
     * A `diet:gluten_free` csak yes/limited/no lehet, tehát több helyi beállítás
     * ugyanarra a származtatott értékre képződik le (`always` és `at_end` is `yes`).
     * A helyi sor ilyenkor is frissül; csak az OSM-hívás marad el, mert nincs mit
     * felküldeni. Ez nem hiányosság, hanem pontosan a jegy célja.
     */
    public function testALocalChangeWithTheSameOsmValueDoesNotSync(): void {
        $this->ment('always', 'always');           // -> OSM: yes

        self::assertNull($this->ment('at_end', 'always'),   // -> OSM: yes, változatlan
            'azonos szarmaztatott ertekre nem kell OSM-hivas');

        // A helyi sor viszont KÖVETI a változást.
        self::assertSame('at_end', DB::table('attributes')
            ->where('church_id', $this->churchId)
            ->where('key', \GlutenFreeCommunion::HOLIDAYS_KEY)->value('value'));
    }

    /** A helyi sor a változatlan mentésnél is MEGMARAD — csak az OSM-hívás marad el. */
    public function testTheLocalRowsAreStillSaved(): void {
        $this->ment('always', 'always');
        $this->ment('always', 'always');

        $tarolt = DB::table('attributes')
            ->where('church_id', $this->churchId)
            ->whereIn('key', [
                \GlutenFreeCommunion::HOLIDAYS_KEY,
                \GlutenFreeCommunion::WEEKDAYS_KEY,
                \GlutenFreeCommunion::OSM_KEY,
            ])->pluck('value', 'key');

        self::assertSame('always', $tarolt[\GlutenFreeCommunion::HOLIDAYS_KEY]);
        self::assertSame('always', $tarolt[\GlutenFreeCommunion::WEEKDAYS_KEY]);
        self::assertArrayHasKey(\GlutenFreeCommunion::OSM_KEY, $tarolt);
    }

    /** Ha egyáltalán nincs gluténmentes mező a bemenetben, továbbra sincs mit tenni. */
    public function testNoFieldsMeansNothingToDo(): void {
        self::assertNull(\GlutenFreeCommunion::save($this->churchId, ['nev' => 'Más mező']));
    }

    /**
     * Oda-vissza váltás: mindkét irány változás.
     *
     * Enélkül a „visszaállítom, ahogy volt" eset némán kimaradna az OSM-ből.
     */
    public function testSwitchingBackIsAlsoAChange(): void {
        $this->ment('always', 'always');
        $this->ment('no', 'no');

        self::assertNotNull($this->ment('always', 'always'),
            'a visszaallitas is valtozas');
    }
}
