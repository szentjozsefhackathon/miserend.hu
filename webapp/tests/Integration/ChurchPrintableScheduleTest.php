<?php

use PHPUnit\Framework\TestCase;

/**
 * #36: nyomtatható miserend.
 *
 * A jegyben a TARTALOM kérdése maradt nyitva („nem egészen értem, hogyan lehetne
 * praktikus a végtelen miseidőszakok nyomtatása"). A tesztek ezért nem csak azt mérik,
 * hogy a lap előáll, hanem a meghozott döntéseket is rögzítik — hogy később látszódjon,
 * mit MIÉRT így csinálunk:
 *
 *  - csak az ÉRVÉNYBEN LÉVŐ rend kerül papírra, nem az összes időszak;
 *  - a lap megmondja, meddig érvényes (ez a válasz a „végtelen időszak" kérdésre);
 *  - nap szerint csoportosítunk, nem időszaknév szerint;
 *  - a dátum abszolút, sosem relatív („ma", „holnap") — a lap hetekig ott lóg;
 *  - a szabad szöveges mezőkből sima szöveg lesz, nem nyers HTML.
 *
 * A lapot ugyanaz a motor tölti fel, mint a naptárat és a keresőt, tehát a papír nem
 * mondhat mást, mint a weboldal.
 */
class ChurchPrintableScheduleTest extends TestCase
{
    /** Szentendre, Péter-Pál: teljes heti rend, nyári/téli időszakkal. */
    private const TESZT_TEMPLOM = 1;

    private function lap(int $tid = self::TESZT_TEMPLOM): \Html\Church\Nyomtat
    {
        return new \Html\Church\Nyomtat([$tid]);
    }

    public function testAlapadatokRakerulnekALapra(): void
    {
        $lap = $this->lap();

        self::assertSame(self::TESZT_TEMPLOM, (int) $lap->church->id);
        self::assertNotSame('', (string) $lap->church->nev);
        self::assertSame('church/nyomtat.twig', $lap->template);
    }

    /** A heti rend napokra bontva áll elő, nem időszaknév szerint. */
    public function testAHetiRendNapokRaBontvaAllElo(): void
    {
        $lap = $this->lap();

        self::assertNotEmpty($lap->hetiRend, 'ennek a templomnak van rendszeres miserendje');

        $napnevek = array_column($lap->hetiRend, 'nap');
        foreach ($napnevek as $nev) {
            self::assertContains($nev,
                ['Vasárnap', 'Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat'],
                'a csoportosítás nem nap szerint történt: ' . $nev);
        }
        self::assertSame(count($napnevek), count(array_unique($napnevek)),
            'ugyanaz a nap többször szerepel');
    }

    /** Vasárnappal kezdünk: a miserendet így olvassák. */
    public function testAHetVasarnappalKezdodik(): void
    {
        $lap = $this->lap();
        $napnevek = array_column($lap->hetiRend, 'nap');

        if (!in_array('Vasárnap', $napnevek, true)) {
            self::markTestSkipped('ezen a templomon nincs vasárnapi mise');
        }
        self::assertSame('Vasárnap', $napnevek[0]);
    }

