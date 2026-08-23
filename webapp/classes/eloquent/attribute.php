<?php

namespace Eloquent;


class Attribute extends \Illuminate\Database\Eloquent\Model
{

	// Disable automatic timestamps
    public $timestamps = false;

    protected $fillable = ['church_id', 'key', 'value','fromOSM'];

    /**
     * #840: mit jelent a `fromOSM` jelző?
     *
     * ÚJ, EGYÉRTELMŰ DEFINÍCIÓ:
     *
     *     fromOSM = 1  <=>  a kulcs az OSM-címke névtérbe tartozik, és az OSM-szinkron
     *                       az autoritás rá.
     *
     * A jelzőt tehát a KULCS dönti el — SOHA nem az, hogy ki írta a sort utoljára.
     *
     * MIÉRT KELLETT EZT KIMONDANI. A jelző korábban három különböző kérdésre válaszolt
     * egyszerre: „ki írta ezt a sort", „OSM-névtérbe tartozik-e a kulcs", és „a szinkron
     * tulajdona-e". 2024-ig ez ugyanaz volt, mert egyetlen író létezett, az OSM-import.
     * A #484 óta viszont a `\GlutenFreeCommunion` a MÁSODIK író, és a `diet:gluten_free`
     * egyszerre OSM-címke ÉS helyben szerkesztett érték.
     *
     * Mivel az `updateOrCreate` a (church_id, key) párra illeszt, és a `fromOSM` fillable,
     * az UTOLSÓ író bélyegezte meg a jelzőt: a sor oda-vissza billegett 0 és 1 között
     * aszerint, hogy az éjszakai cron vagy egy /edit mentés futott-e utoljára. A /josm
     * ezért nem egyszerűen rosszul szűrt — versenyhelyzetet mutatott. Élesben ettől
     * tűnt el a `diet:gluten_free` a statisztikából, pedig három templomnál ott van.
     *
     * @see \GlutenFreeCommunion::LOCAL_KEYS a kivételek, ahol a kulcs NEM OSM-címke
     */
    public static function isOsmKey(string $key): bool
    {
        return !in_array($key, \GlutenFreeCommunion::LOCAL_KEYS, true);
    }

    public function church()
    {
        return $this->belongsTo(Church::class);
    }
}
