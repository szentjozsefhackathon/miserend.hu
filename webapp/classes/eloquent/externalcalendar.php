<?php

namespace Eloquent;

class ExternalCalendar extends \Illuminate\Database\Eloquent\Model {
    /*
     * #890: az `updated_at` oszlopon `ON UPDATE current_timestamp()` van — és ez rendben
     * van így, NEM kell eltávolítani.
     *
     * A séma alapján ez kevert órát jelentene (a MySQL órája a `+05:00`-s session-zóna
     * miatt három órával előrébb jár a PHP-énál), de a gyakorlatban az `ON UPDATE`
     * egyetlen mai úton sem sül el: minden írás Eloquenten megy, az pedig expliciten
     * beteszi az `updated_at`-et a SET-listába, az explicit érték pedig felülírja az
     * `ON UPDATE`-et. Ez a tömeges frissítésre is igaz (`externalcalendarimporter.php`),
     * mert ott `\Eloquent\ExternalCalendar::where(...)` áll, ami Eloquent Buildert ad
     * vissza — az `update()` `addUpdatedAtColumn()`-t hív —, nem nyers query buildert.
     *
     * A záradék tehát ma tartalék, nem kevert óra. Az eltávolítása séma-módosítás lenne,
     * ami cserébe nem old meg semmit, viszont elvenné a védőhálót, ha valaha kerülne ide
     * nyers `DB::table('external_calendars')->update(...)`.
     *
     * FIGYELEM, ha valaki kézi SQL-lel javítja a történelmi adatot ezen a táblán: az
     * `updated_at`-et KÖTELEZŐ beírni a SET-be, különben az `ON UPDATE` a MySQL órájával
     * írja felül — a javító UPDATE maga rontaná el a mezőt.
     */
    protected $table = 'external_calendars';
    
    protected $fillable = ['church_id', 'name', 'url', 'active', 'last_import_at'];
    
    protected $dates = ['last_import_at', 'created_at', 'updated_at'];
    
    /**
     * Relationship: External calendar belongs to a church
     */
    public function church() {
        return $this->belongsTo(Church::class, 'church_id');
    }
    
    /**
     * Scope: Get active external calendars
     */
    public function scopeActive($query) {
        return $query->where('active', 1);
    }
    
    /**
     * Scope: Get external calendars for a specific church
     */
    public function scopeForChurch($query, $churchId) {
        return $query->where('church_id', $churchId);
    }
}
