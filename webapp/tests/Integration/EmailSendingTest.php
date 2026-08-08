<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #610: "Nem küld emailt".
 *
 * A config.php-ban az SMTP Host alapértéke 'mailcatcher' volt, a production compose
 * viszont sosem adta át az SMTP_HOST-ot a konténernek. Így éles környezetben minden
 * levél a dev mailcatcher-be ment (némán elnyelődött), vagy — ha az nem futott —
 * a PHPMailer elkapatlan kivétele szállt el a regisztráció közepén, a levél sora
 * pedig örökre 'sending' státuszban ragadt.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class EmailSendingTest extends TestCase
{
    /** @var array */
    private $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();
        global $config;
        $this->originalConfig = $config;
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        global $config;
        $config = $this->originalConfig;
        parent::tearDown();
    }

    /**
     * A config.php-t friss környezettel értékeli ki: az SMTP_* env-változókat
     * ideiglenesen kiveszi, hogy a config alapértékeit lássuk.
     */
    private function configFor(string $env): array
    {
        $keys  = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_SECURE'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        try {
            $environment = [];
            require PATH . 'config.php';

            $resolved        = $environment['default'];
            $resolved['env'] = $env;
            if (isset($environment[$env])) {
                overrideArray($resolved, $environment[$env]);
            }

            return $resolved;
        } finally {
            foreach ($saved as $key => $value) {
                if ($value !== false) {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    /** Ez a #610 közvetlen regresszió-őre. */
    public function testProductionDoesNotFallBackToDevMailcatcher(): void
    {
        $config = $this->configFor('production');

        $this->assertNotSame(
            'mailcatcher',
            $config['smtp']['Host'],
            'Production nem küldhet a dev mailcatcher-be: ott minden levél elveszik.'
        );
        $this->assertSame(
            '',
            $config['smtp']['Host'],
            'SMTP_HOST nélkül a production Host maradjon üres, hogy a hiba látszódjon.'
        );
    }

    public function testStagingDoesNotFallBackToDevMailcatcher(): void
    {
        $config = $this->configFor('staging');

        $this->assertNotSame('mailcatcher', $config['smtp']['Host']);
    }

    public function testDevelopmentAndTestingStillDefaultToMailcatcher(): void
    {
        $this->assertSame('mailcatcher', $this->configFor('development')['smtp']['Host']);
        $this->assertSame(1025, $this->configFor('development')['smtp']['Port']);
        $this->assertSame('mailcatcher', $this->configFor('testing')['smtp']['Host']);
    }

    public function testUnreachableSmtpMarksTheEmailErroredInsteadOfThrowing(): void
    {
        global $config;
        // Zárt port a loopbacken: azonnali "connection refused", DNS nélkül.
        $config['smtp']['Host'] = '127.0.0.1';
        $config['smtp']['Port'] = 9;

        $email          = new \Eloquent\Email();
        $email->debug   = 0;
        $email->subject = 'phpunit';
        $email->body    = 'phpunit';

        $this->assertFalse(
            $email->send('phpunit@example.invalid'),
            'Elérhetetlen SMTP esetén false a visszatérés — és nem elkapatlan kivétel.'
        );
        $this->assertSame(
            'error',
            DB::table('emails')->where('id', $email->id)->value('status'),
            "A sor ne ragadjon 'sending' státuszban."
        );
    }

    public function testMissingSmtpHostFailsFastWithoutConnecting(): void
    {
        global $config;
        $config['smtp']['Host'] = '';

        $email          = new \Eloquent\Email();
        $email->debug   = 0;
        $email->subject = 'phpunit';
        $email->body    = 'phpunit';

        $this->assertFalse($email->send('phpunit@example.invalid'));
        $this->assertFalse($email->isMailerConfigured());
        $this->assertSame(
            'error',
            DB::table('emails')->where('id', $email->id)->value('status')
        );
    }

    public function testHealthCheckReportsProductionPointingAtMailcatcher(): void
    {
        global $config;
        $config['env']          = 'production';
        $config['smtp']['Host'] = 'mailcatcher';

        $email = new \Eloquent\Email();

        $this->assertStringNotContainsString(
            'OK',
            $email->test(),
            'A /health ne mondja OK-nak, ha éles környezetben a mailcatcher nyeli el a leveleket.'
        );
    }

    public function testHealthCheckReportsMissingSmtpHost(): void
    {
        global $config;
        $config['smtp']['Host'] = '';

        $email = new \Eloquent\Email();

        $this->assertStringContainsString('SMTP_HOST', $email->test());
    }

    public function testSuccessfulSmtpResultDoesNotClaimDelivery(): void
    {
        $this->assertStringContainsString('elfogadta', \Eloquent\Email::SMTP_ACCEPTED);
        $this->assertStringContainsString('nem igazolja', \Eloquent\Email::SMTP_ACCEPTED);
        $this->assertStringNotContainsString('OK', \Eloquent\Email::SMTP_ACCEPTED);
    }
}
