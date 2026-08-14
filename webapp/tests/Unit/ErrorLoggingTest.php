<?php

use PHPUnit\Framework\TestCase;

/**
 * #725: „Hogyan lehet hatékonyan megtalálni a hibaüzeneteket most?"
 *
 * A válasz addig az volt, hogy sehogy: élesben `error_reporting(0)` futott, ami nem
 * csak a kijelzést, a naplózást is elnémítja, az index.php pedig csak `\Exception`-t
 * fogott — a PHP 8-as `\Error`/`TypeError` átment rajta. Egy 500-as oldalról ennyi
 * látszott a naplóban: `"GET /templom/5446/edit HTTP/1.1" 500 236`.
 */
class ErrorLoggingTest extends TestCase {

    private string $logFile;
    private string $eredetiLog;

    protected function setUp(): void {
        parent::setUp();
        $this->logFile = sys_get_temp_dir() . '/miserend-error-log-test.log';
        @unlink($this->logFile);
        $this->eredetiLog = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void {
        ini_set('error_log', $this->eredetiLog);
        @unlink($this->logFile);
        parent::tearDown();
    }

    public function testThrowableIsLoggedWithLocationUriAndTrace(): void {
        $_SERVER['REQUEST_URI'] = '/templom/5444/edit';

        logThrowable('Render failed', new \TypeError('valami elszállt'));

        $log = (string) @file_get_contents($this->logFile);
        $this->assertStringContainsString('[miserend] Render failed', $log);
        $this->assertStringContainsString('TypeError', $log);
        $this->assertStringContainsString('valami elszállt', $log);
        $this->assertStringContainsString('URI: /templom/5444/edit', $log);
        $this->assertStringContainsString('[miserend] trace:', $log);
    }

    /** A Twig-hibák üzenete a becsomagolt kivétel nélkül használhatatlan. */
    public function testPreviousExceptionIsLoggedToo(): void {
        $belso = new \RuntimeException('az igazi ok');
        $kulso = new \Exception('An exception has been thrown during the rendering of a template', 0, $belso);

        logThrowable('Render failed', $kulso);

        $log = (string) @file_get_contents($this->logFile);
        $this->assertStringContainsString('(previous)', $log);
        $this->assertStringContainsString('az igazi ok', $log);
    }

    /**
     * Az `error_log()` hívást az error_reporting maszk nem szűri — épp ezért használjuk.
     * Ez a teszt azt őrzi, hogy a naplózás a legszűkebb maszk mellett is működjön.
     */
    public function testLoggingWorksEvenWithATightErrorReportingMask(): void {
        $eredeti = error_reporting();
        error_reporting(0);
        try {
            logThrowable('Uncaught', new \Exception('néma környezetben is látszik'));
        } finally {
            error_reporting($eredeti);
        }

        $log = (string) @file_get_contents($this->logFile);
        $this->assertStringContainsString('néma környezetben is látszik', $log);
    }

    /**
     * Élesben (production) a maszk fogja a végzetes hibákat. Korábban `false` volt,
     * amiből a load.php `error_reporting(0)`-t csinált.
     */
    public function testProductionMaskCoversFatalErrors(): void {
        // A config.php-t a configurationSetEnvironment() függvény-scope-ban include-olja,
        // tehát az $environment nem globális — itt magunknak kell beolvasnunk.
        $environment = [];
        include PATH . 'config.php';

        $this->assertArrayHasKey('default', $environment);

        // A `production` ág üres, tehát a `default` értékét örökli.
        $this->assertSame([], $environment['production']);

        $mask = $environment['default']['error_reporting'];
        $this->assertNotFalse($mask, 'Élesben megint elnémulna a naplózás.');
        foreach ([E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR] as $level) {
            $this->assertSame($level, $mask & $level);
        }
    }
}
