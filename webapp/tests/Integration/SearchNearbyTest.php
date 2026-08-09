<?php

use PHPUnit\Framework\TestCase;

final class SearchNearbyTest extends TestCase
{
    public function testNearbyMassSearchUsesRadiusFilterAndDistanceSort(): void
    {
        $search = new Search('masses');
        $search->nearby(47.4979, 19.0402, 15.0);

        self::assertSame([
            'geo_distance' => [
                'distance' => '15km',
                'church_runtime_location' => ['lat' => 47.4979, 'lon' => 19.0402],
            ],
        ], $search->query['bool']['filter'][0]);

        self::assertSame('asc', $search->sort[0]['_geo_distance']['order']);
        self::assertSame('km', $search->sort[0]['_geo_distance']['unit']);
        self::assertSame(
            ['lat' => 47.4979, 'lon' => 19.0402],
            $search->sort[0]['_geo_distance']['church_runtime_location']
        );
        self::assertSame('geo_point', $search->runtimeMappings['church_runtime_location']['type']);
        self::assertSame(0, $search->distanceSortIndex);
    }

    /**
     * #608: a „legközelebbi mise" keresés idő szerint rendez, a távolság csak
     * másodlagos. A sugárszűrő viszont ugyanúgy érvényes marad.
     */
    public function testNearbyCanSortByTimeWhileKeepingTheRadiusFilter(): void
    {
        $search = new Search('masses');
        $search->nearby(47.4979, 19.0402, 3.0, false);

        self::assertSame([
            'geo_distance' => [
                'distance' => '3km',
                'church_runtime_location' => ['lat' => 47.4979, 'lon' => 19.0402],
            ],
        ], $search->query['bool']['filter'][0]);

        self::assertSame(['start_date' => ['order' => 'asc']], $search->sort[0]);
        self::assertSame('asc', $search->sort[1]['_geo_distance']['order']);
        // A távolságot az 1-es slotból kell olvasni: a 0-s az epoch-milliszekundum.
        self::assertSame(1, $search->distanceSortIndex);
    }

    public function testNearbyRejectsCoordinatesAndRadiusOutsideTheirValidRanges(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Search('masses'))->nearby(91, 19.0402, 15);
    }

    public function testNearbyIsOnlyAvailableForMassSearches(): void
    {
        $this->expectException(LogicException::class);

        (new Search('churches'))->nearby(47.4979, 19.0402, 15);
    }
}
