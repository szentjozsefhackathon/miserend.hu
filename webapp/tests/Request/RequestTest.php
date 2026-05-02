<?php

use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase {

    public function __construct(?string $name = null)
    {        
        parent::__construct($name);
        include_once __DIR__ . '/../../classes/request.php';
        
        // Mock sanitize function if not exists
        if (!function_exists('sanitize')) {
            function sanitize($value) {
                return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
            }
        }
    }

    protected function tearDown(): void {
        // Clean up $_REQUEST after each test
        $_REQUEST = [];
    }

    // Integer() tests
    public function testInteger() {
        $_REQUEST['test'] = 123;
        $this->assertEquals(123, \Request::Integer('test'));
    }

    public function testIntegerInvalid() {
        $_REQUEST['test'] = 'abc';
        $this->expectException(Exception::class);
        \Request::Integer('test');
    }

    public function testIntegerEmpty() {
        $_REQUEST['test'] = '';
        $this->assertEquals('', \Request::Integer('test'));
    }

    public function testIntegerNotSet() {
        unset($_REQUEST['test']);
        $this->assertFalse(\Request::Integer('test'));
    }

    // IntegerRequired() tests
    public function testIntegerRequired() {
        $_REQUEST['test'] = 456;
        $this->assertEquals(456, \Request::IntegerRequired('test'));
    }

    public function testIntegerRequiredInvalid() {
        $_REQUEST['test'] = 'invalid';
        $this->expectException(Exception::class);
        \Request::IntegerRequired('test');
    }

    public function testIntegerRequiredNotSet() {
        unset($_REQUEST['test']);
        $this->expectException(Exception::class);
        \Request::IntegerRequired('test');
    }

    // IntegerwDefault() tests
    public function testIntegerWithDefault() {
        $_REQUEST['test'] = 789;
        $this->assertEquals(789, \Request::IntegerwDefault('test', 100));
    }

    public function testIntegerWithDefaultUseDefault() {
        unset($_REQUEST['test']);
        $this->assertEquals(100, \Request::IntegerwDefault('test', 100));
    }

    public function testIntegerWithDefaultInvalid() {
        $_REQUEST['test'] = 'invalid';
        $this->expectException(Exception::class);
        \Request::IntegerwDefault('test', 100);
    }

    // Text() tests
    public function testText() {
        $_REQUEST['test'] = 'Hello World';
        $result = \Request::Text('test');
        $this->assertIsString($result);
    }

    public function testTextEmpty() {
        $_REQUEST['test'] = '';
        $result = \Request::Text('test');
        $this->assertEquals('', $result);
    }

    public function testTextNotSet() {
        unset($_REQUEST['test']);
        $result = \Request::Text('test');
        $this->assertIsString($result);
    }

    // TextRequired() tests
    public function testTextRequired() {
        $_REQUEST['test'] = 'Required Text';
        $result = \Request::TextRequired('test');
        $this->assertIsString($result);
    }

    public function testTextRequiredNotSet() {
        unset($_REQUEST['test']);
        $this->expectException(Exception::class);
        \Request::TextRequired('test');
    }

    // TextwDefault() tests
    public function testTextWithDefault() {
        $_REQUEST['test'] = 'Custom';
        $result = \Request::TextwDefault('test', 'Default');
        $this->assertIsString($result);
    }

    public function testTextWithDefaultUseDefault() {
        unset($_REQUEST['test']);
        $result = \Request::TextwDefault('test', 'Default');
        $this->assertEquals('Default', $result);
    }

    // InArray() tests
    public function testInArray() {
        $_REQUEST['test'] = 'value1';
        $array = ['value1', 'value2', 'value3'];
        $this->assertEquals('value1', \Request::InArray('test', $array));
    }

    public function testInArrayNotSet() {
        unset($_REQUEST['test']);
        $array = ['value1', 'value2'];
        $this->assertFalse(\Request::InArray('test', $array));
    }

    public function testInArrayInvalid() {
        $_REQUEST['test'] = 'invalid';
        $array = ['value1', 'value2'];
        $this->expectException(Exception::class);
        \Request::InArray('test', $array);
    }

    public function testInArrayEmpty() {
        $_REQUEST['test'] = '';
        $array = ['value1', 'value2'];
        $this->assertFalse(\Request::InArray('test', $array));
    }

    // InArrayRequired() tests
    public function testInArrayRequired() {
        $_REQUEST['test'] = 'value1';
        $array = ['value1', 'value2'];
        $this->assertEquals('value1', \Request::InArrayRequired('test', $array));
    }

    public function testInArrayRequiredInvalid() {
        $_REQUEST['test'] = 'invalid';
        $array = ['value1', 'value2'];
        $this->expectException(Exception::class);
        \Request::InArrayRequired('test', $array);
    }

    public function testInArrayRequiredNotSet() {
        unset($_REQUEST['test']);
        $array = ['value1', 'value2'];
        $this->expectException(Exception::class);
        \Request::InArrayRequired('test', $array);
    }

    // Simpletext() tests
    public function testSimpletext() {
        $_REQUEST['test'] = 'valid_text-123';
        $this->assertEquals('valid_text-123', \Request::Simpletext('test'));
    }

    public function testSimpletextInvalid() {
        $_REQUEST['test'] = 'invalid text!';
        $this->expectException(Exception::class);
        \Request::Simpletext('test');
    }

    public function testSimpletextEmpty() {
        $_REQUEST['test'] = '';
        $this->assertEquals('', \Request::Simpletext('test'));
    }

    public function testSimpletextNotSet() {
        unset($_REQUEST['test']);
        $this->assertFalse(\Request::Simpletext('test'));
    }

    // SimpletextwDefault() tests
    public function testSimpletextWithDefault() {
        $_REQUEST['test'] = 'valid-123';
        $this->assertEquals('valid-123', \Request::SimpletextwDefault('test', 'default'));
    }

    public function testSimpletextWithDefaultUseDefault() {
        unset($_REQUEST['test']);
        $this->assertEquals('default', \Request::SimpletextwDefault('test', 'default'));
    }

    public function testSimpletextWithDefaultInvalid() {
        $_REQUEST['test'] = 'invalid!';
        $this->expectException(Exception::class);
        \Request::SimpletextwDefault('test', 'default');
    }

    // SimpletextRequired() tests
    public function testSimpletextRequired() {
        $_REQUEST['test'] = 'required_text-123';
        $this->assertEquals('required_text-123', \Request::SimpletextRequired('test'));
    }

    public function testSimpletextRequiredInvalid() {
        $_REQUEST['test'] = 'invalid!';
        $this->expectException(Exception::class);
        \Request::SimpletextRequired('test');
    }

    public function testSimpletextRequiredNotSet() {
        unset($_REQUEST['test']);
        $this->expectException(Exception::class);
        \Request::SimpletextRequired('test');
    }

    // Date() tests
    public function testDate() {
        $_REQUEST['test'] = '2023-01-01';
        $this->assertEquals('2023-01-01', \Request::Date('test'));
    }

    public function testDateEmpty() {
        $_REQUEST['test'] = '';
        $this->assertEquals('', \Request::Date('test'));
    }
}