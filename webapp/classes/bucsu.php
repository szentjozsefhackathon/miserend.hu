<?php

/**
 * #568: a templomok szabad szöveges `bucsu` mezőjének gépi olvasása.
 *
 * A jegy értesítést kér a búcsú (és a hasonlóan egyszeri szentségimádási nap)
 * környékére. borazslo ehhez a konkrét előfeltételt nevezte meg: „a mostani
 * általában szöveges »Búcsú ekkor és ekkor« adatot digitálisabbá kell tenni".
 * Ez az osztály ezt csinálja meg — magát az értesítést NEM, mert annak a formája
 * (kinek, honnan állítható, globális vagy templomonkénti) még nyitott kérdés.
 *
 * Az adat kézzel írt, húsz év alatt gyűlt szöveg. A valóság ilyen:
 *
 *   "Búcsú: augusztus 15. Szentségimádási nap: március 9."
 *   "Búcsú: Szentháromság vasárnap"
 *   "A templom ünnepe:október 9."
 *   "Márc. 19. Szt. József: kápolnabúcsú"
 *   "SZŰZ MÁRIA MENNYBEVÉTELE (NAGYBOLDOGASSZONY) - augusztus 15."
 *   "Búcsú: Szentségimádási nap: május 14."      <- búcsú nincs, csak címke
 *
 * Ezért a parser címkére NEM támaszkodik kizárólagosan, és a mozgó ünnepeket is
 * ismeri. A fel nem ismert szöveget nem dobjuk el: `unparsed`-ként visszaadjuk,
 * hogy az adat javítható legyen.
 */
class Bucsu {

    /** A búcsút bevezető címkék. Az elsőt tekintjük "hivatalosnak". */
    private const BUCSU_CIMKEK = ['búcsú', 'templom ünnepe', 'templom búcsúja', 'főünnepe', 'kápolnabúcsú'];

    /** A szentségimádási napot bevezető címkék. */
    private const SZENTSEGIMADAS_CIMKEK = ['szentségimádási nap', 'szentségimádás'];

    private const HONAPOK = [
        'január' => 1, 'február' => 2, 'március' => 3, 'április' => 4,
        'május' => 5, 'június' => 6, 'július' => 7, 'augusztus' => 8,
        'szeptember' => 9, 'október' => 10, 'november' => 11, 'december' => 12,
    ];

