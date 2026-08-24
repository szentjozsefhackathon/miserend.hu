<?php

final class MassDefinitions
{
    private array $data = [];

    public function __construct(?string $path = null)
    {
        $path ??= PATH . 'mass-definitions.json';

        if (!is_readable($path)) {
            return;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (is_array($data)) {
            $this->data = $data;
        }
    }

    public function categories(): array
    {
        return $this->arrayValue('categories');
    }

    public function rites(): array
    {
        return $this->arrayValue('rites');
    }

    public function definitionKeysByCategory(string $category): array
    {
        $keys = [];
        foreach ($this->arrayValue('definitions') as $definition) {
            if (($definition['category'] ?? null) === $category && isset($definition['key'])) {
                $keys[] = $definition['key'];
            }
        }

        return array_values(array_unique($keys));
    }

    public function titlesByCategories(array $categories): array
    {
        $titles = [];
        $titlesByCategory = $this->arrayValue('titlesByCategory');

        foreach ($categories as $category) {
            if (isset($titlesByCategory[$category]) && is_array($titlesByCategory[$category])) {
                $titles = array_merge($titles, $titlesByCategory[$category]);
            }
        }

        return array_values(array_unique($titles));
    }

    /**
     * #299: a kategóriákhoz tartozó összes cím-alak, ahogy az a `cal_masses.title`-ben
     * (és így az Elasticsearch `title` mezőjében) előfordulhat.
     *
     * Kétféle alak van, mert az adat kétfelől jön: a naptárszerkesztő a definíciókulcsot
     * írja be (`MASS_TITLE.ADORATION`), a régebbi, kézzel felvitt sorokban viszont a
     * lefordított cím áll (`Szentségimádás`). Aki kategóriára szűr, mindkettőt akarja.
     *
     * @param  string[] $categories  MASS, ADORATION, CONFESSION, OTHER
     * @return string[]              `title.keyword` terms-szűrőnek való értékek
     */
    public function titleFiltersByCategories(array $categories): array
    {
        $filters = [];

        // Szándékosan a magyar szótár, nem a felhasználó nyelve: a `cal_masses.title`-ben
        // magyar címek állnak, akkor is, ha valaki angolul nézi az oldalt. (A t() a felület
        // nyelvét követné, és angol nézetben egyetlen sorra sem illeszkedne.) Az init()
        // idempotens, tehát a webes kérésben, ahol a load.php már inicializálta, nem csinál
        // semmit — CLI-ben és tesztben viszont ettől lesz kiszámítható a viselkedés.
        \Translator::init('hu');

        foreach ($this->titlesByCategories($categories) as $title) {
            $filters[] = $title;

            $translated = \Translator::translate($title);
            if (is_string($translated) && $translated !== '') {
                $filters[] = $translated;
            }
        }

        return array_values(array_unique($filters));
    }

    /**
     * #157: melyik kategóriába tartozik ez a MISE-CÍM?
     *
     * Két lépcső, és a sorrend számít:
     *
     *   1. PONTOS egyezés a kanonikus alakokra (a definíciókulcs vagy a magyar
     *      fordítása). Ez garantálja, hogy a mai viselkedés bitre ugyanaz maradjon a
     *      kanonikus címekre — nulla regresszió.
     *   2. Ha nincs pontos találat, a szabad szöveges felismerő — a definíciók
     *      `aliases` szótára (#896), ami az importált és a kézzel írt egyedi címeket
     *      is besorolja.
     *
     * ISMERETLEN CÍM -> NULL, NEM 'OTHER'. Az OTHER felületi neve „Egyéb imaalkalmak",
     * tagjai a zsolozsma, rózsafüzér, litánia, keresztút. Ha minden felismeretlen szabad
     * szöveg oda kerülne, a szűrő hazudna: a „Képviselőtestületi ülés" és a „Hittanóra"
     * imaalkalomként jelenne meg. A hiányzó mező őszinte, és mérhető is.
     */
    public function categoryForTitle(string $title): ?string
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }

        // Ugyanaz a megfontolás, mint a titleFiltersByCategories()-ban: a `cal_masses.title`
        // magyar címeket tartalmaz, akkor is, ha valaki angolul nézi az oldalt.
        \Translator::init('hu');

        foreach ($this->arrayValue('titlesByCategory') as $category => $titles) {
            foreach ((array) $titles as $kanonikus) {
                if ($title === $kanonikus) {
                    return $category;
                }
                $forditott = \Translator::translate($kanonikus);
                if (is_string($forditott) && $forditott !== '' && $title === $forditott) {
                    return $category;
                }
            }
        }

