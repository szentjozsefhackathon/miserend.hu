<?php

namespace Html\Ajax;

class Favorite extends Ajax {

    public function __construct() {
        global $user;

        // A kedvenc a felhasználóhoz tartozik, vendégnek tehát nincs értelme. Eddig
        // viszont a bejelentkezés hiánya nem állította meg a kérést: a sor `uid = 0`-val
        // beíródott a `favorites` táblába. Nem szivárgott ki adat, de bárki korlátlanul
        // tölthette a táblát gazdátlan sorokkal.
        if (empty($user->uid)) {
            throw new \Exception("Hiányzó jogosultság: a kedvencekhez be kell jelentkezni.");
        }

        $tid = \Request::IntegerRequired('tid');
        $method = \Request::SimpletextRequired('method');
        echo $tid."-".$method;
        if ($method == 'add') {
            if (!$user->addFavorites($tid)) {
                throw new \Exception("Could not add favorites.");
            }
        } else if ($method == 'del') {
            if (!$user->removeFavorites($tid)) {
                throw new \Exception("Could not remove favorites.");
            }
        }
    }

}
