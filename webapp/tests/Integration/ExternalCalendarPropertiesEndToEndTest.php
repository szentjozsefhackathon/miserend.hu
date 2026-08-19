<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #157: a naptár-import a bejegyzés minden tulajdonságát átveszi — végponttól végpontig.
 *
 * Az `IcalEventPropertiesTest` a felismerést méri önmagában; itt az a kérdés, hogy a
 * teljes úton — ICS → parser → CalMass → adatbázis — tényleg megérkezik-e.
 *
 * A fixtúra a #157-ben megadott szegedi minta-naptár ALAKJÁT követi: onnan valók a
 * címek, a `LOCATION:templom`, a folytatósoros DESCRIPTION és a `\,` escape is.
 * A valódi feeden mérve ez 949 eseményből 37 nem magyar, 14 nem római katolikus,
 * 43 típusos és 12 helyszínes misét eredményez — mindezt eddig eldobtuk.
 */
final class ExternalCalendarPropertiesEndToEndTest extends TestCase {

    private const TID = 1;

    private int $naptarId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $naptar = \Eloquent\ExternalCalendar::create([
            'church_id' => self::TID,
            'name' => 'Naptár 1',
            'url' => 'https://calendar.google.com/calendar/ical/e2e/public/basic.ics',
            'active' => 1,
        ]);
        $this->naptarId = (int) $naptar->id;
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function esemeny(string $uid, string $summary, array $extra = []): string {
        $sorok = ["BEGIN:VEVENT", "UID:$uid@example.test", "SUMMARY:$summary",
                  "DTSTART:20260809T100000Z", "DURATION:PT1H"];
        foreach ($extra as $kulcs => $ertek) {
            $sorok[] = "$kulcs:$ertek";
        }
        $sorok[] = "END:VEVENT";

        return implode("\r\n", $sorok) . "\r\n";
    }

    private function importal(string ...$esemenyek): void {
        $ics = "BEGIN:VCALENDAR\r\nX-WR-CALNAME:Szent József templom - Miserend\r\n"
             . implode('', $esemenyek) . "END:VCALENDAR\r\n";

        \ExternalCalendarImporter::replaceFromIcs($ics, self::TID, $this->naptarId);
    }

    private function mise(string $cimReszlet): \Eloquent\CalMass {
        return \Eloquent\CalMass::where('external_calendar_id', $this->naptarId)
            ->where('title', 'LIKE', "%$cimReszlet%")
            ->firstOrFail();
    }

    /** Ahol a részlet másik címre is illeszkedne (a LIKE nem érzékeny a kis/nagybetűre). */
    private function pontosMise(string $cim): \Eloquent\CalMass {
        return \Eloquent\CalMass::where('external_calendar_id', $this->naptarId)
            ->where('title', $cim)
            ->firstOrFail();
    }

    /** A nyelv eddig MINDIG magyar volt. */
    public function testAnyelvAcimbolJon(): void {
        $this->importal(
            $this->esemeny('a', 'Mass in English - Angol nyelvű szentmise (P. Elek)'),
            $this->esemeny('b', 'Szentmise (P. Elek)')
        );

        self::assertSame('en', $this->mise('English')->lang);
        self::assertSame('hu', $this->pontosMise('Szentmise (P. Elek)')->lang);
    }

    public function testAszabvanyosLanguageParaméterIsMegerkezik(): void {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:c@example.test\r\n"
             . "SUMMARY;LANGUAGE=de:Heilige Messe\r\n"
             . "DTSTART:20260809T100000Z\r\nDURATION:PT1H\r\n"
             . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        \ExternalCalendarImporter::replaceFromIcs($ics, self::TID, $this->naptarId);

        $mise = $this->mise('Heilige');
        self::assertSame('de', $mise->lang);
        // A paraméteres SUMMARY korábban egyáltalán nem illeszkedett: a cím elveszett.
        self::assertSame('Heilige Messe', $mise->title);
    }

    /** A rítus eddig MINDIG római katolikus volt. */
    public function testAritusAcimbolJon(): void {
        $this->importal(
            $this->esemeny('d', '(Régi rítusú mise)'),
            $this->esemeny('e', 'Szentmise (P. Elek)')
        );

        self::assertSame('TRADITIONAL', $this->mise('Régi rítusú')->rite);
        self::assertSame('ROMAN_CATHOLIC', $this->pontosMise('Szentmise (P. Elek)')->rite);
    }

