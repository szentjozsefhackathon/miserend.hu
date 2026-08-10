<?php

use Illuminate\Database\Capsule\Manager as DB;

/**
 * #724: használati statisztika — süti nélkül, IP nélkül, azonosítás nélkül.
 *
 * A jegy kérdése: „hogyan lehetne a legtöbb használható adatot gyűjteni anélkül, hogy
 * ehhez sokat kéne papírozni?" A válasz az, hogy semmit nem tárolunk, amiből személy
 * azonosítható: nincs süti, nincs IP, nincs User-Agent, nincs munkamenet, nincs
 * időbélyeg percre. Csak napi darabszám útvonalanként.
 *
 * Ebből nem lehet visszakövetni senkit, tehát nincs mihez hozzájárulást kérni — a
 * felugró süti-ablak, amit a jegy elkerülni akart, épp ezért marad el.
 *
 * A számlálás SOHA nem buktathatja meg a kérést: minden írás elnyelt hibával megy.
 * Egy statisztika nem ér annyit, hogy miatta hibaoldalt lásson a látogató.
 */
class Stats {

    /** Ennél régebbi napi sorokat a takarító cron eldob. */
    public const MEGORZES = '24 months';

    /**
     * A nyers útvonalból normalizált, kis számosságú kulcs.
     *
     * A számokat helyőrzőre cseréljük (`templom/1234` → `templom/{id}`), különben
     * templomonként külön sor keletkezne, és a tábla a látogatottsággal együtt nőne.
     * Szabad szöveget (query-stringet) egyáltalán nem veszünk át.
     */
    public static function normalizeRoute(?string $url): string {
        $url = trim((string) $url, "/ \t\n\r\0\x0B");
        if ($url === '') {
            return 'home';
        }

        $reszek = array_slice(explode('/', $url), 0, 4);
        foreach ($reszek as $i => $resz) {
            if ($resz === '' || ctype_digit($resz)) {
                $reszek[$i] = '{id}';
            } else {
                // Csak a saját útvonalneveink karakterei maradhatnak.
                $reszek[$i] = mb_substr(preg_replace('/[^A-Za-z0-9_.-]/u', '', $resz), 0, 40);
                if ($reszek[$i] === '') {
                    $reszek[$i] = '{id}';
                }
            }
        }

        return mb_substr(implode('/', $reszek), 0, 120);
    }

    /**
     * Robot-e? A User-Agentet csak MEGNÉZZÜK, nem tároljuk — a robotok forgalma
     * különben elnyomná a valódi használatot, és pont a nagyságrend veszne el, amit
     * a jegy meg akar tudni.
     */
    public static function isBot(?string $userAgent): bool {
        if ($userAgent === null || $userAgent === '') {
            return true;
        }
        return (bool) preg_match(
            '/bot|crawl|spider|slurp|curl|wget|python-requests|headless|monitor|uptime|preview|fetch/i',
            $userAgent
        );
    }

    /**
     * Valódi látogatói kérés-e? A cron/CLI futás nem használat, a robot forgalma pedig
     * elnyomná a valódit — és pont a nagyságrend veszne el, amit a jegy meg akar tudni.
     */
    public static function shouldCount(): bool {
        return PHP_SAPI !== 'cli' && !self::isBot($_SERVER['HTTP_USER_AGENT'] ?? null);
    }

    public static function countPageview(?string $route, string $kind = 'html'): void {
        if (self::shouldCount()) {
            self::recordPageview($route, $kind);
        }
    }

    /**
     * A nulla találatos keresés a legértékesebb adat: az mondja meg, mit keresnek
     * nálunk, amit nem találnak meg.
     */
    public static function countSearch(?string $keyword, int $results): void {
        if (self::shouldCount()) {
            self::recordSearch($keyword, $results);
        }
    }

    /** A tényleges írás, szűrők nélkül — a szűrést a hívó (countPageview) végzi. */
    public static function recordPageview(?string $route, string $kind = 'html'): void {
        self::increment('stats_pageviews', [
            'date' => date('Y-m-d'),
            'route' => self::normalizeRoute($route),
            'kind' => in_array($kind, ['html', 'api', 'ajax'], true) ? $kind : 'html',
        ]);
    }

    public static function recordSearch(?string $keyword, int $results): void {
        $keyword = self::normalizeKeyword($keyword);
        if ($keyword === null) {
            return;
        }
        self::increment('stats_searches', [
            'date' => date('Y-m-d'),
            'keyword' => $keyword,
            'hits' => $results > 0 ? 1 : 0,
        ]);
    }

    /** Kisbetűs, összevont szóközű alak; a túl rövid és a túl hosszú kimarad. */
    public static function normalizeKeyword(?string $keyword): ?string {
        $keyword = trim(preg_replace('/\s+/u', ' ', (string) $keyword));
        if (mb_strlen($keyword) < 2 || mb_strlen($keyword) > 100) {
            return null;
        }
        return mb_strtolower($keyword);
    }

    private static function increment(string $table, array $kulcsok): void {
        try {
            $frissitett = DB::table($table)->where($kulcsok)->increment('count');
            if ($frissitett === 0) {
                // A versenyhelyzetet (két kérés ugyanarra a napra+útvonalra) az egyedi
                // kulcs fogja meg; ilyenkor a beszúrás elhasal, és a következő kérés
                // növeli. Egy elveszett darab nem számít.
                DB::table($table)->insert($kulcsok + ['count' => 1]);
            }
        } catch (\Throwable $e) {
            // A számlálás soha nem buktathatja meg a kérést. Ha a tábla még nem létezik
            // (élesben az initdb nem fut újra), ez csendben elmarad.
        }
    }

    /** @return array<int, object> napi összesítés az elmúlt N napra */
    public static function dailyTotals(int $days = 30): array {
        return DB::table('stats_pageviews')
            ->selectRaw('`date`, SUM(`count`) AS count')
            ->where('date', '>=', date('Y-m-d', strtotime('-' . $days . ' days')))
            ->groupBy('date')->orderBy('date')
            ->get()->all();
    }

    /** @return array<int, object> a legnépszerűbb útvonalak az elmúlt N napban */
    public static function topRoutes(int $days = 30, int $limit = 20): array {
        return DB::table('stats_pageviews')
            ->selectRaw('`route`, `kind`, SUM(`count`) AS count')
            ->where('date', '>=', date('Y-m-d', strtotime('-' . $days . ' days')))
            ->groupBy('route', 'kind')->orderByDesc('count')->limit($limit)
            ->get()->all();
    }

    /**
     * @param bool $hits true = eredményes keresések, false = amikre NINCS találat
     * @return array<int, object>
     */
    public static function topSearches(bool $hits, int $days = 30, int $limit = 20): array {
        return DB::table('stats_searches')
            ->selectRaw('`keyword`, SUM(`count`) AS count')
            ->where('date', '>=', date('Y-m-d', strtotime('-' . $days . ' days')))
            ->where('hits', $hits ? 1 : 0)
            ->groupBy('keyword')->orderByDesc('count')->limit($limit)
            ->get()->all();
    }
}
