<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #374: Az EditOsm két tiszta helperjének lefedése.
 *
 * - prepareAutocomplete: Overpass-elemek tagjaiból kulcs→érték előfordulás-számláló,
 *   kulcsonként ábécébe rendezve (ksort).
 * - prepareUpdatedOsmtags: az érvényes kulcsokat összeveti az eredeti OSM-tagekkel és
 *   eldönti add/update/delete/no-op-ot; a "Nincs információ..." bemenetet üríti; ha
 *   nincs változás, false.
 *
 * A konstruktor OSM/DB I/O-t végez, ezért newInstanceWithoutConstructor. A helperek
 * public-ok. A prepareUpdatedOsmtags sikeres ága addMessage-et hív (DB-insert a
 * messages táblába), ezért Integration-suite + tranzakció, ami rollbackeli.
 */
class EditOsmHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function editOsm(): \Html\Church\EditOsm
    {
        return (new \ReflectionClass(\Html\Church\EditOsm::class))->newInstanceWithoutConstructor();
    }

    private function element(array $tags): \stdClass
    {
        $e = new \stdClass();
        $e->tags = (object) $tags;
        return $e;
    }

    // ─── prepareAutocomplete() ──────────────────────────────────────────────

    public function testPrepareAutocompleteCountsAndSorts(): void
    {
        $json = new \stdClass();
        $json->elements = [
            $this->element(['amenity' => 'place_of_worship', 'religion' => 'christian', 'denomination' => 'roman_catholic']),
            $this->element(['amenity' => 'place_of_worship', 'religion' => 'christian', 'denomination' => 'greek_catholic']),
            $this->element(['amenity' => 'place_of_worship', 'denomination' => 'roman_catholic']),
        ];

        $r = $this->editOsm()->prepareAutocomplete($json);

        $this->assertSame(3, $r['amenity']['place_of_worship']);
        $this->assertSame(2, $r['denomination']['roman_catholic']);
        $this->assertSame(1, $r['denomination']['greek_catholic']);
        $this->assertSame(2, $r['religion']['christian']);
        // ksort: a denomination értékei ábécében.
        $this->assertSame(['greek_catholic', 'roman_catholic'], array_keys($r['denomination']));
    }

    public function testPrepareAutocompleteEmptyElements(): void
    {
        $json = new \stdClass();
        $json->elements = [];
        $this->assertSame([], $this->editOsm()->prepareAutocomplete($json));
    }

    // ─── prepareUpdatedOsmtags() ────────────────────────────────────────────

    public function testPrepareUpdatedAddDeleteAndNoOp(): void
    {
        $o = $this->editOsm();
        $o->osmtags = (object) ['amenity' => 'place_of_worship', 'phone' => '+36 1 111'];
        $o->validKeys = ['amenity', 'phone', 'website', 'wheelchair'];
        
        $r = $o->prepareUpdatedOsmtags([
            'amenity'    => 'place_of_worship',        // változatlan
            'phone'      => '',                        // törlés
            'website'    => 'https://x.hu',            // hozzáadás
            'wheelchair' => 'Nincs információ. yes',   // '' -> no-op (nincs az eredetiben)
        ]);

        $this->assertSame(
            ['amenity' => 'place_of_worship', 'website' => 'https://x.hu'],
            $r
        );
    }

    public function testPrepareUpdatedChangesExistingValue(): void
    {
        $o = $this->editOsm();
        $o->osmtags = (object) ['amenity' => 'place_of_worship', 'phone' => '+36 1 111'];
        $o->validKeys = ['amenity', 'phone', 'website', 'wheelchair'];
        
        $r = $o->prepareUpdatedOsmtags(['phone' => '+36 2 222']);

        $this->assertSame('+36 2 222', $r['phone']);
    }

    public function testPrepareUpdatedNoChangeReturnsFalse(): void
    {
        $o = $this->editOsm();
        $o->osmtags = (object) ['amenity' => 'place_of_worship', 'phone' => '+36 1 111'];
        $o->validKeys = ['amenity', 'phone', 'website', 'wheelchair'];
        
        $this->assertFalse($o->prepareUpdatedOsmtags(['amenity' => 'place_of_worship']));
    }
}
