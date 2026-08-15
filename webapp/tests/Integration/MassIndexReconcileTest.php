<?php

use PHPUnit\Framework\TestCase;

/**
 * A pótindexelés valós Elasticsearch ellen: a jelöltválasztást a
 * MassReindexCandidatesTest fedi, itt az köré épülő I/O megy — a friss dokumentumok
 * azonnali kereshetősége és az "ennek tényleg nincs idei miséje" vízjel.
 */
class MassIndexReconcileTest extends TestCase {

    private \ExternalApi\ElasticsearchApi $elastic;

    protected function setUp(): void {
        parent::setUp();
        $this->elastic = new \ExternalApi\ElasticsearchApi();
        if (!$this->elastic->isexistsIndex('mass_index')) {
            $this->markTestSkipped('Nincs mass_index ebben a környezetben.');
        }
    }

    /**
     * A bulk insert alapból ~1 másodperc múlva válik kereshetővé. A pótindexelés
     * visszaellenőrzése enélkül hamis "üres" eredményt adna, és minden éppen most
     * pótolt templomot tévesen "tényleg nincs miséje" listára tenne.
     */
    public function testARefreshLefut(): void {
        $this->assertTrue(
            $this->elastic->refreshIndex('mass_index'),
            'Az index frissítésének sikerülnie kell.'
        );
    }

    public function testAFrissenIndexeltMiseAzonnalMegtalalhato(): void {
        $churchId = \Eloquent\Church::where('ok', 'i')->has('massrules')->value('id');
        if (!$churchId) {
            $this->markTestSkipped('Nincs miserenddel rendelkező templom a teszt-adatbázisban.');
        }

        \ExternalApi\ElasticsearchApi::updateMasses([(int) date('Y')], [$churchId], null);
        $this->elastic->refreshIndex('mass_index');

        $indexed = array_map('intval', $this->elastic->churchIdsWithMassesInPeriod(date('Y-01-01'), date('Y-12-31')));

        $this->assertContains(
            (int) $churchId,
            $indexed,
            'A refresh után az imént indexelt templomnak szerepelnie kell az aggregációban.'
        );
    }

    /**
     * A vízjel körbejárása: amit "tényleg üres"-nek jelöltünk, azt vissza kell tudni
     * olvasni, különben a pótindexelés minden futásban újrapróbálná ugyanazokat.
     */
    public function testAzUresTemplomokListajaVisszaolvashato(): void {
        $eredeti = $this->elastic->getIndexMeta('mass_index');

        try {
            $ujMeta = $eredeti;
            $ujMeta['churches_without_masses'] = [111111, 222222];
            $this->assertTrue($this->elastic->setIndexMeta('mass_index', $ujMeta));

            $vissza = $this->elastic->getIndexMeta('mass_index');
            $this->assertSame([111111, 222222], array_map('intval', $vissza['churches_without_masses']));

            // A jelöltválasztás ténylegesen kihagyja őket.
            $this->assertSame(
                [333333],
                \ExternalApi\ElasticsearchApi::massReindexCandidates(
                    [111111, 222222, 333333],
                    [],
                    array_map('intval', $vissza['churches_without_masses']),
                    100
                )
            );
        } finally {
            // Az _meta PUT a teljes tartalmat lecseréli, ezért az eredetit írjuk vissza.
            $this->elastic->setIndexMeta('mass_index', $eredeti);
        }
    }

    /**
     * A pótindexelés akkor se boruljon, ha épp nincs mit tenni.
     */
    public function testNincsMitTenniEsetenIsRendbenFutLe(): void {
        $result = \ExternalApi\ElasticsearchApi::reindexMissingMasses(0, null);

        $this->assertSame(0, $result['candidates']);
        $this->assertSame(0, $result['reindexed']);
        $this->assertSame(0, $result['still_empty']);
    }
}
