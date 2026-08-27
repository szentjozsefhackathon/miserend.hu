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
 * `Church::getConfessionStatusAttribute()` pedig a templom LEGUTOLSÓ sorát vette. Egy
 * jelzett szivárgásból tehát „Most van gyóntatás a helyszínen!" lett a templom oldalán —
 * csukott ajtó mellett is.
 *
 * A döntés IMPORTKOR születik meg (borazslo javaslata a #867-ben): ami nem gyóntatás,
 * az nem kerül a `confessions` táblába. Ezért a teszt a VÉGPONTOT hívja, nem az
 * adatbázisba ír: pont azt kell mérni, hogy a beérkező küldeményből mi lesz.
 */
final class LorawanConfessionTest extends TestCase {

    private string $baseUrl;
    private int $churchId;

    protected function setUp(): void {
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');

        // Nem tranzakcióban: a HTTP-kérést másik folyamat szolgálja ki, oda a rollback
        // nem érne el. Kézzel takarítunk.
        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'LoRaWAN teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);
    }

    protected function tearDown(): void {
        DB::table('confessions')->where('church_id', $this->churchId)->delete();
        DB::table('templomok')->where('id', $this->churchId)->delete();
    }

    /** Egy uplink a végpontra. Visszaadja a dekódolt JSON-választ. */
    private function uplink(array $object, array $extra = []): array {
        $payload = array_merge([
            'deduplicationId' => sprintf(
                '%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)), bin2hex(random_bytes(6))
            ),
            'time' => date('Y-m-d\TH:i:s.000+02:00'),
            'deviceInfo' => [
                'devEui' => bin2hex(random_bytes(8)),
                'tags' => ['templom_id' => $this->churchId, 'local_id' => 1],
            ],
            'object' => $object,
        ], $extra);

        $ch = curl_init($this->baseUrl . '/api/v4/lorawan');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $nyers = curl_exec($ch);
        $hiba = curl_error($ch);
        curl_close($ch);

        self::assertSame('', $hiba, 'curl: ' . $hiba);

        $valasz = json_decode((string) $nyers, true);
        self::assertIsArray($valasz, 'nem JSON jott vissza: ' . substr((string) $nyers, 0, 300));

        return $valasz;
    }

    private function sorok(): int {
        return DB::table('confessions')->where('church_id', $this->churchId)->count();
    }

    private function statusz() {
        return \Eloquent\Church::find($this->churchId)->confessionStatus;
    }

    /** A LÉNYEG: csukott ajtó + jelzett szivárgás = NINCS gyóntatás. */
    public function testALeakAlarmIsNotAConfession(): void {
        $this->uplink(['Mód' => 1, 'Satus_Door' => 0]);
        $this->uplink(['Mód' => 2, 'Status_Leak' => 1]);

        self::assertSame('OFF', $this->statusz(),
            'a vizszivargas nem gyontatas — csukott ajtonal nem lehet ON');
    }

    /** A szivárgás nem is kerül a gyóntatás-táblába. */
    public function testALeakUplinkIsNotStoredAsAConfession(): void {
        $this->uplink(['Mód' => 2, 'Status_Leak' => 1]);

        self::assertSame(0, $this->sorok(),
            'szivargas-jelzesbol nem keletkezhet gyontatas-sor');
    }

    /** Fordítva is: a szivárgás „vége" ne oltsa ki a valódi gyóntatást. */
    public function testALeakClearingDoesNotCancelAConfession(): void {
        $this->uplink(['Mód' => 1, 'Satus_Door' => 1]);
        $this->uplink(['Mód' => 2, 'Status_Leak' => 0]);

        self::assertSame('ON', $this->statusz(),
            'a szivargas-jelzes megszunese nem zarja be a gyontatast');
    }

    /** Az ajtóérzékelő továbbra is dolgozik. */
    public function testTheDoorSensorStillDrivesTheStatus(): void {
        $this->uplink(['Mód' => 1, 'Satus_Door' => 1]);

        self::assertSame(1, $this->sorok());
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
        $this->uplink(['Mód' => 2, 'Status_Leak' => 1]);

        self::assertFalse($this->statusz(),
            'szivargas-erzekelo onmagaban nem jelent gyontatas-kapcsolot');
    }

    /** A 2-es mód is ellenőrzött: hiányzó `Status_Leak` továbbra is hiba. */
    public function testTheLeakFieldIsStillRequired(): void {
        $valasz = $this->uplink(['Mód' => 2]);

        self::assertSame('1', (string) ($valasz['error'] ?? ''), 'hibat kellett volna jeleznie');
    }

    /* ---- A ChirpStack-integrátort blokkoló mezők ---- */

    /** A dokumentált `token` mező LÉTEZIK — enélkül „Unknown field 'token'" volt a válasz. */
    public function testTheDocumentedTokenFieldIsAccepted(): void {
        $valasz = $this->uplink(['Mód' => 1, 'Satus_Door' => 1], ['token' => 'akarmi']);

        self::assertNotSame('1', (string) ($valasz['error'] ?? ''),
            'a torzsben kuldott token nem lehet ismeretlen mezo: ' . json_encode($valasz));
        self::assertSame(1, $this->sorok());
    }

    /** A helyesen írt `Status_Door` alak is működik, nem csak az elgépelt `Satus_Door`. */
    public function testBothSpellingsOfTheDoorFieldAreAccepted(): void {
        $this->uplink(['Mód' => 1, 'Status_Door' => 1]);

        self::assertSame(1, $this->sorok(), 'a helyesen irt alaknak is mennie kell');
        self::assertSame('ON', $this->statusz());
    }

    /**
     * A saját mintaadatunk (ChirpStack-boríték) ELFOGADÁSBA fut.
     *
     * Az /apitest LoRaWAN-mintája — amire az integrátort ráirányítottuk — tíz
     * ChirpStack-metaadatot tartalmaz, amit a gyökér-szűrő elutasított.
     */
    public function testTheChirpstackEnvelopeIsAccepted(): void {
        $valasz = $this->uplink(['Mód' => 1, 'Satus_Door' => 1], [
            'devAddr' => '00f7d3c1', 'adr' => true, 'dr' => 5, 'fCnt' => 12, 'fPort' => 2,
            'confirmed' => false, 'data' => 'AQI=', 'rxInfo' => [], 'txInfo' => [],
            'regionConfigId' => 'eu868',
        ]);

        self::assertNotSame('1', (string) ($valasz['error'] ?? ''),
            'a sajat mintaadatunkat nem utasithatjuk el: ' . json_encode($valasz));
        self::assertSame(1, $this->sorok());
    }
}
