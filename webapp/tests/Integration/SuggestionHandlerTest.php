<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * Két hiba egy helyen, a bejelentés nyomán („az adminfelületen nem lehet látni, ki
 * fogadta el a javaslatot, és amit adminként elfogadok, az vendégként jelenik meg").
 *
 * 1. NYITOTT VÉGPONT. Az elfogadás/elutasítás ágán SEMMILYEN jogosultság-ellenőrzés
 *    nem volt — a `handle()` csak a GET-nél nézte a `writeAccess`-t. Bárki,
 *    bejelentkezés nélkül, egyetlen POST-tal elfogadhatta vagy elutasíthatta bármelyik
 *    templom bármelyik javaslatát. Kipróbálva: sima curl, süti nélkül, HTTP 200.
 *    Ez egyben a „vendég" tünetet is magyarázza: tényleg vendégként ment át a művelet.
 *
 * 2. A KEZELŐ SEHOL NEM TÁROLÓDOTT. A tábla csak az állapotot ismerte, azt nem, hogy ki
 *    és mikor döntött. Nem elveszett az adat, hanem sosem keletkezett.
 */
class SuggestionHandlerTest extends TestCase {

    private const CHURCH_ID = 1;

    private string $baseUrl;

    protected function setUp(): void {
        parent::setUp();
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');
    }

    private function ujCsomag(): int {
        return (int) DB::table('cal_suggestion_packages')->insertGetId([
            'church_id' => self::CHURCH_ID,
            'sender_name' => 'Teszt Beküldő',
            'sender_email' => null,
            'state' => 'PENDING',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function post(string $url, array $adat, ?string $cookie = null): array {
        $fejlec = "Content-Type: application/json\r\n";
        if ($cookie !== null) {
            $fejlec .= "Cookie: " . $cookie . "\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => $fejlec,
            'content' => json_encode($adat), 'timeout' => 20, 'ignore_errors' => true,
        ]]);
        $valasz = @file_get_contents($this->baseUrl . $url, false, $ctx);
        $kod = 0;
        foreach ($http_response_header ?? [] as $sor) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $sor, $m)) {
                $kod = (int) $m[1];
            }
        }
        return ['code' => $kod, 'body' => (string) $valasz];
    }

    /** A lényeg: bejelentkezés nélkül NEM lehet dönteni a javaslatról. */
    public function testAnonymousRequestCannotRejectASuggestion(): void {
        $id = $this->ujCsomag();
        try {
            $valasz = $this->post('/calendar/suggestions/reject/' . $id, ['state' => 'REJECTED']);
            if ($valasz['code'] === 0) {
                $this->markTestSkipped('A futó példány nem érhető el.');
            }

            $this->assertSame(403, $valasz['code'], 'A vendég NEM utasíthat el javaslatot.');
            $this->assertSame('PENDING', DB::table('cal_suggestion_packages')->where('id', $id)->value('state'));
        } finally {
            DB::table('cal_suggestion_packages')->where('id', $id)->delete();
        }
    }

    public function testAnonymousRequestCannotAcceptASuggestion(): void {
        $id = $this->ujCsomag();
        try {
            $valasz = $this->post('/calendar/suggestions/accept/' . $id, ['state' => 'ACCEPTED']);
            if ($valasz['code'] === 0) {
                $this->markTestSkipped('A futó példány nem érhető el.');
            }

            $this->assertSame(403, $valasz['code'], 'A vendég NEM fogadhat el javaslatot.');
            $this->assertSame('PENDING', DB::table('cal_suggestion_packages')->where('id', $id)->value('state'));
        } finally {
            DB::table('cal_suggestion_packages')->where('id', $id)->delete();
        }
    }

    /**
     * A jogosultság-szabály marad a megszokott: nem csak az admin, hanem a templom
     * gondnoka és az egyházmegyei felelős is kezelheti a saját templomához érkezőt —
     * ugyanaz, amit a szerkesztő felület többi művelete használ.
     */
    public function testTheRuleIsTheSameWriteAccessUsedEverywhereElse(): void {
        $church = \Eloquent\Church::findOrFail(self::CHURCH_ID);

        $admin = new \User(DB::table('user')->where('jogok', 'LIKE', '%miserend%')->value('uid'));
        $this->assertTrue($church->checkWriteAccess($admin), 'Az adminnak kezelnie kell tudnia.');

        $vendeg = new \User();
        $this->assertFalse($church->checkWriteAccess($vendeg), 'A vendégnek nem szabad.');
    }

    /** A kezelő és az időpont mostantól tárolódik — eddig sehol nem. */
    public function testHandlerColumnsExist(): void {
        $oszlopok = array_map(
            static fn($c) => strtolower($c->Field),
            DB::select('SHOW COLUMNS FROM cal_suggestion_packages')
        );

        $this->assertContains('handled_by_user_id', $oszlopok);
        $this->assertContains('handled_at', $oszlopok);
    }

    /** A `*vendeg*` belső jelölő nem kerülhet a beküldő nevébe. */
    public function testTheGuestSentinelNeverBecomesASenderName(): void {
        $this->assertNull(\Html\Ajax\Calendar\Suggestions::cleanSenderName('*vendeg*'));
        $this->assertNull(\Html\Ajax\Calendar\Suggestions::cleanSenderName('*vendég*'));
        $this->assertNull(\Html\Ajax\Calendar\Suggestions::cleanSenderName('   '));
        $this->assertSame('Kovács Péter', \Html\Ajax\Calendar\Suggestions::cleanSenderName(' Kovács Péter '));
    }

    /** Emberi nevet mutatunk, nem bejelentkezési azonosítót — és vendégnél semmit. */
    public function testDisplayNamePrefersTheHumanName(): void {
        $admin = new \User(DB::table('user')->where('jogok', 'LIKE', '%miserend%')->value('uid'));
        $nev = \Html\Ajax\Calendar\Suggestions::displayName($admin);
        $this->assertNotNull($nev);
        $this->assertStringNotContainsString('vendeg', mb_strtolower($nev));

        $this->assertNull(\Html\Ajax\Calendar\Suggestions::displayName(new \User()));
    }
}
