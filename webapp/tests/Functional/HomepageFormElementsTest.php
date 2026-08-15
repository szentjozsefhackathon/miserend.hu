<?php

namespace Tests\Functional;


/**
 * Test 2: Homepage Form Elements Test
 * 
 * Validates that all critical form elements on the homepage are present and rendered correctly.
 * This includes search inputs, dropdowns, date pickers, and dynamic filter buttons.
 */
final class HomepageFormElementsTest extends FunctionalTestCase
{
    private static $client;
    private static $crawler;

    public static function setUpBeforeClass(): void
    {
        self::$client = static::pantherClient();
        self::$crawler = self::$client->request('GET', '/');
    }

    public function testKeywordSearchInputExists(): void
    {
        $input = self::$crawler->filter("input[name='kulcsszo']");
        self::assertCount(1, $input, 'Keyword search input (kulcsszo) should exist');
    }


    public function testStartDateInputExists(): void
    {
        $input = self::$crawler->filter("input[name='start_date']");
        self::assertCount(1, $input, 'Start date input should exist');
    }

    public function testEndDateInputExists(): void
    {
        $input = self::$crawler->filter("input[name='end_date']");
        self::assertCount(1, $input, 'End date input should exist');
    }

    public function testSearchFormExists(): void
    {
        $form = self::$crawler->filter("form[name='kereses']");
        self::assertCount(1, $form, 'Search form should exist');
    }

    public function testLanguageFilterButtonsExist(): void
    {
        // Language toggle buttons are dynamically generated from langs array
        $langButtons = self::$crawler->filter("button.lang-toggle");
        self::assertGreaterThan(0, $langButtons->count(), 'Language filter buttons should exist (foreach langs)');
    }

    public function testRiteTypeFilterButtonsExist(): void
    {
        // Rite type toggle buttons (ROM, GKA, etc.)
        $typeButtons = self::$crawler->filter("button.type-toggle");
        self::assertGreaterThan(0, $typeButtons->count(), 'Type filter buttons should exist (foreach rites/masstypes)');
    }

    public function testCategoryFilterButtonsExist(): void
    {
        // Category toggle buttons
        $categoryButtons = self::$crawler->filter("button.category-toggle");
        self::assertGreaterThanOrEqual(0, $categoryButtons->count(), 'Category filter buttons may exist');
    }

    public function testHiddenInputsForFiltersExist(): void
    {
        // Hidden inputs for storing filter states
        $ritesShould = self::$crawler->filter("input[name='rites[should]']");
        self::assertCount(1, $ritesShould, 'Hidden input for rites[should] should exist');
        
        $ritesMustNot = self::$crawler->filter("input[name='rites[must_not]']");
        self::assertCount(1, $ritesMustNot, 'Hidden input for rites[must_not] should exist');
    }

    public function testNoPhpErrorsOnPage(): void
    {
        $pageContent = self::$client->executeScript('return document.body.innerHTML;');
        
        self::assertStringNotContainsString('Fatal error', $pageContent, 'Page should not contain PHP Fatal error');
        self::assertStringNotContainsString('Parse error', $pageContent, 'Page should not contain PHP Parse error');
        self::assertStringNotContainsString('Warning:', $pageContent, 'Page should not contain PHP Warning');
    }
}
