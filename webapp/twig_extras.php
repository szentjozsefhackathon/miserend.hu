<?php
/*
 * twig_extras.php
 *
 * Ebben a fájlban találhatók a Twig-hez készített egyedi filterek és kiegészítők.
 *
 * A Twig sablonmotort a load.php-ban és a classes/html/html.php-ban inicializáljuk.
 * Ez a file tartalmazza az első saját Twig filterünket.
 *
 * Használat:
 *   - A filtert regisztrálni kell a Twig environmentben (pl. load.php-ban):
 *       $twig->addFilter(new \Twig\TwigFilter('hungarian_date', 'twig_hungarian_date_format'));
 *   - A sablonban így használható: {{ datum|hungarian_date }}
 *
 * Dokumentáció:
 *   - Ez a filter magyar dátumformátumot ad vissza.
 *   - A bemenet lehet string vagy timestamp.
 *   - További filterek is ide kerülhetnek a jövőben.
 */


function twig_hungarian_date_format($date, $format = null) {
    $_honapok = [
        1 => ['jan','január'],
        2 => ['feb','február'],
        3 => ['márc','március'],
        4 => ['ápr','április'],
        5 => ['máj','május'],
        6 => ['jún','június'],
        7 => ['júl','július'],
        8 => ['aug','augusztus'],
        9 => ['szept','szeptember'],
        10 => ['okt','október'],
        11 => ['nov','november'],
        12 => ['dec','december'],
    ];

    // Ha string, konvertáld timestamp-re
    if (is_string($date)) {
        $date = strtotime($date);
    }
    
    $napok = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];

    // Alapértelmezett formátum: H:i
    $timeFormat = $format !== null ? $format : 'H:i';
    $showTime = ($timeFormat !== '');

    // Ha ma van
    if (date('Y-m-d', $date) === date('Y-m-d')) {
        return 'ma' . ($showTime ? ' ' . date($timeFormat, $date) : '');
    }
    // Ha tegnap volt
    if (date('Y-m-d', $date) === date('Y-m-d', strtotime('-1 day'))) {
        $weekday = $napok[(int)date('w', $date)];
        return 'tegnap, ' . $weekday . ($showTime ? ' ' . date($timeFormat, $date) : '');
    }
    // Ha holnap lesz
    if (date('Y-m-d', $date) === date('Y-m-d', strtotime('+1 day'))) {
        $weekday = $napok[(int)date('w', $date)];
        return 'holnap, ' . $weekday . ($showTime ? ' ' . date($timeFormat, $date) : '');
    }
    // Ha az aktuális héten van (előző vasárnaptól következő vasárnapig)
    $today = strtotime(date('Y-m-d'));
    $dow = date('w', $today); // 0=vasárnap
    $week_start = strtotime('-' . $dow . ' days', $today); // előző vasárnap 00:00
    $week_end = strtotime('+7 days', $week_start); // következő hét vasárnap 00:00

    if ($date >= $week_start && $date <= $week_end) {
        $weekday = $napok[(int)date('w', $date)];
        $month = $_honapok[(int)date('n', $date)][0].".";
        $day = date('j.', $date);
        return $weekday . ' (' . $month . ' ' . $day . ')' . ($showTime ? ' ' . date($timeFormat, $date) : '');
    }

    // Egyéb esetben csak a hónap, nap és idő
    $month = $_honapok[(int)date('n', $date)][0].".";
    $day = date('j.', $date);
    $weekday = $napok[(int)date('w', $date)];
    $time = date($timeFormat, $date);

    // Pl.: Júl. 16., szerda 14:30
    return $month . ' ' . $day . ', ' . $weekday . ($showTime ? ' ' . $time : '');
}

function twig_translate($text) {

    $text = Translator::translate($text);

    return $text;
}

/**
 * #373: Magyar telefonszámokat keres egy szöveg- vagy HTML-blokkban (pl. a plébánia
 * leírásában) és köré tördel egy `<a href="tel:...">` linket, hogy mobilról egy
 * gombnyomással hívható legyen. A linket egy „phone-link” class-szal és telefon
 * ikonnal jelöljük.
 *
 * Már meglévő `<a>...</a>` elemekbe nem ír bele (pl. egy korábban kézzel beillesztett
 * `tel:` vagy `mailto:` link érintetlen marad).
 */
