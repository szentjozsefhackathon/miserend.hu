<?php

namespace ExternalApi;

/**
 * #673: a saját OSRM útvonaltervező.
 *
 * „Külső" API abban az értelemben, hogy HTTP-n kérdezzük — de a saját compose-unkban
 * fut, tehát nem függünk idegen szolgáltatótól és nincs kvóta. Azért ide kerül, mert
 * így a /health ugyanúgy figyeli, mint a többi végpontot: enélkül némán elhalhatna, és
 * csak abból derülne ki, hogy az útvonal-alapú távolságok visszaesnek légvonalra.
 *
 * OPCIONÁLIS. Az `osrm` compose-profil mögött van, tehát a sima `docker compose up`
 * nem hozza fel. Ha nincs beállítva, a /health nem hibát mutat, hanem azt, hogy nincs
 * bekapcsolva — a piros maradjon a valódi bajnak.
 */
class OsrmApi extends \ExternalApi\ExternalApi {

    public $name = 'osrm';
    public $apiUrl;
    public $format = 'json';

    /**
     * Nincs cache.
     *
     * Saját szolgáltatás, a válasz 10–20 ms; a lemezre írt cache többe kerülne, mint
     * amennyit spórol, és a gráf frissítése után elavult útvonalakat szolgálna ki.
     */
    public $cache = false;

    public $testQuery;
    public $testSkipReason;

    /**
     * @param string|null $apiUrl a végpont; null esetén az OSRM_URL env-ből
     *
     * A paraméter a TESZTELHETŐSÉG miatt van. Az `env()` implementációja
     * környezetfüggő — a projekté csak akkor él, ha az Illuminate helpere még nem
     * definiálta —, és a `putenv()` nem mindenhol látszik rajta keresztül. Emiatt egy
     * env-et állítgató teszt helyben átment, a CI-ban viszont elbukott. Kifejezett
     * paraméterrel a viselkedés mindenhol ugyanaz.
     */
    function __construct(?string $apiUrl = null)
    {
        $this->apiUrl = rtrim($apiUrl ?? (string) env('OSRM_URL', ''), '/');

        if ($this->apiUrl === '') {
            $this->testSkipReason = 'Nincs beállítva az OSRM_URL — az útvonaltervező '
                . 'opcionális (`docker compose --profile osrm up -d`).';
        } else {
            $this->apiUrl .= '/';
            // Két budapesti pont: a válasz alakja számít, nem a konkrét útvonal.
            $this->testQuery = 'route/v1/driving/19.04,47.49;19.05,47.50?overview=false';
        }

        parent::__construct();
    }

    function buildQuery() {
        $this->rawQuery = $this->query;
    }

    /**
     * Az OSRM akkor is `code`-dal felel, ha nem talál útvonalat (`NoSegment`) — például
     * mert a gráf egy másik területről épült. A /health-nek az a kérdése, hogy a
     * szolgáltatás FUT-e és betöltötte-e a gráfot; a lefedettség külön kérdés.
     */
    function test() {
        $this->query = $this->testQuery;
        $this->run();

        if ($this->hasError()) {
            throw new \Exception('Az OSRM nem válaszolt: ' . $this->error);
        }

        if (!isset($this->jsonData->code)) {
            throw new \Exception('Az OSRM válasza nem értelmezhető (nincs `code` mező).');
        }

        return true;
    }

    /**
     * Útvonal-távolság méterben két pont között, vagy null, ha nem kapható.
     *
     * A null SZÁNDÉKOSAN nem kivétel: a hívó (\Distance::resolveDistance) ilyenkor a
     * Mapquestre, majd a légvonalbeli távolságra esik vissza. Az útvonaltervező kiesése
     * ne állítsa meg a szomszéd-számítást — legfeljebb pontatlanabb lesz.
     */
    function routeDistance(array $from, array $to): ?float {
        if ($this->apiUrl === '/' || $this->apiUrl === '') {
            return null;
        }

        $this->query = sprintf(
            'route/v1/driving/%F,%F;%F,%F?overview=false',
            $from['lon'], $from['lat'], $to['lon'], $to['lat']
        );
        $this->quiet = true;
        $this->run();

        if ($this->hasError() || !isset($this->jsonData->code) || $this->jsonData->code !== 'Ok') {
            return null;
        }

        return isset($this->jsonData->routes[0]->distance)
            ? (float) $this->jsonData->routes[0]->distance
            : null;
    }
}