    /** A helyszín, a leírás és a típus eddig sehova nem került. */
    public function testAhelyszinAleirasEsAtipusMegerkezik(): void {
        $this->importal($this->esemeny(
            'f',
            'Szentmise kisgyermekes családoknak (P. SZŐCS)',
            ['LOCATION' => 'Hittanterem', 'DESCRIPTION' => 'Kapcsolat: Csepi László', 'GEO' => '46.2530;20.1414']
        ));

        $mise = $this->mise('kisgyermekes');
        self::assertSame('Hittanterem', $mise->location_name);
        self::assertSame('Kapcsolat: Csepi László', $mise->comment);
        self::assertSame(['FAMILY'], (array) $mise->types);
        self::assertEquals(46.2530, (float) $mise->location_lat);
        self::assertEquals(20.1414, (float) $mise->location_lon);
    }

    /**
     * Ami épp azt mondja, hogy NINCS mise, abból nem lesz mise.
     *
     * A valódi feedben 17 ilyen van. Eddig mind bekerült, tehát a miserend pont az
     * ellenkezőjét állította annak, amit a naptár gazdája kiírt.
     */
    public function testAzElmaradtAlkalombolNemLeszMise(): void {
        $this->importal(
            $this->esemeny('g', 'NINCS Szentmise'),
            $this->esemeny('h', 'ELMARAD! Szentségimádás és gyóntatás'),
            $this->esemeny('i', 'Szentmise (P. Elek)')
        );

        self::assertSame(1, \Eloquent\CalMass::where('external_calendar_id', $this->naptarId)->count());
        self::assertSame('Szentmise (P. Elek)', $this->pontosMise('Szentmise (P. Elek)')->title);
    }

    /** A `\,` visszafejtése: eddig egy fölösleges kötőjelet szúrt a címbe. */
    public function testAzEscapeltVesszoNemRontjaElAcimet(): void {
        $this->importal($this->esemeny('j', 'Szentmise (P. Elek\\, gyóntat: -)'));

        self::assertSame('Szentmise (P. Elek, gyóntat: -)', $this->mise('gyóntat')->title);
    }

    /**
     * A jegy lényege: a MÁSIK naptár importja nem törli ezt.
     *
     * Eddig a templom minden importált miséje ment, tehát templomonként egyetlen
     * naptár működhetett.
     */
    public function testAmasikNaptarImportjaNemTorliEzt(): void {
        $this->importal($this->esemeny('k', 'Szentmise (P. Elek)'));

        $masik = \Eloquent\ExternalCalendar::create([
            'church_id' => self::TID, 'name' => 'Naptár 2',
            'url' => 'https://calendar.google.com/calendar/ical/masik/public/basic.ics', 'active' => 1,
        ]);
        \ExternalCalendarImporter::replaceFromIcs(
            "BEGIN:VCALENDAR\r\n" . $this->esemeny('l', 'Szentségimádás') . "END:VCALENDAR\r\n",
            self::TID,
            (int) $masik->id
        );

        self::assertSame(1, \Eloquent\CalMass::where('external_calendar_id', $this->naptarId)->count(),
            'az első naptár miséje megmaradt');
        self::assertSame(1, \Eloquent\CalMass::where('external_calendar_id', $masik->id)->count());
    }

    /** A saját naptár újraimportja viszont cserél, nem halmoz. */
    public function testAsajatUjraimportCserel(): void {
        $this->importal($this->esemeny('m', 'Régi szentmise'));
        $this->importal($this->esemeny('n', 'Új szentmise'));

        $misek = \Eloquent\CalMass::where('external_calendar_id', $this->naptarId)->get();
        self::assertCount(1, $misek);
        self::assertSame('Új szentmise', $misek->first()->title);
    }

    /** Az importált mise a szerkesztő számára továbbra is felismerhető. */
    public function testAzImportaltMiseJeloltMarad(): void {
        $this->importal($this->esemeny('o', 'Szentmise (P. Elek)'));

        $mise = $this->pontosMise('Szentmise (P. Elek)');
        self::assertTrue($mise->imported, 'a jelölés mostantól az external_calendar_id');
        self::assertSame([$mise->id], \Eloquent\CalMass::importedIdsAmong([$mise->id]));
    }
}
