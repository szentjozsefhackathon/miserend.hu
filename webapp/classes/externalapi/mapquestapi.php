<?php

namespace ExternalApi;

# https://developer.mapquest.com/

class MapquestApi extends \ExternalApi\ExternalApi {

    public $name = 'mapquest';
    public $apiUrl = "http://open.mapquestapi.com/directions/v2/";

    /**
     * Szándékosan nincs testQuery: a Mapquestnél MINDEN hívás a fizetős havi keretből
     * megy, egy útvonalkérés is. A /health-et kézzel is, cronból is nyitogatjuk, tehát
     * az ellenőrzés magát a kvótát fogyasztaná el — pont azt, amit a #129 óta spórolunk.
     *
     * A tényleges állapotot úgyis a valódi használat mutatja meg: 403-nál a distance()
     * megjegyzi a kvótafogyást, és 24 órán át meg sem próbálja újra.
     */
    public $testSkipReason = 'Nincs ingyenes tesztlekérdezés: minden hívás a fizetős keretből megy (#129). Az állapotot a valódi használat mutatja.';

    /**
     * #129: ha a Mapquest free tier havi limitje elfogy, eddig minden hívás
     * elment, csak utána kaptunk 403-at - feleslegesen pazaroltuk az
     * erőforrást (CURL időt, hálózatot, kvótát).
     *
     * Most amint egyszer 403-at kaptunk, megjegyezzük (in-request statikusan
     * + cross-request egy fájlban TTL-lel), és a következő hívás már a HTTP
     * előtt visszatér -2-vel. Mivel a Mapquest kvóta tipikusan havonta áll
     * vissza, 24 óra TTL bőven konzervatív - ha valamiért hamarabb feloldják,
     * legrosszabb esetben fél nap késéssel próbálkozunk újra.
     */
    private const RATE_LIMIT_TTL_SECONDS = 86400; // 24h
    private static $rateLimitHitMemo = null; // in-request memo

    function distance($pointFrom, $pointTo) {

        global $config;

        if (!$config['mapquest']['appkey'] or $config['mapquest']['appkey'] == '***') {
            throw new \Exception("Missing mapquest appkey.");
        }

        // #129: ha a memózott rate-limit állapot mond hogy nem ér semmit
        // próbálkozni, ne is kérjünk semmit.
        if ($this->isRateLimited()) {
            return -2;
        }

        $this->query = "route?from=" . implode(',', $pointFrom) . "&to=" . implode(',', $pointTo) . "";
        $this->query .= "&outFormat=json&unit=k&routeType=shortest&narrativeType=none";
        $this->query .= "&doReverseGeocode=false";

        try {
            $this->runQuery();
        }
        catch (\Exception $e) {
            # Általában akkor kerül elő ez, ha a mapquestApin elfogyott a havi lekérdezés adagunk
            # #129: 403 esetén megjegyezzük, hogy ne pazaroljuk a következő
            # hívást sem. Egyéb hibára (pl. transient 5xx vagy network) nem.
            if ($this->responseCode == 403) {
                $this->markRateLimited();
            }
            return -2; # ??
        }

        # #129: FONTOS - a base runQuery() éles környezetben NEM dob kivételt
        # (csak isTesting módban), hanem elnyeli és false-szal tér vissza; a
        # responseCode-ot viszont a curl után beállítja. Ezért a 403-at a hívás
        # UTÁN is detektálni kell, különben a fenti catch sosem fut le prod-on és
        # a rate-limit jelzés soha nem íródna ki.
        if ($this->responseCode == 403) {
            $this->markRateLimited();
            return -2;
        }

        $mapquest = $this->jsonData;
        if (isset($mapquest->route->routeError->errorCode)) {
            if ($mapquest->info->statuscode == 602)
                return -1;
            elseif ($mapquest->route->routeError->errorCode > 0)
                return -2;
        }
        $d = $mapquest->route->distance * 1000;
        return $d;
    }

    private function isRateLimited(): bool {
        // In-request memo: ugyanazon kérés további hívásai már ne is fájl-ozzanak.
        if (self::$rateLimitHitMemo !== null) {
            return self::$rateLimitHitMemo;
        }

        $path = $this->getRateLimitCachePath();
        if (is_readable($path)) {
            $hitAt = (int) @file_get_contents($path);
            if ($hitAt > 0 && (time() - $hitAt) < self::RATE_LIMIT_TTL_SECONDS) {
                self::$rateLimitHitMemo = true;
                return true;
            }
        }
        self::$rateLimitHitMemo = false;
        return false;
    }

    private function markRateLimited(): void {
        self::$rateLimitHitMemo = true;
        @file_put_contents($this->getRateLimitCachePath(), (string) time());
    }

    private function getRateLimitCachePath(): string {
        global $config;
        // #129: appkey-hash namespace, hogy több install / több key ugyanazon a
        // hoston ne szennyezze egymás rate-limit állapotát.
        $keyHash = substr(md5($config['mapquest']['appkey'] ?? 'default'), 0, 12);
        return sys_get_temp_dir() . '/miserend_mapquest_rate_limit_' . $keyHash;
    }

    function buildQuery() {
        global $config;
        $this->rawQuery = $this->query;
        $this->rawQuery .= "&key=" . $config['mapquest']['appkey'];
    }

}
