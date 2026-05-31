<?php

/**
 * #315: heti hét templom önkéntesség feltámasztása.
 *
 * Az eredeti `Campaign` osztály (2014-2019) `mysql_*` API-t használt — ami a PHP 7
 * óta törölve van. Ezért az `assignUpdates()` és `clearoutVolunteers()` évek óta
 * NEM futtak. A `user.volunteer` mező és a `updates` tábla viszont megmaradt;
 * a profil-oldalon a checkbox is működik a opt-in-hez.
 *
 * Ez az újraírás:
 *   - DB-hozzáférés: Illuminate\Database\Capsule (Eloquent)
 *   - Email-küldés: \Eloquent\Email + Twig template
 *   - Cron-entry: webapp/cron/weekly-volunteers.php (CLI)
 *
 * Üzemmódok (statikus belépők):
 *   Campaign::assignUpdates()       — hetente egyszer: templom-csomag kiosztás + email
 *   Campaign::clearoutVolunteers()  — havonta egyszer: inaktív önkéntesek visszafogása
 */

use Illuminate\Database\Capsule\Manager as DB;

class Campaign {

    /** A heti kvóta felhasználónként (templom-szám). */
    private const WEEKLY_LIMIT = 7;
    /** Az „aktív" timeframe órákban (160h ~ kb. 1 hét). */
    private const RECENT_HOURS = 160;
    /** Honnan számít „régen frissített" a templom (months). */
    private const STALE_MONTHS = 24;
    /** Egy kiosztás bemutatandó találatok cutoff-ja (months). */
    private const SKIP_RECENT_REMARK_MONTHS = 2;

