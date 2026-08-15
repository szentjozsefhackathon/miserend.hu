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

    private function arrayValue(string $key): array
    {
        return isset($this->data[$key]) && is_array($this->data[$key])
            ? $this->data[$key]
            : [];
    }
}
