<?php

use PHPUnit\Framework\TestCase;
use Api\Api;

require_once __DIR__ . '/../../classes/MockPhpInputStreamWrapper.php';

class ApiTest extends TestCase {

    protected array $testFilesCreated = [];
    private string $tmpApiDir;

    protected function setUp(): void {
        parent::setUp();
        // Mock $_REQUEST for version parameter
        $_REQUEST = [];
        // Initialize test file tracking
        $this->testFilesCreated = [];

        $this->tmpApiDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'miserend_api_' . bin2hex(random_bytes(4));
        mkdir($this->tmpApiDir, 0777, true);
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

        if (isset($this->tmpApiDir) && is_dir($this->tmpApiDir)) {
            @rmdir($this->tmpApiDir);
        }
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

    /**
     * #56: itt korábban azt rögzítettük, hogy az 5-ös verzió NINCS. Azóta van, tehát a
     * teszt a kiadott verziók listájával együtt mozgó számot mért — nem invariánst.
     *
     * A valódi állítás az, hogy a legújabbnál eggyel nagyobb verzió elutasításra kerül.
     * Így a teszt akkor is helyes marad, amikor jön a v6.
     */
    public function testValidateVersionMainRejectsTheVersionAboveTheLatest() {
        $api = new Api();
        $api->version = Api::LEGUJABB_VERZIO + 1;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid API version.');

        $api->validateVersionMain();
    }

    /** A legújabb kiadott verziót viszont el kell fogadni. */
    public function testValidateVersionMainAcceptsTheLatestVersion() {
        $api = new Api();
        $api->version = Api::LEGUJABB_VERZIO;

        $api->validateVersionMain();
        $this->assertSame(Api::LEGUJABB_VERZIO, $api->version);
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

    /**
     * #393: az API dátumellenőrzése puszta reguláris kifejezés volt, ezért elfogadta a
     * naptárban nem létező napokat is — miközben ugyanezeket a \Request helyesen
     * visszautasította. A közös \Validate óta ugyanazt a szigorú ellenőrzést kapja.
     *
     * @dataProvider nemLetezoDatumok
     */
    public function testValidateVariableRejectsNonExistentDate($value) {
        $api = new Api();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Field 'testField' should be a date (yyyy-mm-dd).");

        $api->validateVariable('date', 'testField', [], $value);
    }

    public static function nemLetezoDatumok(): array {
        return [
            'nem szökőév'     => ['2023-02-29'],
            'február 31.'     => ['2023-02-31'],
            'április 31.'     => ['2026-04-31'],
            'november 31.'    => ['2026-11-31'],
        ];
    }

    public function testValidateVariableAcceptsLeapDay() {
        $api = new Api();

        $api->validateVariable('date', 'testField', [], '2024-02-29');

        $this->assertTrue(true); // nem dobott
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

    public function testCollectApiEndpointsReadFileWithError() {
         $filePath = $this->tmpApiDir . '/test.php';
         file_put_contents($filePath, '<?php valami namespace Api; class Church extends Api {  }'); // Create a test file with invalid PHP code
         $this->testFilesCreated[] = $filePath;

         $this->expectException(\Exception::class);
         $this->expectExceptionMessage('Error including API endpoint file \'test.php\'.');
         $api = new Api();
         $endpoints = $api->collectApiEndpoints($this->tmpApiDir);
     }

    public function testCollectApiEndpointsReadFileWithNoClass() {
         $filePath = $this->tmpApiDir . '/test2.php';
         file_put_contents($filePath, '<?php namespace Api; function Test2() { }');
         $this->testFilesCreated[] = $filePath;

         $this->expectException(\Exception::class);
         $this->expectExceptionMessage('No new class found in API endpoint file \'test2.php\'.');
         $api = new Api();
         $endpoints = $api->collectApiEndpoints($this->tmpApiDir);
     }

        public function testCollectApiEndpointsReadFileWithMultipleClass() {
         $filePath = $this->tmpApiDir . '/test3.php';
         file_put_contents($filePath, '<?php namespace Api; class Test3 extends Api {  } class Test3b extends Api {  } ');
         $this->testFilesCreated[] = $filePath;

         $this->expectException(\Exception::class);
         $this->expectExceptionMessage('Multiple new classes found in API endpoint file \'test3.php\'. This is not allowed.');
         $api = new Api();
         $endpoints = $api->collectApiEndpoints($this->tmpApiDir);
     }

    public function testCollectApiEndpointsNewTestApi() {
        $filePath = $this->tmpApiDir . '/test4.php';
        file_put_contents($filePath, '<?php namespace Api; class Test4 extends Api { }'); // Create a test file with a valid API endpoint
        $this->testFilesCreated[] = $filePath;

        $api = new Api();
        $endpoints = $api->collectApiEndpoints($this->tmpApiDir);
        
        // Ensure the new test API endpoint is included
        $this->assertContains('Test4', $endpoints);
    }

    public function testCollectApiEndpointsNewTestApiWithBadName() {
        $filePath = $this->tmpApiDir . '/test5.php';
        file_put_contents($filePath, '<?php namespace Api; class Test5b extends Api { }'); // Create a test file with a valid API endpoint
        $this->testFilesCreated[] = $filePath;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The class name \'Test5b\' in file \'test5.php\' does not match the expected format. The class name should be the same as the file name (without .php).');
        $api = new Api();
        $endpoints = $api->collectApiEndpoints($this->tmpApiDir);
    }

    // Az "Api" nevű endpointot nem szabad visszaadni, mivel az maga az alap osztály, nem egy külön API endpoint
    public function testCollectApiEndpointsNoApi() {
        $api = new Api();
        $endpoints = $api->collectApiEndpoints();

        // Ensure the base Api class itself is not returned as an endpoint
        $this->assertNotContains('Api', $endpoints);
    }

    // #374: validateEnum tömb-alapú (típusos) ága — a meglévő tesztek csak skalár
    // értékeket fednek, a ['date'=>[]]-szerű típusos szabály nem volt tesztelve.

    public function testValidateEnumLiteralValueMatches() {
        $api = new Api();
        // 'sunday' literál -> nem dob
        $api->validateEnum('whenMass', ['today', 'tomorrow', 'sunday', ['date' => []]], 'sunday');
        $this->assertTrue(true, 'literál egyezésnél nem dobhat');
    }

    public function testValidateEnumDateRuleMatches() {
        $api = new Api();
        // formátumilag helyes dátum a ['date'=>[]] szabály alatt -> nem dob
        $api->validateEnum('whenMass', ['today', 'sunday', ['date' => []]], '2026-03-20');
        $this->assertTrue(true, 'érvényes dátumnál nem dobhat');
    }

    public function testValidateEnumInvalidDateThrows() {
        $api = new Api();
        $this->expectException(\Exception::class);
        $api->validateEnum('whenMass', ['today', 'sunday', ['date' => []]], '2026-13-45');
    }

    public function testValidateEnumUnknownValueThrows() {
        $api = new Api();
        $this->expectException(\Exception::class);
        $api->validateEnum('whenMass', ['today', 'sunday', ['date' => []]], 'garbage');
    }

    /**
     * #374: dokumentálja, hogy a validateEnum date-ága CSAK formátumot ellenőriz
     * (regex), nem naptár-tudatos — szemben a \Request::validateDateFormat-tal.
     * A 2026-02-30 formátumilag helyes, ezért NEM dob.
     */
    public function testValidateEnumDateIsFormatOnlyNotCalendarAware() {
        $api = new Api();
        $api->validateEnum('whenMass', [['date' => []]], '2026-02-30');
        $this->assertTrue(true, 'a date-ág formátum-only, nem naptár-tudatos');
    }
}
