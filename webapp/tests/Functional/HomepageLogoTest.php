<?php


use Tests\Functional\FunctionalTestCase;

final class HomepageLogoTest extends FunctionalTestCase {

    public function testLogoExistsAndImageIsLoaded(): void {
        $client = static::pantherClient();

        $crawler = $client->request('GET', '/');

        self::assertCount(
            1,
            $crawler->filter("div.logo a[href='/'] img[alt='Miserend oldal']")
        );

        $imageLoaded = $client->executeScript(
            <<<'JS'
            const logo = document.querySelector("div.logo a[href='/'] img[alt='Miserend oldal']");
            return Boolean(
                logo
                && logo.complete
                && typeof logo.naturalWidth !== 'undefined'
                && logo.naturalWidth > 0
            );
            JS
        );

        self::assertTrue($imageLoaded);
    }
}