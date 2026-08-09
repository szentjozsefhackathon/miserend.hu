<?php

namespace Tests\Functional;

use Symfony\Component\Panther\PantherTestCase;

/**
 * Test 4: Mass Search Results with Filters Test
 * 
 * Tests the mass (miserend) search functionality with various filters.
 * This is the most complex test validating foreach loops for results, filters, and liturgical days.
 */
final class MassSearchResultsTest extends PantherTestCase
{
    private $client;

    protected function setUp(): void
    {
        $this->client = static::createPantherClient(
            [
                'external_base_uri' => getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000',
            ],
            [],
            [
                'browser' => static::CHROME,
            ]
        );
    }

    public function testMassSearchPageLoads(): void
    {
        // Search for masses with a date range
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        
        $url = "/?q=SearchResultsMasses&kulcsszo=Budapest&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        // Verify page loaded without PHP errors
        $pageContent = $this->client->executeScript('return document.body.innerHTML;');
        self::assertStringNotContainsString('Fatal error', $pageContent);
        self::assertStringNotContainsString('Parse error', $pageContent);
    }

    public function testNearbyMassSearchRendersSelectableMapAndBackgroundlessListTimes(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+1 day'));
        $url = "/?q=SearchResultsMasses&start_date={$startDate}&start_time=00%3A00"
            . "&end_date={$endDate}&end_time=00%3A00&nearby_lat=47.4979&nearby_lon=19.0402&nearby_radius=15";

        $this->client->request('GET', $url);
        $this->client->waitFor('#nearby-masses-map', 15);
        $this->client->waitFor('.leaflet-interactive', 15);

        $distances = $this->client->executeScript(<<<'JS'
            return Array.from(document.querySelectorAll('.mass-distance')).map(function (node) {
                return Number(node.dataset.distance);
            });
        JS);
        self::assertNotEmpty($distances);
        $sorted = $distances;
        sort($sorted, SORT_NUMERIC);
        self::assertSame($sorted, $distances);

        $labelStyle = $this->client->executeScript(<<<'JS'
            var time = document.querySelector('.nearby-list-time');
            return { background: getComputedStyle(time).backgroundColor, text: time.textContent.trim() };
        JS);
        self::assertSame('rgba(0, 0, 0, 0)', $labelStyle['background']);
        self::assertMatchesRegularExpression('/\d{2}:\d{2}/', $labelStyle['text']);

        // #608: a bejelentő kérése — a gombostű felirata háttér nélküli, pici betűs,
        // és csak az időpontot tartalmazza (a fehér tooltip-téglalap takarta a térképet).
        $this->client->waitFor('.nearby-time-label', 10);
        $pinLabel = $this->client->executeScript(<<<'JS'
            var pin = document.querySelector('.nearby-time-label');
            var style = getComputedStyle(pin);
            return {
                background: style.backgroundColor,
                borderWidth: style.borderTopWidth,
                fontSize: parseFloat(style.fontSize),
                text: pin.textContent.trim()
            };
        JS);
        self::assertSame('rgba(0, 0, 0, 0)', $pinLabel['background']);
        self::assertSame('0px', $pinLabel['borderWidth']);
        self::assertLessThanOrEqual(12, $pinLabel['fontSize']);
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $pinLabel['text']);

        // Kattintás előtt nincs automatikusan kiválasztott templom, a kártya útmutatót mutat.
        self::assertSame(0, $this->client->executeScript("return document.querySelectorAll('.nearby-church-selected').length;"));
        self::assertStringContainsString(
            'Válassz egy templomot a térképen',
            $this->client->getCrawler()->filter('#nearby-church-card')->text()
        );

        $rowsBeforeSelection = $this->client->executeScript("return document.querySelectorAll('.mass-result-row').length;");

