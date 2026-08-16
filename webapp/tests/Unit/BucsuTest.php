<?php

use PHPUnit\Framework\TestCase;

/**
 * #568: a szabad szöveges `bucsu` mező gépi olvasása.
 *
 * A tesztek zöme VALÓDI, az adatbázisból vett szöveggel dolgozik — a mező húsz év
 * alatt gyűlt, kézzel írt tartalom, és a hibái is valódiak (elgépelt ünnepnév,
 * hiányzó pont, más címke, csupa nagybetű, üresen hagyott címke).
 */
class BucsuTest extends TestCase {

    // ---- a leggyakoribb alak -------------------------------------------------

    public function testFixDatumuBucsu(): void {
        $r = \Bucsu::parse('Búcsú: augusztus 15.');

        self::assertSame('fixed', $r['bucsu']['type']);
        self::assertSame(8, $r['bucsu']['month']);
        self::assertSame(15, $r['bucsu']['day']);
    }

    public function testBucsuEsSzentsegimadasEgyMezoben(): void {
        $r = \Bucsu::parse('Búcsú: szeptember 14. Szentségimádási nap: január 3.');

        self::assertSame([9, 14], [$r['bucsu']['month'], $r['bucsu']['day']]);
        self::assertSame([1, 3], [$r['szentsegimadas']['month'], $r['szentsegimadas']['day']]);
    }

    /** Ez 47 templomnál fordul elő: a búcsú-címke ott van, de üresen. */
    public function testUresBucsuCimkeNemHiba(): void {
        $r = \Bucsu::parse('Búcsú: Szentségimádási nap: május 14.');

        self::assertNull($r['bucsu']);
        self::assertSame([5, 14], [$r['szentsegimadas']['month'], $r['szentsegimadas']['day']]);
        self::assertSame('', $r['unparsed'],
            'A kitöltetlen címke hiányzó adat, nem értelmezési hiba.');
    }

    // ---- amit a naiv minta nem talált meg ------------------------------------

    public function testMasCimkeIsSzamit(): void {
        self::assertSame([10, 9], self::honapNap(\Bucsu::parse('A templom ünnepe:október 9.')['bucsu']));
    }

    public function testHianyzoPontUtanIsFelismeri(): void {
        self::assertSame([6, 23], self::honapNap(\Bucsu::parse('Búcsú: június 23')['bucsu']));
    }

    public function testRovidiettHonapnev(): void {
        self::assertSame([3, 19], self::honapNap(\Bucsu::parse('Márc. 19. Szt. József: kápolnabúcsú')['bucsu']));
    }

    public function testCsupaNagybetusZajKozottIsMegtalalja(): void {
        $r = \Bucsu::parse('SZŰZ MÁRIA MENNYBEVÉTELE (NAGYBOLDOGASSZONY) - augusztus 15.');
        self::assertSame([8, 15], self::honapNap($r['bucsu']));
    }

    /** Lehetetlen napot inkább ne ismerjünk fel, mint rosszul. */
    public function testLehetetlenNapotNemFogadEl(): void {
        self::assertNull(\Bucsu::parse('Búcsú: február 45.')['bucsu']);
    }

    // ---- mozgó ünnepek -------------------------------------------------------

    public function testSzentharomsagVasarnap(): void {
        $a = \Bucsu::parse('Búcsú: Szentháromság vasárnap')['bucsu'];

        self::assertSame('moveable', $a['type']);
        self::assertSame('easter', $a['basis']);
        self::assertSame('2026-05-31', \Bucsu::resolve($a, 2026));
    }

    /** Az adatban egy templomnál elgépelve szerepel — azt is fel kell ismerni. */
    public function testElgepeltSzentharomsag(): void {
        self::assertSame('2026-05-31', \Bucsu::resolve(\Bucsu::parse('Búcsú: Szenthátomság vasárnap')['bucsu'], 2026));
    }

    public function testPunkosd(): void {
        self::assertSame('2026-05-24', \Bucsu::resolve(\Bucsu::parse('Búcsú: Pünkösdvasárnap')['bucsu'], 2026));
    }

    public function testKrisztusKiralyAzAdventhezIgazodik(): void {
        $a = \Bucsu::parse('Búcsú: Krisztus Király vasárnapja')['bucsu'];

        self::assertSame('advent', $a['basis']);
        self::assertSame('2026-11-22', \Bucsu::resolve($a, 2026));
    }

    public function testHusvetNedikVasarnapja(): void {
        // Húsvét 2026: április 5. A 3. vasárnap két héttel később.
        self::assertSame('2026-04-19', \Bucsu::resolve(\Bucsu::parse('Szent Benedek Húsvét 3. vasárnapja')['bucsu'], 2026));
    }

    public function testHonapNedikVasarnapja(): void {
        $a = \Bucsu::parse('Búcsú: Október első vasárnapja (Rózsafüzér Királynője)')['bucsu'];

        self::assertSame('nth_sunday', $a['basis']);
        self::assertSame('2026-10-04', \Bucsu::resolve($a, 2026));
    }

