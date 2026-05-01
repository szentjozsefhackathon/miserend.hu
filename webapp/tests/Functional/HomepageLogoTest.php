<?php

use Symfony\Component\Panther\PantherTestCase;

final class HomepageLogoTest extends PantherTestCase {

    public function testLogoExistsAndImageIsLoaded(): void {
        $client = static::createPantherClient(
            array(
                'external_base_uri' => getenv('PANTHER_EXTERNAL_BASE_URI') ?: 'http://127.0.0.1:8000',
            ),
            array(),
            array(
                'browser' => static::CHROME,
            )
        );

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