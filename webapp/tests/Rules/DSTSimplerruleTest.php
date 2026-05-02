<?php

require_once dirname(__DIR__, 2) . '/functions.php';

use PHPUnit\Framework\TestCase;

class DSTSimplerruleTest extends TestCase {

    public function testDstAwareSimplerruleForSummerTimeWindow() {
        $result = simplerrule('2024-07-07 08:00:00', 'Sunday', 0, '8', '9', array(), true);

        $this->assertTrue($result);
    }

    public function testDstAwareSimplerruleForWinterTimeWindow() {
        $result = simplerrule('2024-01-07 08:00:00', 'Sunday', 0, '8', '9', array(), true);

        $this->assertTrue($result);
    }
}