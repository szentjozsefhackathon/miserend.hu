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
        self::assertSame($vart, $this->md()->categoryForTitle($cim), $cim);
    }

    /**
     * #896: amit a régi, szűk szótár NEM ismert fel.
     *
     * Mind a hat alak az éles adatból való — a fejlesztői adatbázis 12 505 sorából ezek
     * maradtak kategória nélkül, pedig mind mise. A kanonikus cím önmagában kevés volt:
     * a „Nagypénteki szertartás" pontosan egyezve felismerhető, de egy „(P. Szőcs)" a
     * végén már kiejtette.
     *
     * @dataProvider valodiCimek
     */
    public function testTitlesFromTheRealDataAreRecognised(string $cim, ?string $vart): void
    {
        self::assertSame($vart, $this->md()->categoryForTitle($cim), $cim);
    }

    public static function valodiCimek(): array
    {
        return [
            'nagypénteki szertartás'  => ['Nagypénteki szertartás (P. Szőcs)', 'MASS'],
            'nagycsütörtöki'          => ['Nagycsütörtöki szertartás (P. Elek)', 'MASS'],
            'húsvéti vigília'         => ['Húsvét vigíliája (P. Elek)', 'MASS'],
            'feltámadási szertartás'  => ['Feltámadási szertartás (P. Elek)', 'MASS'],
            'angol vigília elgépelve' => ['Eastern vigil in English (P. Elek)', 'MASS'],
            'elgépelt szentmise'      => ['Szentmsie', 'MASS'],
            'elgépelt szentmise 2'    => ['Kollégiumi szentmse', 'MASS'],
            'ragozott mise'           => ['Kollégistáink szentmiséje (P. Szőcs)', 'MASS'],
            'újmise'                  => ['P. Phamdinh Ngoc József SJ újmiséje', 'MASS'],

            // #896: borazslo döntése szerint ezek EGYÉB-be tartoznak. Nem definícióként,
            // hanem kategória-aliasként — így a kereső megtalálja őket, a naptárszerkesztő
            // cím-választójában viszont nem jelennek meg.
            'temetés'                 => ['BOHAN BÉLA ATYA TEMETÉSE 12 ÓRAKOR', 'OTHER'],
            'keresztelő'              => ['Keresztelő (P. Vértesaljai)', 'OTHER'],
            'ékezet nélküli keresztelő' => ['Keresztelõ, P. Szőcs', 'OTHER'],
            'esküvő'                  => ['Esküvő (Alácsi Ervin atya)', 'OTHER'],
            'requiem'                 => ['BOHAN BÉLA ATYA - REQUIEM, SZEGEDI SZENT JÓZSEF Templomban', 'OTHER'],

            // De a MISE marad mise: a „10 misén keresztelő" elsősorban szentmise, és a
            // korábbi találat nyer — a „misé" a 3. karakternél áll, a „keresztel" a 9-nél.
            'keresztelő a misén'      => ['10 misén keresztelő, Mária Teodóra', 'MASS'],

            // A „szertartás" önmagában továbbra sem lehet alias: abból a temetés is mise
            // lenne. Csak a nagypénteki/nagycsütörtöki/feltámadási alak szerepel.
            'lelkinap'                => ['Lelkinap', null],
        ];
    }

    /**
     * MINTA-CSAPDA: a „liturgia" teljes szóként szerepel, nem „liturgi"-ként.
     *
     * A „Régi rítusú liturgikus hétvége" különben misévé válna. A `RITE_PATTERNS` már
     * megjárta ugyanezt.
     */
    public function testALiturgicalWeekendIsNotAMass(): void
    {
        self::assertNotSame('MASS', $this->md()->categoryForTitle('Régi rítusú liturgikus hétvége'));
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

    /**
     * #896: a szótár a GENERÁLT adatból jön, nem PHP-konstansból.
     *
     * Ez a teszt az egyetlen forrást őrzi: ha valaki visszacsempészné a mintákat egy
     * osztály konstansába, az `aliasesByCategory()` üresen maradna, és ez elbukna.
     */
    public function testTheAliasDictionaryComesFromTheGeneratedData(): void
    {
        $szotar = $this->md()->aliasesByCategory();

        self::assertNotEmpty($szotar, 'nincs alias a generált JSON-ban');
        foreach (['MASS', 'ADORATION', 'CONFESSION', 'OTHER'] as $category) {
            self::assertNotEmpty($szotar[$category] ?? [], "üres a(z) $category szótára");
        }
        self::assertContains('szentmise', $szotar['MASS']);
        self::assertContains('gyóntat', $szotar['CONFESSION']);
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
