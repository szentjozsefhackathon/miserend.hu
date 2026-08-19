<?php

namespace Html\Ajax;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * AutocompleteCombined – egyetlen végpont a határterület + templom keresőmező számára.
 *
 * GET /ajax/AutocompleteCombined?text=Budapest&excluded_ids=12,34&excluded_church_ids=99
 *
 * Visszaadott JSON:
 * {
 *   "results": [
 *     { "kind": "boundary", "id": 12, "name": "Budapest", "type": "Megye",
 *       "color": "#3a7dc9", "score": 90, "osm": {"type":"relation","id":45678} },
 *     { "kind": "church", "id": 99, "name": "Belvárosi bazilika", "city": "Budapest", "score": 80 }
 *   ]
 * }
 *
 * score: 0-100, DESC sorrendben adja vissza az eredményeket.
 */
class AutocompleteCombined extends Ajax {

    /** Az arab -> római átváltás a budapesti kerületekhez (1-23). */
    private const ROMAI = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII',
        8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII', 13 => 'XIII',
        14 => 'XIV', 15 => 'XV', 16 => 'XVI', 17 => 'XVII', 18 => 'XVIII', 19 => 'XIX',
        20 => 'XX', 21 => 'XXI', 22 => 'XXII', 23 => 'XXIII',
    ];

    /**
     * #497: további keresési minták a budapesti kerület-változatokból.
     *
     * Az OSM-ben a kerület neve „II. kerület" — a látogatók viszont sokféleképpen
     * írják: „Budapest, II. kerület", „2. kerület", „II ker", „Budapest 2 ker".
     * borazslo kérése az volt, hogy ezt a KERESŐ oldja fel, ne az adatbázis.
     *
     * Csak akkor csinálunk bármit, ha a szövegben tényleg kerület-hivatkozás van;
     * egyébként üres tömböt adunk, és a hívó a sima részlet-keresést használja.
     *
     * @param string $szoveg a beírt szöveg
     * @return array<int,string> LIKE-minták (üres, ha nem kerület-jellegű a szöveg)
     */
    public static function keruletVariansok(string $szoveg): array {
        $tiszta = trim(mb_strtolower($szoveg));

        // A "budapest" előtag és az utána álló vessző elhagyható.
        $tiszta = preg_replace('/^budapest\s*,?\s*/u', '', $tiszta);

        // "ker", "ker." -> "kerület"; a szó végi rövidítést is elfogadjuk.
        $tiszta = preg_replace('/\bker\.?$/u', 'kerület', $tiszta);
        $tiszta = preg_replace('/\bker\.?\s/u', 'kerület ', $tiszta);

        /*
         * A "kerület" szó CSAK a római számnál hagyható el. A puszta arab szám
         * ("11") lehet irányítószám-részlet, házszám vagy bármi — abból nem
         * következtetünk kerületre.
         */
        $romaiMinta = '/^([ivx]+)\s*\.?\s*(kerület)?$/u';
        $arabMinta = '/^([0-9]{1,2})\s*\.?\s*kerület$/u';
        if (!preg_match($romaiMinta, trim($tiszta), $m) && !preg_match($arabMinta, trim($tiszta), $m)) {
            return [];
        }

        $szam = $m[1];
        if (ctype_digit($szam)) {
            $index = (int) $szam;
            if (!isset(self::ROMAI[$index])) {
                return [];
            }
            $romai = self::ROMAI[$index];
        } else {
            $romai = mb_strtoupper($szam);
            if (!in_array($romai, self::ROMAI, true)) {
                return [];
            }
        }

        // A tárolt alak "II. kerület"; a % azért kell, mert a régi, oszlopokból
        // gyártott boundary-k neve "Budapest II. kerület".
        /*
         * SZÓHATÁRRA illesztünk, nem sima részletre. A `%II. kerület%` a XII.-t és a
         * XXII.-t is megfogná, mert azok tartalmazzák a "II. kerület" részletet —
         * így egyetlen kerület keresésére húsz találat jött.
         *
         * Három alak kell: pontos egyezés, szó eleji, és a szövegben szóköz után
         * álló (a régi, oszlopokból gyártott boundary-k neve "Budapest II. kerület").
         */
        $nev = $romai . '. kerület';

        return [$nev, $nev . '%', '% ' . $nev . '%'];
    }

    public $format = "json";
    public $content;

    public function __construct() {
        $text = \Request::Text('text');

        // Ha a szöveg rövidebb mint 3 karakter, üres listát adunk vissza
        if (mb_strlen(trim($text)) < 3) {
            $this->content = json_encode(['results' => []]);
            return;
        }

        // Már kiválasztott határterületek kizárása
        $excludedBoundaryIds = \Request::Text('excluded_ids');
        $excludedBoundaryIds = !empty($excludedBoundaryIds)
            ? array_filter(array_map('intval', explode(',', $excludedBoundaryIds)))
            : [];

        // Már kiválasztott templomok kizárása
        $excludedChurchIds = \Request::Text('excluded_church_ids');
        $excludedChurchIds = !empty($excludedChurchIds)
            ? array_filter(array_map('intval', explode(',', $excludedChurchIds)))
            : [];

        // Aktív határterületek – ha vannak, a templomkeresést ezekre szűkítjük
        $boundaryIds = \Request::Text('boundary_ids');
        $boundaryIds = !empty($boundaryIds)
            ? array_filter(array_map('intval', explode(',', $boundaryIds)))
            : [];

        $results = [];

        // ── 1. Határterületek ────────────────────────────────────────────────
        $boundaryLimit = 15;
        /*
         * #496: az ALTERNATÍV névre is keresünk.
         *
         * borazslo iránya szerint a helyre szűkítés boundary-n keresztül megy, nem a
         * templom-dokumentum szöveges mezőin ("Sosem keresünk már megyében, hanem
         * boundary alapján valamilyen osm entity-ben"). Csakhogy a boundary eddig csak
         * a `name` oszlopán volt megtalálható, a köznyelvi név viszont gyakran az
         * `alt_name`-ben ül: a budapesti V. kerület alt_name-je "Belváros-Lipótváros",
         * a XI.-é "Újbuda". Ezekre ma nulla találat jön, pedig a látogatók így hívják
         * őket.
         */
        /*
         * #497: a budapesti kerületek névváltozatai.
         *
         * borazslo: „a keresőbe a rendes nevét kell írni, hogy »II. kerület« nem pedig,
         * hogy »Budapest, II. kerület« vagy »2. kerület«, stb. De szerintem ezt nem
         * adatbázis féle szinten kéne kezelni, hanem a kereső autocomplete részében
         * lehetne varázsolni, nem?"
         *
         * Pontosan ezt csinálja a `keruletVariansok()`: a beírt szövegből további
         * keresési mintákat gyárt, az adatbázisban tárolt neveket nem bántjuk.
         */
        $mintak = array_merge(['%' . $text . '%'], self::keruletVariansok($text));

        $boundaryQuery = \Eloquent\Boundary::where(function ($q) use ($mintak) {
                foreach ($mintak as $minta) {
                    $q->orWhere('name', 'like', $minta)
                      ->orWhere('alt_name', 'like', $minta);
                }
            })
            ->where(function ($q) {
                $q->whereNull('denomination')
                    ->orWhere('denomination', 'like', '%catholic%');
            });

        if (!empty($excludedBoundaryIds)) {
            $boundaryQuery->whereNotIn('id', $excludedBoundaryIds);
        }

        $allowedBoundaries = [
            'religious_administration', 'administrative', 'postal_code',
            'region', 'historic', 'tourism_region', 'wine_growing_area'
        ];
        $boundaryQuery->whereIn('boundary', $allowedBoundaries);
        
        // Only include boundaries with OSM data
        $boundaryQuery->whereNotNull('osmtype')
            ->whereNotNull('osmid');

        $boundaries = $boundaryQuery
            // #571: a PONTOS (majd prefix-) találat kerüljön előre. Enélkül egy rövid
            // településnév (pl. "Áta") kiszorult a take(15) mögé, mert a %áta% substring
            // sok más boundary-t is talál (117 db), és a religious_administration + kisebb
            // admin_level sorok elé kerültek. Így az egyező település mindig a lista elején van.
            // #496: az alternatív névre is illik a rangsor, különben egy PONTOS
            // alt_name-találat ("Belváros-Lipótváros") a 2-es csoportba esne, és
            // kiszorulna a take(15) mögül a sok substring-találat miatt.
            ->orderByRaw(
                "CASE WHEN name = ? OR alt_name = ? THEN 0"
                . " WHEN name LIKE ? OR alt_name LIKE ? THEN 1 ELSE 2 END",
                [$text, $text, $text . '%', $text . '%']
            )
            ->orderByRaw("CASE WHEN boundary = 'religious_administration' THEN 0 WHEN boundary = 'administrative' THEN 1 ELSE 2 END")
            ->orderBy('admin_level', 'asc')
            ->orderBy('name', 'asc')
            ->take($boundaryLimit)
            ->get()
            ->map->toSimpleArray();

        // Score: 100-tól csökken soronként 3-at, maximum 100
        foreach ($boundaries as $rank => $boundary) {
            $boundary['kind']  = 'boundary';
            $boundary['score'] = max(0, 100 - $rank * 3);
            $results[] = $boundary;
        }

        // ── 2. Templomok ─────────────────────────────────────────────────────
        $churchLimit = 9;
        $churchSearch = new \Search('churches');
        $churchSearch->keyword($text);

        if (!empty($excludedChurchIds)) {
            $churchSearch->addMustNot(['terms' => ['id' => array_values($excludedChurchIds)]]);
        }

        // Ha van kiválasztott határterület, a templomkeresést arra szűkítjük
        // (a határterület-találatokra ez nem vonatkozik)
        if (!empty($boundaryIds)) {
            $churchSearch->boundaries(array_values($boundaryIds));
        }

        $churchResults = $churchSearch->getResults(0, $churchLimit, false);
        $churchTotal   = $churchSearch->total;

        // ES score normalizálása 0-80 tartományra (hogy a pontosan illeszkedő
        // határterületek megelőzhessék az általános templomegyezéseket)
        $maxEsScore = 0;
        foreach ($churchResults as $cr) {
            if (isset($cr->score) && $cr->score > $maxEsScore) {
                $maxEsScore = $cr->score;
            }
        }

        foreach ($churchResults as $cr) {
            $esScore    = $cr->score ?? 0;
            $normalized = $maxEsScore > 0 ? round(($esScore / $maxEsScore) * 80) : 40;
            $city = is_array($cr->varos) ? ($cr->varos[0] ?? '') : ($cr->varos ?? '');

            $results[] = [
                'kind'  => 'church',
                'id'    => $cr->id,
                'name'  => $cr->nev,
                'city'  => $city,
                'score' => $normalized,
            ];
        }

        // ── 3. Összefésülés score szerint ────────────────────────────────────
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        // Maximum 12 elem
        $results = array_slice($results, 0, 12);

        $this->content = json_encode(['results' => array_values($results)]);
        return;
    }
}
