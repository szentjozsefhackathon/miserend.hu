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
 *
 * Üzemmódok (statikus belépők):
 *   Campaign::assignUpdates()       — hetente egyszer: templom-csomag kiosztás + email
 *   Campaign::clearoutVolunteers()  — havonta egyszer: inaktív önkéntesek visszafogása
 *
 * Mindkettőt a rendes cron-futtató indítja (webapp/fajlok/crons.php registry), kézzel
 * pedig a cron-oldalról futtatható:
 *
 *     docker compose exec miserend php index.php 'q=cron&cron_id=<id>'
 *
 * Volt hozzá külön CLI-fájl is (webapp/cron/weekly-volunteers.php), de az egyetlen
 * többlete a statisztika kiírása volt — az azóta itt van, tehát mindkét úton látszik.
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
            self::report('assignUpdates', $stats);
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
            // #315: ne osszunk ki olyan templomot, amelynek NYITOTT észrevétele van
            // (allapot u=új, f=folyamatban; j=megoldott). A rewrite-ban ez a kizárás
            // kimaradt - a komment ígéri ("nincs update / észrevétel"), de a kód csak
            // az updates-et nézte. A régi tábla `eszrevetelek`/`hol_id`, az új `remarks`/`church_id`.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('remarks')
                  ->whereColumn('remarks.church_id', 't.id')
                  ->whereIn('remarks.allapot', ['u', 'f']);
            })
            // Ugyanez a javaslatokra: egy függő (PENDING) csomag ugyanúgy folyamatban
            // lévő munka, mint egy nyitott észrevétel — aki azt feldolgozza, annak ne
            // dolgozzon rá az önkéntes. Az észrevételek mellett ez a másik beküldési
            // csatorna, és remélhetőleg innen jön majd a több.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('cal_suggestion_packages')
                  ->whereColumn('cal_suggestion_packages.church_id', 't.id')
                  ->where('cal_suggestion_packages.state', 'PENDING');
            })
            ->orderBy('t.frissites')
            ->orderBy('t.id')
            ->limit($eligibleUsers->count() * self::WEEKLY_LIMIT)
            ->get();

        if ($assignableChurches->isEmpty()) {
            $stats['errors'][] = 'Nincs kiosztható templom (mind friss, vagy van nyitott észrevétele/javaslata).';
            self::report('assignUpdates', $stats);
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

        self::report('assignUpdates', $stats);
        return $stats;
    }

    /**
     * A futás összegzése a kimenetre.
     *
     * A cron-futtató eldobja a visszatérési értéket, tehát enélkül sem a cron-oldalon,
     * sem a `docker logs`-ban nem látszott, csinált-e egyáltalán valamit a munka.
     */
    private static function report(string $what, array $stats): void {
        $parts = [];
        foreach ($stats as $key => $value) {
            if ($key === 'errors') {
                continue;
            }
            $parts[] = $key . '=' . $value;
        }
        echo 'Campaign::' . $what . '(): ' . implode(', ', $parts)
            . ', errors=' . count($stats['errors']) . "\n";

        foreach ($stats['errors'] as $error) {
            echo '  HIBA: ' . $error . "\n";
        }
    }

    /**
     * #315: inaktív önkéntesek visszafogása. Akitől az utóbbi hónapban SEMMI nem
     * érkezett be, annak a `volunteer` flagjét kivesszük. Email-figyelmeztetés is megy.
     *
     * A feltétel korábban az `updates` táblát is nézte („aki nem hozott be updates
     * sort"), és ez félreértés volt. Az `updates`-be egyedül az assignUpdates() ír,
     * amikor KIOSZTJA a templomokat — tehát az a kiosztás naplója, nem a munkáé.
     * Minden önkéntes hetente kap hét sort, így a feltétel gyakorlatilag soha nem
     * teljesült: a takarítás sosem fogott meg senkit. Fordítva pedig igazságtalan
     * volt: aki azért nem kapott kiosztást, mert épp nem akadt kiosztható templom,
     * az inaktívnak látszott.
     *
     * Tevékenységnek ezért azt tekintjük, amit a felhasználó BEKÜLDÖTT: észrevétel
     * vagy javaslat-csomag. Amíg a `updates` tábla nem jelzi a munka elvégzését,
     * addig nem is használható erre.
     */
    public static function clearoutVolunteers(): array {
        $stats = ['cleared' => 0, 'errors' => []];
        $monthAgo = (new DateTime())->modify('-1 month')->format('Y-m-d H:i:s');

        $inactives = DB::table('user')
            ->select('user.uid', 'user.login', 'user.email', 'user.nev', 'user.becenev')
            ->where('user.volunteer', 1)
            ->whereNotExists(function ($q) use ($monthAgo) {
                // #315: a tábla `eszrevetelek` -> `remarks`, a submission-dátum
                // `datum` -> `created_at` (a `login` oszlop megmaradt).
                $q->select(DB::raw(1))->from('remarks')
                  ->whereColumn('remarks.login', 'user.login')
                  ->where('remarks.created_at', '>', $monthAgo);
            })
            ->whereNotExists(function ($q) use ($monthAgo) {
                // A javaslat-csomag ugyanúgy beküldött munka, mint az észrevétel —
                // az állapota itt mindegy, a beküldés ténye számít.
                $q->select(DB::raw(1))->from('cal_suggestion_packages')
                  ->whereColumn('cal_suggestion_packages.sender_user_id', 'user.uid')
                  ->where('cal_suggestion_packages.created_at', '>', $monthAgo);
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

        self::report('clearoutVolunteers', $stats);
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
                    // #497: a levélben a település a boundary-ból, mint mindenhol máshol.
                    'varos' => $c->locationCityName(),
                    'frissites' => $c->frissites,
                ];
            }, $churches),
            'addressee' => $user,
        ]);
        $mail->send($user->email);
    }
}
