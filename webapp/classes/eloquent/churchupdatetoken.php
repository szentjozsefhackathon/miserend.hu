<?php

namespace Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Capsule\Manager as DB;

class ChurchUpdateToken extends Model {

    protected $table      = 'church_update_tokens';
    protected $primaryKey = 'token';
    public    $incrementing = false;
    protected $keyType    = 'string';

    protected $fillable = ['token', 'uid', 'church_id', 'email_batch_id', 'expires_at'];

    public $timestamps = false;

    /**
     * @return array{success: bool, church_id?: int, message?: string}
     */
    public static function redeem(string $tokenStr): array {
        $record = self::find($tokenStr);

        if (!$record) {
            return ['success' => false, 'message' => 'Érvénytelen token.'];
        }
        if ($record->used_at !== null) {
            return ['success' => false, 'message' => 'Ez a link már felhasználásra került.'];
        }
        if ($record->expires_at < date('Y-m-d H:i:s')) {
            return ['success' => false, 'message' => 'Ez a link lejárt.'];
        }

        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        if ($record->church_id === null) {
            $batchTokens = self::where('email_batch_id', $record->email_batch_id)->get();
            $churchIds   = $batchTokens->pluck('church_id')->filter()->unique()->values();

            Church::whereIn('id', $churchIds)->update(['frissites' => $today]);
            self::where('email_batch_id', $record->email_batch_id)->update(['used_at' => $now]);

            // #497: a település a boundary-ból jön, nem oszlopból — ezért utólag tesszük rá.
            $churches = Church::whereIn('id', $churchIds)->get(['id', 'nev', 'ismertnev'])
                ->map(function ($templom) {
                    $tomb = $templom->toArray();
                    $tomb['varos'] = $templom->locationCityName();
                    return $tomb;
                })->toArray();

            return ['success' => true, 'church_id' => $churchIds->first(), 'churches' => $churches];
        } else {
            Church::where('id', $record->church_id)->update(['frissites' => $today]);
            $record->used_at = $now;
            $record->save();

            return ['success' => true, 'church_id' => $record->church_id, 'churches' => []];
        }
    }
}
