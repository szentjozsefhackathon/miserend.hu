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
     *   2. Ha nincs pontos találat, a szabad szöveges felismerő
     *      (`\IcalEventProperties::detectCategory()`), ami az importált és a kézzel
     *      írt egyedi címeket is besorolja.
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

        return \IcalEventProperties::detectCategory($title);
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
