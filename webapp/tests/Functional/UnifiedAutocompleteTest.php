<?php

namespace Tests\Functional;


/**
 * Functional (browser) tests for the Unified Autocomplete UX.
 *
 * Uses Symfony Panther (Chrome headless) to verify the full
 * badge interaction on the homepage search form.
 *
 * Requires a running local server at PANTHER_EXTERNAL_BASE_URI
 * (defaults to http://127.0.0.1:8000).
 */
final class UnifiedAutocompleteTest extends FunctionalTestCase
{

    /**
     * A találati sor típusát a BADGE osztálya árulja el (l. unified-autocomplete.js):
     * a misézőhely külön `unified-dropdown-church-badge`-et kap, a területi találat
     * csak a sima `unified-dropdown-badge`-et.
     *
     * A tesztek eddig `unified-dropdown-church-icon`-ra illesztettek — az az osztálynév
     * viszont KIZÁRÓLAG a stíluslapban létezik, a felület sosem teszi ki. Így a
     * feltétel akkor sem teljesülhetett volna, ha a HTML-olvasás működik.
     */
    private const TEMPLOM_JELOLO = '.unified-dropdown-church-badge';
    private const HATAR_JELOLO = '.unified-dropdown-badge:not(.unified-dropdown-church-badge)';

    /**
     * Tartalmazza-e a találati sor a keresett elemet?
     *
     * Eddig `$item->getAttribute('innerHTML')`-lel néztük, sztring-illesztéssel. Csakhogy
     * az `innerHTML` nem ATTRIBÚTUM, hanem property: a W3C WebDriver
     * `getElementAttribute` hívása NULL-t ad rá. A `strpos(null, ...)` PHP 8.1 óta
     * elavult figyelmeztetést ír — a valódi kár viszont az, hogy a feltétel SOSEM
     * teljesült, tehát a tesztek fele „nincs megfelelő találat" indokkal kihagyásra ment.
     * Ebben az osztályban 13 tesztből 7 futott így, üresben, évek óta.
     *
     * A böngészőtől nem a nyers HTML-t kérjük, hanem magát az elemet: ez az, amit a
     * WebDriver támogat, és egyben pontosabb is, mint osztálynevekre illeszteni.
     */
    private static function tartalmaz(\Facebook\WebDriver\WebDriverElement $item, string $selector): bool
    {
        return $item->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector($selector)) !== [];
    }
    private static $client;

    public static function setUpBeforeClass(): void
    {
        self::$client = static::pantherClient();
    }

    private function loadHomepage(): \Symfony\Component\DomCrawler\Crawler
    {
        return self::$client->request('GET', '/');
    }

    // ── DOM presence ─────────────────────────────────────────────────────────

    public function testUnifiedInputWrapperExists(): void
    {
        $crawler = $this->loadHomepage();
        // After JS initialisation the input should be wrapped
        self::$client->waitFor('.unified-input-wrapper');
        $wrapper = $crawler->filter('.unified-input-wrapper');
        self::assertCount(1, $wrapper, 'unified-input-wrapper should be created by JS');
    }

    public function testBadgesContainerExists(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('.unified-badges-container');
        $container = self::$client->getCrawler()->filter('.unified-badges-container');
        self::assertCount(1, $container, '.unified-badges-container should be created by JS');
    }

    public function testDropdownExistsHidden(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('.unified-dropdown');
        $dropdown = self::$client->getCrawler()->filter('.unified-dropdown');
        self::assertCount(1, $dropdown, '.unified-dropdown should be present');
        // It should not have the "visible" class initially
        self::assertStringNotContainsString('visible',
            $dropdown->attr('class') ?? '',
            'Dropdown should be hidden on load');
    }

    // ── Dropdown opens on typing ─────────────────────────────────────────────

    public function testDropdownOpensAfterThreeChars(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('#keyword');
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Bud');

        // Wait for dropdown to become visible (up to 3 s)
        self::$client->waitForVisibility('.unified-dropdown', 3);

        $dropdown = self::$client->getCrawler()->filter('.unified-dropdown');
        self::assertStringContainsString('visible', $dropdown->attr('class') ?? '',
            'Dropdown should be visible after typing 3+ chars');
    }

    public function testDropdownIsHiddenForShortInput(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('#keyword');
        $input = self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'));
        $input->clear();
        $input->sendKeys('Bu');

        usleep(400000); // wait longer than debounce

        $dropdown = self::$client->getCrawler()->filter('.unified-dropdown');
        self::assertStringNotContainsString('visible', $dropdown->attr('class') ?? '',
            'Dropdown should NOT be visible for fewer than 3 chars');
    }

    // ── Church item in dropdown ──────────────────────────────────────────────

    public function testChurchIconVisibleInDropdownItem(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('#keyword');
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Budapest');

        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-church-badge', 3);

        $badges = self::$client->getCrawler()->filter('.unified-dropdown.visible .unified-dropdown-church-badge');
        self::assertGreaterThan(0, $badges->count(),
            'At least one church badge should appear in dropdown for "Budapest"');
    }

    // ── Selecting a church ───────────────────────────────────────────────────

    public function testChurchBadgeAppearsAfterSelectingChurch(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('#keyword');
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Budapest');

        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-item', 3);

        // Click first church item in dropdown
        $items = self::$client->getCrawler()->filter(
            '.unified-dropdown.visible .unified-dropdown-item'
        );
        // Find first church item
        $churchItem = null;
        foreach ($items as $item) {
            if (self::tartalmaz($item, self::TEMPLOM_JELOLO)) {
                $churchItem = $item;
                break;
            }
        }

        if ($churchItem === null) {
            $this->markTestSkipped('No church item found in dropdown for "Budapest"');
        }

        $churchItem->click();

        self::$client->waitFor('.unified-church-badge', 2);

        $badge = self::$client->getCrawler()->filter('.unified-church-badge');
        self::assertCount(1, $badge, 'One church badge should appear after selection');
    }

    public function testChurchBadgeContainsTempleIcon(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('#keyword');
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Budapest');

        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-item', 3);

        $items = self::$client->getCrawler()->filter('.unified-dropdown.visible .unified-dropdown-item');
        $churchItem = null;
        foreach ($items as $item) {
            if (self::tartalmaz($item, self::TEMPLOM_JELOLO)) {
                $churchItem = $item;
                break;
            }
        }
        if ($churchItem === null) {
            $this->markTestSkipped('No church item found for "Budapest"');
        }
        $churchItem->click();

        self::$client->waitFor('.unified-church-badge .unified-church-icon', 2);
        $icon = self::$client->getCrawler()->filter('.unified-church-badge .unified-church-icon');
        self::assertGreaterThan(0, $icon->count(), 'Church badge must contain .unified-church-icon');
    }

    public function testInputIsClearedAfterChurchSelection(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('#keyword');
        $input = self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'));
        $input->sendKeys('Budapest');

        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-item', 3);

        $items = self::$client->getCrawler()->filter('.unified-dropdown.visible .unified-dropdown-item');
        $churchItem = null;
        foreach ($items as $item) {
            if (self::tartalmaz($item, self::TEMPLOM_JELOLO)) {
                $churchItem = $item;
                break;
            }
        }
        if ($churchItem === null) {
            $this->markTestSkipped('No church item found for "Budapest"');
        }
        $churchItem->click();

        self::$client->waitFor('.unified-church-badge', 2);

        $inputValue = self::$client->executeScript('return document.getElementById("keyword").value;');
        self::assertEmpty($inputValue, 'Input should be cleared after church selection');
    }

    // ── Hidden field ─────────────────────────────────────────────────────────

    public function testHiddenChurchIdFieldSetAfterSelection(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('#keyword');
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Budapest');

        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-item', 3);

        $items = self::$client->getCrawler()->filter('.unified-dropdown.visible .unified-dropdown-item');
        $churchItem = null;
        foreach ($items as $item) {
            if (self::tartalmaz($item, self::TEMPLOM_JELOLO)) {
                $churchItem = $item;
                break;
            }
        }
        if ($churchItem === null) {
            $this->markTestSkipped('No church item found for "Budapest"');
        }
        $churchItem->click();

        self::$client->waitFor('.unified-church-badge', 2);

        $hiddenVal = self::$client->executeScript(
            'var f = document.querySelector(\'input[name="church_ids[]"]\'); return f ? f.value : null;'
        );
        self::assertNotNull($hiddenVal, 'Hidden church_ids[] input should exist after selection');
        self::assertNotEmpty($hiddenVal, 'Hidden church_ids[] value should not be empty');
        self::assertIsNumeric($hiddenVal, 'church_ids[] value should be a numeric ID');
    }

    // ── Badge removal ────────────────────────────────────────────────────────

    public function testChurchBadgeRemovalClearsHiddenField(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('#keyword');
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Budapest');

        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-item', 3);

        $items = self::$client->getCrawler()->filter('.unified-dropdown.visible .unified-dropdown-item');
        $churchItem = null;
        foreach ($items as $item) {
            if (self::tartalmaz($item, self::TEMPLOM_JELOLO)) {
                $churchItem = $item;
                break;
            }
        }
        if ($churchItem === null) {
            $this->markTestSkipped('No church item found for "Budapest"');
        }
        $churchItem->click();

        self::$client->waitFor('.unified-church-badge .unified-badge-remove', 2);

        // Click the × button on the badge
        self::$client->getCrawler()->filter('.unified-church-badge .unified-badge-remove')->first()->click();

        usleep(300000); // wait for DOM update

        $hiddenVal = self::$client->executeScript(
            'var f = document.querySelector(\'input[name="church_ids[]"]\'); return f ? f.value : null;'
        );
        $badge = self::$client->getCrawler()->filter('.unified-church-badge');
        self::assertCount(0, $badge, 'Church badge should be removed after clicking ×');
        self::assertNull($hiddenVal, 'Hidden church_ids[] field should be removed after badge removal');
    }

    // ── Multi-select ─────────────────────────────────────────────────────────

    public function testMultipleChurchesCanBeSelected(): void
    {
        $this->loadHomepage();

        // Select first church
        self::$client->waitFor('#keyword');
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Budapest');
        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-item', 3);

        $items = self::$client->getCrawler()->filter('.unified-dropdown.visible .unified-dropdown-item');
        $first = null;
        foreach ($items as $item) {
            if (self::tartalmaz($item, self::TEMPLOM_JELOLO)) {
                $first = $item;
                break;
            }
        }
        if ($first === null) {
            $this->markTestSkipped('No church items for "Budapest"');
        }
        $first->click();
        self::$client->waitFor('.unified-church-badge', 2);

        // Select a second church using a different search
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Debrecen');
        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-item', 3);

        $items2 = self::$client->getCrawler()->filter('.unified-dropdown.visible .unified-dropdown-item');
        $second = null;
        foreach ($items2 as $item) {
            if (self::tartalmaz($item, self::TEMPLOM_JELOLO)) {
                $second = $item;
                break;
            }
        }

        if ($second === null) {
            $this->markTestSkipped('No church items for "Debrecen"');
        }
        $second->click();

        self::$client->waitFor('.unified-church-badge', 2);

        $badges = self::$client->getCrawler()->filter('.unified-church-badge');
        self::assertGreaterThanOrEqual(2, $badges->count(),
            'Two church badges should be visible after selecting two churches');
    }

    // ── Boundary and church coexist ──────────────────────────────────────────

    public function testBoundaryAndChurchBadgeCoexist(): void
    {
        $this->loadHomepage();
        self::$client->waitFor('#keyword');
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Budapest');

        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-item', 3);

        $items = self::$client->getCrawler()->filter('.unified-dropdown.visible .unified-dropdown-item');

        $boundaryItem = null;
        $churchItem   = null;

        foreach ($items as $item) {
            if ($boundaryItem === null && self::tartalmaz($item, self::HATAR_JELOLO)) {
                $boundaryItem = $item;
            }
            if ($churchItem === null && self::tartalmaz($item, self::TEMPLOM_JELOLO)) {
                $churchItem = $item;
            }
        }

        if ($boundaryItem === null || $churchItem === null) {
            $this->markTestSkipped('Need at least one boundary and one church item for "Budapest"');
        }

        $boundaryItem->click();
        self::$client->waitFor('.unified-badge', 2);

        // Re-open
        self::$client->getWebDriver()->findElement(\Facebook\WebDriver\WebDriverBy::id('keyword'))
            ->sendKeys('Budapest');
        self::$client->waitFor('.unified-dropdown.visible .unified-dropdown-item', 3);

        $items2 = self::$client->getCrawler()->filter('.unified-dropdown.visible .unified-dropdown-item');
        $churchItem2 = null;
        foreach ($items2 as $item) {
            if (self::tartalmaz($item, self::TEMPLOM_JELOLO)) {
                $churchItem2 = $item;
                break;
            }
        }
        if ($churchItem2 === null) {
            $this->markTestSkipped('No church items on second search');
        }
        $churchItem2->click();

        self::$client->waitFor('.unified-church-badge', 2);

        $boundaries = self::$client->getCrawler()->filter('.unified-badge:not(.unified-church-badge)');
        $churches   = self::$client->getCrawler()->filter('.unified-church-badge');

        self::assertGreaterThan(0, $boundaries->count(), 'Boundary badge should still be present');
        self::assertGreaterThan(0, $churches->count(),   'Church badge should be present');
    }
}
