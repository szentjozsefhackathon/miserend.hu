<?php

use PHPUnit\Framework\TestCase;

/**
 * #299: kategóriából cím-szűrő.
 *
 * Kétféle cím-alak létezik az adatban, mert kétfelől jön: a naptárszerkesztő a
 * definíciókulcsot írja be (`MASS_TITLE.ADORATION`), a régi, kézzel felvitt sorokban
 * viszont a magyar cím áll (`Szentségimádás`). Aki kategóriára szűr, mindkettőt akarja —
 * ha az egyik kimarad, néma módon tűnnek el találatok.
 */
class MassDefinitionsTitleFiltersTest extends TestCase {

    public function testAdorationCoversBothTheKeyAndTheHungarianTitle(): void {
        $filters = (new \MassDefinitions())->titleFiltersByCategories(['ADORATION']);

        self::assertContains('MASS_TITLE.ADORATION', $filters);
        self::assertContains('Szentségimádás', $filters);
    }

    public function testConfessionCoversBothForms(): void {
        $filters = (new \MassDefinitions())->titleFiltersByCategories(['CONFESSION']);

        self::assertContains('MASS_TITLE.CONFESSION', $filters);
        self::assertContains('Gyóntatás', $filters);
    }

    /*
     * A magyar címre azért kell ragaszkodni, mert az adatbázisban az van: az élő indexben
     * ~7000 „Szentségimádás" című esemény szerepel, „MASS_TITLE.ADORATION" címmel viszont
     * gyakorlatilag egy sem. Ha a felület nyelvét követnénk, angol nézetben a szűrő
     * egyetlen sorra sem illeszkedne.
     */
    public function testHungarianTitleDoesNotDependOnTheInterfaceLanguage(): void {
        $filters = (new \MassDefinitions())->titleFiltersByCategories(['ADORATION']);

        self::assertContains('Szentségimádás', $filters);
        self::assertNotContains('Adoration', $filters);
    }

    public function testMassCategoryCoversEveryMassKind(): void {
        $filters = (new \MassDefinitions())->titleFiltersByCategories(['MASS']);

        self::assertContains('Szentmise', $filters);
        self::assertContains('Szent Liturgia', $filters);
        self::assertContains('MASS_TITLE.HOLY_MASS', $filters);
    }

    public function testSeveralCategoriesAreMerged(): void {
        $filters = (new \MassDefinitions())->titleFiltersByCategories(['ADORATION', 'CONFESSION']);

        self::assertContains('Szentségimádás', $filters);
        self::assertContains('Gyóntatás', $filters);
        self::assertNotContains('Szentmise', $filters, 'a mise nem tartozik ebbe a két kategóriába');
    }

    public function testUnknownCategoryGivesNoFilterInsteadOfEverything(): void {
        self::assertSame([], (new \MassDefinitions())->titleFiltersByCategories(['SZENTSEGIMADAS']));
        self::assertSame([], (new \MassDefinitions())->titleFiltersByCategories([]));
    }

    public function testFiltersAreUnique(): void {
        $filters = (new \MassDefinitions())->titleFiltersByCategories(['ADORATION', 'ADORATION']);

        self::assertSame(array_values(array_unique($filters)), $filters);
    }
}