function twig_phone_links($html) {
    if ($html === null || $html === '') {
        return $html;
    }
    // A korábbi szűrők (pl. |nl2br) Twig\Markup objektumot adhatnak vissza, ami
    // nem string, de __toString-gel string-é alakítható.
    if (!is_string($html) && !(is_object($html) && method_exists($html, '__toString'))) {
        return $html;
    }
    $html = (string)$html;

    // A meglévő <a> tageket változatlanul hagyjuk - csak a szövegben szereplő
    // telefonszámokat akarjuk autolinkelni. PREG_SPLIT_DELIM_CAPTURE-rel a páratlan
    // indexű elemek lesznek maguk a <a>...</a> blokkok.
    $parts = preg_split('#(<a\b[^>]*>.*?</a>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        // borazslo (#438 review): a return egy Twig\Markup objektum kell legyen,
        // különben a Twig auto-escape downgrade-eli az output-ot stringgé. Lásd
        // a hosszabb magyarázatot a függvény végén.
        return new \Twig\Markup($html, 'UTF-8');
    }

    // Két támogatott magyar formátum:
    //   1. Országhívóval: +36 / 0036 / 06 + körzet/szolgáltató + 6-9 jegy
    //   2. borazslo #438 review: zárójeles vidéki körzet, országhívó nélkül -
    //      pl. "(62) 442-384" - sok régi adatban így van rögzítve. Itt fix
    //      kötelező a zárójel, hogy random "1 234 567" típusú sorozatokra
    //      ne találjunk be.
    // A környezet ne legyen szám/szó-jelleg, nehogy hosszabb sorozatot vágjunk ketté.
    $phoneRegex = '/(?<![\d\w])('
        . '(?:\+36|0036|06)[\s\-\.\/]*\(?\d{1,2}\)?[\s\-\.\/]*\d{2,4}[\s\-\.\/]*\d{2,4}(?:[\s\-\.\/]*\d{1,4})?'
        . '|'
        . '\(\d{1,2}\)[\s\-\.\/]*\d{2,4}[\s\-\.\/]*\d{2,4}'
        . ')(?![\d\w])/u';

    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) {
            // <a>...</a> blokk - érintetlenül hagyjuk.
            continue;
        }
        $parts[$i] = preg_replace_callback($phoneRegex, function ($m) {
            $display = $m[1];
            $digits  = preg_replace('/\D/', '', $display);
            if ($digits === null || $digits === '') {
                return $display;
            }
            // E.164 normalizálás a tel: linkhez.
            if (strpos($digits, '0036') === 0) {
                $digits = substr($digits, 2);
            } elseif (strpos($digits, '06') === 0) {
                $digits = '36' . substr($digits, 2);
            } elseif (strpos($digits, '36') !== 0) {
                // Országhívó nélküli formátum (pl. "(62) 442-384") - magyarnak
                // tekintjük és elé tesszük a 36-ot.
                $digits = '36' . $digits;
            }
            // Minimum hossz: 36 + 8 vidéki / 36 + 9 mobil = 10-11 jegy.
            // Ennél kevesebbet ne linkeljünk, hogy pl. irányítószámra ne legyen
            // téves találat (bár a regex maga is nehezen passzol arra).
            if (strlen($digits) < 10) {
                return $display;
            }
            $tel = '+' . $digits;
            return '<a href="tel:' . htmlspecialchars($tel, ENT_QUOTES, 'UTF-8') . '" class="phone-link" title="Hívás"><i class="fa fa-phone"></i> ' . $display . '</a>';
        }, $part);
    }

    // borazslo #438 review: a |raw|nl2br|... lánc a Twig\Markup-ot megőrzi
    // mindaddig, amíg minden közbenső szűrő ugyanezt visszaadja. Ha string-et
    // adunk vissza, a Twig auto-escape-eli az output-ot, és <strong> stb.
    // tagok &lt;strong&gt;-ként jelennek meg. Markup-ba csomagolással ez a
    // downgrade nem történik meg, és nem kell trailing |raw a template-ben.
    return new \Twig\Markup(implode('', $parts), 'UTF-8');
}

/**
 * Strip http/https protocol from URLs
 * Usage: {{ url|strip_protocol }}
 * Example: 'https://example.com' becomes 'example.com'
 */
function twig_strip_protocol($url) {
    if (empty($url)) {
        return $url;
    }
    return preg_replace('#^https?://#i', '', $url);
}

/**
 * Extract Facebook user/page path from URL
 * Usage: {{ facebook_url|facebook_path }}
 * Example: 'https://www.facebook.com/mypage' becomes 'mypage'
 * Example: 'https://www.facebook.com/pages/MyPage/123456789' becomes 'pages/MyPage/123456789'
 */
function twig_facebook_path($url) {
    if (empty($url)) {
        return $url;
    }
    // Remove protocol
    $url = preg_replace('#^https?://#i', '', $url);
    // Remove www. prefix
    $url = preg_replace('#^www\.#i', '', $url);
    // Remove facebook.com domain
    $url = preg_replace('#^facebook\.com/#i', '', $url);
    // Remove trailing slash
    $url = rtrim($url, '/');
    return $url;
}

/**
 * #307/#536: RRULE → emberi-olvasható magyar szöveg a Twig-ből.
 * A javaslat-email „egyszerűsített változások" listájában a mise
 * ismétlődését (rrule) így nem nyers JSON-ként, hanem pl. „hétfőn,
 * szerdán, pénteken, minden héten" formában mutatjuk.
 *
 * Használat a sablonban:  {{ rrule|readable_rrule }}
 */
function twig_readable_rrule($rrule) {
    return SimpleRRule::humanText($rrule);
}

/**
 * A naptár-bundle cache-buster verziója.
 *
 * A `main.js` / `styles.css` neve a #750 óta STABIL (angular.json:
 * outputHashing = "media"), mert a tartalomhash-elt fájlnév minden branchen
 * más lett, és emiatt MINDEN nyitott PR ütközött a `webapp/js/mcal/main-*.js`
 * fájlon (rename/rename). Stabil név mellett viszont kell egy külön
 * cache-buster, különben a böngésző a régi csomagot szolgálná ki a deploy
 * után. A bundle mtime-ja pontosan ezt adja: minden új build új értéket ad,
 * és semmilyen verziókövetett fájlba nem kell beleírni.
 */
function mcalVersion(): string {
    static $version = null;
    if ($version !== null) {
        return $version;
    }
    $main = PATH . 'js/mcal/main.js';
    $mtime = @filemtime($main);
    // Ha nincs bundle (pl. friss checkout build nélkül), a konstans érték is jó:
    // ilyenkor úgysincs mit cache-elni.
    $version = $mtime ? (string) $mtime : '0';
    return $version;
}
