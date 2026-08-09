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

        self::assertSame(
            ['MapquestApi'],
            $notTestable,
            'Új, nem ellenőrizhető végpont került be. Ha ez szándékos, adj neki testSkipReason-t, '
            . 'és vedd fel ide — ha nem, írj hozzá testQuery-t.'
        );
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
