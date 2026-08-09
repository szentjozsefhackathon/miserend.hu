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

    private function arrayValue(string $key): array
    {
        return isset($this->data[$key]) && is_array($this->data[$key])
            ? $this->data[$key]
            : [];
    }
}