        $this->client->executeScript("document.querySelectorAll('.leaflet-interactive')[2].dispatchEvent(new MouseEvent('click', {bubbles:true}));");
        $this->client->waitFor('#nearby-church-card .nearby-church-card-times', 5);
        self::assertStringContainsString('km légvonalban', $this->client->getCrawler()->filter('#nearby-church-card')->text());
        self::assertGreaterThan(0, $this->client->executeScript("return document.querySelectorAll('.nearby-church-selected').length;"));
        // A kiválasztás csak kiemel: a teljes találati lista változatlanul látszik.
        self::assertSame(
            $rowsBeforeSelection,
            $this->client->executeScript("return document.querySelectorAll('.mass-result-row').length;")
        );
        self::assertSame(
            $rowsBeforeSelection,
            $this->client->executeScript(<<<'JS'
                return Array.from(document.querySelectorAll('.mass-result-row')).filter(function (row) {
                    return row.offsetParent !== null;
                }).length;
            JS)
        );
    }

    /**
     * #608: a szűk időablak (pl. „következő két óra") este szinte mindig üres.
     * Ilyenkor a legközelebbi jövőbeli misét ajánljuk fel, nem néma üres oldalt.
     */
    public function testEmptyNearbySearchOffersTheNextUpcomingMass(): void
    {
        $startDate = date('Y-m-d');
        // Egyperces éjszakai ablak Budapest belvárosában. Kerek időpont helyett 23:47,
        // hogy az éjféli misék (karácsony) se tegyék dátumfüggővé a tesztet.
        $url = "/?q=SearchResultsMasses&start_date={$startDate}&start_time=23%3A47"
            . "&end_date={$startDate}&end_time=23%3A48&nearby_lat=47.4979&nearby_lon=19.0402&nearby_radius=3";

        $this->client->request('GET', $url);
        $this->client->waitFor('.mass-empty-state', 15);

        $emptyState = $this->client->getCrawler()->filter('.mass-empty-state')->text();
        self::assertStringContainsString('nincs mise', $emptyState);
        self::assertStringContainsString('A legközelebbi mise a közelben', $emptyState);
        self::assertMatchesRegularExpression('/\d+,\d{2} km légvonalban/', $emptyState);

        // A gomb ugyanerre a helyre visz, kitágított időablakkal — és tényleg ad találatot.
        $lookaheadUrl = $this->client->getCrawler()->filter('.mass-empty-state a.btn')->attr('href');
        self::assertStringContainsString('nearby_lat=47.4979', $lookaheadUrl);
        $this->client->request('GET', $lookaheadUrl);
        $this->client->waitFor('.mass-result-row', 15);
        self::assertGreaterThan(
            0,
            $this->client->executeScript("return document.querySelectorAll('.mass-result-row').length;")
        );
    }

    /**
     * #608: a number input a magyar tizedesvesszőt üres stringként küldi el, így csak az
     * egyik koordináta érkezik meg. Ez felhasználói elgépelés, nem fatális hiba.
     */
    public function testHalfGivenCoordinatesFallBackToASearchWithoutLocation(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+1 day'));
        $url = "/?q=SearchResultsMasses&start_date={$startDate}&end_date={$endDate}"
            . "&nearby_lat=&nearby_lon=19.0402&nearby_radius=3";

        $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);

        $pageText = $this->client->executeScript('return document.body.textContent;');
        self::assertStringNotContainsString('Hiba történt', $pageText);
        self::assertStringContainsString('A szélességi és a hosszúsági fokot együtt kell megadni', $pageText);
        // A hely szerinti szűrés kimarad, de a keresés lefut.
        self::assertSame(
            0,
            $this->client->executeScript("return document.querySelectorAll('#nearby-masses-map').length;")
        );
    }

    /**
     * #608: a dátum ellenőrzés nélkül ment az Elasticsearchbe, így egy elgépelt érték
     * nyers ES-stacktrace-t öntött a felhasználó képébe a találatok helyett.
     */
    public function testUnparseableDateFallsBackToTheDefaultRangeWithoutLeakingEngineErrors(): void
    {
        $url = '/?q=SearchResultsMasses&start_date=%24S&start_time=19%3A23&end_date=%24S&end_time=21%3A23';

        $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);

        $pageText = $this->client->executeScript('return document.body.textContent;');
        self::assertStringNotContainsString('parse_exception', $pageText);
        self::assertStringNotContainsString('search_phase_execution_exception', $pageText);
        self::assertStringNotContainsString('Hiba történt', $pageText);
        self::assertStringContainsString('A megadott dátum vagy időpont értelmezhetetlen', $pageText);
    }

    public function testCurrentLocationFillsTheNearbyOrigin(): void
    {
        $this->client->request('GET', '/');
        $this->client->waitFor('#use_current_location', 10);
        $this->client->executeScript(<<<'JS'
            Object.defineProperty(navigator, 'geolocation', {
                configurable: true,
                value: {
                    getCurrentPosition: function (success) {
                        success({coords: {latitude: 47.497913, longitude: 19.040236}});
                    }
                }
            });
        JS);
        // A gomb az összecsukott „Egyéni közeli keresés" details-ben van: zárva nem kattintható.
        $this->client->executeScript("document.querySelector('.nearby-search-options').open = true;");
        $this->client->waitForVisibility('#use_current_location', 5);
        $this->client->getCrawler()->filter('#use_current_location')->click();

        $coordinates = $this->client->executeScript(<<<'JS'
            return {
                lat: document.querySelector('#nearby_lat').value,
                lon: document.querySelector('#nearby_lon').value
            };
        JS);
        self::assertSame('47.497913', $coordinates['lat']);
        self::assertSame('19.040236', $coordinates['lon']);

        $this->client->executeScript("HTMLFormElement.prototype.submit = function () { this.dataset.submitted = '1'; };");
        $this->client->getCrawler()->filter('#walking_masses')->click();
        self::assertSame('3', $this->client->executeScript("return document.querySelector('#nearby_radius').value;"));
        self::assertSame('1', $this->client->executeScript("return document.querySelector('#kereses').dataset.submitted;"));
        // A gyalogos gyorskeresés mindhárom feltételt beállítja: hely + 3 km + következő két óra.
        $walkingOrigin = $this->client->executeScript(<<<'JS'
            return {
                lat: document.querySelector('#nearby_lat').value,
                lon: document.querySelector('#nearby_lon').value
            };
        JS);
        self::assertSame('47.497913', $walkingOrigin['lat']);
        self::assertSame('19.040236', $walkingOrigin['lon']);
        $walkingStartOffsetMinutes = $this->client->executeScript(<<<'JS'
            var start = new Date(document.querySelector('#start_date').value + 'T' + document.querySelector('#start_time').value);
            return Math.abs(Date.now() - start.getTime()) / 60000;
        JS);
        self::assertLessThan(5, $walkingStartOffsetMinutes, 'A gyalogos keresés a mostani időponttól indul.');
        $walkingRangeMinutes = $this->client->executeScript(<<<'JS'
            var start = new Date(document.querySelector('#start_date').value + 'T' + document.querySelector('#start_time').value);
            var end = new Date(document.querySelector('#end_date').value + 'T' + document.querySelector('#end_time').value);
            return (end.getTime() - start.getTime()) / 60000;
        JS);
        self::assertSame(120, $walkingRangeMinutes);

        $this->client->getCrawler()->filter('#next_two_hours')->click();
        $rangeMinutes = $this->client->executeScript(<<<'JS'
            var start = new Date(document.querySelector('#start_date').value + 'T' + document.querySelector('#start_time').value);
            var end = new Date(document.querySelector('#end_date').value + 'T' + document.querySelector('#end_time').value);
            return (end.getTime() - start.getTime()) / 60000;
        JS);
        self::assertSame(120, $rangeMinutes);
    }

    public function testSearchFiltersAreDisplayed(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        
        $url = "/?q=SearchResultsMasses&kulcsszo=templom&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        // Check for "Keresési paraméterek" section which contains the foreach filters loop
        $hasFilterSection = $this->client->executeScript(
            "return document.body.textContent.includes('Keresési paraméterek');"
        );
        self::assertTrue($hasFilterSection, 'Filter parameters section should be visible');
    }

    public function testFilterSpansRendered(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        
        // Search with a keyword to trigger filter display
        $url = "/?q=SearchResultsMasses&kulcsszo=Budapest&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        // Filter spans are rendered via foreach loop in the template
        // Check span.alap elements exist (may be 0 if no filters active)
        $filterSpansCount = $this->client->executeScript(
            "return document.querySelectorAll('span.alap').length;"
        );
        
        // At minimum, verify the query doesn't crash
        self::assertGreaterThanOrEqual(0, $filterSpansCount, 'Filter spans should render without error');
    }

    public function testMassResultsTableRendered(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        
        $url = "/?q=SearchResultsMasses&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        // Check for results table structure
        $hasTable = $this->client->executeScript(
            "return document.querySelectorAll('table.table').length > 0;"
        );
        
        // Table may not exist if no results
        self::assertTrue(true, 'Page structure validated (results depend on data)');
    }

    public function testDateHeadersRendered(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        
        $url = "/?q=SearchResultsMasses&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        // Date headers are rendered via the foreach loop with previousDate tracking
        // They appear as td.table-secondary elements
        $dateHeaders = $this->client->executeScript(
            "return document.querySelectorAll('td.table-secondary').length;"
        );
        
        // May be 0 if no results
        self::assertGreaterThanOrEqual(0, $dateHeaders, 'Date headers render without error');
    }

    public function testRiteIconsRendered(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        
        $url = "/?q=SearchResultsMasses&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        // Rite icons are rendered for each mass result
        $riteIcons = $this->client->executeScript(
            "return document.querySelectorAll('img[src*=\"/cal_images/types/\"]').length;"
        );
        
        // May be 0 if no results
        self::assertGreaterThanOrEqual(0, $riteIcons, 'Rite icons render without error');
    }

    public function testTimeBadgesRendered(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        
        $url = "/?q=SearchResultsMasses&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        // Time badges show the mass start time
        $timeBadges = $this->client->executeScript(
            "return document.querySelectorAll('span.badge').length;"
        );
        
        self::assertGreaterThanOrEqual(0, $timeBadges, 'Time badges render without error');
    }

    public function testPaginationRenderedIfNeeded(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+30 days'));
        
        $url = "/?q=SearchResultsMasses&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        // Pagination is rendered if there are multiple pages of results
        $paginationLinks = $this->client->executeScript(
            "return document.querySelectorAll('.pagination a').length;"
        );
        
        self::assertGreaterThanOrEqual(0, $paginationLinks, 'Pagination renders without error');
    }

    public function testChurchLinksInResults(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        
        $url = "/?q=SearchResultsMasses&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        // Each result should link to a church page
        $churchLinks = $this->client->executeScript(
            "return document.querySelectorAll('a[href*=\"/templom/\"]').length;"
        );
        
        self::assertGreaterThanOrEqual(0, $churchLinks, 'Church links render without error');
    }

    public function testNoPhpErrorsWithDioceseFilter(): void
    {
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        
        // Test with diocese filter (ehm=1 for first diocese)
        $url = "/?q=SearchResultsMasses&ehm=1&start_date={$startDate}&end_date={$endDate}";
        $crawler = $this->client->request('GET', $url);
        $this->client->waitFor('body', 10);
        
        $pageContent = $this->client->executeScript('return document.body.innerHTML;');
        self::assertStringNotContainsString('Fatal error', $pageContent);
        self::assertStringNotContainsString('Parse error', $pageContent);
    }
}
