<?php

namespace Html\Ajax;

class Ajax extends \Html\Html {

    public $template = "layout_empty.twig";

    /*
     * Az ajax végpontok JSON-t adnak vissza. A külső API-k hibakereső üzemmódban
     * eddig a teljes verem-kiírást a válaszba echózták — az a JSON törzs elé
     * került, tehát értelmezhetetlen lett, és a látogató fájlútvonalakat látott
     * (élő eset: az Overpass 429-e a főoldal egyházmegye-rétegénél). Innentől ott
     * csak a szerver-naplóba kerül.
     *
     * A leszármazottak konstruktora előtt kell megtörténnie, ezért van itt, és nem
     * az egyes végpontokban — különben minden új ajax végponton újra elő tudna
     * jönni ugyanez.
     */
    public static function markJson(): void {
        \ExternalApi\ExternalApi::markJsonResponse(true);
    }

    public function __construct($path) {
        // #391: eddig a teljes $_REQUEST-et visszaechóztuk JSON-ként. Ennek nincs hívója —
        // egyetlen leszármazott sem épít rá —, viszont az /ajax végpont így bármit
        // visszatükrözött, amit küldtek neki:
        //     GET /ajax?titok=erzekeny-ertek  ->  {"q":"ajax","titok":"erzekeny-ertek"}
        // Egy önmagában nem létező végpont ne szolgáljon tükörként; a leszármazottak
        // úgyis felülírják a content-et.
        $this->content = json_encode(['error' => 'Nincs ilyen ajax végpont.']);
    }
}