    /** Rövidített hónapnevek, ahogy az adatban előfordulnak (pl. "Márc. 19."). */
    private const HONAP_ROVIDITESEK = [
        'jan' => 1, 'febr' => 2, 'feb' => 2, 'márc' => 3, 'marc' => 3, 'ápr' => 4, 'apr' => 4,
        'máj' => 5, 'maj' => 5, 'jún' => 6, 'jun' => 6, 'júl' => 7, 'jul' => 7,
        'aug' => 8, 'szept' => 9, 'szep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Húsvéthoz képesti eltolású ünnepek, napban.
     *
     * A kulcs ékezet- és kisbetű-normalizált részlet, amit a szövegben keresünk.
     * A sorrend SZÁMÍT: az első illeszkedés nyer, ezért a bővebb minta előrébb áll
     * ("jezus szive unnepet koveto vasarnap" a "jezus szive" előtt).
     */
    private const HUSVETI_UNNEPEK = [
        'jezus szive unnepet koveto vasarnap' => 70,
        'jezus szive vasarnapja'              => 70,
        'jezus szive'                         => 68,
        'punkosdvasarnapot koveto vasarnap'   => 56,   // = Szentháromság vasárnapja
        'szentharomsag vasarnap'              => 56,
        'szenthatomsag vasarnap'              => 56,   // elgépelés az adatban, 1 templom
        'urnapja'                             => 63,
        'punkosdvasarnap'                     => 49,
        'punkosd vasarnap'                    => 49,
        'aldozocsutortok'                     => 39,
        'urunk mennybemenetele'               => 39,
        'husvetvasarnap'                      => 0,
    ];

    /** Az advent 1. vasárnapjához képesti eltolású ünnepek, napban. */
    private const ADVENTI_UNNEPEK = [
        'krisztus kiraly vasarnapja' => -7,
        'krisztus kiraly'            => -7,
    ];

    private const SORSZAMOK = [
        'elso' => 1, 'masodik' => 2, 'harmadik' => 3, 'negyedik' => 4, 'otodik' => 5,
        '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5,
    ];

    /**
     * Szétszedi a szabad szöveget búcsúra és szentségimádási napra.
     *
     * @param string|null $szoveg a `templomok.bucsu` nyers tartalma
     * @return array{bucsu: ?array, szentsegimadas: ?array, unparsed: string}
     *         Az alkalom alakja: ['type' => 'fixed'|'moveable', ...], lásd alkalom().
     */
    public static function parse(?string $szoveg): array {
        $ures = ['bucsu' => null, 'szentsegimadas' => null, 'unparsed' => ''];
        $szoveg = trim(preg_replace('/\s+/u', ' ', (string) $szoveg));
        if ($szoveg === '') {
            return $ures;
        }

        [$bucsuResz, $szentsegimadasResz] = self::vagas($szoveg);

        $bucsu = self::alkalom($bucsuResz);
        $szentsegimadas = self::alkalom($szentsegimadasResz);

        /*
         * Ami egyik ágon sem állt össze, azt megőrizzük — javítható adat, nem szemét.
         *
         * De a puszta címke NEM parse-hiba: a "Búcsú: Szentségimádási nap: május 14."
         * alakban a búcsú egyszerűen nincs kitöltve. Ha a címkéket és a központozást
         * levéve nem marad érdemi szöveg, akkor hiányzó adatról van szó, nem arról,
         * hogy nem értettük meg.
         */
        $maradek = [];
        foreach ([[$bucsu, $bucsuResz], [$szentsegimadas, $szentsegimadasResz]] as [$alkalom, $resz]) {
            if ($alkalom !== null) {
                continue;
            }
            $erdemi = self::cimkekNelkul($resz);
            if ($erdemi !== '') {
                $maradek[] = $erdemi;
            }
        }

        return [
            'bucsu' => $bucsu,
            'szentsegimadas' => $szentsegimadas,
            'unparsed' => implode(' | ', $maradek),
        ];
    }

    /**
     * Leszedi a bevezető címkéket és a központozást, hogy eldönthessük: van-e
     * egyáltalán érdemi tartalom, vagy csak egy üresen hagyott címke áll ott.
     */
    private static function cimkekNelkul(string $resz): string {
        $tiszta = $resz;
        foreach (array_merge(self::BUCSU_CIMKEK, self::SZENTSEGIMADAS_CIMKEK) as $cimke) {
            $tiszta = preg_replace('/' . preg_quote($cimke, '/') . '/iu', ' ', $tiszta);
        }
        // A maradék központozás és a magányos betűk nem hordoznak információt.
        $tiszta = trim(preg_replace('/[\s:.,;\-]+/u', ' ', $tiszta));

        return mb_strlen($tiszta) > 1 ? $tiszta : '';
    }

    /**
     * A szöveg kettévágása a szentségimádás-címkénél.
     *
     * @return array{0: string, 1: string} [búcsú-rész, szentségimádás-rész]
     */
    private static function vagas(string $szoveg): array {
        foreach (self::SZENTSEGIMADAS_CIMKEK as $cimke) {
            $pos = mb_stripos($szoveg, $cimke);
            if ($pos !== false) {
                return [
                    mb_substr($szoveg, 0, $pos),
                    mb_substr($szoveg, $pos + mb_strlen($cimke)),
                ];
            }
        }
        return [$szoveg, ''];
    }

    /**
     * Egyetlen szövegrészből alkalmat csinál.
     *
     * @return array|null ['type' => 'fixed', 'month' => int, 'day' => int]
     *                    vagy ['type' => 'moveable', 'feast' => string, ...]
     */
    private static function alkalom(string $resz): ?array {
        $resz = trim($resz, " \t\n\r\0\x0B:.-");
        if ($resz === '') {
            return null;
        }

        // A mozgó ünnep ELŐBB, mert a szövegében is állhat szám ("Húsvét 3. vasárnapja").
        $mozgo = self::mozgoUnnep($resz);
        if ($mozgo !== null) {
            return $mozgo;
        }

        return self::fixDatum($resz);
    }

    /** @return array{type: string, month: int, day: int}|null */
    private static function fixDatum(string $resz): ?array {
        $minta = '/(' . implode('|', array_keys(self::HONAPOK)) . ')\s*\.?\s*(\d{1,2})\b/iu';
        if (preg_match($minta, $resz, $m)) {
            return self::napEllenorzes(self::HONAPOK[mb_strtolower($m[1])], (int) $m[2]);
        }

        $rovid = '/\b(' . implode('|', array_keys(self::HONAP_ROVIDITESEK)) . ')\.\s*(\d{1,2})\b/iu';
        if (preg_match($rovid, $resz, $m)) {
            return self::napEllenorzes(self::HONAP_ROVIDITESEK[mb_strtolower($m[1])], (int) $m[2]);
        }

        return null;
    }

    /** A hónaphoz képest lehetetlen napot inkább nem ismerjük fel, mint rosszul. */
    private static function napEllenorzes(int $honap, int $nap): ?array {
        $max = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30,
                7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];
        if ($nap < 1 || $nap > $max[$honap]) {
            return null;
        }
        return ['type' => 'fixed', 'month' => $honap, 'day' => $nap];
    }

