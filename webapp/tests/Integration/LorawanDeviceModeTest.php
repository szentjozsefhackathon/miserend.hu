<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #866: a vízszivárgás-jelzésből „most van gyóntatás" lett.
 *
 * A LoRaWAN-végpont két módot ismer (`object/Mód`):
 *   1 — ajtó állapot     -> ez a gyóntatás jelzése
 *   2 — vízszivárgás     -> ez valami egészen más
 *
 * Csakhogy MINDKETTŐ ugyanabba a `confessions.status` mezőbe írt 'ON'/'OFF'-ot, a
 * `Church::getConfessionStatusAttribute()` pedig a templom LEGUTOLSÓ sorát vette, módra
 * való szűrés nélkül. Egy jelzett szivárgásból tehát „Most van gyóntatás a helyszínen!"
 * lett a templom oldalán — csukott ajtó mellett is.
 *
 * Nem elméleti eset: a végpont saját dokumentációja mondja, hogy „Egy misézőhelyen több
 * eszköz is lehet" — ajtó- és szivárgásérzékelő egy templomban a TERVEZETT üzemmód.
 */
final class LorawanDeviceModeTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        DB::beginTransaction();

        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'LoRaWAN teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    /** @param int $mode 1 = ajtó, 2 = szivárgás */
    private function jelzes(int $mode, string $status, string $mikor): void {
        DB::table('confessions')->insert([
            'deduplicationId' => bin2hex(random_bytes(8)) . '-' . bin2hex(random_bytes(4)),
            'church_id' => $this->churchId,
            'local_id' => $mode,
            'device_mode' => $mode,
            'status' => $status,
            'timestamp' => $mikor,
            'fulldata' => '{}',
        ]);
    }

    private function statusz() {
        return \Eloquent\Church::find($this->churchId)->confessionStatus;
    }

    /**
     * A LÉNYEG: csukott ajtó + jelzett szivárgás = NINCS gyóntatás.
     *
     * A javítás előtt itt 'ON' jött vissza, mert a szivárgás sora volt a legutolsó.
     */
    public function testALeakAlarmIsNotAConfession(): void {
        $this->jelzes(1, 'OFF', date('Y-m-d H:i:s', strtotime('-10 minutes')));
        $this->jelzes(2, 'ON',  date('Y-m-d H:i:s', strtotime('-5 minutes')));

        self::assertSame('OFF', $this->statusz(),
            'a vizszivargas nem gyontatas — csukott ajtonal nem lehet ON');
    }

    /** Fordítva is: a szivárgás „vége" ne oltsa ki a valódi gyóntatást. */
    public function testALeakClearingDoesNotCancelAConfession(): void {
        $this->jelzes(1, 'ON',  date('Y-m-d H:i:s', strtotime('-10 minutes')));
        $this->jelzes(2, 'OFF', date('Y-m-d H:i:s', strtotime('-5 minutes')));

        self::assertSame('ON', $this->statusz(),
            'a szivargas-jelzes megszunese nem zarja be a gyontatast');
    }

    /** Az ajtóérzékelő továbbra is dolgozik. */
    public function testTheDoorSensorStillDrivesTheStatus(): void {
        $this->jelzes(1, 'ON', date('Y-m-d H:i:s', strtotime('-5 minutes')));
        self::assertSame('ON', $this->statusz());
    }

    /** Ha SOSEM kaptunk adatot, az „nincs telepítve kapcsoló" — nem 'OFF'. */
    public function testNoDataMeansNoSwitchInstalled(): void {
        self::assertFalse($this->statusz());
    }

    /**
     * Csak szivárgás-adat: a templomban NINCS gyóntatás-kapcsoló.
     *
     * A `false` és az `'OFF'` különbsége látszik a felületen: az egyik „nincs ilyen
     * szolgáltatásunk", a másik „most éppen nincs gyóntatás".
     */
    public function testOnlyLeakDataMeansNoConfessionSwitch(): void {
        $this->jelzes(2, 'ON', date('Y-m-d H:i:s', strtotime('-5 minutes')));

        self::assertFalse($this->statusz(),
            'szivargas-erzekelo onmagaban nem jelent gyontatas-kapcsolot');
    }

    /* ---- A #285 integrátorát blokkoló mezők ---- */

    /** A dokumentált `token` mező LÉTEZIK — enélkül „Unknown field 'token'" volt a válasz. */
    public function testTheDocumentedTokenFieldIsAccepted(): void {
        $api = new \Api\LoraWan();

        self::assertArrayHasKey('token', $api->fields,
            'a dokumentacio a token mezot igeri a JSON torzsben');
    }

    /** A helyesen írt `Status_Door` is elfogadott — a `Satus_Door` elgépelés mellett. */
    public function testBothSpellingsOfTheDoorFieldAreAccepted(): void {
        $api = new \Api\LoraWan();

        self::assertArrayHasKey('object/Satus_Door', $api->fields);
        self::assertArrayHasKey('object/Status_Door', $api->fields,
            'aki a dokumentaciobol dolgozik, a helyes alakot kodolja be');
    }

    /**
     * A SAJÁT mintaadatunk gyökér-kulcsai mind elfogadottak.
     *
     * Az /apitest oldal mintája — amire az integrátort ráirányítottuk — tíz olyan
     * ChirpStack-metaadatot tartalmaz, amit a gyökér-szűrő elutasított.
     */
    public function testTheChirpstackEnvelopeIsAccepted(): void {
        $api = new \Api\LoraWan();
        $gyokerek = [];
        foreach (array_keys($api->fields) as $mezo) {
            $gyokerek[explode('/', $mezo)[0]] = true;
        }

        $hianyzik = [];
        foreach (['devAddr','adr','dr','fCnt','fPort','confirmed','data','rxInfo','txInfo','regionConfigId'] as $kulcs) {
            if (!isset($gyokerek[$kulcs])) {
                $hianyzik[] = $kulcs;
            }
        }

        self::assertSame([], $hianyzik,
            'a sajat /apitest mintank ezeket kuldi, es elutasitasba futna: ' . implode(', ', $hianyzik));
    }
}
