<?php

use PHPUnit\Framework\TestCase;

/**
 * A /health régóta mutatja, hogy vannak misézőhelyek, amiknek van miserendjük az
 * adatbázisban, de az idei évre egyetlen dokumentumuk sincs a mass_indexben — élesben
 * 631 ilyen volt, vagyis ennyi templom egyszerűen kimaradt a keresésekből.
 *
 * A pótindexelés jelöltválasztása szándékosan tiszta függvény (se DB, se ES), így itt
 * mockok nélkül tesztelhető.
 */
class MassReindexCandidatesTest extends TestCase {

    public function testCsakAzokMaradnakAkiknekNincsIndexeltMiseje(): void {
        $candidates = \ExternalApi\ElasticsearchApi::massReindexCandidates(
            [1, 2, 3, 4, 5],   // van miserendjük
            [1, 3],            // ezeknek van idei indexelt miséjük
            [],                // nincs ismert üres
            100
        );

        $this->assertSame([2, 4, 5], $candidates);
    }

    public function testAzIsmertenUresTemplomokatNemProbaljukUjra(): void {
        // Ha nem hagynánk ki őket, minden futás ugyanazon a néhány száz templomon
        // pörögne, amiknek tényleg nincs idei miséjük.
        $candidates = \ExternalApi\ElasticsearchApi::massReindexCandidates(
            [1, 2, 3, 4, 5],
            [1],
            [3, 5],
            100
        );

        $this->assertSame([2, 4], $candidates);
    }

    public function testALimitBetartasa(): void {
        $withRules = range(1, 500);
        $candidates = \ExternalApi\ElasticsearchApi::massReindexCandidates($withRules, [], [], 100);

        $this->assertCount(100, $candidates);
        $this->assertSame(1, $candidates[0]);
        $this->assertSame(100, $candidates[99]);
    }

    public function testHaMindenIndexelveVanNincsJelolt(): void {
        $this->assertSame(
            [],
            \ExternalApi\ElasticsearchApi::massReindexCandidates([1, 2, 3], [1, 2, 3], [], 100)
        );
    }

    /**
     * Az ES aggregáció stringként is adhat vissza kulcsot, a DB pluck() int-et — a
     * kettőt össze kell tudni vetni, különben minden templom "hiányzónak" látszana.
     */
    public function testStringEsIntAzonositokatIsOsszeveti(): void {
        $candidates = \ExternalApi\ElasticsearchApi::massReindexCandidates(
            ['1', '2', '3'],
            ['2'],
            ['3'],
            100
        );

        $this->assertSame([1], $candidates);
    }

    /**
     * Nulla (vagy negatív) limit = ne csinálj semmit. A naiv "adjuk hozzá, aztán
     * ellenőrizzük a darabszámot" ciklus itt épp egy jelöltet adna vissza.
     */
    public function testNullaLimitreNincsJelolt(): void {
        $this->assertSame(
            [],
            \ExternalApi\ElasticsearchApi::massReindexCandidates([1, 2, 3], [], [], 0)
        );
        $this->assertSame(
            [],
            \ExternalApi\ElasticsearchApi::massReindexCandidates([1, 2, 3], [], [], -5)
        );
    }

    public function testDuplikatumokatNemAdVisszaKetszer(): void {
        $candidates = \ExternalApi\ElasticsearchApi::massReindexCandidates(
            [7, 7, 8, 8, 8],
            [],
            [],
            100
        );

        $this->assertSame([7, 8], $candidates);
    }
}
