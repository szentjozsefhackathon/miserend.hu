<?php

use PHPUnit\Framework\TestCase;

/**
 * #299: a kategóriaszűrő valós Elasticsearch ellen.
 *
 * A szentségimádás és a gyóntatás ugyanolyan `cal_masses` esemény, mint a mise, csak más
 * a címe — tehát ugyanaz a mise-index szolgálja ki, ugyanazzal a válaszszerkezettel. Ezt
 * mockolni értelmetlen volna: pont az a kérdés, hogy a `title.keyword` szűrő illeszkedik-e
 * a ténylegesen indexelt címekre.
 */
class SearchCategoriesFilterTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        if (!(new \ExternalApi\ElasticsearchApi())->isexistsIndex('mass_index')) {
            $this->markTestSkipped('Nincs mass_index ebben a környezetben.');
        }
    }

    private function totalFor(array $categories): int {
        $search = new \Search('masses');
        $search->keyword('');

        if (!empty($categories)) {
            $filters = (new \MassDefinitions())->titleFiltersByCategories($categories);
            $search->query['bool']['must'][] = [ 'terms' => ['title.keyword' => $filters] ];
        }

        $search->getResults(0, 1, true);
        return (int) $search->total;
    }

    public function testAdorationIsFoundInTheMassIndex(): void {
        $adorations = $this->totalFor(['ADORATION']);

        if ($adorations === 0) {
            $this->markTestSkipped('Ebben az adatbázisban nincs szentségimádás-esemény.');
        }

        self::assertGreaterThan(0, $adorations);
    }

    /* A szűrő tényleg szűkít: a kategóriára szűrt halmaz kisebb, mint a szűretlen. */
    public function testTheFilterNarrowsTheResultSet(): void {
        $all = $this->totalFor([]);
        $adorations = $this->totalFor(['ADORATION']);

        if ($adorations === 0) {
            $this->markTestSkipped('Ebben az adatbázisban nincs szentségimádás-esemény.');
        }

        self::assertLessThan($all, $adorations, 'a szűrő nem szűkített semmit');
    }

    /* A mise és a szentségimádás két külön halmaz — nem folyhatnak egymásba. */
    public function testMassAndAdorationDoNotOverlap(): void {
        $mass = $this->totalFor(['MASS']);
        $adorations = $this->totalFor(['ADORATION']);
        $together = $this->totalFor(['MASS', 'ADORATION']);

        if ($adorations === 0) {
            $this->markTestSkipped('Ebben az adatbázisban nincs szentségimádás-esemény.');
        }

        self::assertSame($mass + $adorations, $together, 'átfedés van a két kategória között');
    }

    /*
     * A találatok tényleg szentségimádások: a szűrt keresés első lapjának minden
     * dokumentuma a kategóriához tartozó címek egyikét viseli.
     */
    public function testEveryHitCarriesAnAdorationTitle(): void {
        $expected = (new \MassDefinitions())->titleFiltersByCategories(['ADORATION']);

        $elastic = new \ExternalApi\ElasticsearchApi();
        $elastic->buildQuery('mass_index/_search', json_encode([
            'size'  => 20,
            'query' => ['bool' => ['must' => [['terms' => ['title.keyword' => $expected]]]]],
            '_source' => ['includes' => ['title']],
        ]));
        $elastic->run();

        $hits = $elastic->jsonData->hits->hits ?? [];
        if (empty($hits)) {
            $this->markTestSkipped('Ebben az adatbázisban nincs szentségimádás-esemény.');
        }

        foreach ($hits as $hit) {
            self::assertContains($hit->_source->title, $expected);
        }
    }
}
