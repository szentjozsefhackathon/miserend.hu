<?php

use PHPUnit\Framework\TestCase;
use Api\Api;

require_once __DIR__ . '/../../classes/MockPhpInputStreamWrapper.php';

class ApiTest extends TestCase {

    protected array $testFilesCreated = [];

    protected function setUp(): void {
        parent::setUp();
        // Mock $_REQUEST for version parameter
        $_REQUEST = [];
        // Initialize test file tracking
        $this->testFilesCreated = [];
    }

    protected function tearDown(): void {
        parent::tearDown();
        $_REQUEST = [];
        // Clean up any test files created during the test
        foreach ($this->testFilesCreated as $filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        $this->testFilesCreated = [];
    }

    // Version validation tests

    public function testValidateVersionMainAcceptsVersion1() {
        $api = new Api();
        $api->version = 1;
        
        $api->validateVersionMain();
        
        $this->assertEquals(1, $api->version);
    }

    public function testValidateVersionMainAcceptsVersion4() {
        $api = new Api();
        $api->version = 4;
        
        $api->validateVersionMain();
        
        $this->assertEquals(4, $api->version);
    }

    public function testValidateVersionMainRejectsVersion0() {
        $api = new Api();
        $api->version = 0;
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid API version.');
        
        $api->validateVersionMain();
    }

    public function testValidateVersionMainRejectsVersion5() {
        $api = new Api();
        $api->version = 5;
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid API version.');
        
        $api->validateVersionMain();
    }

    public function testValidateVersionMainRejectsNonNumericVersion() {
        $api = new Api();
        $api->version = 'invalid';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid API version.');
        
        $api->validateVersionMain();
    }

    public function testValidateVersionMainEnforcesMinimumRequiredVersion() {
        $api = new Api();
        $api->version = 3;
        $api->requiredVersion = ['>=', 4];
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API version (3) does not match the required version');
        
        $api->validateVersionMain();
    }

    public function testValidateVersionMainAcceptsVersionMeetingMinimumRequirement() {
        $api = new Api();
        $api->version = 4;
        $api->requiredVersion = ['>=', 4];
        
        $api->validateVersionMain();
        
        $this->assertEquals(4, $api->version);
    }

    public function testValidateVersionMainEnforcesMaximumRequiredVersion() {
        $api = new Api();
        $api->version = 4;
        $api->requiredVersion = ['<', 4];
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API version (4) does not match the required version');
        
        $api->validateVersionMain();
    }

    public function testValidateVersionMainRejectNonArrayRequiredVersion() {
        $api = new Api();
        $api->version = 4;
        $api->requiredVersion = 'invalid';
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid requiredVersion for API endpoint.');
        
        $api->validateVersionMain();
    }

    public function testValidateVersionLocalIfExists() {
        
        $api = new class extends Api {
            public $validateVersionCalled = false;
            public function validateVersion() {
                $this->validateVersionCalled = true;
            }
        };
        $api->version = 4;
        $api->requiredVersion = ['>=', 4];
        
        // Should not throw an exception since local validation is optional
        $api->validateVersionMain();

        $this->assertTrue($api->validateVersionCalled); // No exception thrown
    }

    // Integer validation tests

    public function testValidateIntegerAcceptsValidInteger() {
        $api = new Api();
        
        $api->validateInteger('testField', [], 42);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateIntegerRejectsFloat() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be an integer.");
        
        $api->validateInteger('testField', [], 42.5);
    }

    public function testValidateIntegerRejectsString() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be an integer.");
        
        $api->validateInteger('testField', [], 'not a number');
    }

    public function testValidateIntegerEnforcesMinimum() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be at least 10.");
        
