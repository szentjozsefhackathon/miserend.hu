<?php

use PHPUnit\Framework\TestCase;

/**
 * #56: API v5.
 *
 * A jegy tizenegy éven át gyűlt ötletzsák; ebből ez a rész a 2025-ös és 2026-os
 * bejegyzés: „az egész átadott mise adatok kövessék majd a nagy megújulás calendar
 * típusú új állapotát", illetve „többféle nyelv tartozhasson egy eseményhez".
 *
 * A v4 mise-adata két mező volt: egy időbélyeg és egy ELŐRE ÖSSZERAKOTT MAGYAR MONDAT
 * („Római katolikus Szentmise"). A kliens ebből nem tud szűrni rítusra, nyelvre vagy
 * típusra, nem tud fordítani, és a hosszt sem tudja — pedig mindez strukturáltan
 * megvan a `cal_masses`-ben.
 *
 * A v5 ezért BŐVÍTÉS, nem csere: az `informacio` megmarad a strukturált mezők mellett,
 * hogy a meglévő kliens (KAPP) mezőnként állhasson át.
 *
 * A tesztek a válasz ALAKJÁT rögzítik, nem a tartalmát: az adat változhat, a szerződés nem.
 */
class ApiV5Test extends TestCase {

    private const TESZT_TEMPLOM = 1;

    /**
     * @return array a templom API-tömbje az adott verzióban
     *
     * A napot KIFEJEZETTEN megadjuk. Alapértelmezésben a `toAPIArray()` a mai napra
     * kérdez, és ha épp nincs mise, a teszt csak kihagyásra fut — vagyis a futások
     * fele nem mér semmit. A következő vasárnapon minden templomnak van miséje.
     */
    private function church(int $verzio, string $length = 'minimal'): array {
        return \Eloquent\Church::find(self::TESZT_TEMPLOM)
            ->toAPIArray($length, self::kovetkezoVasarnap(), $verzio);
    }

    private static function kovetkezoVasarnap(): string {
        return date('Y-m-d', strtotime('next sunday'));
    }

    /* ---------- verzió-kezelés ---------- */

    public function testTheFifthVersionIsAccepted(): void {
        $api = new \Api\Api();
        $api->version = 5;

        $api->validateVersionMain();
        self::assertSame(5, $api->version);
    }

    public function testAnUnknownVersionIsRejected(): void {
        $api = new \Api\Api();
        $api->version = 99;

        $this->expectException(\Exception::class);
        $api->validateVersionMain();
    }

    /**
     * A régebbi verziók változatlanul élnek: a v5 bevezetése nem kapcsolhat ki semmit,
     * amíg a kliensek át nem álltak.
     *
     * @dataProvider korabbiVerziok
     */
    public function testEarlierVersionsStillWork(int $verzio): void {
        $api = new \Api\Api();
        $api->version = $verzio;

        $api->validateVersionMain();
        self::assertSame($verzio, $api->version);
    }

    public static function korabbiVerziok(): array {
        return [[1], [2], [3], [4]];
    }

    /* ---------- a v4 válasza NEM változhat ---------- */

    public function testTheFourthVersionMassPayloadIsUnchanged(): void {
        $misek = $this->church(4)['misek'];

        if (!$misek) {
            self::markTestSkipped('ezen a napon nincs mise ezen a templomon');
        }

        /*
         * Részhalmazt mérünk, nem pontos egyezést: az `informacio` KIMARAD, ha a
         * mondat üresre jönne ki (`if($info != '')`). A lényeg, hogy a v4-be ne
         * kerüljön ÚJ mező — az sértené a meglévő kliensek szerződését.
         */
        foreach ($misek as $mise) {
            self::assertSame([], array_diff(array_keys($mise), ['idopont', 'informacio']),
                'a v4 mise-adata új mezővel bővült: ' . implode(', ', array_keys($mise)));
            self::assertArrayHasKey('idopont', $mise);
        }
    }

