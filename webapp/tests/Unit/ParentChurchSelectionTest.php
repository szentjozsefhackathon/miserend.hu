<?php

use PHPUnit\Framework\TestCase;

/**
 * #639: az „Honnan látják el" mező mentési döntése.
 *
 * A hiba az volt, hogy a legördülő placeholder-értéke ("0") két szék közé esett:
 * a külső feltétel beengedte (nem üres string), a belső viszont kidobta (!== 0),
 * így se nem állított be kapcsolatot, se nem törölte a meglévőt. Vagyis a
 * beállított ellátó plébániát semmilyen módon nem lehetett visszavonni.
 */
final class ParentChurchSelectionTest extends TestCase
{
    private const CHURCH_ID = 42;

    public function testChoosingAChurchReplacesTheRelationship(): void
    {
        $this->assertSame(
            \Html\Church\Edit::PARENT_REPLACE,
            \Html\Church\Edit::parentChurchAction(1234, self::CHURCH_ID)
        );
    }

    /** A placeholder ("– válassz templomot –") értéke: nincs ellátó plébánia. */
    public function testPlaceholderRemovesTheRelationship(): void
    {
        $this->assertSame(
            \Html\Church\Edit::PARENT_REMOVE,
            \Html\Church\Edit::parentChurchAction(0, self::CHURCH_ID)
        );
    }

    /** Ha a mező be sem érkezik, a hívó 0-t ad át — az is törlés. */
    public function testMissingFieldRemovesTheRelationship(): void
    {
        $this->assertSame(
            \Html\Church\Edit::PARENT_REMOVE,
            \Html\Church\Edit::parentChurchAction(0, self::CHURCH_ID)
        );
    }

    /*
     * Önmagára mutató választás érvénytelen — de ilyenkor NE dobjuk el a meglévő
     * kapcsolatot sem, mert az adatvesztés lenne egy elgépelés miatt.
     */
    public function testSelfReferenceIsInvalidAndKeepsTheCurrentValue(): void
    {
        $this->assertSame(
            \Html\Church\Edit::PARENT_INVALID,
            \Html\Church\Edit::parentChurchAction(self::CHURCH_ID, self::CHURCH_ID)
        );
    }

    /** Negatív / szemetes érték se hozzon létre kapcsolatot. */
    public function testNegativeValueRemovesTheRelationship(): void
    {
        $this->assertSame(
            \Html\Church\Edit::PARENT_REMOVE,
            \Html\Church\Edit::parentChurchAction(-7, self::CHURCH_ID)
        );
    }
}
