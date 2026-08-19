<?php

use PHPUnit\Framework\TestCase;

/**
 * A mise hosszának átszámítása percre.
 *
 * Ugyanez a számítás kétszer szerepelt szó szerint a generálásban, egy harmadik helyen
 * pedig beégetett `0` állt helyette, egy TODO kíséretében („a $mass->duration-ből ki
 * tudnánk találni"). Az a hely az „extra", időszak nélküli miséket állítja elő —
 * náluk tehát a hossz elveszett.
 *
 * Ennek látható következménye is volt: az iCal-export a nulla hosszt egyórásra pótolja
 * (`ical.php`: `$duration = ($mass['duration_minutes']!=0) ? ... : 60;`), tehát egy
 * húszperces alkalom a feliratkozó naptárában egy órát foglalt.
 */
class MassDurationTest extends TestCase {

    public function testOrabolEsPercbolSzamol(): void {
        self::assertSame(90, \Eloquent\CalMass::durationInMinutes(['hours' => 1, 'minutes' => 30]));
    }

    public function testANapokatIsBeszamitja(): void {
        self::assertSame(24 * 60 + 60 + 5, \Eloquent\CalMass::durationInMinutes(
            ['days' => 1, 'hours' => 1, 'minutes' => 5]
        ));
    }

    /** Az élő adatban a `hours` gyakran null — a `{"hours": null, "minutes": 30}` alak. */
    public function testANullMezotNullanakVeszi(): void {
        self::assertSame(30, \Eloquent\CalMass::durationInMinutes(['hours' => null, 'minutes' => 30]));
    }

    public function testAHianyzoMezotNullanakVeszi(): void {
        self::assertSame(45, \Eloquent\CalMass::durationInMinutes(['minutes' => 45]));
    }

    /** A modell tömbbé alakít, de a hívó kaphat nyers JSON-sztringet is. */
    public function testASztringetIsErtelmezi(): void {
        self::assertSame(30, \Eloquent\CalMass::durationInMinutes('{"hours": 0, "minutes": 30}'));
    }

    /** @dataProvider ertelmezhetetlenErtekek */
    public function testAzErtelmezhetetlenErtekNulla($ertek): void {
        self::assertSame(0, \Eloquent\CalMass::durationInMinutes($ertek));
    }

    public static function ertelmezhetetlenErtekek(): array {
        return [
            'null'          => [null],
            'üres tömb'     => [[]],
            'üres sztring'  => [''],
            'hibás JSON'    => ['{nem json}'],
            'szám'          => [42],
            'nulla'         => [0],
        ];
    }

    /** Nulla hossz nulla marad — ez az „ismeretlen", nem az „azonnal véget ér". */
    public function testANullaHosszNullaMarad(): void {
        self::assertSame(0, \Eloquent\CalMass::durationInMinutes(['hours' => 0, 'minutes' => 0]));
    }
}