    /**
     * #315: a fő hetenkénti munka. Az önkénteseknek kioszt egy adag templomot
     * (max WEEKLY_LIMIT / user), elmenti az `updates` táblába és értesítő emailt
     * küld. Idempotens: ha a felhasználó már megkapott ennyi templomot ebben a héten,
     * NEM kap újabb adagot.
     */
    public static function assignUpdates(): array {
        $stats = [
            'users_processed' => 0,
            'churches_assigned' => 0,
            'emails_sent' => 0,
            'errors' => [],
        ];

        // 1. Aktív önkéntesek listája — akinek `volunteer=1` és az utóbbi heti adagját
        //    még NEM kapta meg (kevesebb mint WEEKLY_LIMIT update sorral).
        $cutoff = (new DateTime())->modify('-' . self::RECENT_HOURS . ' hours')->format('Y-m-d H:i:s');
        $eligibleUsers = DB::table('user')
            ->select('user.uid', 'user.login', 'user.email', 'user.becenev', 'user.nev')
            ->selectRaw('COALESCE(u.cnt, 0) AS recent_count')
            ->leftJoinSub(
                DB::table('updates')->selectRaw('uid, COUNT(*) AS cnt')
                    ->where('timestamp', '>', $cutoff)
                    ->groupBy('uid'),
                'u',
                'u.uid', '=', 'user.uid'
            )
            ->where('user.volunteer', 1)
            ->where(function ($q) {
                $q->whereNull('u.cnt')->orWhere('u.cnt', '<', self::WEEKLY_LIMIT);
            })
            ->get();

        if ($eligibleUsers->isEmpty()) {
            return $stats;
        }

        // 2. Kiosztható templomok — ok=i, magyar, „templom"-jellegű név,
        //    régen frissített (>2 év), nincs aktuális update / észrevétel.
        $staleDate = (new DateTime())->modify('-' . self::STALE_MONTHS . ' months')->format('Y-m-d');
        $assignableChurches = DB::table('templomok AS t')
            ->select('t.id', 't.nev', 't.ismertnev', 't.varos', 't.frissites')
            ->where('t.ok', 'i')
            ->where('t.orszag', 12)
            ->where(function ($q) {
                $q->where('t.nev', 'LIKE', '%templom%')
                  ->orWhere('t.nev', 'LIKE', '%bazilika%')
                  ->orWhere('t.nev', 'LIKE', '%székesegyház%');
            })
            ->where('t.frissites', '<', $staleDate)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('updates')
                  ->whereColumn('updates.tid', 't.id')
                  ->where('timestamp', '>', (new DateTime())->modify('-' . self::SKIP_RECENT_REMARK_MONTHS . ' months')->format('Y-m-d H:i:s'));
            })
            ->orderBy('t.frissites')
            ->orderBy('t.id')
            ->limit($eligibleUsers->count() * self::WEEKLY_LIMIT)
            ->get();

        if ($assignableChurches->isEmpty()) {
            $stats['errors'][] = 'Nincs kiosztható templom (mind friss vagy van észrevétel).';
            return $stats;
        }

        // 3. Felhasználónként WEEKLY_LIMIT templomot oszt ki — INSERT updates + email.
        $churchOffset = 0;
        foreach ($eligibleUsers as $user) {
            $remaining = (int) (self::WEEKLY_LIMIT - $user->recent_count);
            $batch = array_slice($assignableChurches->all(), $churchOffset, $remaining);
            if (empty($batch)) {
                break;
            }
            $churchOffset += count($batch);

            try {
                foreach ($batch as $church) {
                    DB::table('updates')->insert([
                        'uid' => $user->uid,
                        'tid' => $church->id,
                    ]);
                }
                self::sendWeeklyEmail($user, $batch);
                $stats['users_processed']++;
                $stats['churches_assigned'] += count($batch);
                $stats['emails_sent']++;
            } catch (\Throwable $e) {
                $stats['errors'][] = "user {$user->uid}: " . $e->getMessage();
            }
        }

        return $stats;
    }

    /**
     * #315: inaktív önkéntesek visszafogása. Aki az utóbbi hónapban semmilyen
     * `updates` sort nem hozott be ÉS semmilyen `eszrevetelek`-et nem küldött,
     * annak a `volunteer` flag-jét kivesszük (0-ra állítjuk). Email-figyelmeztetés
     * is megy.
     */
    public static function clearoutVolunteers(): array {
        $stats = ['cleared' => 0, 'errors' => []];
        $monthAgo = (new DateTime())->modify('-1 month')->format('Y-m-d H:i:s');

        $inactives = DB::table('user')
            ->select('user.uid', 'user.login', 'user.email', 'user.nev', 'user.becenev')
            ->where('user.volunteer', 1)
            ->whereNotExists(function ($q) use ($monthAgo) {
                $q->select(DB::raw(1))->from('updates')
                  ->whereColumn('updates.uid', 'user.uid')
                  ->where('timestamp', '>', $monthAgo);
            })
            ->whereNotExists(function ($q) use ($monthAgo) {
                $q->select(DB::raw(1))->from('eszrevetelek')
                  ->whereColumn('eszrevetelek.login', 'user.login')
                  ->where('datum', '>', $monthAgo);
            })
            ->get();

        foreach ($inactives as $user) {
            try {
                DB::table('user')->where('uid', $user->uid)->update(['volunteer' => 0]);
                $stats['cleared']++;
                // Mint a régi Campaign-ben: udvariasan értesítjük is őket, hogy ne
                // egy „hirtelen elhalkulás" legyen — kérdés nélkül vissza tudják állítani.
                try {
                    self::sendOptOutEmail($user);
                } catch (\Throwable $mailErr) {
                    $stats['errors'][] = "opt-out mail user {$user->uid}: " . $mailErr->getMessage();
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = "user {$user->uid}: " . $e->getMessage();
            }
        }

        return $stats;
    }

    /**
     * #315: opt-out értesítés az inaktívvá tett önkéntesnek.
     * A régi Campaign-ben is ment ilyen email — most külön Twig template-tel,
     * udvarias hangnemmel, link a profil-oldalra.
     */
    private static function sendOptOutEmail($user): void {
        $name = $user->becenev ?: ($user->nev ?: $user->login);
        $mail = new \Eloquent\Email();
        $mail->render('volunteer_optout', [
            'name' => $name,
            'addressee' => $user,
        ]);
        $mail->send($user->email);
    }

    /**
     * #315: a heti email-küldés segédje. A Twig template-tel rendereljük,
     * az Email osztály SMTP-vel küldi (a `notifications` flag-et az önkéntes-
     * választás explicit felülírja, mert az opt-in egy másik, saját akarat).
     */
    private static function sendWeeklyEmail($user, array $churches): void {
        $name = $user->becenev ?: ($user->nev ?: $user->login);
        $week = (int) date('W');

        $mail = new \Eloquent\Email();
        $mail->render('volunteer_weekly', [
            'name' => $name,
            'week_number' => $week,
            'churches' => array_map(function ($c) {
                return [
                    'id' => $c->id,
                    'nev' => $c->nev,
                    'ismertnev' => $c->ismertnev,
                    'varos' => $c->varos,
                    'frissites' => $c->frissites,
                ];
            }, $churches),
            'addressee' => $user,
        ]);
        $mail->send($user->email);
    }
}
