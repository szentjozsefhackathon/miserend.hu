<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

class ExternalCalendarImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection()->rollBack();
        parent::tearDown();
    }

    public function testImportReplacesOnlyEarlierExternalMasses(): void
    {
        $manual = $this->createMass('Kézzel felvitt mise', null);
        $oldExternal = $this->createMass('Régi import', ExternalCalendarImporter::IMPORT_MARKER);

        $created = ExternalCalendarImporter::replaceFromIcs($this->validIcs(), 1);

        $this->assertSame(1, $created);
        $this->assertTrue(Eloquent\CalMass::whereKey($manual->id)->exists());
        $this->assertFalse(Eloquent\CalMass::whereKey($oldExternal->id)->exists());
        $this->assertDatabaseMassExists('Új vasárnapi mise', ExternalCalendarImporter::IMPORT_MARKER);
    }

    public function testMalformedFeedKeepsEarlierImportUntouched(): void
    {
        $oldExternal = $this->createMass('Megőrzendő korábbi import', ExternalCalendarImporter::IMPORT_MARKER);
        $brokenIcs = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Hiányos mise\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        try {
            ExternalCalendarImporter::replaceFromIcs($brokenIcs, 1);
            $this->fail('A hiányos eseményt tartalmazó feednek hibával kell leállnia.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('DTSTART is missing', $e->getMessage());
        }

        $this->assertTrue(Eloquent\CalMass::whereKey($oldExternal->id)->exists());
    }

    public function testOnlyPublicHttpsCalendarUrlsAreAccepted(): void
    {
        $this->assertTrue(ExternalCalendarImporter::isAllowedCalendarUrl(
            'https://calendar.google.com/calendar/ical/example/public/basic.ics',
            ['142.250.74.78']
        ));
        $this->assertFalse(ExternalCalendarImporter::isAllowedCalendarUrl('http://example.com/masses.ics', ['93.184.216.34']));
        $this->assertFalse(ExternalCalendarImporter::isAllowedCalendarUrl('https://localhost/masses.ics', ['127.0.0.1']));
        $this->assertFalse(ExternalCalendarImporter::isAllowedCalendarUrl('https://10.0.0.4/masses.ics', ['10.0.0.4']));
    }

    public function testGoogleCalendarUtcAndFoldedFieldsAreImportedCorrectly(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\n"
            . "UID:google-1@example.test\r\n"
            . "SUMMARY:Magyar nyelvű vasárnapi \r\n szentmise\r\n"
            . "DTSTART:20260809T100000Z\r\n"
            . "DURATION:PT1H30M\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        ExternalCalendarImporter::replaceFromIcs($ics, 1);

        $mass = Eloquent\CalMass::where('church_id', 1)
            ->where('comment', ExternalCalendarImporter::IMPORT_MARKER)
            ->firstOrFail();
        $this->assertSame('Magyar nyelvű vasárnapi szentmise', $mass->title);
        $this->assertSame('2026-08-09T12:00:00', $mass->start_date);
        $this->assertSame(['hours' => 1, 'minutes' => 30], $mass->duration);
    }

    /**
     * #723: a naptár utolsó módosítása lesz a templom frissesség-dátuma, ha újabb.
     */
    public function testFeedLastModifiedIsReportedBack(): void
    {
        $ics = "BEGIN:VCALENDAR\r\n"
            . "BEGIN:VEVENT\r\nSUMMARY:Régi\r\nDTSTART:20260301T080000Z\r\n"
            . "LAST-MODIFIED:20250104T101500Z\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nSUMMARY:Újabb\r\nDTSTART:20260302T080000Z\r\n"
            . "LAST-MODIFIED:20260214T091500Z\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";

        $modifiedOn = null;
        ExternalCalendarImporter::replaceFromIcs($ics, 1, $modifiedOn);

        $this->assertSame('2026-02-14', $modifiedOn);
    }

    /**
     * A DTSTAMP-ot a Google az EXPORTÁLÁSKOR tölti ki, tehát minden lekérésnél mai.
     * Ha azt vennénk alapul, minden naptár örökké frissnek látszana — pont az ellenkezője
     * annak, amit a #723 kér. LAST-MODIFIED nélküli feednél ezért nem nyúlunk semmihez.
     */
    public function testFeedWithoutLastModifiedDoesNotTouchFreshness(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\n"
            . "SUMMARY:Mise\r\nDTSTART:20260301T080000Z\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $modifiedOn = null;
        ExternalCalendarImporter::replaceFromIcs($ics, 1, $modifiedOn);

        $this->assertNull($modifiedOn);
    }

    /** Elrontott naptár ne tolhassa a jövőbe a frissesség-dátumot. */
    public function testFutureLastModifiedIsIgnored(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\n"
            . "SUMMARY:Mise\r\nDTSTART:20260301T080000Z\r\n"
            . "LAST-MODIFIED:" . gmdate('Ymd\THis\Z', strtotime('+3 days')) . "\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $modifiedOn = null;
        ExternalCalendarImporter::replaceFromIcs($ics, 1, $modifiedOn);

        $this->assertNull($modifiedOn);
    }

    /**
     * A `templomok` írásait ez a teszt SAJÁT MAGA állítja vissza, nem a setUp
     * tranzakciójára bízza: az \Eloquent\Church::save() az Elasticsearchöt is frissíti,
     * és ezen az úton a mentés túléli a rollbacket — a szomszéd teszt pedig már a
     * beszivárgott értéket látná.
     */
    public function testFreshnessMovesForwardOnlyNeverBackwards(): void
    {
        $eredeti = Eloquent\Church::findOrFail(1)->frissites;

        try {
            $this->setFrissites(null);
            $this->assertTrue(ExternalCalendarImporter::touchChurchFreshness(1, '2026-02-14'));
            $this->assertSame('2026-02-14', (string) Eloquent\Church::findOrFail(1)->frissites);

            // Régebbi dátum: előre mozdul.
            $this->setFrissites('2020-01-01');
            $this->assertTrue(ExternalCalendarImporter::touchChurchFreshness(1, '2026-02-14'));
            $this->assertSame('2026-02-14', (string) Eloquent\Church::findOrFail(1)->frissites);

            // A kézi frissítés újabb: a naptár nem húzhatja vissza.
            $this->setFrissites('2026-07-01');
            $this->assertFalse(ExternalCalendarImporter::touchChurchFreshness(1, '2026-02-14'));
            $this->assertSame('2026-07-01', (string) Eloquent\Church::findOrFail(1)->frissites);
        } finally {
            $this->setFrissites($eredeti);
        }
    }

    private function setFrissites(?string $ertek): void
    {
        DB::table('templomok')->where('id', 1)->update(['frissites' => $ertek]);
    }

    public function testCronReportsFailedCalendarInsteadOfMarkingTheRunSuccessful(): void
    {
        $calendar = Eloquent\ExternalCalendar::create([
            'church_id' => 1,
            'name' => 'Hibás tesztnaptár',
            'url' => 'https://calendar.example.test/masses.ics',
            'active' => 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Church #1');

        try {
            ExternalCalendarImporter::importAllExternalCalendars(
                static function (): void {
                    throw new RuntimeException('A külső naptár nem érhető el.');
                }
            );
        } finally {
            $calendar->refresh();
            $this->assertNull($calendar->last_import_at);
        }
    }

    public function testSavingCalendarUrlReactivatesTheExistingCalendar(): void
    {
        $calendar = Eloquent\ExternalCalendar::create([
            'church_id' => 1,
            'name' => 'Google Calendar',
            'url' => 'https://calendar.google.com/old.ics',
            'active' => 0,
        ]);

        ExternalCalendarImporter::saveCalendarUrl(
            1,
            'https://calendar.google.com/calendar/ical/example/public/basic.ics',
            ['142.250.74.78']
        );

        $calendar->refresh();
        $this->assertSame(1, $calendar->active);
        $this->assertSame('https://calendar.google.com/calendar/ical/example/public/basic.ics', $calendar->url);
        $this->assertSame(1, Eloquent\ExternalCalendar::where('church_id', 1)->count());
    }

    public function testMissingImportCronIsRegisteredForExistingDatabases(): void
    {
        Eloquent\Cron::whereIn('class', [ExternalCalendarImporter::class, '\\ExternalCalendarImporter'])
            ->where('function', 'importAllExternalCalendars')
            ->delete();

        $cron = ExternalCalendarImporter::ensureCronRegistered();

        $this->assertSame('\\ExternalCalendarImporter', $cron->class);
        $this->assertSame('importAllExternalCalendars', $cron->function);
        $this->assertSame('1 day', $cron->frequency);
        $this->assertNotSame('0000-00-00 00:00:00', $cron->deadline_at);
    }

    /*
     * #638: a registry hiányzó sorait az init felveszi, a MEGLÉVŐKET nem bántja —
     * különben minden deploy elfelejtené, mikor futott utoljára sikeresen egy munka.
     */
    public function testCronInitAddsMissingJobsAndKeepsExistingHistory(): void
    {
        $existing = Eloquent\Cron::where('class', '\\ExternalApi\\ElasticsearchApi')
            ->where('function', 'updateMasses')->firstOrFail();
        $existing->lastsuccess_at = '2026-01-26 17:58:39';
        $existing->frequency = '12 hours'; // kézzel hangolt érték
        $existing->save();

        Eloquent\Cron::whereIn('class', [ExternalCalendarImporter::class, '\\ExternalCalendarImporter'])
            ->where('function', 'importAllExternalCalendars')
            ->delete();

        $created = Eloquent\Cron::init();

        $this->assertContains('\\ExternalCalendarImporter->importAllExternalCalendars()', $created);
        $this->assertTrue(
            Eloquent\Cron::where('class', '\\ExternalCalendarImporter')
                ->where('function', 'importAllExternalCalendars')->exists()
        );

        $existing->refresh();
        $this->assertSame('12 hours', $existing->frequency);
        $this->assertSame('2026-01-26 17:58:39', (string) $existing->lastsuccess_at);

        // Másodszor futtatva már nincs mit felvenni.
        $this->assertSame([], Eloquent\Cron::init());
    }

    /*
     * #638: minden registry-beli munka osztálya és metódusa tényleg létezik — így a
     * lista nem tud némán elavulni egy átnevezés után.
     */
    public function testEveryRegisteredCronJobIsCallable(): void
    {
        $registry = Eloquent\Cron::registry();
        $this->assertNotEmpty($registry);

        foreach ($registry as $job) {
            $this->assertTrue(
                class_exists($job['class']),
                'Nincs ilyen osztály a cron-registryben: ' . $job['class']
            );
            $this->assertTrue(
                method_exists($job['class'], $job['function']),
                'Nincs ilyen metódus: ' . $job['class'] . '->' . $job['function'] . '()'
            );
        }
    }

    private function createMass(string $title, ?string $comment): Eloquent\CalMass
    {
        return Eloquent\CalMass::create([
            'church_id' => 1,
            'period_id' => null,
            'title' => $title,
            'types' => [],
            'rite' => 'ROMAN_CATHOLIC',
            'start_date' => '2026-08-09T10:00:00',
            'duration' => ['hours' => 1, 'minutes' => 0],
            'rrule' => null,
            'exdate' => null,
            'lang' => 'hu',
            'comment' => $comment,
        ]);
    }

    private function assertDatabaseMassExists(string $title, string $comment): void
    {
        $this->assertTrue(Eloquent\CalMass::where([
            'church_id' => 1,
            'title' => $title,
            'comment' => $comment,
        ])->exists());
    }

    private function validIcs(): string
    {
        return "BEGIN:VCALENDAR\r\n"
            . "VERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\n"
            . "UID:mass-1@example.test\r\n"
            . "SUMMARY:Új vasárnapi mise\r\n"
            . "DTSTART;TZID=Europe/Budapest:20260809T100000\r\n"
            . "DTEND;TZID=Europe/Budapest:20260809T110000\r\n"
            . "END:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }
}
