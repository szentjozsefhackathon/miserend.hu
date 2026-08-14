<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * Az ajax végpontok jogosultság-ellenőrzése.
 *
 * A javaslat-elfogadásnál derült ki, hogy egy adatot MÓDOSÍTÓ végpont teljesen
 * ellenőrzés nélkül maradhat: a `handle()` csak a GET ágon nézte a jogosultságot, a
 * POST-ot nem. Átnézve az összes ajax végpontot, még kettő volt hasonló állapotban:
 *
 *  * `ajax/calendar/generate` (PUT) — bejelentkezés nélkül indított teljes
 *    mise-újraindexelést. Nem adatszivárgás, de egy futás 15+ perc és erősen terheli az
 *    Elasticsearchöt, tehát bárki, korlátlanul terhelhette a szervert.
 *  * `ajax/favorite` — vendégként is „elmentette" a kedvencet: a sor `uid = 0`-val
 *    beíródott a táblába. Gazdátlan adat, korlátlanul szaporítható.
 *
 * Ez a teszt névsor szerint végigmegy az adatot módosító végpontokon, és megköveteli,
 * hogy bejelentkezés nélkül MINDEGYIK visszautasítson. Új írási végpontnál ide is fel
 * kell venni egy sort — ez a szándék.
 */
class AjaxEndpointAuthTest extends TestCase {

    private string $baseUrl;

    protected function setUp(): void {
        parent::setUp();
        $this->baseUrl = rtrim(getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000', '/');
    }

    /** @return array{code:int, body:string} */
    private function keres(string $url, string $method = 'GET', ?array $adat = null): array {
        $opt = ['method' => $method, 'timeout' => 60, 'ignore_errors' => true];
        if ($adat !== null) {
            $opt['header'] = "Content-Type: application/json\r\n";
            $opt['content'] = json_encode($adat);
        }
        $valasz = @file_get_contents($this->baseUrl . $url, false, stream_context_create(['http' => $opt]));
        $kod = 0;
        foreach ($http_response_header ?? [] as $sor) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $sor, $m)) {
                $kod = (int) $m[1];
            }
        }
        return ['code' => $kod, 'body' => (string) $valasz];
    }

    /**
     * A visszautasítás kétféle alakban jöhet: JSON hibakód (a naptár-végpontok), vagy
     * kivétel -> hibaoldal (a régebbi ajax végpontok). Mindkettő elfogadható; ami NEM,
     * az a csendes siker.
     */
    private function assertVisszautasit(array $valasz, string $vegpont): void {
        if ($valasz['code'] === 0) {
            $this->markTestSkipped('A futó példány nem érhető el.');
        }

        // A törzs lehet JSON, ahol az ékezet `á` alakban áll ("jogosultság"),
        // ezért csak az ékezet nélküli tőre illesztünk.
        $elutasitva = $valasz['code'] >= 400
            || (bool) preg_match('/jogosults|megtagadva|Hiba t[öo]rt|error/iu', $valasz['body']);

        $this->assertTrue($elutasitva,
            "Bejelentkezés nélkül átment: $vegpont (HTTP {$valasz['code']}) — "
            . mb_substr(trim($valasz['body']), 0, 160));
    }

    /**
     * Ez a kettő volt nyitva. A generate PUT-ot szándékosan egyetlen templomra kérjük,
     * hogy ha mégis átmenne, ne induljon el egy teljes újraindexelés a teszt alatt.
     */
    public function testGenerateCannotBeTriggeredAnonymously(): void {
        $this->assertVisszautasit(
            $this->keres('/ajax/calendar/generate?tids[]=1&years[]=' . date('Y'), 'PUT'),
            'ajax/calendar/generate (PUT)'
        );
    }

    public function testFavoriteCannotBeSetAnonymously(): void {
        $elotte = (int) DB::table('favorites')->where('uid', 0)->count();

        $this->assertVisszautasit(
            $this->keres('/ajax/favorite?tid=1&method=add'),
            'ajax/favorite'
        );

        $this->assertSame($elotte, (int) DB::table('favorites')->where('uid', 0)->count(),
            'Gazdátlan (uid=0) kedvenc-sor keletkezett.');
    }

    /**
     * Ezek már korábban is ellenőriztek — a teszt azt rögzíti, hogy így is maradjon.
     *
     * @dataProvider vedettVegpontok
     */
    public function testWritingEndpointsRefuseAnonymousRequests(string $url, string $method, ?array $adat): void {
        $this->assertVisszautasit($this->keres($url, $method, $adat), $url);
    }

    public static function vedettVegpontok(): array {
        return [
            'miserend mentése'      => ['/calendar/masses/1', 'POST', ['masses' => [], 'deletedMasses' => []]],
            'periódusok'            => ['/calendar/periods', 'POST', ['years' => [2026]]],
            'észrevétel megbízható' => ['/ajax/switchreliable?rid=1&reliable=i', 'GET', null],
            'OSM-kapcsolat törlése' => ['/ajax/osmkapcsolat?action=delete&tid=1', 'GET', null],
            'chat mentése'          => ['/ajax/chat?action=save&message=teszt', 'GET', null],
            'templom-link hozzáadása' => ['/ajax/churchlink?action=add&church_id=1&href=http%3A%2F%2Fpelda.hu', 'GET', null],
        ];
    }
}
