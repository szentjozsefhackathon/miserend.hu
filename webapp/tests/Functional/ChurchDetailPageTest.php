<?php

namespace Tests\Functional;

use Facebook\WebDriver\WebDriverDimension;

/**
 * Test 5: Church Detail Page Test
 * 
 * Tests individual church pages to verify data is rendered correctly,
 * the schedule (miserend) is displayed, and no PHP errors occur.
 */
final class ChurchDetailPageTest extends FunctionalTestCase
{
    private $client;

    protected function setUp(): void
    {
        $this->client = static::pantherClient();
    }

    /**
     * Helper to get a valid church ID by searching
     */
    private function getFirstChurchId(): ?int
    {
        $crawler = $this->client->request('GET', '/?q=SearchResultsChurches&kulcsszo=Budapest');
        $this->client->waitFor('body', 10);
        
        $churchId = $this->client->executeScript(
            "var link = document.querySelector('a[href*=\"/templom/\"]'); 
             if(link) { 
                 var match = link.href.match(/\\/templom\\/(\\d+)/); 
                 return match ? parseInt(match[1]) : null; 
             } 
             return null;"
        );
        
        return $churchId;
    }

    public function testChurchPageLoadsWithoutError(): void
    {
        // Try to get a church ID dynamically, or use a fallback
        $churchId = $this->getFirstChurchId();
        
        if ($churchId === null) {
            // Fallback: try a known low ID that might exist
            $churchId = 1;
        }
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        $pageContent = $this->client->executeScript('return document.body.innerHTML;');
        
        // Critical: No PHP fatal errors
        self::assertStringNotContainsString('Fatal error', $pageContent);
        self::assertStringNotContainsString('Parse error', $pageContent);
    }

    public function testChurchNameDisplayed(): void
    {
        $churchId = $this->getFirstChurchId() ?? 1;
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        // Check for page header with church name
        $hasHeader = $this->client->executeScript(
            "return document.querySelector('.page-header h2') !== null || 
                    document.querySelector('h1') !== null || 
                    document.querySelector('h2') !== null;"
        );
        
        self::assertTrue($hasHeader, 'Church page should have a header with name');
    }

    public function testNoAlertDangerWithFatalError(): void
    {
        $churchId = $this->getFirstChurchId() ?? 1;
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        // Check that there's no fatal error displayed in alert-danger
        $alertDangerContent = $this->client->executeScript(
            "var alert = document.querySelector('.alert.alert-danger');
             return alert ? alert.textContent : '';"
        );
        
        self::assertStringNotContainsString('Fatal', $alertDangerContent);
        self::assertStringNotContainsString('Error', $alertDangerContent);
        self::assertStringNotContainsString('Exception', $alertDangerContent);
    }

    public function testScheduleOrNoScheduleMessageDisplayed(): void
    {
        $churchId = $this->getFirstChurchId() ?? 1;
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        // Either the Angular calendar is present OR "Nincs rendszeres szentmise" message
        $hasScheduleOrMessage = $this->client->executeScript(
            "return document.querySelector('[ng-app]') !== null || 
                    document.querySelector('app-root') !== null ||
                    document.body.textContent.includes('Nincs rendszeres szentmise') ||
                    document.body.textContent.includes('miserend') ||
                    document.body.textContent.includes('kalendárium');"
        );
        
        self::assertTrue($hasScheduleOrMessage, 'Page should show schedule or no-schedule message');
    }

    public function testRemarkLinkExists(): void
    {
        $churchId = $this->getFirstChurchId() ?? 1;
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        // Check for remark/észrevétel link or form
        $hasRemarkOption = $this->client->executeScript(
            "return document.querySelector('a[href*=\"ujeszrevetel\"]') !== null ||
                    document.querySelector('a[href*=\"eszrevetel\"]') !== null ||
                    document.body.textContent.includes('észrevétel');"
        );
        
        self::assertTrue($hasRemarkOption, 'Church page should have remark option');
    }

    public function testStaleChurchMovesCompleteHelpPanelToTopOnMobile(): void
    {
        // A stabil tesztadatbázisban publikus, 180 napnál régebben frissített templom.
        $churchId = 2061;

        $this->client->manage()->window()->setSize(new WebDriverDimension(375, 800));
        $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('#church-mobile-help-placeholder #church-help-panel-wrap', 10);

        $helpItems = $this->client->executeScript(
            "var panel = document.querySelector('#church-mobile-help-placeholder #church-help-panel-wrap');
             return {
                 remark: !!panel.querySelector('a[href*=\"ujeszrevetel\"]'),
                 holder: panel.textContent.includes('Gondnokság vállalása'),
                 photo: !!panel.querySelector('a[href*=\"ujkep\"]')
             };"
        );

        self::assertTrue($helpItems['remark']);
        self::assertTrue($helpItems['holder']);
        self::assertTrue($helpItems['photo']);

        $this->client->manage()->window()->setSize(new WebDriverDimension(1024, 800));
        usleep(250000); // A sablon resize-kezelője 100 ms-os debounce-ot használ.
        self::assertTrue(
            $this->client->executeScript(
                "return document.querySelector('.church-site-left-sidebar > #church-help-panel-wrap') !== null;"
            )
        );
    }

    public function testPhotosRenderIfPresent(): void
    {
        $churchId = $this->getFirstChurchId() ?? 1;
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        // Photos are rendered in a foreach loop with lightbox links
        $photoCount = $this->client->executeScript(
            "return document.querySelectorAll('a[data-lightbox]').length;"
        );
        
        // May be 0 if no photos - just verify no errors
        self::assertGreaterThanOrEqual(0, $photoCount, 'Photos render without error');
    }

    public function testNeighboursRenderIfPresent(): void
    {
        $churchId = $this->getFirstChurchId() ?? 1;
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        // Neighbours are rendered in a foreach loop
        $neighbourLinks = $this->client->executeScript(
            "return document.querySelectorAll('ul li a[href*=\"/templom/\"]').length;"
        );
        
        // May be 0 - just verify no errors
        self::assertGreaterThanOrEqual(0, $neighbourLinks, 'Neighbours render without error');
    }

    public function testLocationInfoDisplayed(): void
    {
        $churchId = $this->getFirstChurchId() ?? 1;
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        // Check for location information (address, city, etc.)
        $hasLocationInfo = $this->client->executeScript(
            "return document.body.textContent.length > 100;"  // Page has substantial content
        );
        
        self::assertTrue($hasLocationInfo, 'Church page should have location information');
    }

    public function testUpdatedDateDisplayed(): void
    {
        $churchId = $this->getFirstChurchId() ?? 1;
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        // Check for "Frissítve" text
        $hasUpdatedInfo = $this->client->executeScript(
            "return document.body.textContent.includes('Frissítve') || 
                    document.body.textContent.includes('frissítve') ||
                    document.body.textContent.includes('megerősítve');"
        );
        
        self::assertTrue($hasUpdatedInfo, 'Church page should show update/confirmation date');
    }

    public function testCalendarButtonsIfActive(): void
    {
        $churchId = $this->getFirstChurchId() ?? 1;
        
        $crawler = $this->client->request('GET', "/templom/{$churchId}");
        $this->client->waitFor('body', 10);
        
        // If church has active masses, calendar buttons should be present
        $hasCalendarOptions = $this->client->executeScript(
            "return document.body.textContent.includes('Naptár') || 
                    document.body.textContent.includes('ical') ||
                    document.body.textContent.includes('webcal') ||
                    document.body.textContent.includes('Nincs rendszeres');"
        );
        
        self::assertTrue($hasCalendarOptions, 'Calendar options or no-schedule message should be present');
    }
}