    public function testTheFourthVersionStillSendsFullPhotoUrls(): void {
        $photos = $this->church(4, 'full')['photos'];

        if (!$photos) {
            self::markTestSkipped('ennek a templomnak nincs képe');
        }
        self::assertStringStartsWith('/kepek/templomok/', $photos[0]);
    }

    /* ---------- a v5 új tartalma ---------- */

    public function testTheFifthVersionKeepsTheHumanReadableSentence(): void {
        $misek = $this->church(5)['misek'];

        if (!$misek) {
            self::markTestSkipped('ezen a napon nincs mise ezen a templomon');
        }
        self::assertArrayHasKey('informacio', $misek[0],
            'az informacio a v5-ben is kell: enélkül a KAPP nem tud fokozatosan átállni');
    }

    public function testTheFifthVersionAddsStructuredMassFields(): void {
        $misek = $this->church(5)['misek'];

        if (!$misek) {
            self::markTestSkipped('ezen a napon nincs mise ezen a templomon');
        }

        foreach (['mise_id', 'ritus', 'megnevezes', 'nyelvek', 'tipusok', 'megjegyzes', 'hossz_perc'] as $mezo) {
            self::assertArrayHasKey($mezo, $misek[0], 'hiányzik a v5 mezője: ' . $mezo);
        }
    }

    /** A típusok rögzítettek: a kliens ezekre épít. */
    public function testTheStructuredFieldsHaveStableTypes(): void {
        $misek = $this->church(5)['misek'];

        if (!$misek) {
            self::markTestSkipped('ezen a napon nincs mise ezen a templomon');
        }

        $mise = $misek[0];
        self::assertIsInt($mise['mise_id']);
        self::assertIsString($mise['ritus']);
        self::assertIsString($mise['megnevezes']);
        self::assertIsString($mise['megjegyzes']);
        self::assertIsInt($mise['hossz_perc']);
        // #334: egy misének több nyelve is lehet — ezért MINDIG lista, akkor is, ha egy.
        self::assertIsArray($mise['nyelvek']);
        self::assertIsArray($mise['tipusok']);
    }

    /**
     * A nyelv listaként megy, nem a magyar mondatba ágyazva.
     *
     * Ez borazslo 2026-os kérése. Belül már megvolt (#334), csak az API nem adta ki.
     */
    public function testLanguagesAreSentAsAList(): void {
        $misek = $this->church(5)['misek'];

        if (!$misek) {
            self::markTestSkipped('ezen a napon nincs mise ezen a templomon');
        }

        foreach ($misek as $mise) {
            self::assertIsArray($mise['nyelvek']);
            self::assertSame(array_values($mise['nyelvek']), $mise['nyelvek'],
                'a lista indexei nem folytonosak — JSON-ben objektumként jelenne meg');
            foreach ($mise['nyelvek'] as $nyelv) {
                self::assertIsString($nyelv);
            }
        }
    }

    /**
     * A képek rövid úton: `{templomid}/{fájlnév}`.
     *
     * A teljes cím minden képnél megismételte ugyanazt az előtagot; a bázis ismert és
     * állandó (`{domain}/kepek/templomok/`).
     */
    public function testTheFifthVersionSendsShortPhotoPaths(): void {
        $photos = $this->church(5, 'full')['photos'];

        if (!$photos) {
            self::markTestSkipped('ennek a templomnak nincs képe');
        }

        foreach ($photos as $kep) {
            self::assertStringNotContainsString('/kepek/', $kep, 'maradt benne az előtag');
            self::assertMatchesRegularExpression('#^\d+/[^/]+$#', $kep,
                'nem {templomid}/{fájlnév} alakú: ' . $kep);
        }
    }

    /** Verzió nélkül a régi alak jön: a keresőindex építése is ezen az úton megy. */
    public function testWithoutAVersionTheOldShapeIsReturned(): void {
        $misek = \Eloquent\Church::find(self::TESZT_TEMPLOM)
            ->toAPIArray('minimal', self::kovetkezoVasarnap())['misek'];

        if (!$misek) {
            self::markTestSkipped('ezen a napon nincs mise ezen a templomon');
        }
        self::assertArrayNotHasKey('ritus', $misek[0]);
    }
}
