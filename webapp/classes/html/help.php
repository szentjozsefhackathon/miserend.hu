<?php

namespace Html;

class Help extends Html {

    public function __construct($path) {
        $this->setTitle('Súgó');
        $this->content = '';

        /*
         * A jegyzet, ami itt állt („validate"), a bemenet ellenőrzését hiányolta. A
         * beírt érték azonban sehova nem jut el: a \Help egy `switch` a rögzített
         * azonosítókon, `default` ágán „Nincs ilyen segítség." — nincs lekérdezés, és a
         * bemenet nem kerül a kimenetre sem. Ellenőrizni tehát nincs mit.
         *
         * Ami VALÓBAN hiányzott: az argumentum nélküli /sugo hívás. Ott a `$path[0]`
         * nem létezik, és a null `explode()`-ba adása PHP 8 óta elavult figyelmeztetést
         * ad, majd üres lapot.
         */
        $azonositok = isset($path[0]) && $path[0] !== '' ? explode('-', (string) $path[0]) : [];

        foreach ($azonositok as $id) {
            $help = new \Help($id);
            $this->content .= $help->html;
        }

        if ($this->content === '') {
            $this->content = 'Nincs ilyen segítség.';
        }
    }

}
