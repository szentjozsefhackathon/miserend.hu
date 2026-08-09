<?php

use PHPUnit\Framework\TestCase;

/**
 * #644: akadálymentesség és csökkentett gluténtartalmú áldozás szűrők.
 *
 * A lekérdezés-építést nézzük (ES nélkül): mely mezőre, mely értékekre szűrünk,
 * és — ami a legfontosabb — hogy a NEGATÍV keresés ne legyen lehetséges.
 */
final class AccessibilitySearchFilterTest extends TestCase
{
    private function mustClauses(\Search $search): array
    {
        return $search->query['bool']['must'] ?? [];
    }

    public function testWheelchairFiltersOnTheChurchFieldInTheMassIndex(): void
    {
        $search = new \Search('masses');
        $search->wheelchair(['yes']);

        self::assertSame(
            [['terms' => ['church.wheelchair.keyword' => ['yes']]]],
            $this->mustClauses($search)
        );
    }

    public function testWheelchairFiltersWithoutPrefixOnTheChurchIndex(): void
    {
        $search = new \Search('churches');
        $search->wheelchair(['yes', 'limited']);

        self::assertSame(
            [['terms' => ['wheelchair.keyword' => ['yes', 'limited']]]],
            $this->mustClauses($search)
        );
    }

    /*
     * Kifejezetten NEM akadálymentes helyre nem lehet keresni — borazslo kérése:
     * „ne akarjon direkt olyanra keresni ahol nem lehet wheelchair".
     */
    public function testNegativeWheelchairSearchIsNotPossible(): void
    {
        $search = new \Search('masses');
        $search->wheelchair(['no']);

        self::assertSame([], $this->mustClauses($search), 'A "no" értéket el kell dobni.');
    }

    public function testUnknownWheelchairValueIsIgnored(): void
    {
        $search = new \Search('masses');
        $search->wheelchair(['talán', '']);

        self::assertSame([], $this->mustClauses($search));
    }

    /*
     * A hétköznap és a vasárnap külön kérdés; ha mindkettőt kérik, mindkettőnek
     * teljesülnie kell (AND), nem elég az egyik.
     */
    public function testGlutenFreeDaysAreIndependentAndCombineWithAnd(): void
    {
        $search = new \Search('masses');
        $search->glutenFree(['holidays', 'weekdays']);

        $clauses = $this->mustClauses($search);
        self::assertCount(2, $clauses);
        self::assertArrayHasKey('church.gluten_free_holidays.keyword', $clauses[0]['terms']);
        self::assertArrayHasKey('church.gluten_free_weekdays.keyword', $clauses[1]['terms']);
    }

    /* A „nem lehetséges" és a hiányzó adat nem számít lehetőségnek. */
    public function testGlutenFreeAcceptsOnlyValuesThatMeanItIsPossible(): void
    {
        $search = new \Search('masses');
        $search->glutenFree(['holidays']);

        $values = $this->mustClauses($search)[0]['terms']['church.gluten_free_holidays.keyword'];
        self::assertNotContains('no', $values);
        self::assertNotContains('', $values);
        self::assertContains('always', $values);
        self::assertContains('ask_sacristy', $values);
    }

    public function testUnknownDayIsIgnored(): void
    {
        $search = new \Search('masses');
        $search->glutenFree(['szombat']);

        self::assertSame([], $this->mustClauses($search));
    }

    /* A szűrők megjelennek a felhasználónak mutatott feltétel-listában is. */
    public function testFiltersAreDescribedForTheUser(): void
    {
        $search = new \Search('masses');
        $search->wheelchair(['yes', 'limited']);
        $search->glutenFree(['weekdays']);

        $text = implode(' ', $search->filters);
        self::assertStringContainsString('Kerekesszékkel', $text);
        self::assertStringContainsString('részben akadálymentes', $text);
        self::assertStringContainsString('hétköznapokon', $text);
    }
}
