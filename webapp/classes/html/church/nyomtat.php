<?php

namespace Html\Church;

use Carbon\Carbon;

/**
 * #36: nyomtatható miserend egy templomhoz.
 *
 * A jegyben a tartalom kérdése nyitva maradt („nem egészen értem, hogyan lehetne
 * praktikus a végtelen miseidőszakok nyomtatása"), ezért a döntéseket itt írom le —
 * mindegyik abból indul ki, KI olvassa a papírt: a templom ajtajára kitett lapot idős
 * hívek nézik, a plébánián pedig titkárok sokszorosítják.
 *
 * 1. NEM nyomtatjuk ki az összes időszakot. Az „egész évben / nyári / téli / adventi"
 *    rétegek egymást írják felül; papíron egymás mellett állva félrevezetők, mert a
 *    lapról nem derül ki, melyik érvényes MA. Ezért csak az érvényben lévő rend kerül
 *    ki, heti bontásban.
 *
 * 2. A lap MEGMONDJA, meddig érvényes. Ez a válasz a jegy nyitott kérdésére: a
 *    „végtelen időszak" nem attól kezelhető, hogy mindent kiírunk, hanem attól, hogy a
 *    lap maga jelzi, mikor jár le. Előre végignézzük a következő évet, és megkeressük
 *    az első napot, amikor a heti rend megváltozik — az a lejárat.
 *
 * 3. Nem időszaknév, hanem NAP szerint csoportosítunk. A „Nyári időszámítás" belső
 *    szakszó; a hívő azt kérdezi, vasárnap hánykor van mise.
 *
 * 4. Az alkalmi (nem heti) alkalmak — ünnepek, búcsú — külön szakaszban, dátummal,
 *    a következő három hónapra. Ennyi ideig marad reálisan a falon egy lap.
 *
 * 5. A miséket ugyanaz a motor állítja elő, mint a naptárat és a keresőt
 *    (`CalMass::generateMassPeriodInstancesForYears`), tehát a papír nem mondhat mást,
 *    mint a weboldal. Az időszakok közti elsőbbséget sem itt számoljuk újra: a
 *    kizárt napok (`exdate`) már benne vannak a szabályban.
 */
class Nyomtat extends \Html\Html {

    /** Meddig soroljuk előre az alkalmi miséket. */
    private const ALKALMI_NAPOK = 90;

    /** Meddig keressük előre a rend megváltozását. */
    private const ELORETEKINTES_NAPOK = 400;

    private const HONAPOK = [
        1 => 'január', 2 => 'február', 3 => 'március', 4 => 'április',
        5 => 'május', 6 => 'június', 7 => 'július', 8 => 'augusztus',
        9 => 'szeptember', 10 => 'október', 11 => 'november', 12 => 'december',
    ];

    /** Vasárnappal kezdünk: a miserendet is így olvassák. */
    private const NAPOK = [
        0 => 'Vasárnap',
        1 => 'Hétfő',
        2 => 'Kedd',
        3 => 'Szerda',
        4 => 'Csütörtök',
        5 => 'Péntek',
        6 => 'Szombat',
    ];

    /*
     * A property-ket kiírjuk. PHP 8.2 óta a dinamikus property elavult, és bár a régebbi
     * oldalosztályok még így csinálják, új kód ne termeljen újabb figyelmeztetést.
     */
    public $church;
    public $nyomtatasNapja;
    public $hetiRend = [];
    public $alkalmak = [];
    public $ervenyesigSzoveg;
    public $nyomtatasSzoveg;
    public $ellenorzesSzoveg;
    public $plebaniaSzoveg = '';
    public $megjegyzesSzoveg = '';
    public $bucsuSzoveg = '';

