<?php

use PHPUnit\Framework\TestCase;

/**
 * Az iCal-esemény UID-je az esemény GLOBÁLIS AZONOSSÁGA a feliratkozó
 * naptáralkalmazásában — ez alapján dönti el, hogy két bejegyzés ugyanaz-e.
 *
 * Eddig beégetve `@miserend.hu` állt benne, tehát a staging és az éles ugyanazt az
 * azonosítót adta ugyanarra a misére. Aki mindkettőre feliratkozott — jellemzően épp a
 * tesztelő —, annak a naptára a kettőt EGY eseménynek látta, és az egyik felülírta a
 * másikat. Ugyanez a hibaosztály, mint a levelekbe égetett `miserend.hu` link.
 *
 * A UID-nek ugyanakkor STABILNAK kell lennie: ha egy futásnál más lenne, a feliratkozó
 * naptárában minden alkalom duplázódna. Ezért a hoszt hiányánál marad a régi érték.
 */
class IcalUidTest extends TestCase {

    private function uidHost(): string {
        $metodus = new ReflectionMethod(\Html\Church\Ical::class, 'uidHost');
        $metodus->setAccessible(true);

        return $metodus->invoke((new ReflectionClass(\Html\Church\Ical::class))->newInstanceWithoutConstructor());
    }

    /**
     * A beállított domainből a HOSZT kerül a UID-be — séma és port nélkül, mert a UID
     * jobb oldala hosztnév, nem URL.
     */
    public function testAUidHosztjaABeallitottDomainbolJon(): void {
        $host = $this->uidHost();

        self::assertNotSame('', $host);
        self::assertStringNotContainsString('://', $host, 'a séma nem való a UID-be');
        self::assertStringNotContainsString('/', $host);
    }

    /** A fejlesztői környezetben ez épp a localhost — és pont ez a lényeg. */
    public function testAFejlesztoiKornyezetSajatHosztotAd(): void {
        $host = $this->uidHost();
        $vartHost = parse_url((string) constant('DOMAIN'), PHP_URL_HOST);

        self::assertSame($vartHost, $host);
    }

    /** Kétszer hívva ugyanaz — enélkül a feliratkozó naptárában duplázódnának az alkalmak. */
    public function testAHosztStabil(): void {
        self::assertSame($this->uidHost(), $this->uidHost());
    }

    /**
     * A UID a mise és a generált időszak azonosítójából áll össze — vagyis egy mise
     * minden időszakában külön eseményként jelenik meg, de futásról futásra ugyanazzal
     * az azonosítóval.
     */
    public function testAUidFelepiteseStabil(): void {
        $osztaly = new ReflectionClass(\Html\Church\Ical::class);
        $peldany = $osztaly->newInstanceWithoutConstructor();

        $metodus = $osztaly->getMethod('createCalendarEvent');
        $metodus->setAccessible(true);

        $sorok = $metodus->invoke($peldany, [
            'mass_id' => 101,
            'generated_period_id' => 7,
            'start_date' => '2026-08-16T09:00:00',
            'duration_minutes' => 60,
            'title' => 'Szentmise',
        ]);

        $uidSorok = array_values(array_filter($sorok, static fn($sor) => str_starts_with((string) $sor, 'UID:')));

        self::assertCount(1, $uidSorok);
        self::assertSame('UID:101-7@' . $this->uidHost(), $uidSorok[0]);
    }

    /** Két különböző időszak két külön eseményt ad — ez a naptárban is így helyes. */
    public function testKulonbozoIdoszakKulonbozoUidotAd(): void {
        $osztaly = new ReflectionClass(\Html\Church\Ical::class);
        $peldany = $osztaly->newInstanceWithoutConstructor();
        $metodus = $osztaly->getMethod('createCalendarEvent');
        $metodus->setAccessible(true);

        $uid = static function (array $mise) use ($metodus, $peldany): string {
            foreach ($metodus->invoke($peldany, $mise) as $sor) {
                if (str_starts_with((string) $sor, 'UID:')) {
                    return $sor;
                }
            }
            return '';
        };

        $alap = ['mass_id' => 101, 'start_date' => '2026-08-16T09:00:00', 'duration_minutes' => 60, 'title' => 'Szentmise'];

        self::assertNotSame(
            $uid($alap + ['generated_period_id' => 7]),
            $uid($alap + ['generated_period_id' => 8])
        );
    }
}
