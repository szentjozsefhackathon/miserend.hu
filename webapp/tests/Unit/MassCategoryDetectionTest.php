<?php

use PHPUnit\Framework\TestCase;

/**
 * #157: a kereső kategória-szűrője nem kategóriára szűrt, hanem egy zárt CÍMLISTÁRA.
 *
 * borazslo: „ha a keresőben filterként választom ki hogy misefélék vagy szentségimádások
 * vagy gyóntatások vagy egyebek, akkor azok még nem fogják jól megtalálni az importált
 * speciális miséinket."
 *
 * A `cal_masses.title` szabad szöveg: kézi felvitelnél a kanonikus magyar cím kerül bele,
 * importnál a nyers ICS SUMMARY. Ezért minden importált esemény ÉS minden kézzel írt
 * egyedi cím kiesett minden kategóriából — nem azért, mert nem mise, hanem mert a címe
 * nem karakterre azonos a szótári alakkal.
 */
final class MassCategoryDetectionTest extends TestCase
{
    private function md(): \MassDefinitions
    {
        return new \MassDefinitions();
    }

    /* ---- A szabad szöveges felismerés ---- */

    /**
     * A LEGKORÁBBAN előforduló minta nyer, nem a csoport-sorrend.
     *
     * A magyar naptárcímek a főeseményt írják előre. Csoport-sorrenddel mindhárom
     * ugyanazt adná, tehát kettő rossz lenne.
     */
    public static function cimek(): array
    {
        return [
            'importált szentmise'      => ['Szentmise a Szent Kereszt kápolnában', 'MASS'],
            'régi rítusú'              => ['Régi rítusú mise', 'MASS'],
            'angol nyelvű'             => ['Angol nyelvű szentmise', 'MASS'],
            'szentségimádás'           => ['Csendes szentségimádás', 'ADORATION'],
            'adoráció'                 => ['Adoráció a kápolnában', 'ADORATION'],
            'gyóntatás'                => ['Gyóntatás', 'CONFESSION'],
            'szentgyónás'              => ['Szentgyónás lehetősége', 'CONFESSION'],
            'rózsafüzér'               => ['Rózsafüzér a Szűzanyáért', 'OTHER'],
            'keresztút'                => ['Keresztút a kálvárián', 'OTHER'],
            'vecsernye'                => ['Ünnepi vecsernye', 'OTHER'],

            // A sorrend dönt:
            'mise, utána imádás'       => ['Szentmise, utána szentségimádás', 'MASS'],
            'imádás a mise után'       => ['Szentségimádás a szentmise után', 'ADORATION'],
            'gyóntatás a mise előtt'   => ['Gyóntatás a szentmise előtt', 'CONFESSION'],

            // Nem liturgikus bejegyzés: NINCS kategória.
            'testületi ülés'           => ['Képviselőtestületi ülés', null],
            'hittanóra'                => ['Hittanóra', null],
            'vásár'                    => ['Adventi vásár', null],
        ];
    }

    /** @dataProvider cimek */
    public function testTheTitleDecidesTheCategory(string $cim, ?string $vart): void
    {
        self::assertSame($vart, \IcalEventProperties::detectCategory($cim), $cim);
    }

    /**
     * MINTA-CSAPDA: a „liturgia" teljes szóként szerepel, nem „liturgi"-ként.
     *
     * A „Régi rítusú liturgikus hétvége" különben misévé válna. A `RITE_PATTERNS` már
     * megjárta ugyanezt.
     */
    public function testALiturgicalWeekendIsNotAMass(): void
    {
        self::assertNotSame('MASS', \IcalEventProperties::detectCategory('Régi rítusú liturgikus hétvége'));
    }

    /* ---- A kanonikus címek viselkedése NEM változik ---- */

    /**
     * Ez a nulla-regressziós garancia: a szótári alakokra a pontos egyezés dönt, nem a
     * heurisztika.
     */
    public function testCanonicalTitlesKeepTheirCategory(): void
    {
        self::assertSame('ADORATION', $this->md()->categoryForTitle('MASS_TITLE.ADORATION'));
        self::assertSame('CONFESSION', $this->md()->categoryForTitle('MASS_TITLE.CONFESSION'));
        self::assertSame('MASS', $this->md()->categoryForTitle('MASS_TITLE.HOLY_MASS'));
    }

    /** A magyar fordítás is kanonikus alak — a régi sorokban ez áll. */
    public function testTheHungarianTitlesAlsoResolve(): void
    {
        self::assertSame('ADORATION', $this->md()->categoryForTitle('Szentségimádás'));
        self::assertSame('MASS', $this->md()->categoryForTitle('Szentmise'));
    }

    public function testAnEmptyTitleHasNoCategory(): void
    {
        self::assertNull($this->md()->categoryForTitle(''));
        self::assertNull($this->md()->categoryForTitle('   '));
    }

    /* ---- A keresési klauzula ---- */

    /**
     * KÉT ág kell: az új `category` és a régi cím-lista.
     *
     * A cím-ág tartja életben a szűrőt az újraindexelés ALATT — a régi dokumentumokban
     * még nincs `category`. Enélkül a deploy és a reindex vége között a szűrő rosszabb
     * lenne, mint ma.
     */
    public function testTheClauseKeepsTheOldTitleBranch(): void
    {
        $clause = $this->md()->categoryQueryClause(['ADORATION']);

        self::assertNotNull($clause);
        self::assertSame(1, $clause['bool']['minimum_should_match']);

        $mezok = array_map(fn($ag) => array_key_first($ag['terms']), $clause['bool']['should']);
        self::assertContains('category.keyword', $mezok);
        self::assertContains('title.keyword', $mezok);
    }

    public function testTheClauseFiltersToKnownCategories(): void
    {
        $clause = $this->md()->categoryQueryClause(['ADORATION', 'SZEMET']);

        self::assertSame(['ADORATION'], $clause['bool']['should'][0]['terms']['category.keyword']);
    }

    /**
     * Ismeretlen kulcs -> NINCS szűrés, nem nulla találat.
     *
     * Ez a `MassDefinitionsTitleFiltersTest` régi szerződése; nem szabad elrontani.
     */
    public function testAnUnknownCategoryMeansNoFilterAtAll(): void
    {
        self::assertNull($this->md()->categoryQueryClause(['SZEMET']));
        self::assertNull($this->md()->categoryQueryClause([]));
    }

    /**
     * A mezőnév `category.keyword`, nem `category`.
     *
     * A mapping dinamikus (text + .keyword alfield), ahogy a `title`, `types`, `lang` és
     * `rite` esetében is. A puszta `category`-ra kérdezés az analizált 'mass' értéket
     * keresné, és a nagybetűs 'MASS' term NÉMÁN nulla találatot adna.
     */
    public function testTheClauseUsesTheKeywordSubfield(): void
    {
        $clause = $this->md()->categoryQueryClause(['MASS']);

        self::assertArrayHasKey('category.keyword', $clause['bool']['should'][0]['terms']);
    }
}
