<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

final class GlutenFreeCommunionPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
    }

    public function testDetailedSettingsAndDerivedOsmValueAreSavedTogether(): void
    {
        $osmValue = \GlutenFreeCommunion::save(1, [
            \GlutenFreeCommunion::HOLIDAYS_KEY => 'at_end',
            \GlutenFreeCommunion::WEEKDAYS_KEY => 'ask_sacristy',
        ]);

        // #484: a mentés visszaadja a származtatott értéket, ezt küldi fel a hívó az OSM-be.
        $this->assertSame('yes', $osmValue);

        $attributes = DB::table('attributes')->where('church_id', 1)
            ->whereIn('key', [
                \GlutenFreeCommunion::HOLIDAYS_KEY,
                \GlutenFreeCommunion::WEEKDAYS_KEY,
                'diet:gluten_free',
            ])
            ->pluck('value', 'key');

        $this->assertSame('at_end', $attributes[\GlutenFreeCommunion::HOLIDAYS_KEY]);
        $this->assertSame('ask_sacristy', $attributes[\GlutenFreeCommunion::WEEKDAYS_KEY]);
        $this->assertSame('yes', $attributes['diet:gluten_free']);
    }

    public function testInvalidSettingIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \GlutenFreeCommunion::save(1, [\GlutenFreeCommunion::HOLIDAYS_KEY => 'invalid']);
    }

    /*
     * #484: ha a formon nincs is gluténmentes mező, ne induljon OSM-felküldés.
     */
    public function testNothingSubmittedMeansNothingToPush(): void
    {
        $this->assertNull(\GlutenFreeCommunion::save(1, ['nev' => 'Valami templom']));
    }
}
