<?php

use PHPUnit\Framework\TestCase;

/**
 * #800: melyik API-verziót hirdetjük kurrensnek.
 *
 * borazslo a #778-hoz:
 *
 *   „még éppen szerintem az api v4 maradhat a kurrensnek kikiálltot, mert a v5
 *    sqlite még nagyon változnia kell"
 *
 * A két szám ezért külön él: a `LEGUJABB_VERZIO` a validálás felső korlátja (a v5
 * kéréseket el KELL fogadni), az `AJANLOTT_VERZIO` pedig az, amit a dokumentáció
 * aktuálisként mutat. Ez a teszt azt őrzi, hogy a kettő ne csússzon össze
 * véletlenül — a v5 kikiáltása tudatos döntés legyen, ne egy elfelejtett konstans.
 */
class ApiRecommendedVersionTest extends TestCase {

    public function testAzAjanlottVerzioNemLehetMagasabbALegujabbnal(): void {
        self::assertLessThanOrEqual(\Api\Api::LEGUJABB_VERZIO, \Api\Api::AJANLOTT_VERZIO);
    }

    /** Amíg a v5 sqlite mise-formátuma alakul, a v4 marad a kurrens. */
    public function testJelenlegAV4AzAjanlott(): void {
        self::assertSame(4, \Api\Api::AJANLOTT_VERZIO,
            'Ha a v5 megszilárdult, ezt a tesztet és a konstanst együtt kell átírni.');
    }

    /** A v5 kéréseket akkor is el kell fogadni, ha nem az az ajánlott. */
    public function testAzOtosVerzioTovabbraIsErvenyes(): void {
        self::assertSame(5, \Api\Api::LEGUJABB_VERZIO);
    }

    /**
     * A dokumentáció az AJÁNLOTT verziót mutatja aktuálisként — ez volt borazslo
     * konkrét kérése.
     */
    public function testADokumentacioAzAjanlottVerziotMutatja(): void {
        $sablon = file_get_contents(PATH . 'templates/apidocs.twig');

        self::assertStringContainsString("Api\\\\Api::AJANLOTT_VERZIO", $sablon);
        self::assertStringContainsString('kísérleti', $sablon,
            'A legújabb verziót kísérletiként kell jelölni, amíg nem az az ajánlott.');
    }
}
