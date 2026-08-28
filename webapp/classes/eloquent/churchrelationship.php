<?php

namespace Eloquent;

/**
 * ChurchRelationship – kapcsolat két misézőhely között.
 *
 * A modell kizárólag angol kulcsszavakat kezel.
 * A fordítás (t()) kizárólag a Twig sablonokban és JS scriptekben történik.
 *
 * Tábla: church_relationships
 * Mezők: id, parent_church_id, child_church_id, type, created_at, updated_at
 */
class ChurchRelationship extends \Illuminate\Database\Eloquent\Model {

    /*
     * #890: az `updated_at` oszlopon `ON UPDATE current_timestamp()` van, a `DEFAULT`
     * pedig NULL — és ez így marad, NEM kell hozzányúlni.
     *
     * Ezen a táblán ugyanis EGYÁLTALÁN NINCS UPDATE-útvonal: a kapcsolat módosítása a
     * #521 óta delete + create (l. `html/church/edit.php`), a `create()` pedig mindkét
     * időbélyeget beteszi az INSERT-be, PHP órával. Az `ON UPDATE` tehát holt szabály, a
     * `DEFAULT NULL` pedig sosem sül el.
     *
     * FIGYELEM, ha valaki kézi SQL-lel javítja a történelmi adatot: az `updated_at`-et
     * KÖTELEZŐ beírni a SET-be, különben az `ON UPDATE` a MySQL órájával — a PHP-énál
     * három órával előrébb járó órával — írja felül.
     */
    protected $table = 'church_relationships';

    /*
     * #663: a `type` kivezetésre került. Minden kapcsolat alárendeltség — az
     * "alárendelt plébánia" (oldallagosan ellátva) és az "alárendelt fília" között a
     * megjelenítésben sincs különbség, tehát nincs mit tárolni róla.
     */
    protected $fillable = ['parent_church_id', 'child_church_id'];

    /**
     * A felsőbbrendű misézőhely (szülő).
     */
    public function parent() {
        return $this->belongsTo(Church::class, 'parent_church_id');
    }

    /**
     * Az alsóbbrendű misézőhely (gyerek).
     */
    public function child() {
        return $this->belongsTo(Church::class, 'child_church_id');
    }

    /**
     * Érvényes rang kulcsok (angol, DB enum értékek).
     */
    public static function validRanks(): array {
        return ['parish', 'auxiliary', 'filial', 'rectoral'];
    }
}