    public function __construct($path) {
        parent::__construct();

        $id = $this->churchIdFromPath($path);
        if (!$id) {
            throw new \Exception('Nem található templom azonosító a nyomtatási nézethez.');
        }

        $church = \Eloquent\Church::find($id);
        if (!$church) {
            throw new \Exception('Nincs ilyen templom.');
        }

        /*
         * Ugyanaz a jogosultság-ellenőrzés, mint a templomoldalon (Html\Church\Church):
         * a nem publikus templom adata ezen az úton sem szivároghat ki. Külön útvonal,
         * külön belépési pont — a védelmet is külön kell kimondani.
         */
        $church = $church->append(['readAccess']);
        if (!$church->readAccess) {
            throw new \Exception('Ehhez a templomhoz nincs hozzáférésed.');
        }

        $this->template = 'church/nyomtat.twig';
        $this->church = $church;
        $this->nyomtatasNapja = Carbon::now()->startOfDay();

        $naptar = $this->occurrencesByDate($church, $this->nyomtatasNapja);

        $this->hetiRend   = $this->weeklySchedule($naptar, $this->nyomtatasNapja);
        $this->alkalmak   = $this->upcomingOneOffs($naptar, $this->nyomtatasNapja);

        /*
         * A dátumokat itt formázzuk, nem a sablonban. A `miserend_date` szűrő
         * RELATÍV alakot ad („ma", „holnap", „szerda"), ami képernyőn hasznos, papíron
         * viszont értelmetlen: a lap hetekig ott lóg, és a „holnap" nem tudni, mikorra
         * vonatkozik. Nyomtatáshoz csak abszolút dátum jó.
         */
        $ervenyesig = $this->validUntil($naptar, $this->nyomtatasNapja, $this->hetiRend);
        $this->ervenyesigSzoveg = $ervenyesig
            ? self::magyarDatum(Carbon::parse($ervenyesig), false) . '-ig'
            : null;
        $this->nyomtatasSzoveg = self::magyarDatum($this->nyomtatasNapja);
        $this->ellenorzesSzoveg = $church->frissites
            ? self::magyarDatum(Carbon::parse($church->frissites))
            : null;

        // A szabad szöveges mezők HTML-t tartalmaznak (<strong>, <br />, entitások).
        // Papírra sima szöveg való, és így nem is kell nyers HTML-t kiengedni a sablonba.
        $this->plebaniaSzoveg = self::plainText($church->plebania ?? '');
        $this->megjegyzesSzoveg = self::plainText($church->misemegj ?? '');
        $this->bucsuSzoveg = self::plainText($church->bucsu ?? '');
    }

    /** „2026. október 24." — ponttal a végén, kivéve ha rag jön utána. */
    private static function magyarDatum(Carbon $nap, bool $zaroPont = true): string {
        return $nap->year . '. ' . self::HONAPOK[(int) $nap->month] . ' ' . $nap->day . ($zaroPont ? '.' : '');
    }

