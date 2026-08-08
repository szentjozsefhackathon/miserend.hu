<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

class ChurchRelationshipTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
        // Izoláció a seed-adattól: a seed church_relationships valós templom-id-kra
        // hivatkozik (child_church_id egészen 5435-ig), a teszt-templomok viszont
        // auto-increment id-t kapnak (friss init: 5420-tól) — ezek ÜTKÖZNEK, amitől
        // a `where('child_church_id', id)` egy EXTRA seed-relációt is visszaad, és a
        // ancestors/descendants count nem-determinisztikusan hibás lesz (flaky).
        // A tranzakción belül kiürítjük; a tearDown rollback visszaállítja a seedet.
        DB::table('church_relationships')->delete();
        DB::table('church_holders')->delete();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function createChurch(string $name = 'Teszt', float $lat = 47.5, float $lon = 19.0): int {
        return DB::table('templomok')->insertGetId([
            'nev'       => $name,
            'varos'     => 'Budapest',
            'frissites' => '2020-01-01',
            'ok'        => 'i',
            'plebania'  => '',
            'leiras'    => '',
            'megjegyzes'=> '',
            'misemegj'  => '',
            'bucsu'     => '',
            'adminmegj' => '',
            'log'       => '',
            'lat'       => $lat,
            'lon'       => $lon,
        ]);
    }

    private function createRelationship(int $parentId, int $childId, string $type = 'subordinate'): int {
        return DB::table('church_relationships')->insertGetId([
            'parent_church_id' => $parentId,
            'child_church_id'  => $childId,
            'type'             => $type,
        ]);
    }

    public function testCanCreateRelationship(): void {
        $parent = $this->createChurch('Anyaplébánia');
        $child  = $this->createChurch('Fília');

        $relId = $this->createRelationship($parent, $child, 'subordinate');

        $rel = DB::table('church_relationships')->where('id', $relId)->first();
        $this->assertNotNull($rel);
        $this->assertEquals($parent, $rel->parent_church_id);
        $this->assertEquals($child, $rel->child_church_id);
        $this->assertEquals('subordinate', $rel->type);
    }

    public function testCannotCreateDuplicateRelationship(): void {
        $parent = $this->createChurch('Anyaplébánia');
        $child  = $this->createChurch('Fília');

        $this->createRelationship($parent, $child, 'subordinate');

        $this->expectException(\Exception::class);
        // A UNIQUE KEY unique_pair megakadályozza a duplikátumot
        DB::table('church_relationships')->insert([
            'parent_church_id' => $parent,
            'child_church_id'  => $child,
            'type'             => 'associated',
        ]);
    }

    public function testDeleteCascadesWhenChurchDeleted(): void {
        $parent = $this->createChurch('Anyaplébánia');
        $child  = $this->createChurch('Fília');
        $this->createRelationship($parent, $child, 'subordinate');

        // Közvetlen DB törlés (a CASCADE-t teszteljük)
        DB::table('templomok')->where('id', $parent)->delete();

        $rel = DB::table('church_relationships')
            ->where('parent_church_id', $parent)
            ->first();
        $this->assertNull($rel, 'A kapcsolatnak törlődnie kellett volna a szülő törlésével.');
    }

    public function testGetAncestorsReturnsCorrectChain(): void {
        $grandparent = $this->createChurch('Nagyszülő');
        $parent      = $this->createChurch('Szülő');
        $child       = $this->createChurch('Gyerek');

        $this->createRelationship($grandparent, $parent, 'subordinate');
        $this->createRelationship($parent, $child, 'subordinate');

        $churchModel = \Eloquent\Church::find($child);
        $ancestors = $churchModel->ancestors;

        $this->assertIsArray($ancestors);
        $this->assertCount(1, $ancestors);
        $this->assertEquals($parent, $ancestors[0]['church']->id);
        $this->assertCount(1, $ancestors[0]['children']);
        $this->assertEquals($grandparent, $ancestors[0]['children'][0]['church']->id);
    }

    public function testGetDescendantsReturnsCorrectTree(): void {
        $parent = $this->createChurch('Szülő');
        $child1 = $this->createChurch('Gyerek1');
        $child2 = $this->createChurch('Gyerek2');

        $this->createRelationship($parent, $child1, 'subordinate');
        $this->createRelationship($parent, $child2, 'associated');

        $churchModel = \Eloquent\Church::find($parent);
        $descendants = $churchModel->descendants;

        $this->assertIsArray($descendants);
        $this->assertCount(2, $descendants);

        $ids = array_map(fn($d) => $d['church']->id, $descendants);
        $this->assertContains($child1, $ids);
        $this->assertContains($child2, $ids);
    }

    public function testFullNetworkKeepsEveryGenerationOnItsOwnLevel(): void {
        $grandparent = $this->createChurch('Nagyszülő');
        $parent = $this->createChurch('Szülő');
        $current = $this->createChurch('Aktuális');
        $child = $this->createChurch('Gyerek');

        $this->createRelationship($grandparent, $parent);
        $this->createRelationship($parent, $current);
        $this->createRelationship($current, $child);

        $network = \Eloquent\Church::find($current)->fullNetwork;

        $this->assertSame(
            [$grandparent, $parent, $current, $child],
            array_map(static fn(array $item): int => (int) $item['church']->id, $network)
        );
        $this->assertSame(
            [0, 1, 2, 3],
            array_column($network, 'level')
        );
    }

    public function testCircularRelationshipDoesNotCauseInfiniteLoop(): void {
        $a = $this->createChurch('A');
        $b = $this->createChurch('B');
        $c = $this->createChurch('C');

        // A -> B -> C -> A (kör)
        DB::table('church_relationships')->insert([
            ['parent_church_id' => $a, 'child_church_id' => $b, 'type' => 'subordinate'],
            ['parent_church_id' => $b, 'child_church_id' => $c, 'type' => 'subordinate'],
            ['parent_church_id' => $c, 'child_church_id' => $a, 'type' => 'subordinate'],
        ]);

        $church = \Eloquent\Church::find($a);
        // Nem szabad végtelen hurokba esni
        $descendants = $church->descendants;
        $this->assertIsArray($descendants);
    }

    public function testGetDescendantIdsIncludesSelf(): void {
        $parent = $this->createChurch('Szülő');
        $child  = $this->createChurch('Gyerek');

        $this->createRelationship($parent, $child, 'subordinate');

        $churchModel = \Eloquent\Church::find($parent);
        $ids = $churchModel->descendantIds;

        $this->assertContains($parent, $ids);
        $this->assertContains($child, $ids);
    }

    public function testInheritedWriteAccessFromAncestorHolder(): void {
        $parent = $this->createChurch('Anyaplébánia');
        $child  = $this->createChurch('Fília');
        $this->createRelationship($parent, $child, 'subordinate');

        // Felhasználó létrehozása (fixture user uid=2 az adatbázisban)
        $childChurch = \Eloquent\Church::find($child);

        // Gondnok hozzáadása a szülőhöz
        DB::table('church_holders')->insert([
            'church_id'  => $parent,
            'user_id'    => 2,
            'status'     => 'allowed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Felhasználó mock
        $user = new \stdClass();
        $user->uid = 2;
        $user->username = 'testuser';
        $user->roles = [];

        // checkRole mock - nem admin
        $userMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['checkRole'])
            ->getMock();
        $userMock->uid = 2;
        $userMock->username = 'testuser';
        $userMock->method('checkRole')->willReturn(false);

        $access = $childChurch->checkWriteAccess($userMock);
        $this->assertTrue($access, 'Az örökölt gondnokságnak hozzáférést kell adnia.');
    }

    public function testNonAncestorHolderHasNoInheritedAccess(): void {
        $church1 = $this->createChurch('Első');
        $church2 = $this->createChurch('Második');
        // Nincs kapcsolat a két között

        DB::table('church_holders')->insert([
            'church_id'  => $church1,
            'user_id'    => 2,
            'status'     => 'allowed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $church2Model = \Eloquent\Church::find($church2);

        $userMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['checkRole'])
            ->getMock();
        $userMock->uid = 2;
        $userMock->username = 'testuser';
        $userMock->method('checkRole')->willReturn(false);

        $access = $church2Model->checkWriteAccess($userMock);
        $this->assertFalse($access, 'Nem kapcsolódó templomhoz nem szabad hozzáférést adni.');
    }

    public function testAllEnumTypesAreValid(): void {
        $validTypes = \Eloquent\ChurchRelationship::validTypes();
        $this->assertContains('subordinate', $validTypes);
        $this->assertContains('associated', $validTypes);
        $this->assertContains('territorially_independent', $validTypes);
        $this->assertCount(3, $validTypes);
    }

    public function testAllEnumRanksAreValid(): void {
        $validRanks = \Eloquent\ChurchRelationship::validRanks();
        $this->assertContains('parish', $validRanks);
        $this->assertContains('auxiliary', $validRanks);
        $this->assertContains('filial', $validRanks);
        $this->assertContains('rectoral', $validRanks);
        $this->assertCount(4, $validRanks);
    }
}
