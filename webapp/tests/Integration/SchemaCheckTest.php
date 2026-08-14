<?php

use PHPUnit\Framework\TestCase;

/**
 * #706: a szerkezet-összevetés logikája.
 *
 * A compare() tiszta függvény — nincs benne adatbázis-hívás —, ezért kézzel
 * összerakott szerkezetekkel pontosan ellenőrizhető, hogy melyik eltérést milyen
 * súllyal jelzi. A súlyozás a lényeg: a /health-en a „hiba" azt jelenti, hogy a
 * kód el fog hasalni rajta, a „figyelmeztetés" azt, hogy működik, de eltér.
 */
final class SchemaCheckTest extends TestCase {

    private static function table(array $columns = [], array $indexes = [], string $engine = 'InnoDB', string $collation = 'utf8mb4_unicode_ci'): array {
        return ['engine' => $engine, 'collation' => $collation, 'columns' => $columns, 'indexes' => $indexes];
    }

    private static function column(string $type = 'int(11)', bool $nullable = false, ?string $default = null, string $extra = ''): array {
        return ['type' => $type, 'nullable' => $nullable, 'default' => $default, 'extra' => $extra];
    }

    private static function kinds(array $findings): array {
        return array_column($findings, 'kind');
    }

    private static function bySeverity(array $findings, string $severity): array {
        return array_values(array_filter($findings, fn($f) => $f['severity'] === $severity));
    }

    public function testIdenticalStructuresProduceNoFindings(): void {
        $structure = ['tables' => ['templomok' => self::table(['id' => self::column()])]];

        self::assertSame([], \SchemaCheck::compare($structure, $structure));
    }

    /* Hiányzó tábla: a kód biztosan elhasal rajta. */
    public function testMissingTableIsADanger(): void {
        $expected = ['tables' => ['templomok' => self::table(['id' => self::column()])]];
        $actual   = ['tables' => []];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertCount(1, $findings);
        self::assertSame('missing_table', $findings[0]['kind']);
        self::assertSame(\SchemaCheck::DANGER, $findings[0]['severity']);
    }