    /**
     * A hosszabb mintának kell nyernie: a "Jézus Szíve ünnepét követő vasárnap"
     * nem ugyanaz, mint a "Jézus Szíve" (péntek).
     */
    public function testABovebbMintaNyer(): void {
        $penetek = \Bucsu::parse('Búcsú: Jézus Szíve')['bucsu'];
        $vasarnap = \Bucsu::parse('Búcsú: Jézus Szíve ünnepét követő vasárnap')['bucsu'];

        self::assertSame(68, $penetek['offset']);
        self::assertSame(70, $vasarnap['offset']);
    }

    // ---- naptári alapok ------------------------------------------------------

    /**
     * A húsvétszámítás a mozgó ünnepek alapja. Szándékosan nem a PHP
     * easter_date()-je, mert az ext-calendar bővítményt igényel.
     */
    public function testHusvetSzamitas(): void {
        self::assertSame('2026-04-05', \Bucsu::husvet(2026));
        self::assertSame('2027-03-28', \Bucsu::husvet(2027));
        self::assertSame('2024-03-31', \Bucsu::husvet(2024));
        self::assertSame('2000-04-23', \Bucsu::husvet(2000));
    }

    public function testAdventElsoVasarnapja(): void {
        self::assertSame('2026-11-29', \Bucsu::adventElsoVasarnap(2026));
        self::assertSame('2027-11-28', \Bucsu::adventElsoVasarnap(2027));
    }

    /** Karácsonyra eső vasárnapnál is a NEGYEDIK adventi vasárnaptól számolunk. */
    public function testAdventAkkorIsHelyesHaKaracsonyVasarnap(): void {
        // 2033. december 25. vasárnap.
        self::assertSame('2033-11-27', \Bucsu::adventElsoVasarnap(2033));
    }

    // ---- feloldás ------------------------------------------------------------

    public function testFixDatumFeloldasa(): void {
        self::assertSame('2027-08-15', \Bucsu::resolve(['type' => 'fixed', 'month' => 8, 'day' => 15], 2027));
    }

    public function testUresMezo(): void {
        $r = \Bucsu::parse('');

        self::assertNull($r['bucsu']);
        self::assertNull($r['szentsegimadas']);
        self::assertSame('', $r['unparsed']);
    }

    public function testNullMezo(): void {
        self::assertNull(\Bucsu::parse(null)['bucsu']);
    }

    /**
     * Amit nem értünk, azt megőrizzük — az adat javítható, és a riportnak
     * meg kell tudnia mutatni, mi maradt ki.
     */
    public function testAzErtelmezetlenSzovegMegmarad(): void {
        $r = \Bucsu::parse('Búcsú: Szent György vértanú ünnepéhez közelebbi vasárnap');

        self::assertNull($r['bucsu']);
        self::assertStringContainsString('Szent György', $r['unparsed']);
    }

    // ---- a templom oldali API ------------------------------------------------

    /** @param string $bucsu a nyers mezőérték */
    private static function templom(string $bucsu): \Eloquent\Church {
        $templom = new \Eloquent\Church();
        $templom->bucsu = $bucsu;
        return $templom;
    }

    /**
     * Egy értesítő cron pontosan ezt hívná: "mikor lesz legközelebb".
     */
    public function testAKovetkezoBucsuMegAzIdeiHaMegNemVoltMeg(): void {
        self::assertSame('2026-08-15', self::templom('Búcsú: augusztus 15.')->nextBucsuDate('2026-03-01'));
    }

    public function testAKovetkezoBucsuAJovoEviHaAzIdeiElmult(): void {
        self::assertSame('2027-08-15', self::templom('Búcsú: augusztus 15.')->nextBucsuDate('2026-09-01'));
    }

    public function testAMaiNapMegSzamit(): void {
        self::assertSame('2026-08-15', self::templom('Búcsú: augusztus 15.')->nextBucsuDate('2026-08-15'));
    }

    /**
     * Mozgó ünnepnél a hónap/nap összehasonlítás nem elég: a Szentháromság
     * vasárnapja 2026-ban május 31., 2027-ben május 23. — évente máshova esik.
     * (Húsvét 2027: március 28., + 56 nap.)
     */
    public function testMozgoUnnepnelIsAKovetkezoEvetAdja(): void {
        $templom = self::templom('Búcsú: Szentháromság vasárnap');

        self::assertSame('2026-05-31', $templom->nextBucsuDate('2026-01-01'));
        self::assertSame('2027-05-23', $templom->nextBucsuDate('2026-06-01'));
    }

    public function testErtelmezhetetlenMezonelNincsKovetkezoDatum(): void {
        self::assertNull(self::templom('Búcsú:')->nextBucsuDate('2026-01-01'));
    }

    /** @return array{0: int, 1: int} */
    private static function honapNap(?array $alkalom): array {
        self::assertNotNull($alkalom, 'Nem ismerte fel a dátumot.');
        return [$alkalom['month'], $alkalom['day']];
    }
}