    /** A HTML-t tartalmazó szabad szöveges mezőkből olvasható, sima szöveg. */
    private static function plainText(string $ertek): string {
        $ertek = preg_replace('#<br\s*/?>#i', "\n", $ertek);
        $ertek = html_entity_decode(strip_tags($ertek), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // A nyers mezőkben gyakori a többszörös szóköz és a sorvégi szemét.
        $ertek = preg_replace('/[ \t]+/', ' ', $ertek);
        return trim(preg_replace('/\n{2,}/', "\n", $ertek));
    }

    private function churchIdFromPath($path): ?int {
        if (is_array($path) && isset($path[0]) && is_numeric($path[0])) {
            return (int) $path[0];
        }
        $value = \Request::get('id');
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Nap → alkalmak. A szabályokat ugyanazzal a motorral bontjuk ki, amivel a naptár;
     * a `SimpleRRule` a kizárt napokat (`exdate`) is figyelembe veszi, tehát ami itt
     * megjelenik, az tényleg meg is történik.
     *
     * @return array<string, array<int, array{ido:string, cim:string, heti:bool}>>
     */
    private function occurrencesByDate(\Eloquent\Church $church, Carbon $mostantol): array {
        $masses = \Eloquent\CalMass::where('church_id', $church->id)->get()->all();
        if (!$masses) {
            return [];
        }

        $evek = [(int) $mostantol->year, (int) $mostantol->year + 1];
        $sorok = \Eloquent\CalMass::generateMassPeriodInstancesForYears($masses, [], $evek);

        $hatar = $mostantol->copy()->addDays(self::ELORETEKINTES_NAPOK);
        $naptar = [];

        foreach ($sorok as $sor) {
            if (empty($sor['rrule'])) {
                continue;
            }
            $heti = ($sor['rrule']['freq'] ?? '') === 'weekly';

            foreach ((new \SimpleRRule($sor['rrule']))->getOccurrences() as $alkalom) {
                $nap = Carbon::instance($alkalom);
                if ($nap->lt($mostantol) || $nap->gt($hatar)) {
                    continue;
                }
                $naptar[$nap->toDateString()][] = [
                    'ido'  => $nap->format('H:i'),
                    'cim'  => (string) ($sor['title'] ?? 'Szentmise'),
                    'heti' => $heti,
                ];
            }
        }

        foreach ($naptar as $nap => $alkalmak) {
            usort($alkalmak, static fn($a, $b) => strcmp($a['ido'], $b['ido']));
            $naptar[$nap] = $alkalmak;
        }

        return $naptar;
    }

    /**
     * A most érvényes heti rend: a következő hét napból olvassuk ki, mert az adja azt,
     * amit a hívő MA tapasztal.
     *
     * @return array<int, array{nap:string, alkalmak:array<int,array{ido:string,cim:string}>}>
     */
    private function weeklySchedule(array $naptar, Carbon $mostantol): array {
        $rend = [];
        for ($i = 0; $i < 7; $i++) {
            $nap = $mostantol->copy()->addDays($i);
            $alkalmak = array_values(array_filter(
                $naptar[$nap->toDateString()] ?? [],
                static fn($a) => $a['heti']
            ));
            if ($alkalmak) {
                $rend[(int) $nap->dayOfWeek] = $alkalmak;
            }
        }

        // Vasárnappal kezdve, a hét sorrendjében.
        $rendezett = [];
        foreach (self::NAPOK as $sorszam => $nev) {
            if (isset($rend[$sorszam])) {
                $rendezett[] = [
                    'nap'      => $nev,
                    'alkalmak' => array_map(
                        static fn($a) => ['ido' => $a['ido'], 'cim' => $a['cim']],
                        $rend[$sorszam]
                    ),
                ];
            }
        }
        return $rendezett;
    }

    /**
     * Meddig érvényes a fenti rend? Az első olyan napig, amelyen a heti rend eltér a
     * mostanitól.
     *
     * Ez a jegy nyitott kérdésére a válasz: a „végtelen időszak" nem attól kezelhető,
     * hogy mindent kiírunk, hanem attól, hogy a lap megmondja, mikor avul el. Az
     * alkalmi (ünnepi) miséket szándékosan kihagyjuk a vizsgálatból: egy búcsú nem
     * rendváltozás, csak egyszeri alkalom.
     *
     * @return string|null ISO-dátum, vagy null ha a belátható időn belül nem változik
     */
    private function validUntil(array $naptar, Carbon $mostantol, array $hetiRend): ?string {
        if (!$hetiRend) {
            return null;
        }

        $minta = [];
        for ($i = 0; $i < 7; $i++) {
            $nap = $mostantol->copy()->addDays($i);
            $minta[(int) $nap->dayOfWeek] = $this->weeklySignature($naptar, $nap);
        }

        for ($i = 7; $i <= self::ELORETEKINTES_NAPOK; $i++) {
            $nap = $mostantol->copy()->addDays($i);
            if ($this->weeklySignature($naptar, $nap) !== ($minta[(int) $nap->dayOfWeek] ?? '')) {
                return $nap->copy()->subDay()->toDateString();
            }
        }

        return null;
    }

    /** Egy nap heti alkalmainak ujjlenyomata — ebből látszik, ha megváltozik a rend. */
    private function weeklySignature(array $naptar, Carbon $nap): string {
        $alkalmak = array_filter($naptar[$nap->toDateString()] ?? [], static fn($a) => $a['heti']);
        $jegyek = array_map(static fn($a) => $a['ido'] . ' ' . $a['cim'], $alkalmak);
        sort($jegyek);
        return implode('|', $jegyek);
    }

    /**
     * Alkalmi (nem heti) misék a következő három hónapra, dátummal.
     *
     * @return array<int, array{datum:string, alkalmak:array<int,array{ido:string,cim:string}>}>
     */
    private function upcomingOneOffs(array $naptar, Carbon $mostantol): array {
        $hatar = $mostantol->copy()->addDays(self::ALKALMI_NAPOK);
        $lista = [];

        foreach ($naptar as $nap => $alkalmak) {
            $datum = Carbon::parse($nap);
            if ($datum->gt($hatar)) {
                continue;
            }
            $alkalmi = array_values(array_filter($alkalmak, static fn($a) => !$a['heti']));
            if (!$alkalmi) {
                continue;
            }
            $lista[] = [
                'datum'    => $nap,
                // A hétköznap nevét is kiírjuk: papíron a puszta dátumból nehéz
                // fejben kiszámolni, milyen nap lesz.
                'nap'      => self::NAPOK[(int) $datum->dayOfWeek],
                'datumSzoveg' => self::magyarDatum($datum, false) . ', ' . mb_strtolower(self::NAPOK[(int) $datum->dayOfWeek]),
                'alkalmak' => array_map(
                    static fn($a) => ['ido' => $a['ido'], 'cim' => $a['cim']],
                    $alkalmi
                ),
            ];
        }

        usort($lista, static fn($a, $b) => strcmp($a['datum'], $b['datum']));
        return $lista;
    }
}
