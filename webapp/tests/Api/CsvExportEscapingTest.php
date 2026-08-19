<?php

use PHPUnit\Framework\TestCase;

/**
 * #829: a CSV-export idézőjelezése.
 *
 * A régi kód nyersen fűzte össze a mezőket, és ott állt mellette a figyelmeztetés:
 * „a szöveg nem tartalmazhatja az elválasztó karaktert, különben gond van". Csak épp
 * tartalmazza. Élő adaton mérve, az alapértelmezett `;` elválasztóval:
 *
 *     leírás pontosvesszővel:  2646
 *     leírás idézőjellel:      3059
 *     leírás sortöréssel:       499
 *
 * Vagyis a leírást is tartalmazó export gyakorlatilag használhatatlan volt: az oszlopok
 * elcsúsztak, a sortörés pedig új sort nyitott a táblázatban. És mindez NÉMÁN — a fájl
 * letöltődött, csak rossz volt benne az adat.
 *
 * A szabály az RFC 4180: idézőjel csak ott, ahol muszáj, és a belső idézőjel duplázódik.
 */
final class CsvExportEscapingTest extends TestCase {

    /** A `renderCsv()` az `Html\Api`-n ül, ami az API-objektumból dolgozik. */
    private function mezo($ertek, string $delimiter = ';'): string {
        $api = new \stdClass();
        $api->delimiter = $delimiter;

        // A konstruktor útvonalat elemez és átirányít — itt csak a metódus kell.
        $html = (new \ReflectionClass(\Html\Api::class))->newInstanceWithoutConstructor();
        $html->api = $api;

        $metodus = new \ReflectionMethod(\Html\Api::class, 'csvField');
        $metodus->setAccessible(true);

        return $metodus->invoke($html, $ertek);
    }

    // ---- amit békén hagyunk --------------------------------------------------

    /**
     * A felesleges idézőjelezés is elrontaná a meglévő fogyasztók dolgát: aki eddig
     * sima szöveget kapott, ne kapjon hirtelen idézőjeleset.
     */
    public function testAzArtatlanSzovegetNemIdezojelezi(): void {
        self::assertSame('Szent István-templom', $this->mezo('Szent István-templom'));
    }

    public function testAzUresErtekUresMarad(): void {
        self::assertSame('', $this->mezo(''));
        self::assertSame('', $this->mezo(null));
    }

    public function testASzamotNemBantja(): void {
        self::assertSame('42', $this->mezo(42));
    }

    // ---- amit meg KELL védeni ------------------------------------------------

    /** Ez a hiba magva: az elválasztó a szövegben új oszlopot nyitott. */
    public function testAzElvalasztotTartalmazoSzovegetIdezojelezi(): void {
        self::assertSame('"Szentmise; utána gyóntatás"', $this->mezo('Szentmise; utána gyóntatás'));
    }

    /** A sortörés új SORT nyitott a táblázatban — ez csúsztatta el a legtöbbet. */
    public function testASortoresIsIdezojelbeKerul(): void {
        self::assertSame("\"első sor\nmásodik sor\"", $this->mezo("első sor\nmásodik sor"));
    }

    public function testAKocsivisszaIsIdezojelbeKerul(): void {
        self::assertSame("\"a\r\nb\"", $this->mezo("a\r\nb"));
    }

    /** RFC 4180: a belső idézőjel duplázódik, különben lezárná a mezőt. */
    public function testABelsoIdezojeletDuplazza(): void {
        self::assertSame('"A ""Nagy"" templom"', $this->mezo('A "Nagy" templom'));
    }

    // ---- a választott elválasztó számít --------------------------------------

    /**
     * Az elválasztó kérésenként állítható. A vessző önmagában ártalmatlan `;`
     * elválasztónál — de `,` esetén már oszlopot nyitna. Élesben 29 templom neve
     * tartalmaz vesszőt.
     */
    public function testAVesszoCsakVesszosElvalasztonalSzamit(): void {
        self::assertSame('Szent Péter és Pál, társszékesegyház',
            $this->mezo('Szent Péter és Pál, társszékesegyház', ';'));

        self::assertSame('"Szent Péter és Pál, társszékesegyház"',
            $this->mezo('Szent Péter és Pál, társszékesegyház', ','));
    }

    // ---- tömbérték -----------------------------------------------------------

    /** A több nevű templom `names` mezője tömb — a CSV-ben egy cella egy érték. */
    public function testATombotOsszefuzi(): void {
        self::assertSame('Első Második', $this->mezo(['Első', 'Második']));
    }

    /** Ha az összefűzött tömbben elválasztó van, azt is védeni kell. */
    public function testAzOsszefuzottTombotIsIdezojelezi(): void {
        self::assertSame('"Első; név Második"', $this->mezo(['Első; név', 'Második']));
    }
}