    /** @return array{type: string, feast: string, basis: string, offset: int}|null */
    private static function mozgoUnnep(string $resz): ?array {
        $n = self::normalizal($resz);

        foreach (self::HUSVETI_UNNEPEK as $minta => $eltolas) {
            if (str_contains($n, $minta)) {
                return ['type' => 'moveable', 'feast' => $minta, 'basis' => 'easter', 'offset' => $eltolas];
            }
        }

        // "Húsvét 3. vasárnapja" — az N. vasárnap (N-1) héttel húsvét után.
        if (preg_match('/husvet (\d)\.? vasarnapja/', $n, $m)) {
            return ['type' => 'moveable', 'feast' => 'husvet ' . $m[1] . '. vasarnapja',
                    'basis' => 'easter', 'offset' => ((int) $m[1] - 1) * 7];
        }

        foreach (self::ADVENTI_UNNEPEK as $minta => $eltolas) {
            if (str_contains($n, $minta)) {
                return ['type' => 'moveable', 'feast' => $minta, 'basis' => 'advent', 'offset' => $eltolas];
            }
        }

        // "október első vasárnapja", "május első vasárnapja"
        $normaltHonapok = [];
        foreach (self::HONAPOK as $nev => $szam) {
            $normaltHonapok[self::normalizal($nev)] = $szam;
        }
        $sorszamok = implode('|', array_keys(self::SORSZAMOK));
        $honapok = implode('|', array_keys($normaltHonapok));
        if (preg_match('/(' . $honapok . ') (' . $sorszamok . ')\.? vasarnapja/', $n, $m)) {
            return [
                'type' => 'moveable',
                'feast' => $m[1] . ' ' . $m[2] . '. vasarnapja',
                'basis' => 'nth_sunday',
                'month' => $normaltHonapok[$m[1]],
                'offset' => self::SORSZAMOK[$m[2]],
            ];
        }

        return null;
    }

    /**
     * Feloldja az alkalmat konkrét dátumra egy adott évben.
     *
     * @param array|null $alkalom a parse() egyik alkalma
     * @param int $ev
     * @return string|null Y-m-d, vagy null ha nem feloldható
     */
    public static function resolve(?array $alkalom, int $ev): ?string {
        if ($alkalom === null) {
            return null;
        }

        if ($alkalom['type'] === 'fixed') {
            return sprintf('%04d-%02d-%02d', $ev, $alkalom['month'], $alkalom['day']);
        }

        if ($alkalom['basis'] === 'easter') {
            return date('Y-m-d', strtotime('+' . $alkalom['offset'] . ' days', strtotime(self::husvet($ev))));
        }

        if ($alkalom['basis'] === 'advent') {
            return date('Y-m-d', strtotime($alkalom['offset'] . ' days', strtotime(self::adventElsoVasarnap($ev))));
        }

        if ($alkalom['basis'] === 'nth_sunday') {
            return self::nedikVasarnap($ev, $alkalom['month'], $alkalom['offset']);
        }

        return null;
    }

    /**
     * Húsvétvasárnap dátuma (gregorián, Meeus/Jones/Butcher).
     *
     * Szándékosan nem a PHP `easter_date()`-je: az az ext-calendar bővítményt
     * igényli, ami nincs garantálva a képünkben.
     *
     * @return string Y-m-d
     */
    public static function husvet(int $ev): string {
        $a = $ev % 19;
        $b = intdiv($ev, 100);
        $c = $ev % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $honap = intdiv($h + $l - 7 * $m + 114, 31);
        $nap = (($h + $l - 7 * $m + 114) % 31) + 1;

        return sprintf('%04d-%02d-%02d', $ev, $honap, $nap);
    }

    /**
     * Advent 1. vasárnapja: a karácsony előtti negyedik vasárnap.
     *
     * @return string Y-m-d
     */
    public static function adventElsoVasarnap(int $ev): string {
        $karacsony = strtotime(sprintf('%04d-12-25', $ev));
        // A karácsony előtti (vagy arra eső) utolsó vasárnap = advent 4. vasárnapja.
        $negyedik = strtotime('last sunday', $karacsony);
        return date('Y-m-d', strtotime('-21 days', $negyedik));
    }

    /**
     * @param int $honap 1-12
     * @param int $nedik hányadik vasárnap
     * @return string|null Y-m-d, null ha a hónapban nincs annyi vasárnap
     */
    private static function nedikVasarnap(int $ev, int $honap, int $nedik): ?string {
        $elso = strtotime(sprintf('%04d-%02d-01', $ev, $honap));
        $vasarnap = date('w', $elso) == 0 ? $elso : strtotime('next sunday', $elso);
        $datum = strtotime('+' . (($nedik - 1) * 7) . ' days', $vasarnap);

        if ((int) date('n', $datum) !== $honap) {
            return null;
        }
        return date('Y-m-d', $datum);
    }

    /** Ékezet- és kisbetű-normalizálás, hogy a mintáink illeszkedjenek. */
    private static function normalizal(string $szoveg): string {
        $csere = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        ];
        return strtr(mb_strtolower($szoveg), $csere);
    }
}
