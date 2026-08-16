<?php

use PHPUnit\Framework\TestCase;

/**
 * #673: melyik útvonaltervező nyer a távolságszámításban.
 *
 * Az OSRM kifejezetten azért került a compose-ba, hogy ne függjünk idegen
 * szolgáltatótól, kulcstól és kvótától. A bekötése viszont elmaradt: a
 * `resolveDistance()` egyedül a Mapquestet hívta, miközben az `OsrmApi` docblockja
 * azt állította, hogy ez a metódus a hívója.
 *
 * A sorrend ezért itt van rögzítve: OSRM -> Mapquest -> légvonal. Ezt éles
 * szolgáltatásokkal nem lehetne mérni (az OSRM opcionális, a Mapquest kulcsos),
 * ezért a Distance a két példányosítást külön metódusban tartja, és a teszt
 * lecseréli őket.
 */
class DistanceRoutingOrderTest extends TestCase {

    private const BUDAPEST = ['lat' => 47.4979, 'lon' => 19.0402];
    private const SZEGED   = ['lat' => 46.2530, 'lon' => 20.1414];

    /** A légvonalbeli táv, amire vissza kell esni, ha egyik tervező sem felel. */
    private const LEGVONAL = 161000.0;

    /**
     * @param float|null $osrm amit az OSRM ad (null = nincs beállítva)
     * @param float|null $mapquest amit a Mapquest ad (null = dobjon kivételt)
     */
    private function tavolsag(?float $osrm, ?float $mapquest): \Distance {
        return new class($osrm, $mapquest) extends \Distance {
            public int $osrmHivas = 0;
            public int $mapquestHivas = 0;

            public function __construct(private ?float $osrm, private ?float $mapquest) {
                parent::__construct();
            }

            protected function osrmApi(): \ExternalApi\OsrmApi {
                $this->osrmHivas++;
                $ertek = $this->osrm;
                return new class($ertek) extends \ExternalApi\OsrmApi {
                    public function __construct(private ?float $ertek) {
                        parent::__construct('');
                    }
                    function routeDistance(array $from, array $to): ?float {
                        return $this->ertek;
                    }
                };
            }

            protected function mapquestApi(): \ExternalApi\MapquestApi {
                $this->mapquestHivas++;
                if ($this->mapquest === null) {
                    throw new \Exception('nincs Mapquest-kulcs');
                }
                $ertek = $this->mapquest;
                return new class($ertek) extends \ExternalApi\MapquestApi {
                    public function __construct(private float $ertek) {}
                    function distance($from, $to) {
                        return $this->ertek;
                    }
                };
            }
        };
    }

    /** A saját szolgáltatásunk az elsődleges — ne fizessünk idegen kvótát fölöslegesen. */
    public function testAzOsrmNyerHaVanValasza(): void {
        $tavolsag = $this->tavolsag(170000.0, 999999.0);

        $eredmeny = $tavolsag->resolveDistance(self::BUDAPEST, self::SZEGED, self::LEGVONAL);

        self::assertSame(170000.0, $eredmeny['distance']);
        self::assertTrue($eredmeny['road']);
    }

    public function testAzOsrmUtanMarNemKerdezzukAMapquestet(): void {
        $tavolsag = $this->tavolsag(170000.0, 999999.0);

        $tavolsag->resolveDistance(self::BUDAPEST, self::SZEGED, self::LEGVONAL);

        self::assertSame(0, $tavolsag->mapquestHivas,
            'Ha az OSRM felelt, a Mapquest hívása fölösleges kvótafogyasztás.');
    }

    /** Beállítatlan OSRM: a routeDistance() null-t ad, jön a Mapquest. */
    public function testOsrmNelkulAMapquestJon(): void {
        $tavolsag = $this->tavolsag(null, 175000.0);

        $eredmeny = $tavolsag->resolveDistance(self::BUDAPEST, self::SZEGED, self::LEGVONAL);

        self::assertSame(175000.0, $eredmeny['distance']);
        self::assertTrue($eredmeny['road']);
    }

    /**
     * A korábbi viselkedés bitre azonos marad ott, ahol nincs OSRM: ez a fontos,
     * mert az OSRM opcionális compose-profil mögött van.
     */
    public function testMindkettoNelkulLegvonalraEsunkVissza(): void {
        $tavolsag = $this->tavolsag(null, null);

        $eredmeny = $tavolsag->resolveDistance(self::BUDAPEST, self::SZEGED, self::LEGVONAL);

        self::assertSame(self::LEGVONAL, $eredmeny['distance']);
        self::assertFalse($eredmeny['road'],
            'A légvonal nem útvonal-távolság — a hívó ez alapján jelöli újraszámolandónak.');
    }

    /** A nulla nem érvényes útvonalhossz két különböző pont között. */
    public function testANullaOsrmValaszNemFogadhatoEl(): void {
        $tavolsag = $this->tavolsag(0.0, 175000.0);

        self::assertSame(175000.0, $tavolsag->resolveDistance(self::BUDAPEST, self::SZEGED, self::LEGVONAL)['distance']);
    }

    /** Az OSRM kiesése ne állítsa meg a szomszéd-számítást. */
    public function testAzOsrmHibajaNemSzallElHanemTovabbEsik(): void {
        $tavolsag = new class extends \Distance {
            protected function osrmApi(): \ExternalApi\OsrmApi {
                throw new \Exception('az OSRM nem elérhető');
            }
            protected function mapquestApi(): \ExternalApi\MapquestApi {
                throw new \Exception('nincs Mapquest-kulcs');
            }
        };

        $eredmeny = $tavolsag->resolveDistance(self::BUDAPEST, self::SZEGED, self::LEGVONAL);

        self::assertSame(self::LEGVONAL, $eredmeny['distance']);
        self::assertFalse($eredmeny['road']);
    }
}
