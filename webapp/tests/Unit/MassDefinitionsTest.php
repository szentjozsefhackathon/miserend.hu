<?php

use PHPUnit\Framework\TestCase;

final class MassDefinitionsTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            unlink($file);
        }
    }

    public function testReadsDefinitionsThroughDedicatedQueries(): void
    {
        $definitions = new MassDefinitions($this->temporaryJson([
            'categories' => [['key' => 'MASS']],
            'rites' => [['key' => 'ROMAN_CATHOLIC']],
            'definitions' => [
                ['key' => 'HOLY_MASS', 'category' => 'MASS'],
                ['key' => 'ROSARY', 'category' => 'OTHER'],
                ['key' => 'HOLY_MASS', 'category' => 'MASS'],
            ],
            'titlesByCategory' => [
                'MASS' => ['MASS_TITLE.HOLY_MASS'],
                'OTHER' => ['MASS_TITLE.ROSARY'],
            ],
        ]));

        $this->assertSame([['key' => 'MASS']], $definitions->categories());
        $this->assertSame([['key' => 'ROMAN_CATHOLIC']], $definitions->rites());
        $this->assertSame(['HOLY_MASS'], $definitions->definitionKeysByCategory('MASS'));
        $this->assertSame(
            ['MASS_TITLE.HOLY_MASS', 'MASS_TITLE.ROSARY'],
            $definitions->titlesByCategories(['MASS', 'OTHER'])
        );
    }

    public function testMissingFileReturnsEmptyDefinitions(): void
    {
        $definitions = new MassDefinitions(sys_get_temp_dir() . '/missing-mass-definitions.json');

        $this->assertSame([], $definitions->categories());
        $this->assertSame([], $definitions->rites());
        $this->assertSame([], $definitions->definitionKeysByCategory('MASS'));
        $this->assertSame([], $definitions->titlesByCategories(['MASS']));
    }

    public function testMalformedJsonReturnsEmptyDefinitions(): void
    {
        $file = $this->temporaryFile('{invalid json');
        $definitions = new MassDefinitions($file);

        $this->assertSame([], $definitions->categories());
        $this->assertSame([], $definitions->definitionKeysByCategory('MASS'));
    }

    public function testLoadsTheGeneratedProjectDefinitions(): void
    {
        $definitions = new MassDefinitions();

        $this->assertContains(['key' => 'MASS', 'color' => '#0A0A0A'], $definitions->categories());
        $this->assertContains('HOLY_MASS', $definitions->definitionKeysByCategory('MASS'));
        $this->assertContains('MASS_TITLE.HOLY_MASS', $definitions->titlesByCategories(['MASS']));
    }

    private function temporaryJson(array $data): string
    {
        return $this->temporaryFile(json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function temporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'mass-definitions-');
        file_put_contents($file, $contents);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
