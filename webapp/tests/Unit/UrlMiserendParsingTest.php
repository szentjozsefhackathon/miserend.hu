<?php

use PHPUnit\Framework\TestCase;

/**
 * #658: az OSM-ben az `url:miserend` címke sokféle alakban van forgalomban — van, ahol
 * `https` nélkül, máshol `miserend.hu/?templom=345` formában, és állítólag olyan is,
 * hogy `miserend:/?templom=456`.
 *
 * Két külön kérdés, és a jegy szempontjából mindkettő fontos:
 *
 *  1. Melyik alakot ISMERJÜK FEL? Amit felismerünk, azt az OSM-ben nem is muszáj
 *     javítani — a takarítás úgyis egyetlen nagy changesetben megy majd.
 *  2. Melyiket NEM? Azok kerülnek a futás végi jelentésbe, és pont azok a javítandók.
 *
 * A felismerés eddig a szinkronizáló ciklus közepén, névtelenül állt; ezért került ki
 * külön metódusba.
 */
class UrlMiserendParsingTest extends TestCase
{
    /** @dataProvider elfogadottAlakok */
    public function testTheseFormsAreRecognised(string $url, int $vart): void
    {
        self::assertSame($vart, \OSM::churchIdFromMiserendUrl($url), $url);
    }

    public static function elfogadottAlakok(): array
    {
        return [
            'kanonikus'            => ['https://miserend.hu/templom/452', 452],
            'https nélkül'         => ['http://miserend.hu/templom/452', 452],
            'séma nélkül'          => ['miserend.hu/templom/452', 452],
            'www-vel'              => ['https://www.miserend.hu/templom/452', 452],
            'régi kérdőjeles'      => ['miserend.hu/?templom=345', 345],
            'kérdőjel nélkül'      => ['miserend.hu/templom=345', 345],
            'aloldallal'           => ['https://miserend.hu/templom/5/calendar', 5],
            'záró perjellel'       => ['https://miserend.hu/templom/452/', 452],
            'nagybetűs hoszt'      => ['HTTPS://MISEREND.HU/templom/452', 452],
            'hosszú azonosító'     => ['https://miserend.hu/templom/123456', 123456],
            'szöveg közepén'       => ['lásd itt: https://miserend.hu/templom/99 (miserend)', 99],
        ];
    }

    /** @dataProvider elutasitottAlakok */
    public function testTheseFormsAreReported(?string $url): void
    {
        self::assertNull(\OSM::churchIdFromMiserendUrl($url), (string) $url);
    }

    public static function elutasitottAlakok(): array
    {
        return [
            'null'                 => [null],
            'üres'                 => [''],
            'csak szóköz'          => ['   '],
            // #510: szándékosan nem fogadjuk el — hibás adat, kézzel javítandó.
            'uj.miserend.hu'       => ['https://uj.miserend.hu/templom/452'],
            // A jegyben említett, elrontott séma: essen a jelentésbe, ne áldjuk meg.
            'hoszt nélküli séma'   => ['miserend:/?templom=456'],
            'azonosító nélkül'     => ['https://miserend.hu/templom/'],
            'nem szám azonosító'   => ['https://miserend.hu/templom/abc'],
            'más oldal'            => ['https://example.com/templom/452'],
            'főoldal'              => ['https://miserend.hu'],
            'kereső link'          => ['https://miserend.hu/kereses?kulcsszo=Budapest'],
        ];
    }

    /**
     * A jegy egyik konkrét példája: ugyanaz a templom háromféle alakban. Amit fel tudunk
     * ismerni, az ugyanazt az azonosítót kell adja — különben az OSM-takarítás előtt
     * ugyanaz a templom többféleképpen viselkedne.
     */
    public function testTheRecognisedFormsAgreeOnTheChurch(): void
    {
        $alakok = [
            'https://miserend.hu/templom/452',
            'http://miserend.hu/templom/452',
            'miserend.hu/?templom=452',
            'https://www.miserend.hu/templom/452/',
        ];

        foreach ($alakok as $alak) {
            self::assertSame(452, \OSM::churchIdFromMiserendUrl($alak), $alak);
        }
    }
}
