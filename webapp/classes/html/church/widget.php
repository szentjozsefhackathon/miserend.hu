<?php

namespace Html\Church;

use Illuminate\Database\Capsule\Manager as DB;

class Widget extends \Html\Html {

    public function __construct($path) {
        parent::__construct();

        // A Path osztály argumentuma itt az URL többi része, tipikusan [0] az id
        $id = null;
        if (is_array($path) && isset($path[0]) && is_numeric($path[0])) {
            $id = (int)$path[0];
        }

        // fallback: kérés paraméterekből is megpróbáljuk.
        // #391: a kézi isset + is_numeric páros pontosan az, amit a \Request::Integer()
        // ad — az üres/hiányzó értékre false-t, nem-számra kivételt. Itt a kivétel nem
        // kívánatos (a widget essen vissza a másik kulcsra), ezért a nyers olvasás
        // helyett a \Request::get()-en át jövő értéket ellenőrizzük.
        if (!$id) {
            foreach (['id', 'church_id'] as $key) {
                $value = \Request::get($key);
                if (is_numeric($value)) {
                    $id = (int) $value;
                    break;
                }
            }
        }

        if (!$id) {
            throw new \Exception('Nem található templom azonosító a widget megjelenítéséhez.');
        }

        // A twig sablonnak átadjuk a templom id-t, az Angular kliens ebből, vagy az útvonalból veszi majd
        $this->template = 'church/widget.twig';
        $this->churchId = $id;
    }

}
