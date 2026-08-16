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
        $boundaryQuery = \Eloquent\Boundary::where(function ($q) use ($text) {
                $q->where('name', 'like', '%' . $text . '%')
                  ->orWhere('alt_name', 'like', '%' . $text . '%');
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
