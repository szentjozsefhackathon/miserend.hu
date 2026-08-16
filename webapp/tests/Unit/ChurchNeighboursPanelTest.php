<?php

use PHPUnit\Framework\TestCase;

/**
 * A "Szomszédos templomok" panel megjelenési küszöbe.
 *
 * A panel valaha KÉT részből állt: egy "Legközelebbi:" blokkból, ami mindig látszott,
 * és egy "10 km-en belül:" listából, aminek `length > 1` volt a feltétele. Amikor a
 * kettő egyetlen listává olvadt, a MÁSODIK blokk küszöbe maradt meg az EGÉSZ panelre.
 * Azóta egyetlen szomszédnál az egész panel eltűnt, pedig pont azt az egyet kellett
 * volna kiírnia — a `getNeighboursAttribute()` magát a templomot amúgy is kiszűri,
 * tehát az 1 hosszú lista egy valódi szomszédot jelent.
 */
class ChurchNeighboursPanelTest extends TestCase {

    private const SABLON = 'church/_panelneighbours.twig';

    /** @return array<int,array<string,mixed>> */
    private function szomszedok(int $darab): array {
        $lista = [];
        for ($i = 1; $i <= $darab; $i++) {
            $lista[] = [
                'id'         => 100 + $i,
                'nev'        => 'Szomszéd ' . $i,
                'ismertnev'  => 'Szomszéd ' . $i . ' temploma',
                'distance'   => 1000 * $i,
                'location'   => ['city' => ['name' => 'Budapest']],
            ];
        }
        return $lista;
    }

    private function render(int $darab): string {
        return $GLOBALS['twig']->render(self::SABLON, [
            'neighbours' => $this->szomszedok($darab),
            'location'   => ['city' => ['name' => 'Budapest']],
        ]);
    }

    private function talalatokSzama(string $html): int {
        return preg_match_all('#<a\s[^>]*href="/templom/\d+"#', $html);
    }

    /** A javítás lényege: egyetlen szomszéd is megjelenik. */
    public function testEgyetlenSzomszedIsMegjelenik(): void {
        $html = $this->render(1);

        self::assertSame(1, $this->talalatokSzama($html),
            'Egy szomszédnál a panel üresen maradt — visszajött a régi `length > 1` küszöb.');
        self::assertStringContainsString('/templom/101', $html);
    }

    public function testTobbSzomszedMindegyikeKikerul(): void {
        self::assertSame(5, $this->talalatokSzama($this->render(5)));
    }

    /** Szomszéd nélkül nincs mit kiírni. */
    public function testSzomszedNelkulNincsLista(): void {
        $html = $this->render(0);

        self::assertSame(0, $this->talalatokSzama($html));
        self::assertStringNotContainsString('<ul', $html);
    }

    /**
     * A `getNeighboursAttribute()` 10 találatnál levágja a listát, ezért a 10 hosszú
     * lista azt jelenti: "van még". A "..." tehát NEM elcsúszás, hanem a vágás jelzése.
     */
    public function testTizSzomszednalKikerulAFolytatasJelzes(): void {
        $html = $this->render(10);

        self::assertSame(10, $this->talalatokSzama($html), 'Mind a tíz szomszédnak ki kell kerülnie.');
        self::assertStringContainsString('...', $html);
    }

    public function testKilencSzomszednalMegNincsFolytatasJelzes(): void {
        self::assertStringNotContainsString('...', $this->render(9));
    }

    /**
     * A panelt a `church.twig` is lekapuzza, mielőtt beemelné — ha a két küszöb
     * elcsúszik egymástól, a panel megint eltűnhet ott, ahol itt már megjelenne.
     * Pontosan ez a fajta másolgatás okozta az eredeti hibát.
     */
    public function testAKetKuszobNemCsuszhatEl(): void {
        $minta = '#\{%\s*if\s+neighbours\|length\s*>\s*(\d+)\s*%\}#';
        $kuszobok = [];

        foreach (['church/church.twig', self::SABLON] as $sablon) {
            $forras = file_get_contents(PATH . 'templates/' . $sablon);
            self::assertMatchesRegularExpression($minta, $forras,
                $sablon . ': nem találom a szomszéd-küszöböt.');
            preg_match($minta, $forras, $m);
            $kuszobok[$sablon] = (int) $m[1];
        }

        self::assertSame([0, 0], array_values($kuszobok),
            'Mindkét helyen `> 0` a küszöb, különben egy szomszédnál megint eltűnik a panel: '
            . json_encode($kuszobok, JSON_UNESCAPED_SLASHES));
    }
}
