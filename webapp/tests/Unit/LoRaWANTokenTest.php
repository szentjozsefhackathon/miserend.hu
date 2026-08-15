<?php

use PHPUnit\Framework\TestCase;

/**
 * A `/api/v4/lorawan` végpont EDDIG teljesen azonosítás nélkül fogadott adatot: bárki
 * beírhatott „gyóntatás folyamatban" állapotot BÁRMELYIK templomhoz, és korlátlanul
 * szaporíthatta a sorokat. Kipróbálva a futó példányon:
 *
 *     POST /api/v4/lorawan   (sima curl, semmilyen azonosítás)
 *     → HTTP 200, {"error":0}, a confessions sor bekerült
 *
 * A megosztott titok az `.env`-ből jön. Ha nincs beállítva, a végpont a régi módon
 * viselkedik — különben a merge pillanatában elnémulnának az éles eszközök, amíg a
 * küldő oldal nincs átállítva. Ez a teszt azt rögzíti, hogy BEÁLLÍTOTT titok mellett
 * tényleg fogjon.
 */
class LoRaWANTokenTest extends TestCase {

    private $eredeti;

    protected function setUp(): void {
        parent::setUp();
        $this->eredeti = getenv('LORAWAN_TOKEN');
        unset($_SERVER['HTTP_X_MISEREND_TOKEN']);
    }

    protected function tearDown(): void {
        if ($this->eredeti === false) {
            putenv('LORAWAN_TOKEN');
            unset($_ENV['LORAWAN_TOKEN'], $_SERVER['LORAWAN_TOKEN']);
        } else {
            putenv('LORAWAN_TOKEN=' . $this->eredeti);
        }
        unset($_SERVER['HTTP_X_MISEREND_TOKEN']);
        parent::tearDown();
    }

    private function titokBeallit(?string $ertek): void {
        if ($ertek === null) {
            putenv('LORAWAN_TOKEN');
            unset($_ENV['LORAWAN_TOKEN'], $_SERVER['LORAWAN_TOKEN']);
            return;
        }
        putenv('LORAWAN_TOKEN=' . $ertek);
        $_ENV['LORAWAN_TOKEN'] = $ertek;
        $_SERVER['LORAWAN_TOKEN'] = $ertek;
    }

    /** A privát ellenőrzőt reflexióval hívjuk — külön végpont-hívás nélkül. */
    private function ellenoriz(array $input): void {
        $api = new \Api\LoRaWAN();
        $api->input = $input;

        $m = new \ReflectionMethod(\Api\LoRaWAN::class, 'checkSharedSecret');
        $m->setAccessible(true);
        $m->invoke($api);
    }

    public function testConfiguredSecretRejectsARequestWithoutToken(): void {
        $this->titokBeallit('nagyon-titkos');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or missing token.');

        $this->ellenoriz([]);
    }

    public function testConfiguredSecretRejectsAWrongToken(): void {
        $this->titokBeallit('nagyon-titkos');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid or missing token.');

        $this->ellenoriz(['token' => 'rossz']);
    }

    public function testTheRightTokenPassesInTheBody(): void {
        $this->titokBeallit('nagyon-titkos');

        $this->ellenoriz(['token' => 'nagyon-titkos']);
        $this->addToAssertionCount(1);
    }

    public function testTheRightTokenPassesInTheHeader(): void {
        $this->titokBeallit('nagyon-titkos');
        $_SERVER['HTTP_X_MISEREND_TOKEN'] = 'nagyon-titkos';

        $this->ellenoriz([]);
        $this->addToAssertionCount(1);
    }

    /**
     * Beállítatlan titoknál a régi viselkedés marad — szándékosan, hogy a merge ne
     * némítsa el az éles eszközöket. Ez NEM a végállapot: amíg üres, a végpont nyitva van.
     */
    public function testWithoutAConfiguredSecretTheEndpointStaysOpen(): void {
        $this->titokBeallit(null);

        $this->ellenoriz([]);
        $this->addToAssertionCount(1);
    }
}