    /* Többlet-tábla: nem hiba, csak elhagyott — pontosan ezeket keressük. */
    public function testExtraTableIsOnlyAWarning(): void {
        $expected = ['tables' => []];
        $actual   = ['tables' => ['nevnaptar' => self::table(['datum' => self::column('varchar(4)')])]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame(['extra_table'], self::kinds($findings));
        self::assertSame(\SchemaCheck::WARNING, $findings[0]['severity']);
    }

    public function testMissingColumnIsADanger(): void {
        $expected = ['tables' => ['templomok' => self::table(['id' => self::column(), 'lat' => self::column('decimal(11,7)', true)])]];
        $actual   = ['tables' => ['templomok' => self::table(['id' => self::column()])]];

        $findings = self::bySeverity(\SchemaCheck::compare($expected, $actual), \SchemaCheck::DANGER);

        self::assertCount(1, $findings);
        self::assertSame('missing_column', $findings[0]['kind']);
        self::assertStringContainsString('lat', $findings[0]['message']);
    }

    public function testExtraColumnIsOnlyAWarning(): void {
        $expected = ['tables' => ['chat' => self::table(['id' => self::column()])]];
        $actual   = ['tables' => ['chat' => self::table(['id' => self::column(), 'ip' => self::column('varchar(50)')])]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame(['extra_column'], self::kinds($findings));
        self::assertSame(\SchemaCheck::WARNING, $findings[0]['severity']);
    }

    /*
     * A NULL-ozhatóság iránya számít. Ha ÉLESBEN szigorúbb (NOT NULL), mint nálunk,
     * akkor a kódunk NULL-t próbálhat írni, amit az adatbázis visszautasít — ez hiba.
     */
    public function testStricterNullabilityInProductionIsADanger(): void {
        $expected = ['tables' => ['cal_masses' => self::table(['updated_at' => self::column('date', true)])]];
        $actual   = ['tables' => ['cal_masses' => self::table(['updated_at' => self::column('date', false)])]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame('column_nullable', $findings[0]['kind']);
        self::assertSame(\SchemaCheck::DANGER, $findings[0]['severity']);
    }

    /* Fordítva viszont csak figyelmeztetés: a lazább oldal elfogadja, amit írunk. */
    public function testLooserNullabilityInProductionIsOnlyAWarning(): void {
        $expected = ['tables' => ['cal_masses' => self::table(['updated_at' => self::column('date', false)])]];
        $actual   = ['tables' => ['cal_masses' => self::table(['updated_at' => self::column('date', true)])]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame(\SchemaCheck::WARNING, $findings[0]['severity']);
    }

    /* Hiányzó EGYEDI index adathibát enged be, nem csak lassít. */
    public function testMissingUniqueIndexIsADanger(): void {
        $expected = ['tables' => ['megye' => self::table(['id' => self::column()], ['PRIMARY' => ['unique' => true, 'columns' => ['id']]])]];
        $actual   = ['tables' => ['megye' => self::table(['id' => self::column()])]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame('missing_index', $findings[0]['kind']);
        self::assertSame(\SchemaCheck::DANGER, $findings[0]['severity']);
        self::assertStringContainsString('EGYEDI', $findings[0]['message']);
    }

    /* A nem egyedi index hiánya lassít, de nem ront adatot. */
    public function testMissingPlainIndexIsOnlyAWarning(): void {
        $expected = ['tables' => ['cal_masses' => self::table(['church_id' => self::column()], ['church_id' => ['unique' => false, 'columns' => ['church_id']]])]];
        $actual   = ['tables' => ['cal_masses' => self::table(['church_id' => self::column()])]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame(\SchemaCheck::WARNING, $findings[0]['severity']);
    }

    public function testColumnTypeDifferenceIsReported(): void {
        $expected = ['tables' => ['remarks' => self::table(['leiras' => self::column('text')])]];
        $actual   = ['tables' => ['remarks' => self::table(['leiras' => self::column('mediumtext')])]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame('column_type', $findings[0]['kind']);
        self::assertStringContainsString('mediumtext', $findings[0]['message']);
        self::assertStringContainsString('text', $findings[0]['message']);
    }

    /*
     * A karakterkészlet önmagában nem hiba — a #669 épp ezeket egységesíti, és amíg
     * az éles migráció nem futott le, minden táblán eltérne. Ha ez „figyelmeztetés"
     * lenne, elnyomná a valódi bajokat a /health-en.
     */
    public function testCollationDifferenceIsOnlyInfo(): void {
        $expected = ['tables' => ['templomok' => self::table([], [], 'InnoDB', 'utf8mb4_unicode_ci')]];
        $actual   = ['tables' => ['templomok' => self::table([], [], 'InnoDB', 'utf8mb3_uca1400_ai_ci')]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame(['collation'], self::kinds($findings));
        self::assertSame(\SchemaCheck::INFO, $findings[0]['severity']);
    }

    public function testEngineDifferenceIsAWarning(): void {
        $expected = ['tables' => ['megye' => self::table([], [], 'InnoDB')]];
        $actual   = ['tables' => ['megye' => self::table([], [], 'MyISAM')]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame('engine', $findings[0]['kind']);
        self::assertSame(\SchemaCheck::WARNING, $findings[0]['severity']);
    }

    /* A hibák elöl legyenek — a /health-en ez a lista teteje. */
    public function testFindingsAreSortedBySeverity(): void {
        $expected = ['tables' => [
            'a' => self::table([], [], 'InnoDB', 'utf8mb3_general_ci'),          // info
            'b' => self::table(['x' => self::column()]),                          // danger (hiányzó oszlop)
        ]];
        $actual = ['tables' => [
            'a' => self::table([], [], 'InnoDB', 'utf8mb4_unicode_ci'),
            'b' => self::table([]),
        ]];

        $findings = \SchemaCheck::compare($expected, $actual);

        self::assertSame(\SchemaCheck::DANGER, $findings[0]['severity']);
        self::assertSame(\SchemaCheck::INFO, $findings[count($findings) - 1]['severity']);
    }

    public function testSummariseCountsEachSeverity(): void {
        $findings = [
            ['severity' => \SchemaCheck::DANGER,  'table' => 'a', 'kind' => 'x', 'message' => ''],
            ['severity' => \SchemaCheck::WARNING, 'table' => 'b', 'kind' => 'y', 'message' => ''],
            ['severity' => \SchemaCheck::WARNING, 'table' => 'c', 'kind' => 'z', 'message' => ''],
        ];

        self::assertSame(
            [\SchemaCheck::DANGER => 1, \SchemaCheck::WARNING => 2, \SchemaCheck::INFO => 0],
            \SchemaCheck::summarise($findings)
        );
    }
}
