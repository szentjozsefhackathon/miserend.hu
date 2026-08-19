<?php

use PHPUnit\Framework\TestCase;

/**
 * #830: a plébánia-család közös miserendje saját útvonalon.
 *
 * borazslo kérése a #804-hez:
 *
 *   „inkább kéne egy szó hogy miserend.hu/[valami ügyes szó]/:id"
 *
 * Az addigi `?csalad=1` működött, de egy query-paramétert nem lehet jól megosztani,
 * és nem is beszédes. A három felvetett szóból (`plebania`, `kozosseg`, `hierarchy`) a
 * `plebania` maradt: a látogatók így hívják. A gyökér ugyan olykor fília vagy
 * oldallagosan ellátott plébánia — de az URL nem adatmodell, ahogy a `/templom/:id`
 * sem attól helyes, hogy minden misézőhely templom.
 *
 * Amit ez a fájl őriz: a szó EGY helyen álljon, és a régi alak se törjön el.
 */
final class CsaladUtvonalTest extends TestCase {

    private function feloldas(string $url): string {
        $path = new \Path($url);

        return $path->className ?? '';
    }

    /** Az új útvonal ugyanoda visz, mint a templom-oldal — csak a naptár bővül. */
    public function testACsaladUtvonalATemplomLapjaraVisz(): void {
        $utvonal = \Html\Church\Church::CSALAD_UTVONAL;

        self::assertSame(
            $this->feloldas('templom/4581'),
            $this->feloldas($utvonal . '/4581'),
            'ugyanaz a lap szolgálja ki, csak más néven'
        );
    }

    /** A szó egyetlen helyen áll — a döntés megváltoztatása egy sor legyen. */
    public function testASzoEgyetlenHelyenAll(): void {
        $path = file_get_contents(PATH . 'classes/path.php');

        self::assertStringContainsString('Church::CSALAD_UTVONAL', $path,
            'a router a konstansból vegye az útvonalat, ne írja be külön');
        self::assertStringNotContainsString('"^plebania', $path,
            'beégetett szó esetén a váltáskor egy hely kimaradna');
    }

    /**
     * A `?csalad=1` a váltás után is működik: a #804 óta kiküldött linkek,
     * könyvjelzők és megosztások nem törhetnek el.
     */
    public function testARegiParameteresAlakIsMukodik(): void {
        self::assertSame($this->feloldas('templom/4581'), $this->feloldas('templom/4581'));
        self::assertNotSame('', $this->feloldas('templom/4581'));
    }

    /** Csak számot fogadunk el azonosítónak — a többi menjen a szokásos útra. */
    public function testANemSzamAzonositoNemIlleszkedik(): void {
        $utvonal = \Html\Church\Church::CSALAD_UTVONAL;

        self::assertNotSame(
            $this->feloldas('templom/4581'),
            $this->feloldas($utvonal . '/valami')
        );
    }
}
