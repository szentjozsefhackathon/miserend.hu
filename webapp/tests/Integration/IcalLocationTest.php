<?php

use PHPUnit\Framework\TestCase;

/**
 * #831: helyszín az iCal-eseményekben.
 *
 * A `LOCATION` és a `GEO` eddig CSAK akkor került az eseménybe, ha az alkalomnak volt
 * saját koordinátája (#431). A misék túlnyomó része viszont a templomban van — azokban
 * az eseményekben tehát semmilyen helyszín nem szerepelt. Élőben ellenőrizve: a
 * `/templom/:id/ical` kimenetében `SUMMARY` után rögtön `END:VEVENT` jött.
 *
 * Aki feliratkozik a naptárra, pontosan ezt veszíti el: a telefonja nem tudja térképen
 * megmutatni, és nem tud útvonalat tervezni oda. A naptár-export fő haszna épp az, hogy
 * a mise bekerül a saját naptáradba — hely nélkül fél adat.
 *
 * A régi `//TODO: bele a LOCATION és GEO` pontosan erre mutatott.
 */
final class IcalLocationTest extends TestCase {

    /** @param array<string,mixed> $church  @param array<string,mixed> $mass */
    private function esemeny(array $mass, array $church): array {
        $ical = (new ReflectionClass(\Html\Church\Ical::class))->newInstanceWithoutConstructor();
        $ical->church = (object) $church;

        $metodus = new ReflectionMethod(\Html\Church\Ical::class, 'createCalendarEvent');
        $metodus->setAccessible(true);

        return $metodus->invoke($ical, $mass + [
            'start_date' => date('Y-m-d\TH:i:s'),
            'mass_id' => 1,
            'generated_period_id' => 1,
            'duration_minutes' => 60,
            'title' => 'Szentmise',
        ]);
    }

    private function templom(): array {
        return ['id' => 1, 'nev' => 'Nagy Szent Teréz-templom', 'cim' => '7300 Komló, Templom tér 1.',
                'lat' => 46.171093, 'lon' => 18.093341];
    }

    /** @param string[] $lines */
    private function sor(array $lines, string $kulcs): ?string {
        foreach ($lines as $sor) {
            if (str_starts_with($sor, $kulcs . ':')) {
                return $sor;
            }
        }
        return null;
    }

    // ---- a templomi mise (a misék túlnyomó része) -----------------------------

    public function testATemplomiMiseIsKapKoordinatat(): void {
        $lines = $this->esemeny([], $this->templom());

        self::assertSame('GEO:46.171093;18.093341', $this->sor($lines, 'GEO'));
    }

    /**
     * A megnevezésben a név ÉS a cím — a naptáralkalmazás így tud útvonalat tervezni.
     *
     * A vessző `\,` alakban megy ki: az RFC 5545 szerint a szöveges mezőkben a vessző
     * elválasztó jelentésű, tehát escape-elni kell. Enélkül a naptáralkalmazás három
     * külön értéknek olvasná a nevet és a cím két felét.
     */
    public function testATemplomiMiseHelyszineANevEsACim(): void {
        $lines = $this->esemeny([], $this->templom());

        self::assertSame(
            'LOCATION:Nagy Szent Teréz-templom\, 7300 Komló\, Templom tér 1.',
            $this->sor($lines, 'LOCATION')
        );
    }

    // ---- az alkalom saját helyszíne (#431) elsőbbséget élvez ------------------

    /**
     * Ez a lényeg: ha a mise NEM a templomban van, a templom koordinátája rossz válasz
     * lenne — a hívő oda menne, ahol nincs mise.
     */
    public function testASajatHelyszinFelulirjaATemplomet(): void {
        $lines = $this->esemeny(
            ['location_lat' => 46.18, 'location_lon' => 20.03, 'location_name' => 'Röszkei puszta'],
            $this->templom()
        );

        self::assertSame('GEO:46.18;20.03', $this->sor($lines, 'GEO'));
        self::assertSame('LOCATION:Röszkei puszta', $this->sor($lines, 'LOCATION'));
    }

    /** Név nélküli saját helyszínnél a koordináta marad — az legalább pontos. */
    public function testNevNelkuliSajatHelyszinnelIsAKoordinataAzErvenyes(): void {
        $lines = $this->esemeny(
            ['location_lat' => 46.18, 'location_lon' => 20.03],
            $this->templom()
        );

        self::assertSame('GEO:46.18;20.03', $this->sor($lines, 'GEO'));
        self::assertNull($this->sor($lines, 'LOCATION'), 'a templom neve itt félrevezetne');
    }

    // ---- hiányzó adat --------------------------------------------------------

    /**
     * 47 templomnak nincs koordinátája (#497). Náluk a GEO kimarad — de a NÉV attól
     * még hasznos, abból a naptáralkalmazás is tud keresni.
     */
    public function testKoordinataNelkuliTemplomnalCsakANevMegy(): void {
        $lines = $this->esemeny([], ['id' => 1, 'nev' => 'Kápolna', 'cim' => 'Fő utca 1.',
                                     'lat' => null, 'lon' => null]);

        self::assertNull($this->sor($lines, 'GEO'));
        self::assertSame('LOCATION:Kápolna\, Fő utca 1.', $this->sor($lines, 'LOCATION'));
    }

    /** A 0 nem koordináta, hanem a hiányzó érték jelölése — nem küldhetjük ki. */
    public function testANullaKoordinatatNemKuldjukKi(): void {
        $lines = $this->esemeny([], ['id' => 1, 'nev' => 'Kápolna', 'cim' => '',
                                     'lat' => 0, 'lon' => 0]);

        self::assertNull($this->sor($lines, 'GEO'), 'a 0;0 a Guineai-öbölbe vinné a hívőt');
    }

    /** Templom-adat nélkül se hasaljon el az export. */
    public function testTemplomAdatNelkulSemSzallEl(): void {
        $ical = (new ReflectionClass(\Html\Church\Ical::class))->newInstanceWithoutConstructor();
        $metodus = new ReflectionMethod(\Html\Church\Ical::class, 'createCalendarEvent');
        $metodus->setAccessible(true);

        $lines = $metodus->invoke($ical, [
            'start_date' => date('Y-m-d\TH:i:s'), 'mass_id' => 1,
            'generated_period_id' => 1, 'duration_minutes' => 60, 'title' => 'Szentmise',
        ]);

        self::assertNotSame([], $lines);
        self::assertNull($this->sor($lines, 'GEO'));
    }
}
