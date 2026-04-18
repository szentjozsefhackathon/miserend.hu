<?php

use PHPUnit\Framework\TestCase;
use Api\Login;

require_once __DIR__ . '/../../classes/MockPhpInputStreamWrapper.php';

class LoginTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $_REQUEST = [];
    }

    protected function tearDown(): void {
        parent::tearDown();
        $_REQUEST = [];
        MockPhpInputStreamWrapper::restorePhpInput();
    }

    // Configuration tests

    public function testLoginRequiresApiVersion4OrHigher() {
        $login = new Login();
        
        $this->assertEquals(['>=', 4], $login->requiredVersion);
    }

    public function testLoginHasCorrectTitle() {
        $login = new Login();
        
        $this->assertEquals('Felhasználó azonosítás', $login->title);
    }

    // Field definition tests

    public function testLoginDefinesUsernameField() {
        $login = new Login();
        
        $this->assertArrayHasKey('username', $login->fields);
    }

    public function testLoginDefinesPasswordField() {
        $login = new Login();
        
        $this->assertArrayHasKey('password', $login->fields);
    }

    public function testUsernameFieldIsRequired() {
        $login = new Login();
        
        $this->assertTrue($login->fields['username']['required']);
    }

    public function testPasswordFieldIsRequired() {
        $login = new Login();
        
        $this->assertTrue($login->fields['password']['required']);
    }

    public function testUsernameFieldValidationIsString() {
        $login = new Login();
        
        $this->assertEquals('string', $login->fields['username']['validation']);
    }

    public function testPasswordFieldValidationIsString() {
        $login = new Login();
        
        $this->assertEquals('string', $login->fields['password']['validation']);
    }

    public function testUsernameFieldHasDescription() {
        $login = new Login();
        
        $this->assertArrayHasKey('description', $login->fields['username']);
        $this->assertNotEmpty($login->fields['username']['description']);
    }

    public function testPasswordFieldHasDescription() {
        $login = new Login();
        
        $this->assertArrayHasKey('description', $login->fields['password']);
        $this->assertNotEmpty($login->fields['password']['description']);
    }

    // Documentation tests

    public function testDocsReturnsArray() {
        $login = new Login();
        
        $docs = $login->docs();
        
        $this->assertIsArray($docs);
    }

    public function testDocsContainsDescription() {
        $login = new Login();
        
        $docs = $login->docs();
        
        $this->assertArrayHasKey('description', $docs);
        $this->assertNotEmpty($docs['description']);
    }

    public function testDocsContainsResponse() {
        $login = new Login();
        
        $docs = $login->docs();
        
        $this->assertArrayHasKey('response', $docs);
        $this->assertNotEmpty($docs['response']);
    }

    public function testDocsDescriptionMentionsToken() {
        $login = new Login();
        
        $docs = $login->docs();
        
        $this->assertStringContainsString('token', strtolower($docs['description']));
    }

    public function testDocsResponseMentionsError() {
        $login = new Login();
        
        $docs = $login->docs();
        
        $this->assertStringContainsString('error', strtolower($docs['response']));
    }

    public function testDocsResponseMentionsToken() {
        $login = new Login();
        
        $docs = $login->docs();
        
        $this->assertStringContainsString('token', strtolower($docs['response']));
    }

    // Run method tests

    public function testRunRequiresVersion4() {
        $_REQUEST['v'] = 3; // Set API version in request
        
        $login = new Login();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API version (3) does not match the required version');
        
        $login->run();
    }

    public function testRunRequiresJsonInput() {
        $_REQUEST['v'] = 4;
        
        $login = new Login();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('There is no JSON input');
        
        $login->run();
    }

    public function testRunRequiresUsernameField() {
        $_REQUEST['v'] = 4;
        MockPhpInputStreamWrapper::mockPhpInput(json_encode(['password' => 'test123']));
        
        $login = new Login();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'username' is required in JSON");
        
        $login->run();
    }

    public function testRunRequiresPasswordField() {
        $_REQUEST['v'] = 4;
        MockPhpInputStreamWrapper::mockPhpInput(json_encode(['username' => 'testuser']));
        
        $login = new Login();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'password' is required in JSON");
        
        $login->run();
    }
}
