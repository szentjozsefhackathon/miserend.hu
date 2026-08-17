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

        /*
         * #833: MEGVÁRJUK a kép betöltését, nem egyetlen pillanatban mérünk.
         *
         * A teszt eddig azonnal a `request()` után kérdezte meg, hogy a logó
         * `complete`-e. A kép betöltése viszont a HTML-től FÜGGETLENÜL fut: terhelt
         * futtatón simán előfordul, hogy a DOM már kész, a kép még nem. Ez a CI-ban
         * alkalmankénti, megmagyarázhatatlan bukást adott — ami rosszabb, mint ha nem
         * is lenne teszt, mert elkezdi az ember a saját változásában keresni az okot.
         *
         * A `loading="lazy"` miatt ráadásul a böngésző szándékosan halogathatja.
         */
        $imageLoaded = false;
        $hatarido = microtime(true) + 10;
        while (microtime(true) < $hatarido) {
            $imageLoaded = (bool) $client->executeScript(
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
            if ($imageLoaded) {
                break;
            }
            usleep(200000);
        }

        self::assertTrue($imageLoaded, 'a logó képe tíz másodperc alatt sem töltődött be');
    }
}