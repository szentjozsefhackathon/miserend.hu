<?php

use PHPUnit\Framework\TestCase;

/**
 * #496/#497/#498: a location() pozíció szerint címkézi az ország/megye/település
 * hármast, de az admin_level jelentése országonként eltér. A lenti szintkészletek
 * nem kitaláltak: az OSM Overpass `is_in` lekérdezésével mértem ki őket egy-egy
 * valódi templomunk koordinátáján, 2026-08-09-én.
 *
 * A függvény DB nélkül fut, ezért közvetlenül hívható.
 */
final class AdminLevelMappingTest extends TestCase {

    /** Az Overpass által visszaadott szintekből épít boundary-tömböt. */
    private static function boundaries(array $levelsAndNames): array {
        $out = [];
        foreach ($levelsAndNames as $level => $name) {
            $out[] = ['admin_level' => $level, 'name' => $name, 'osmtype' => 'relation', 'osmid' => $level];
        }
        return $out;
    }

    private static function names(array $boundaries): array {
        return array_column($boundaries, 'name');
    }

    /*
     * Magyarország: van 6-os (vármegye), tehát a 4-es nagyrégiónak ki kell esnie.
     * Ez a templomok 87%-a — itt semmi nem változhat.
     */
    public function testHungarianRegionIsDroppedInFavourOfTheCounty(): void {
        $input = self::boundaries([
            2 => 'Magyarország',
            4 => 'Közép-Magyarország',
            6 => 'Pest vármegye',
            8 => 'Szentendre',
        ]);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame(['Magyarország', 'Pest vármegye', 'Szentendre'], self::names($result));
    }

    /* Budapest: a kerület és a városrész is a helyén marad. */
    public function testBudapestKeepsDistrictLevels(): void {
        $input = self::boundaries([
            2 => 'Magyarország',
            4 => 'Közép-Magyarország',
            6 => 'Budapest',
            8 => 'Budapest',
            9 => 'V. kerület',
        ]);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame(['Magyarország', 'Budapest', 'Budapest', 'V. kerület'], self::names($result));
    }

    /*
     * A bejelentett hiba: Romániában nincs 6-os szint, a megyét a 4-es judet
     * hordozza. Korábban a lista [ország, település] lett, vagyis a település
     * csúszott a megye helyére, a city pedig üresen maradt.
     */
    public function testRomanianCountyComesFromLevelFour(): void {
        $input = self::boundaries([
            2 => 'România',
            4 => 'Alba',
            8 => 'Vințu de Jos',
        ]);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame(['România', 'Alba', 'Vințu de Jos'], self::names($result));
    }

    /* Ugyanaz a lényeg, a hívó szemszögéből: a település a 3. helyre kerül. */
    public function testRomanianSettlementIsNoLongerLabelledAsCounty(): void {
        $input = self::boundaries([
            2 => 'România',
            4 => 'Alba',
            8 => 'Vințu de Jos',
        ]);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame('Alba', $result[1]['name'], 'a megye helyén megyének kell állnia');
        self::assertArrayHasKey(2, $result, 'a település nem eshet ki');
        self::assertSame('Vințu de Jos', $result[2]['name']);
    }

    /* Szlovákia: az OSM ma 6-on tartja az okrest, tehát a kraj kiesik. */
    public function testSlovakKrajIsDroppedBecauseOkresIsLevelSix(): void {
        $input = self::boundaries([
            2 => 'Slovensko',
            4 => 'Banskobystrický kraj',
            6 => 'okres Lučenec',
            8 => 'Boľkovce',
        ]);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame(['Slovensko', 'okres Lučenec', 'Boľkovce'], self::names($result));
    }

    /* Szerbia: van 6-os okrug, a tartomány kiesik. */
    public function testSerbianProvinceIsDropped(): void {
        $input = self::boundaries([
            2 => 'Србија',
            4 => 'Војводина',
            6 => 'Севернобачки управни округ',
            8 => 'Општина Бачка Топола',
            9 => 'Бачка Топола',
        ]);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame(
            ['Србија', 'Севернобачки управни округ', 'Општина Бачка Топола', 'Бачка Топола'],
            self::names($result)
        );
    }

    /* Ukrajna: van 6-os rajon, az oblaszty kiesik. */
    public function testUkrainianOblastIsDropped(): void {
        $input = self::boundaries([
            2 => 'Україна',
            4 => 'Закарпатська область',
            6 => 'Берегівський район',
            9 => 'Берегове',
        ]);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame(['Україна', 'Берегівський район', 'Берегове'], self::names($result));
    }

    /* Bécs: nincs 6-os, így a Bundesland kerül a megye helyére. */
    public function testViennaFallsBackToTheBundesland(): void {
        $input = self::boundaries([
            2 => 'Österreich',
            4 => 'Wien',
            9 => 'Innere Stadt',
            10 => 'Katastralgemeinde Innere Stadt',
        ]);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame(
            ['Österreich', 'Wien', 'Innere Stadt', 'Katastralgemeinde Innere Stadt'],
            self::names($result)
        );
    }

    /* Ha csak ország van, ne essen szét. */
    public function testCountryOnlyIsLeftAlone(): void {
        $input = self::boundaries([2 => 'România']);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame(['România'], self::names($result));
    }

    /* Üres bemenetre üres kimenet, nem hiba. */
    public function testEmptyInputStaysEmpty(): void {
        self::assertSame([], \Eloquent\Church::pickAdministrativeBoundaries([]));
    }

    /* A kulcsoknak 0-tól folytonosnak kell lenniük, mert a hívó indexel. */
    public function testResultKeysAreSequential(): void {
        $input = self::boundaries([
            2 => 'Magyarország',
            4 => 'Közép-Magyarország',
            6 => 'Pest vármegye',
            8 => 'Szentendre',
        ]);

        $result = \Eloquent\Church::pickAdministrativeBoundaries($input);

        self::assertSame([0, 1, 2], array_keys($result));
    }
}
