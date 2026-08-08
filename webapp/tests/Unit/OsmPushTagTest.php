<?php

use PHPUnit\Framework\TestCase;

/*
 * #484: az /edit mentése azonnal felküldi az OSM-be a származtatott címkét.
 * Itt a döntés tiszta része van tesztelve: mikor kell egyáltalán changesetet nyitni,
 * és milyen taglista menjen ki.
 */
final class OsmPushTagTest extends TestCase
{
    public function testNewTagIsAdded(): void
    {
        $result = \OSM::applyTagChange(['amenity' => 'place_of_worship'], 'diet:gluten_free', 'yes');

        $this->assertSame(['amenity' => 'place_of_worship', 'diet:gluten_free' => 'yes'], $result);
    }

    public function testChangedValueIsWritten(): void
    {
        $result = \OSM::applyTagChange(['diet:gluten_free' => 'limited'], 'diet:gluten_free', 'yes');

        $this->assertSame(['diet:gluten_free' => 'yes'], $result);
    }

    public function testEmptyValueRemovesTheTag(): void
    {
        $result = \OSM::applyTagChange(
            ['amenity' => 'place_of_worship', 'diet:gluten_free' => 'no'],
            'diet:gluten_free',
            ''
        );

        $this->assertSame(['amenity' => 'place_of_worship'], $result);
    }

    public function testUnchangedValueOpensNoChangeset(): void
    {
        $this->assertNull(\OSM::applyTagChange(['diet:gluten_free' => 'yes'], 'diet:gluten_free', 'yes'));
    }

    public function testStillMissingTagOpensNoChangeset(): void
    {
        $this->assertNull(\OSM::applyTagChange(['amenity' => 'place_of_worship'], 'diet:gluten_free', ''));
    }

    public function testOtherTagsAreKeptUntouched(): void
    {
        $tags = [
            'amenity' => 'place_of_worship',
            'religion' => 'christian',
            'denomination' => 'roman_catholic',
        ];

        $result = \OSM::applyTagChange($tags, 'diet:gluten_free', 'limited');

        $this->assertSame($tags + ['diet:gluten_free' => 'limited'], $result);
    }
}
