<?php

/**
 * #873: CSRF-védelem — POST + együtt utazó token.
 *
 * A gond, amiből a jegy indult (#869): az állapotváltásaink GET-en mennek, és
 * kizárólag a süti azonosítja a kérést. A böngésző a sütit MINDEN kéréshez
 * mellékeli, akkor is, ha a kérést egy idegen oldal indította — egy `<img
 * src="https://miserend.hu/church/1/changeholders?uid=666&access=allowed">` a
 * bejelentkezett adminunk nevében ad ki jogosultságot. A jogosultság-ellenőrzés
 * ilyenkor hibátlanul lefut, és igent mond: tényleg az admin kérte, csak épp nem
 * ő akarta.
 *
 * Két, egymást kiegészítő őr kell:
 *
 *   1. LEGYEN POST. Ez önmagában kizárja a `<img>`, `<script>`, `<link>` és a
 *      puszta link alakú támadásokat — azok mind GET-et adnak ki. Rejtett űrlapot
 *      viszont továbbra is lehet küldeni, ezért kell a második is.
 *   2. LEGYEN TOKEN. Olyan érték, amit a támadó oldala nem tud kitalálni és nem
 *      tud kiolvasni. A böngésző a sütinket automatikusan küldi, de a sütink
 *      TARTALMÁT az idegen oldal nem látja (same-origin policy), tehát a
 *      belőle számolt tokent sem tudja mellékelni.
 *
 * Miért külön süti, és miért nem a belépési token?
 *
 * borazslo felvetette (#873), hogy „egyszerűen a belépett user tokenjét küldjük
 * át". A gondolat jó — de a belépési token a `token` sütiben él, és két gyakorlati
 * baja lenne:
 *
 *   - A vendég is küld be űrlapot (észrevétel, javaslat, regisztráció), neki
 *     viszont nincs belépési tokenje. Így minden ilyen űrlap kiesne a védelemből,
 *     vagy külön ágat kapna.
 *   - A belépés/kilépés lecseréli a tokent. A már megnyitott lapok űrlapjai
 *     ilyenkor elavult tokent hordoznának, és a beküldés — látszólag ok nélkül —
 *     elszállna.
 *
 * Ezért külön, kizárólag erre a célra szolgáló sütit használok. Ami a lapra kerül,
 * az nem a süti értéke, hanem annak lenyomata: ha a token valaha kiszivárog (napló,
 * Referer, képernyőkép), a süti értéke akkor sem derül ki belőle.
 */
class Csrf {

    /** A süti neve. HttpOnly: a böngészőben futó szkriptnek sem kell látnia. */
    const COOKIE = 'csrf';

    /** Az űrlapmező és a JSON/ajax fejléc neve. */
    const FIELD = 'csrf_token';
    const HEADER = 'HTTP_X_CSRF_TOKEN';

    /** A süti élettartama. Bőven túl a leghosszabb belépési tokenen (2 hét). */
    const LIFETIME = '+1 year';

    /** Teszt/CLI: ha nem tudunk sütit küldeni, ebben tartjuk a kérés erejéig. */
    private static ?string $memoria = null;

    /**
     * A lapra kitehető token. Mellékhatása van: ha még nincs sütink, itt keletkezik.
     * Ezért a sablonokba GLOBÁLISKÉNT kerül (l. buildTwigEnvironment) — így minden
     * oldal legenerálja, még mielőtt bármit kiírnánk.
     */
    public static function token(): string {
        return hash('sha256', 'miserend-csrf|' . self::titok());
    }

    /** A rejtett űrlapmező, kész HTML-ként. */
    public static function field(): string {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Érvényes-e a beküldött token?
     *
     * Az összehasonlítás `hash_equals`: futásidő-független, tehát a token nem
     * találgatható ki bájtonként a válaszidőből.
     */
    public static function valid(): bool {
        $kuldott = self::submitted();
        if ($kuldott === '') {
            return false;
        }
        return hash_equals(self::token(), $kuldott);
    }

    /**
     * Az őr: POST-ot és érvényes tokent követel.
     *
     * Kivételt dob, mert a hívási helyeken (Html\* konstruktorok) ez a bevett mód:
     * az index.php elkapja, naplóz, és hibaoldalt/JSON-hibát ad. A 403-at előre
     * kiírjuk, hogy a válasz státusza is beszédes legyen.
     */
    public static function guard(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::megtagad('Ez a művelet csak POST kéréssel indítható.');
        }
        if (!self::valid()) {
            self::megtagad('Érvénytelen vagy hiányzó biztonsági token. Töltsd újra az oldalt, és próbáld meg ismét.');
        }
    }

    private static function megtagad(string $uzenet): void {
        if (!headers_sent()) {
            http_response_code(403);
        }
        throw new \Exception($uzenet);
    }

    /** A beküldött token: űrlapmezőből vagy — ajax/JSON esetén — fejlécből. */
    public static function submitted(): string {
        if (isset($_POST[self::FIELD]) && is_string($_POST[self::FIELD])) {
            return $_POST[self::FIELD];
        }
        if (isset($_SERVER[self::HEADER]) && is_string($_SERVER[self::HEADER])) {
            return $_SERVER[self::HEADER];
        }
        return '';
    }

    /**
     * A süti értéke — ha nincs, most keletkezik.
     *
     * SameSite=Lax: a süti keresztoldali POST-hoz el sem indul, tehát önmagában is
     * megfogja a támadás javát. Nem hagyatkozom rá egyedül (régi böngészők,
     * és a Lax a GET-es top-level navigációt átengedi), de ingyen van.
     */
    private static function titok(): string {
        if (isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) && $_COOKIE[self::COOKIE] !== '') {
            return $_COOKIE[self::COOKIE];
        }
        if (self::$memoria !== null) {
            return self::$memoria;
        }

        $ertek = bin2hex(random_bytes(32));
        self::$memoria = $ertek;
        $_COOKIE[self::COOKIE] = $ertek;

        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            setcookie(self::COOKIE, $ertek, [
                'expires' => strtotime(self::LIFETIME),
                'path' => '/',
                'secure' => \Token::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        return $ertek;
    }

    /** Csak tesztekhez: a kérésenkénti állapot eldobása. */
    public static function reset(): void {
        self::$memoria = null;
        unset($_COOKIE[self::COOKIE]);
    }
}
