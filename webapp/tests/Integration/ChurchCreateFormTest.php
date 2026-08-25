<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #898: az új misézőhely felvitele.
 *
 * borazslo az első pontban ezt írja: „Aránylag ritkán van rá szükség, így elég könnyű
 * eltörni a folyamatot, mert nincsenek tesztek hogy minden rendben működik-e." Ez a
 * fájl az a teszt.
 *
 * Valódi HTTP-hívások bejelentkezett adminisztrátorként — a `ChurchEditFormTest`
 * mintájára, mert az űrlap feldolgozása a `Request`-en és a session-ön múlik, azt pedig
 * kívülről érdemes megfogni.
 */
class ChurchCreateFormTest extends TestCase {

    private const NEV_ELOTAG = 'Létrehozás teszt ';

    private string $baseUrl;
    private ?string $cookie = null;

    protected function setUp(): void {
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');
        $this->login();
        if ($this->cookie === null) {
            $this->markTestSkipped('Nem sikerült adminisztrátorként bejelentkezni.');
        }
    }

    protected function tearDown(): void {
        DB::table('templomok')->where('nev', 'LIKE', self::NEV_ELOTAG . '%')->delete();
    }

    private function login(): void {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query(['login' => 'admin', 'passw' => 'miserend', 'logout' => 'false']),
            'timeout'       => 30,
            'ignore_errors' => true,
        ]]);
        @file_get_contents($this->baseUrl . '/', false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Set-Cookie:') === 0 && stripos($h, 'token=') !== false) {
                $this->cookie = trim(explode(';', substr($h, 11))[0]);
            }
        }
    }

    private function post(array $fields): string {
        $header = "Content-Type: application/x-www-form-urlencoded\r\n";
        if ($this->cookie) {
            $header .= 'Cookie: ' . $this->cookie . "\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => $header,
            'content' => http_build_query($fields), 'timeout' => 60, 'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($this->baseUrl . '/church/create', false, $ctx);
        $this->assertNotFalse($body, 'A kérés nem sikerült.');

        return $body;
    }

    private function mezok(string $nev, array $override = []): array {
        return array_merge([
            'submit'          => 'Létrehozás',
            'church[nev]'     => self::NEV_ELOTAG . $nev,
            'church[lat]'     => '47.5',
            'church[lon]'     => '19.0',
        ], $override);
    }

    private function sor(string $nev) {
        return DB::table('templomok')->where('nev', self::NEV_ELOTAG . $nev)->first();
    }

    /**
     * A LÉNYEG: tizedesVESSZŐVEL is működik.
     *
     * A magyar számformátumban a tizedesjel a vessző, és a felhasználók így írják be. A
     * régi kód az `is_numeric()`-re épülő `FloatRequired()`-del ezt elutasította, méghozzá
     * `Required 'church[lat]' is not a Float.` szöveggel — abból nem derül ki, mit kell
     * másképp csinálni.
     */
    public function testACommaIsAcceptedAsDecimalSeparator(): void {
        $this->post($this->mezok('vessző', [
            'church[lat]' => '47,4979',
            'church[lon]' => '19,0402',
        ]));

        $sor = $this->sor('vessző');
        self::assertNotNull($sor, 'a tizedesvesszős koordinátával is létre kell jönnie');
        self::assertEqualsWithDelta(47.4979, (float) $sor->lat, 0.00001);
        self::assertEqualsWithDelta(19.0402, (float) $sor->lon, 0.00001);
    }

    /**
     * #898: az űrlapon ott a megjegyzés-mező, de a mentés nem olvasta.
     *
     * Nem elég a `Church::create()` tömbjébe betenni: az `adminmegj` nincs a
     * `$fillable`-ben, tehát a tömeges értékadás CSENDBEN eldobja. Kimértem — a templom
     * létrejön, a megjegyzés helye üres.
     */
    public function testTheAdminNoteIsSaved(): void {
        $this->post($this->mezok('megjegyzés', [
            'church[adminmegj]' => 'Ezt a szerkesztő írta be.',
        ]));

        $sor = $this->sor('megjegyzés');
        self::assertNotNull($sor);
        self::assertStringContainsString('Ezt a szerkesztő írta be.', (string) $sor->adminmegj);
    }

    /** Felcserélt szélesség/hosszúság: gyakori hiba, és a térkép túloldalán kötne ki. */
    public function testAnOutOfRangeLatitudeIsRejected(): void {
        $valasz = $this->post($this->mezok('tartomány', [
            'church[lat]' => '190',
            'church[lon]' => '19',
        ]));

        self::assertNull($this->sor('tartomány'), 'érvénytelen koordinátával nem jöhet létre templom');
        self::assertStringContainsString('szélességi fok', $valasz);
        self::assertStringContainsString('felcserélve', $valasz);
    }

    /** Ami egyáltalán nem szám, arra érthető mondat jár, nem belső hibaszöveg. */
    public function testANonNumericCoordinateGetsAReadableMessage(): void {
        $valasz = $this->post($this->mezok('szöveg', [
            'church[lat]' => 'valahol Hajdúnánáson',
        ]));

        self::assertNull($this->sor('szöveg'));
        self::assertStringContainsString('nem szám', $valasz);
        self::assertStringNotContainsString('is not a Float', $valasz);
    }

    public function testAMissingCoordinateGetsAReadableMessage(): void {
        $valasz = $this->post($this->mezok('hiányzó', ['church[lat]' => '']));

        self::assertNull($this->sor('hiányzó'));
        self::assertStringContainsString('Hiányzik a szélességi fok', $valasz);
    }

    /**
     * Fél OSM-azonosító nem ér semmit: az `OSM::updateChurch()` az
     * `empty($osmtype) OR empty($osmid)` ágon kihagyja, a templom viszont úgy néz ki,
     * mintha kötve lenne.
     */
    public function testAHalfOsmIdentifierIsRejected(): void {
        $valasz = $this->post($this->mezok('fél-osm', ['church[osmid]' => '123456']));

        self::assertNull($this->sor('fél-osm'));
        self::assertStringContainsString('OSM azonosítóhoz', $valasz);
    }

    public function testACompleteOsmIdentifierIsStored(): void {
        $this->post($this->mezok('teljes-osm', [
            'church[osmid]'   => '123456',
            'church[osmtype]' => 'node',
        ]));

        $sor = $this->sor('teljes-osm');
        self::assertNotNull($sor);
        self::assertSame('123456', (string) $sor->osmid);
        self::assertSame('node', (string) $sor->osmtype);
    }

    /** OSM-azonosító nélkül is létre KELL jönnie — csak letiltva. */
    public function testAChurchCanBeCreatedWithoutAnOsmLink(): void {
        $this->post($this->mezok('osm-nélkül'));

        $sor = $this->sor('osm-nélkül');
        self::assertNotNull($sor, 'OSM-azonosító nélkül is létre kell jönnie');
        self::assertSame('n', (string) $sor->ok, 'a friss templom még nem engedélyezett');
    }
}