    /** Minden alkalomnak van időpontja és megnevezése — üres cella papíron használhatatlan. */
    public function testMindenAlkalomnakVanIdopontjaEsMegnevezese(): void
    {
        $lap = $this->lap();

        foreach ($lap->hetiRend as $nap) {
            self::assertNotEmpty($nap['alkalmak']);
            foreach ($nap['alkalmak'] as $alkalom) {
                self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $alkalom['ido']);
                self::assertNotSame('', trim($alkalom['cim']));
            }
        }
    }

    /**
     * A napon belül időrendben — a szem fentről lefelé olvassa.
     */
    public function testAzAlkalmakIdorendbenAllnak(): void
    {
        $lap = $this->lap();

        foreach ($lap->hetiRend as $nap) {
            $idok = array_column($nap['alkalmak'], 'ido');
            $rendezett = $idok;
            sort($rendezett);
            self::assertSame($rendezett, $idok, $nap['nap'] . ': nem időrendben');
        }
    }

    /**
     * A lényeg: a lap megmondja, meddig érvényes.
     *
     * Ez a válasz a jegy nyitott kérdésére. Nem az összes időszakot nyomtatjuk ki
     * egymás mellé (papíron nem derülne ki, melyik él MA), hanem a mostanit — és
     * mellé azt, mikor avul el.
     */
    public function testALapMegmondjaMeddigErvenyes(): void
    {
        $lap = $this->lap();

        self::assertNotNull($lap->ervenyesigSzoveg,
            'ennek a templomnak van évszakos rendje, tehát van lejárat');
        self::assertMatchesRegularExpression('/^\d{4}\. [a-záéíóöőúüű]+ \d{1,2}-ig$/u',
            $lap->ervenyesigSzoveg);
    }

    /**
     * A dátum SOSEM lehet relatív. A `miserend_date` szűrő „ma"/„holnap"/„szerda"
     * alakot ad, ami képernyőn hasznos — papíron viszont értelmetlen, mert a lap
     * hetekig ott lóg a falon.
     */
    public function testADatumokAbszolutak(): void
    {
        $lap = $this->lap();

        foreach ([$lap->nyomtatasSzoveg, $lap->ervenyesigSzoveg, $lap->ellenorzesSzoveg] as $szoveg) {
            if ($szoveg === null) {
                continue;
            }
            self::assertMatchesRegularExpression('/^\d{4}\./', (string) $szoveg,
                'relatív dátum került a nyomtatott lapra: ' . $szoveg);
        }
    }

    /**
     * A szabad szöveges mezők HTML-t tartalmaznak (`<strong>`, `<br />`, entitások).
     * Papírra sima szöveg való — és így nyers HTML-t sem kell kiengedni a sablonba.
     */
    public function testASzabadSzovegesMezokbolSimaSzovegLesz(): void
    {
        $lap = $this->lap();

        foreach (['plebaniaSzoveg', 'megjegyzesSzoveg', 'bucsuSzoveg'] as $mezo) {
            $ertek = (string) $lap->{$mezo};
            self::assertStringNotContainsString('<', $ertek, $mezo . ': maradt benne HTML');
            self::assertStringNotContainsString('&amp;', $ertek, $mezo . ': maradt benne entitás');
            self::assertStringNotContainsString('&oacute;', $ertek, $mezo . ': maradt benne entitás');
        }
    }

    /** Az alkalmi (ünnepi) miséket dátummal és a nap nevével írjuk ki. */
    public function testAzAlkalmiMisekDatummalSzerepelnek(): void
    {
        $lap = $this->lap();

        self::assertIsArray($lap->alkalmak);

        foreach ($lap->alkalmak as $nap) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $nap['datum']);
            self::assertMatchesRegularExpression(
                '/^\d{4}\. [a-záéíóöőúüű]+ \d{1,2}, [a-záéíóöőúüű]+$/u',
                $nap['datumSzoveg'],
                'az alkalmi dátum nem olvasható alakban van'
            );
        }
    }

    /**
     * Nem publikus templom adata ezen az úton sem szivároghat ki.
     *
     * Külön útvonal, külön belépési pont — a templomoldal `readAccess`-ellenőrzését
     * itt is ki kell mondani, nem elég, hogy amott megvan.
     */
    public function testNemPublikusTemplomotNemAdunkKi(): void
    {
        $rejtett = \Illuminate\Database\Capsule\Manager::table('templomok')
            ->where('ok', '<>', 'i')->value('id');

        if (!$rejtett) {
            self::markTestSkipped('nincs nem publikus templom a teszt-adatbázisban');
        }

        $this->expectException(\Exception::class);
        new \Html\Church\Nyomtat([(int) $rejtett]);
    }

    /** Ismeretlen templomra nem adunk üres lapot. */
    public function testIsmeretlenTemplomraHibatDobunk(): void
    {
        $this->expectException(\Exception::class);
        new \Html\Church\Nyomtat([999999999]);
    }

    public function testAzonositoNelkulHibatDobunk(): void
    {
        $this->expectException(\Exception::class);
        new \Html\Church\Nyomtat([]);
    }
}
