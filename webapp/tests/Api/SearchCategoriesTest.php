<?php

use PHPUnit\Framework\TestCase;
use Api\Search;

require_once __DIR__ . '/../../classes/MockPhpInputStreamWrapper.php';

/**
 * #299: az /api/v4/search `categories` szűrője.
 *
 * A szentségimádás és a gyóntatás ugyanolyan naptáresemény, mint a mise, csak más a
 * fajtája — tehát nem külön végpont és nem külön adatforrás, hanem cím-szűrő ugyanazon a
 * mise-keresésen. Itt a bemenet-ellenőrzést teszteljük (Elasticsearch nélkül): a mezőt,
 * a `q` feltételes kötelezőségét és a kategórianevek szigorát.
 *
 * A tényleges szűrés (hogy a `terms` a `title.keyword`-re valóban rákerül, és mit ad
 * vissza) az integrációs tesztben van: Integration/SearchCategoriesFilterTest.
 */
class SearchCategoriesTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $_REQUEST = [];
        $_REQUEST['v'] = 4;
    }

    protected function tearDown(): void {
        parent::tearDown();
        $_REQUEST = [];
        MockPhpInputStreamWrapper::restorePhpInput();
    }

    private function parse(array $input): Search {
        MockPhpInputStreamWrapper::mockPhpInput(json_encode($input));
        $search = new Search();
        $search->getInputJson();
        return $search;
    }

    public function testCategoriesFieldExists(): void {
        $search = new Search();

        self::assertArrayHasKey('categories', $search->fields);
        self::assertSame(
            ['MASS', 'ADORATION', 'CONFESSION', 'OTHER'],
            $search->fields['categories']['validation']['list']['enum']
        );
    }

    /* A kategória nélküli hívás viselkedése nem változhat: q továbbra is kötelező. */
    public function testKeywordStaysRequiredWithoutCategories(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'q' is required in JSON.");

        $this->parse(['limit' => 3]);
    }

    /* Misére sem enged el kulcsszó nélkül: a mise-index több millió időpont. */
    public function testKeywordStaysRequiredForMassCategory(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'q' is required in JSON.");

        $this->parse(['categories' => ['MASS']]);
    }

    /* Vegyes kérésnél is kötelező, mert a MASS ott van a halmazban. */
    public function testKeywordStaysRequiredWhenMassIsMixedIn(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'q' is required in JSON.");

        $this->parse(['categories' => ['ADORATION', 'MASS']]);
    }

    /* A LÉNYEG: „hol van ma egyáltalán szentségimádás" kulcsszó nélkül is kérdés. */
    public function testKeywordIsOptionalForAdoration(): void {
        $search = $this->parse(['categories' => ['ADORATION'], 'when' => 'today']);

        self::assertSame(['ADORATION'], $search->input['categories']);
        self::assertArrayNotHasKey('q', $search->input);
    }

    public function testKeywordIsOptionalForConfession(): void {
        $search = $this->parse(['categories' => ['CONFESSION']]);

        self::assertSame(['CONFESSION'], $search->input['categories']);
    }

    public function testKeywordIsOptionalForAdorationAndConfessionTogether(): void {
        $search = $this->parse(['categories' => ['ADORATION', 'CONFESSION']]);

        self::assertSame(['ADORATION', 'CONFESSION'], $search->input['categories']);
    }

    /* Az OTHER (zsolozsma, rózsafüzér…) tág, ezért ott marad a kulcsszókényszer. */
    public function testKeywordStaysRequiredForOtherCategory(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'q' is required in JSON.");

        $this->parse(['categories' => ['OTHER']]);
    }

    public function testUnknownCategoryIsRejected(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'categories' should be one of");

        $this->parse(['q' => 'Szent István', 'categories' => ['SZENTSEGIMADAS']]);
    }

    public function testCategoriesMustBeAList(): void {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'categories' should be a list/array.");

        $this->parse(['q' => 'Szent István', 'categories' => 'ADORATION']);
    }

    /* Kulcsszóval együtt is használható: a kategória tovább szűkít. */
    public function testKeywordAndCategoryTogetherAreAccepted(): void {
        $search = $this->parse(['q' => 'Szent István', 'categories' => ['ADORATION']]);

        self::assertSame('Szent István', $search->input['q']);
        self::assertSame(['ADORATION'], $search->input['categories']);
    }
}
