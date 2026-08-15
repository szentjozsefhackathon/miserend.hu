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
