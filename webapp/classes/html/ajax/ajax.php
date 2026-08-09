<?php

namespace Html\Ajax;

class Ajax extends \Html\Html {

    public $template = "layout_empty.twig";

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
