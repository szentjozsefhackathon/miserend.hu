<?php

use PHPUnit\Framework\TestCase;

class UtilityFunctionsTest extends TestCase {

    public function testTranslateDayHungarianToEnglish() {
        $this->assertEquals('Wednesday', translateDay('szerda'));
    }

    public function testTranslateDayEnglishToHungarian() {
        $this->assertEquals('szerda', translateDay('Wednesday'));
    }

    public function testTranslateDayUnknownValueReturnsOriginal() {
        $this->assertEquals('foo', translateDay('foo'));
    }

    public function testTranslateWeekHungarianToEnglish() {
        $this->assertEquals('week', translateWeek('hét'));
    }

    public function testTranslateWeekEnglishToHungarian() {
        $this->assertEquals('hét', translateWeek('week'));
    }
}