        return $this->categoryByAliases($title);
    }

    /**
     * #896: kategóriánkénti szabad szöveges alakok a generált JSON-ból.
     *
     * A mesterpéldány a `calendar/src/app/data/mass-definitions.ts` — ott, a definíciók
     * mellett. Ez a metódus csak a kigenerált indexet adja vissza; ha üres (régi JSON,
     * ami még az `aliasesByCategory` előtt készült), a felismerés egyszerűen nem talál
     * semmit, és a kanonikus egyezés marad — nem hasal el.
     *
     * @return array<string, string[]>
     */
    public function aliasesByCategory(): array
    {
        $szotar = [];
        foreach ($this->arrayValue('aliasesByCategory') as $category => $aliases) {
            if (is_array($aliases)) {
                $szotar[(string) $category] = array_values(array_filter(
                    array_map('strval', $aliases),
                    static fn(string $a): bool => $a !== ''
                ));
            }
        }

        return $szotar;
    }

    /**
     * #157/#896: a címben LEGKORÁBBAN előforduló alias kategóriája.
     *
     * A magyar naptárcímek a FŐESEMÉNYT írják előre, ezért a szöveg sorrendje dönt, nem a
     * kategóriáké:
     *
     *   „Szentmise, utána szentségimádás"      -> MASS
     *   „Szentségimádás a szentmise után"      -> ADORATION
     *   „Gyóntatás a szentmise előtt"          -> CONFESSION
     *
     * Kategória-sorrenddel mindhárom ugyanazt adná, tehát kettő rossz lenne. (Az Angular
     * oldal pontosan ezt a hibát követte el a #896 előtt.)
     *
     * Azonos pozíciónál a HOSSZABB alias nyer: az a szűkebb, tehát a beszédesebb találat.
     * Így az eredmény nem függ attól, milyen sorrendben soroljuk fel a kategóriákat.
     */
    private function categoryByAliases(string $title): ?string
    {
        $cim = mb_strtolower($title, 'UTF-8');

        $nyertes = null;
        $hol = null;
        $hossz = 0;

        foreach ($this->aliasesByCategory() as $category => $aliases) {
            foreach ($aliases as $alias) {
                $pozicio = self::aliasPozicio($cim, $alias);
                if ($pozicio === null) {
                    continue;
                }

                $aliasHossz = mb_strlen($alias);
                if ($hol === null || $pozicio < $hol || ($pozicio === $hol && $aliasHossz > $hossz)) {
                    $hol = $pozicio;
                    $hossz = $aliasHossz;
                    $nyertes = $category;
                }
            }
        }

        return $nyertes;
    }

    /**
     * #896: az alias első olyan előfordulása, ami SZÓ ELEJÉN áll.
     *
     * Szóhatár nélkül az „UnknownMassTitle"-ből a `mass` alias misét csinál. Szó VÉGÉT
     * viszont nem kötünk ki: az aliasok egy része szándékosan tő („szentségimád"), hogy
     * a ragozott alakok — „szentségimádás", „szentségimádást" — is illeszkedjenek.
     *
     * Ugyanez a szabály fut az Angular oldalon (`MassTitleCategoryConfig`), különben a
     * két felismerés megint elválna egymástól — pont attól, amiért a #896 megszületett.
     */
    private static function aliasPozicio(string $cim, string $alias): ?int
    {
        $tol = 0;
        while (($pozicio = mb_strpos($cim, $alias, $tol)) !== false) {
            $elozo = $pozicio === 0 ? '' : mb_substr($cim, $pozicio - 1, 1);
            if ($elozo === '' || !preg_match('/\p{L}/u', $elozo)) {
                return $pozicio;
            }
            $tol = $pozicio + 1;
        }

        return null;
    }

    /**
     * #157: a kategória-szűrő Elasticsearch-klauzulája.
     *
     * KÉT ág, `should`-dal összekötve, és mindkettőre szükség van:
     *
     *   - `category.keyword` — az új, indexelt kategória. Ez fogja meg az importált és
     *     a kézzel írt egyedi címeket, amiket a régi szűrő némán elhagyott.
     *   - `title.keyword` — a mai, cím-alapú lista. Ez tartja életben a szűrőt az
     *     újraindexelés ALATT (a régi dokumentumokban még nincs `category`), és utána
     *     is olcsó biztosíték.
     *
     * Így nincs olyan pillanat, amikor a szűrő rosszabb lenne a mainál: szigorú bővítés.
     *
     * NULL, ha egyetlen érvényes kategória sincs a kérésben — a hívó ilyenkor NE tegyen
     * be szűrőt. Az ismeretlen kulcs (pl. „SZEMET") tehát nem nulla találatot ad, hanem
     * szűrés nélküli keresést, ahogy eddig is.
     *
     * @param  string[] $categories
     * @return ?array<string, mixed>
     */
    public function categoryQueryClause(array $categories): ?array
    {
        $ervenyesKulcsok = array_column($this->categories(), 'key');
        $ervenyes = array_values(array_intersect(
            array_map('strval', $categories),
            $ervenyesKulcsok
        ));

        if ($ervenyes === []) {
            return null;
        }

        $should = [['terms' => ['category.keyword' => $ervenyes]]];

        $cimek = $this->titleFiltersByCategories($ervenyes);
        if ($cimek !== []) {
            $should[] = ['terms' => ['title.keyword' => $cimek]];
        }

        return ['bool' => ['should' => $should, 'minimum_should_match' => 1]];
    }

    private function arrayValue(string $key): array
    {
        return isset($this->data[$key]) && is_array($this->data[$key])
            ? $this->data[$key]
            : [];
    }
}