        $api->validateInteger('testField', ['minimum' => 10], 5);
    }

    public function testValidateIntegerEnforcesMaximum() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be at most 100.");
        
        $api->validateInteger('testField', ['maximum' => 100], 150);
    }

    public function testValidateIntegerAcceptsValueWithinRange() {
        $api = new Api();
        
        $api->validateInteger('testField', ['minimum' => 10, 'maximum' => 100], 50);
        
        $this->assertTrue(true); // No exception thrown
    }

    // Float validation tests

    public function testValidateFloatAcceptsValidFloat() {
        $api = new Api();
        
        $api->validateFloat('testField', [], 42.5);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateFloatAcceptsInteger() {
        $api = new Api();
        
        $api->validateFloat('testField', [], 42);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateFloatRejectsString() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be a float.");
        
        $api->validateFloat('testField', [], 'not a number');
    }

    public function testValidateFloatEnforcesMinimum() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be at least 10.5.");
        
        $api->validateFloat('testField', ['minimum' => 10.5], 5.2);
    }

    public function testValidateFloatEnforcesMaximum() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be at most 100.5.");
        
        $api->validateFloat('testField', ['maximum' => 100.5], 150.8);
    }

    // String validation tests

    public function testValidateStringAcceptsValidString() {
        $api = new Api();
        
        $api->validateString('testField', [], 'valid string');
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateStringRejectsInteger() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be a string.");
        
        $api->validateString('testField', [], 42);
    }

    public function testValidateStringRejectsArray() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be a string.");
        
        $api->validateString('testField', [], ['not', 'a', 'string']);
    }

    public function testValidateStringEnforcesMinLength() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be at least 5 characters long.");
        
        $api->validateString('testField', ['minLength' => 5], 'abc');
    }

    public function testValidateStringEnforcesMaxLength() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be at most 10 characters long.");
        
        $api->validateString('testField', ['maxLength' => 10], 'this is a very long string');
    }

    public function testValidateStringEnforcesPattern() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' does not match the required pattern.");
        
        $api->validateString('testField', ['pattern' => '^[a-z]+$'], 'ABC123');
    }

    public function testValidateStringAcceptsValidPattern() {
        $api = new Api();
        
        $api->validateString('testField', ['pattern' => '^[a-z]+$'], 'validstring');
        
        $this->assertTrue(true); // No exception thrown
    }

    // Enum validation tests

    public function testValidateEnumAcceptsSimpleValue() {
        $api = new Api();
        
        $api->validateEnum('testField', ['option1', 'option2', 'option3'], 'option2');
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateEnumRejectsInvalidValue() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be one of:");
        
        $api->validateEnum('testField', ['option1', 'option2'], 'invalid');
    }

    // Variable validation tests (dispatcher method)

    public function testValidateVariableDispatchesToIntegerValidation() {
        $api = new Api();
        
        $api->validateVariable('integer', 'testField', [], 42);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateVariableDispatchesToStringValidation() {
        $api = new Api();
        
        $api->validateVariable('string', 'testField', [], 'test');
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateVariableValidatesBoolean() {
        $api = new Api();
        
        $api->validateVariable('boolean', 'testField', [], true);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateVariableRejectsInvalidBoolean() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be a boolean.");
        
        $api->validateVariable('boolean', 'testField', [], 'true');
    }

    public function testValidateVariableValidatesDate() {
        $api = new Api();
        
        $api->validateVariable('date', 'testField', [], '2026-03-20');
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateVariableRejectsInvalidDate() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be a date (yyyy-mm-dd).");
        
        $api->validateVariable('date', 'testField', [], '2026-13-45');
    }

    public function testValidateVariableValidatesList() {
        $api = new Api();
        
        $api->validateVariable('list', 'testField', ['integer' => []], [1, 2, 3]);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testValidateVariableRejectsNonArrayList() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be a list/array.");
        
        $api->validateVariable('list', 'testField', [], 'not an array');
    }

    public function testValidateVariableRejectsUnknownType() {
        $api = new Api();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Unknown validation type 'unknown' for field 'testField'.");
        
        $api->validateVariable('unknown', 'testField', [], 'value');
    }

    // RequiredInput tests (used by getInputJson)

    public function testRequiredInputAcceptsWhenFieldExists() {
        $api = new Api();
        $api->input = ['username' => 'test', 'password' => 'secret'];
        
        $api->requiredInput(['username', 'password']);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testRequiredInputThrowsWhenFieldMissing() {
        $api = new Api();
        $api->input = ['username' => 'test'];
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'password' is required in JSON.");
        
        $api->requiredInput(['username', 'password']);
    }

    public function testRequiredInputSupportsHierarchicalFields() {
        $api = new Api();
        $api->input = [
            'user' => [
                'name' => 'test',
                'email' => 'test@example.com'
            ]
        ];
        
        $api->requiredInput(['user/name', 'user/email']);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testRequiredInputThrowsWhenHierarchicalFieldMissing() {
        $api = new Api();
        $api->input = [
            'user' => [
                'name' => 'test'
            ]
        ];
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'user/email' is required in JSON.");
        
        $api->requiredInput(['user/email']);
    }

    public function testRequiredInputThrowsWhenParentFieldMissing() {
        $api = new Api();
        $api->input = [];
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'user/name' is required in JSON.");
        
        $api->requiredInput(['user/name']);
    }

    // Tests for getInputJson

    public function testGetInputJsonThrowsWhenNoInput() {
        $api = new Api();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('There is no JSON input.');
        $api->getInputJson();
    }

    public function testGetInputJsonThrowsWhenInvalidJson() {
        // Mock php://input with invalid JSON
        MockPhpInputStreamWrapper::mockPhpInput('invalid json');
        
        $api = new Api();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid JSON input.');
        $api->getInputJson();
    
        // Restore php://input stream wrapper
        MockPhpInputStreamWrapper::restorePhpInput();        
    }

    public function testGetInputJsonUnkownField() {
        MockPhpInputStreamWrapper::mockPhpInput(json_encode(['unknownField' => 'value']));
   
        $api = new Api();
        $api->fields = $fields = [
            'knownField' => [
                'required' => true, 
                'description' => 'A known field'
            ],              
        ];            
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unknown field \'unknownField\' in JSON input.');
        $input = $api->getInputJson();
    
        MockPhpInputStreamWrapper::restorePhpInput();        
    }

    public function testGetInputJsonValidInput() {
        $inputData = [
            'knownField' => 1.3,
            'knownField2' => 'value2',
            'anotherField' => 15,
            'anotherField2' => 20
        ];
        MockPhpInputStreamWrapper::mockPhpInput(json_encode($inputData));

        $api = new class extends Api {
            public function validateField($field,$validation) {
                return true;
            }
            public function validateInput() {
                return true;
            }
        };
        $api->requiredFields = ['knownField',"knownField2"];
        $api->fields = $fields = [
            'knownField' => [
                'required' => true, 
                'validation' => 'float',
                'description' => 'A known field'
            ],
            'emptyField' => [
                'required' => false, 
                'validation' => 'string',
                'description' => 'An optional field'
            ],            
            'knownField2' => [
                'required' => true, 
                'description' => 'A known field'
            ],
            'anotherField' => [
                'required' => false, 
                'validation' => ['integer' => ['minimum' => 10]],
                'description' => 'Another known field'
            ],
            'anotherField2' => [
                'required' => false, 
                'validation' => ['integer' => 10],
                'description' => 'Another known field 2'
            ],
            'field/subfield' => [
                'required' => false, 
                'validation' => 'string',
                'description' => 'A known field with subfield'
            ]
        ];            
        $input = $api->getInputJson();
    
        $this->assertTrue(true); // No exception thrown
    
        MockPhpInputStreamWrapper::restorePhpInput();        
    }

    // Test for collectApiEndpoints()
    
    public function testCollectApiEndpointsReturns() {
        $api = new Api();
        $endpoints = $api->collectApiEndpoints();
        
        // Ensure the base Api class itself is not returned as an endpoint
        $this->assertIsArray($endpoints);
        $this->assertNotEmpty($endpoints);
    }

    // Az "Api" nevű endpointot nem szabad visszaadni, mivel az maga az alap osztály, nem egy külön API endpoint
    public function testCollectApiEndpointsNoApi() {
        $api = new Api();
        $endpoints = $api->collectApiEndpoints();
        
        // Ensure the base Api class itself is not returned as an endpoint
        $this->assertNotContains('Api', $endpoints);
    }
}
