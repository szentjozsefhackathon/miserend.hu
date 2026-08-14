<?php

use PHPUnit\Framework\TestCase;

/**
 * A /health külső API táblája: a piros tényleg csak a bajt jelentse.
 *
 * Két külön dolgot mosott össze eddig: a „hibás" és a „nem tudjuk ellenőrizni" is
 * pirosan jött. A Mapquestnek szándékosan nincs tesztlekérdezése — minden hívás a
 * fizetős keretből megy (#129) —, így hónapok óta hibaként virított egy olyan végpont,
 * amivel semmi baj. Az állandó piros pedig pont azt öli meg, amiért az oldal van.
 */
final class ExternalApiTestabilityTest extends TestCase {

    public function testMapquestIsDeliberatelyNotChecked(): void {
        $api = new \ExternalApi\MapquestApi();

        self::assertFalse($api->isTestable(), 'a Mapquestet szándékosan nem ellenőrizzük');
        self::assertNotNull($api->testSkipReason(), 'mondjuk is meg, miért nem');
        self::assertStringContainsString('#129', $api->testSkipReason());
    }

    /* Aminek van tesztlekérdezése, azt ellenőrizzük is — ez nem bújhat ki alóla. */
    public function testEveryOtherEndpointIsChecked(): void {
        $notTestable = [];

        foreach (\ExternalApi\ExternalApi::collectExternalApis() as $name) {
            $className = "\\ExternalApi\\" . $name;
            if (!class_exists($className)) {
                continue;
            }

            $api = new $className();
            if (!$api->isTestable()) {
                $notTestable[] = $name;
            }
        }

        /*
         * Két végpontnál SZÁNDÉKOS, hogy nincs ellenőrzés:
         *   - Mapquest: minden hívás a fizetős keretből megy (#129);
         *   - OSRM: opcionális, az `osrm` compose-profil mögött van (#673) — ha nincs
         *     beállítva az OSRM_URL, nincs mit kérdezni.
         *
         * A pontos lista KÖRNYEZETFÜGGŐ (kulcs/URL megléte dönti el), ezért nem
         * rögzítjük. Az őrzendő szabály viszont pontosan megfogalmazható: nem lehet
         * NÉMÁN ellenőrizetlen végpont — amelyiket nem ellenőrizzük, arról tudni kell,
         * hogy melyik az, és MIÉRT.
         */
        $szandekos = ['MapquestApi', 'OsrmApi'];

        $ismeretlen = array_values(array_diff($notTestable, $szandekos));
        self::assertSame(
            [],
            $ismeretlen,
            'Új, nem ellenőrizhető végpont került be: ' . implode(', ', $ismeretlen)
            . '. Ha ez szándékos, adj neki testSkipReason-t, és vedd fel ide — '
            . 'ha nem, írj hozzá testQuery-t.'
        );

        foreach ($notTestable as $name) {
            $className = "\\ExternalApi\\" . $name;
            $api = new $className();
            self::assertNotNull(
                $api->testSkipReason(),
                $name . ': ha nem ellenőrizzük, mondjuk meg, miért.'
            );
        }
    }

    /** #673: beállított OSRM_URL mellett az útvonaltervező is a figyelt végpontok közé kerül. */
    public function testOsrmIsCheckedWhenConfigured(): void {
        $eredeti = getenv('OSRM_URL');
        putenv('OSRM_URL=http://osrm:5000');
        $_ENV['OSRM_URL'] = 'http://osrm:5000';

        try {
            $api = new \ExternalApi\OsrmApi();
            self::assertTrue($api->isTestable(), 'beállított OSRM_URL mellett ellenőrizni kell');
            self::assertNull($api->testSkipReason());
        } finally {
            if ($eredeti === false) {
                putenv('OSRM_URL');
                unset($_ENV['OSRM_URL']);
            } else {
                putenv('OSRM_URL=' . $eredeti);
                $_ENV['OSRM_URL'] = $eredeti;
            }
        }
    }

    /** Beállítás nélkül viszont mondja meg, MIÉRT nincs ellenőrzés. */
    public function testOsrmExplainsWhyItIsNotChecked(): void {
        $api = new \ExternalApi\OsrmApi();

        if ($api->isTestable()) {
            self::markTestSkipped('Ebben a környezetben be van állítva az OSRM_URL.');
        }

        self::assertNotNull($api->testSkipReason());
        self::assertStringContainsString('OSRM_URL', $api->testSkipReason());
    }

    /*
     * Aminél van testQuery, ott a hiányzó-testQuery ág nem is futhat le. Ez azért
     * fontos, mert a health.php ezen az elágazáson dönti el, hogy pirosat fessen-e.
     */
    public function testTestableEndpointsNeverReportTheMissingQueryMessage(): void {
        $api = new \ExternalApi\OverpassApi();

        self::assertTrue($api->isTestable());
        self::assertNull($api->testSkipReason());
    }
}
