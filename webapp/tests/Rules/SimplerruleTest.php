<?php

require_once dirname(__DIR__, 2) . '/functions.php';

use PHPUnit\Framework\TestCase;

class SimplerruleTest extends TestCase {

    public function testSimplerruleNoVariableNoException() {
        $result = simplerrule(date('Y-m-d H:i:s'), 'Wednesday', 0, '1', '10');
        $this->assertTrue($result);
    }

    public function testSimplerruleNoVariableExceptionNoException() {
        $result = simplerrule(date('Y-m-d H:i:s'), 'Wednesday', 1, '10', '10');
        $this->assertFalse($result);
    }

    public function testSimplerruleNullDateReturnsFalse() {
        $this->assertFalse(simplerrule(null, 'Wednesday', 0, '1', '10'));
    }
}