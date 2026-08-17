<?php

use PHPUnit\Framework\TestCase;

/**
 * #826: a `location` mező nélkül indexelt templomok pótlása.
 *
 * A távolság szerinti keresés kizárólag erre a geo_point mezőre szűr. Ha egy templom
 * dokumentumából hiányzik, a „X km-en belül" keresés NÉMÁN nem találja meg: nem hiba,
 * csak nulla találat — amit a látogató „nincs ilyen templom"-nak olvas.
 *
 * A /health eddig csak a SZÁMOT mutatta (élesben 22), a javítás pedig kézi, teljes
 * újraindexelés volt. Egy kézi deploy-lépés viszont előbb-utóbb elmarad — és ez pont
 * olyan hiba, amiről senki nem szól, mert semmi nem hasal el tőle.
 *
 * Ez a teszt a VALÓDI indexre dolgozik: ha nincs Elasticsearch, kihagyja magát.
 */
class ChurchLocationReindexTest extends TestCase {

    private \ExternalApi\ElasticsearchApi $elastic;

    protected function setUp(): void {
        parent::setUp();

        $this->elastic = new \ExternalApi\ElasticsearchApi();
        if (!$this->elastic->isexistsIndex('churches')) {
            self::markTestSkipped('Nincs churches index ebben a környezetben.');
        }
    }

    /**
     * A lista és a számláló ugyanarról a halmazról beszél — a lista KORLÁTJÁIG.
     *
     * A korlát nem részletkérdés: friss indexben (a CI-ben pontosan ez a helyzet) az
     * ÖSSZES templomból hiányzik a mező, tehát a lista a limitnél vág. A pótlás így
     * darabokban halad, nem egyetlen ötezres körben.
     */
    public function testAListaEsASzamlaloEgyezik(): void {
        $szamlalo = $this->elastic->churchesMissingLocation();
        $lista = $this->elastic->churchIdsMissingLocation(1000);

        if ($szamlalo === null || $lista === null) {
            self::markTestSkipped('Az index nem kérdezhető le.');
        }

        self::assertSame(min($szamlalo['missing'], 1000), count($lista),
            'Ha a kettő eltér, a pótlás vagy kihagy templomokat, vagy feleslegesen dolgozik.');
    }

    /** A rendezés nélküli lekérdezés más-más részhalmazt adna — az ismételhetetlen. */
    public function testAListaKetHivasraUgyanaz(): void {
        $elso = $this->elastic->churchIdsMissingLocation(20);
        $masodik = $this->elastic->churchIdsMissingLocation(20);

        if ($elso === null || $masodik === null) {
            self::markTestSkipped('Az index nem kérdezhető le.');
        }

        self::assertSame($elso, $masodik, 'A pótlásnak kiszámítható sorrendben kell haladnia.');
    }

    /** Csak azonosítókat adunk vissza, forrás-dokumentum nélkül — az felesleges adat. */
    public function testCsakAzonositokatAd(): void {
        $lista = $this->elastic->churchIdsMissingLocation(5);

        if ($lista === null) {
            self::markTestSkipped('Az index nem kérdezhető le.');
        }

        foreach ($lista as $id) {
            self::assertIsInt($id);
            self::assertGreaterThan(0, $id);
        }
    }

    /** A korlát tartja magát: nem húzunk be tízezer dokumentumot egy körben. */
    public function testAKorlatErvenyesul(): void {
        $lista = $this->elastic->churchIdsMissingLocation(3);

        if ($lista === null) {
            self::markTestSkipped('Az index nem kérdezhető le.');
        }

        self::assertLessThanOrEqual(3, count($lista));
    }

    /**
     * Ha nincs mit pótolni, a cron ne kezdjen újraindexelésbe — az 5000 templom
     * felesleges átépítése lenne hatóránként.
     */
    public function testNincsMitPotolniEsetenNemIndexelUjra(): void {
        $lista = $this->elastic->churchIdsMissingLocation();
        if ($lista === null) {
            self::markTestSkipped('Az index nem kérdezhető le.');
        }
        if ($lista !== []) {
            self::markTestSkipped('Ebben az indexben van pótolni való — ez az ág nem mérhető.');
        }

        $eredmeny = \ExternalApi\ElasticsearchApi::reindexChurchesWithoutLocation();

        self::assertSame(0, $eredmeny['candidates']);
        self::assertSame(0, $eredmeny['reindexed']);
    }

    /** A visszatérési szerződés stabil: a hívó (cron, health) erre épít. */
    public function testAValaszMindigUgyanazokatAKulcsokatAdja(): void {
        $eredmeny = \ExternalApi\ElasticsearchApi::reindexChurchesWithoutLocation(1);

        self::assertArrayHasKey('candidates', $eredmeny);
        self::assertArrayHasKey('reindexed', $eredmeny);
        self::assertArrayHasKey('still_missing', $eredmeny);
    }

    /** #638: ami cronként fut, annak a regiszterben is ott kell lennie. */
    public function testACronBenneVanARegiszterben(): void {
        $registry = require PATH . 'fajlok/crons.php';

        $megvan = false;
        foreach ($registry as $job) {
            if ($job['function'] === 'reindexChurchesWithoutLocation') {
                $megvan = true;
                break;
            }
        }

        self::assertTrue($megvan, 'Enélkül soha nem futna le éles adatbázisban.');
    }

    /**
     * A legfontosabb állítás: a cron KONVERGÁL.
     *
     * Élesben mind a 15 hiányzó `location` olyan templomé volt, amelynek nincs
     * koordinátája az adatbázisban — azok dokumentumában soha nem is lesz mező.
     * Szűrés nélkül ez a cron hatóránként újraindexelte volna ugyanazt a 15
     * templomot, örökre, eredmény nélkül.
     */
    public function testAKoordinataNelkuliTemplomotNemIndexeliUjra(): void {
        $ids = $this->elastic->churchIdsMissingLocation();
        if ($ids === null || $ids === []) {
            self::markTestSkipped('Ebben az indexben nincs hiányzó location.');
        }

        $koordinataNelkul = \Eloquent\Church::whereIn('id', $ids)
                ->where(function ($q) {
                    $q->whereNull('lat')->orWhere('lat', 0)
                      ->orWhereNull('lon')->orWhere('lon', 0);
                })
                ->count();
        if ($koordinataNelkul === 0) {
            self::markTestSkipped('Itt minden hiányzó templomnak van koordinátája.');
        }

        $eredmeny = \ExternalApi\ElasticsearchApi::reindexChurchesWithoutLocation(count($ids));

        // A két szám EGYÜTT adja ki a megvizsgált halmazt: amit újraindexeltünk, és
        // amit szándékosan nem. Ha a koordináta nélküliek is a jelöltek közé
        // kerülnének, a cron örökre ugyanazon a halmazon körözne.
        self::assertSame(count($ids), $eredmeny['candidates'] + $eredmeny['no_coordinates']);
        self::assertGreaterThan(0, $eredmeny['no_coordinates'],
            'A koordináta nélkülieket külön kell számolni, nem újraindexelni.');
    }

    /** A válasz a hiány OKÁT is megmondja — erre épül a health oldal tanácsa. */
    public function testAValaszMegkulonbozetiAKetOkot(): void {
        $eredmeny = \ExternalApi\ElasticsearchApi::reindexChurchesWithoutLocation(1);

        self::assertArrayHasKey('no_coordinates', $eredmeny);
    }
}
