<?php

namespace Html\Ajax;

class ChurchesInBBox extends Ajax {

    public function __construct() {

        // #391: a bbox parse+validáció a \Request::Bbox()-ba került (pontosvesszős
        // 4-float lista). Hiányzó/rossz alakú bbox → false, ekkor némán kilépünk
        // (mint korábban a count()!=4 / is_numeric őrök).
        $bbox = \Request::Bbox('bbox');
        if($bbox === false) return;

        /*
         * #641: ez a végpont a térkép MINDEN mozdításakor lefut, és templomonként
         * külön-külön kérdezett le mindent — fotót, attribútumokat, elhelyezkedést
         * (az utóbbi a boundaries táblát is), plusz KÉT Elasticsearch-kört a hétvégi
         * misékre. Mérve: 91 templomra 1,0 s, 460-ra 4,0 s, 4628-ra 35,5 s.
         *
         * Most minden köteges: egy lekérdezés a templomokra (fotókkal együtt), egy az
         * attribútumokra, és EGY Elasticsearch-hívás az összes hétvégi misére.
         * A lat/lon közvetlenül az oszlopból jön — a `location` accessor a boundaries
         * táblát is húzta, holott itt csak a koordináta kell.
         */
        $churchesInBBox = \Eloquent\Church::where('ok','i')
            ->inBBox(['latMin'=>$bbox[0],'lonMin'=>$bbox[1],'latMax'=>$bbox[2],'lonMax'=>$bbox[3]])
            ->with('photos')
            ->get();

        if ($churchesInBBox->isEmpty()) {
            echo json_encode([]);
            return;
        }

        $churchIds = $churchesInBBox->pluck('id')->map('intval')->all();

        // Minden attribútum egyetlen lekérdezéssel, templomonként kulcs => érték térképpé.
        $attributesByChurch = \Eloquent\Attribute::whereIn('church_id', $churchIds)
            ->get()
            ->groupBy('church_id')
            ->map(function ($rows) {
                return $rows->pluck('value', 'key')->toArray();
            })
            ->toArray();

        $weekendMasses = \Eloquent\Church::weekendMassesForChurches($churchIds);

        $return = [];
        global $user;
        $isAdmin = isset($user->isadmin) && $user->isadmin;

        foreach($churchesInBBox as $church) {
            $churchId = (int) $church->id;
            $attributes = $attributesByChurch[$churchId] ?? [];

            $thumbnail = isset($church->photos[0]) ? $church->photos[0]->smallUrl : false;

            $return[] = [
                'id' => $churchId,
                'nev' => self::primaryName($church, $attributes),
                'thumbnail' => $thumbnail,
                'denomination' => self::denomination($church, $attributes),
                'active' => $church->miseaktiv,
                'lat'=> $church->lat,
                'lon'=> $church->lon,
                'church_type' => $attributes['church:type'] ?? 'other',
                'weekend_masses' => $weekendMasses[$churchId] ?? ['saturday' => [], 'sunday' => []],
                // Az admin-linkekhez a sablon csak az id-t használja.
                'adminLinks' => $isAdmin ? ['id' => $churchId] : []
            ];
        }
        echo json_encode($return);
    }

    /*
     * #641: a `names` accessor templomonként újra lekérdezte az attribútumokat.
     * Ugyanaz a sorrend, csak a már betöltött adatból.
     */
    private static function primaryName($church, array $attributes): string {
        foreach (['name:hu', 'name'] as $key) {
            if (!empty($attributes[$key])) {
                return $attributes[$key];
            }
        }
        return (string) $church->nev;
    }

    /*
     * #542/#641: a felekezet az OSM `denomination` attribútumból jön, fallbackként a
     * korábbi egyházmegye-heurisztikából — ugyanaz, mint a Church accessorban, de
     * lekérdezés nélkül.
     */
    private static function denomination($church, array $attributes): string {
        if (!empty($attributes['denomination'])) {
            return $attributes['denomination'];
        }
        return in_array($church->egyhazmegye, [34, 17, 18]) ? 'greek_catholic' : 'roman_catholic';
    }

}
