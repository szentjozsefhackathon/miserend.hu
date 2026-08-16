<?php

use PHPUnit\Framework\TestCase;

/**
 * #497: a budapesti kerületek névváltozatai a keresőben.
 *
 * borazslo:
 *
 *   „a keresőbe a rendes nevét kell írni, hogy »II. kerület« nem pedig, hogy
 *    »Budapest, II. kerület« vagy »2. kerület«, stb. De szerintem ezt nem adatbázis
 *    féle szinten kéne kezelni, hanem a kereső autocomplete részében lehetne
 *    varázsolni, nem?"
 *
 * Pontosan ez történik: a beírt szövegből további keresési mintákat gyártunk, az
 * adatbázisban tárolt neveket nem bántjuk.
 */
class KeruletVariansokTest extends TestCase {

    /** @return array<int,string> */
    private function variansok(string $szoveg): array {
        return \Html\Ajax\Autocompletecombined::keruletVariansok($szoveg);
    }

    private function elsoNev(string $szoveg): ?string {
        $v = $this->variansok($szoveg);
        return $v[0] ?? null;
    }

    public function testABudapestElotagElhagyhato(): void {
        self::assertSame('II. kerület', $this->elsoNev('Budapest, II. kerület'));
        self::assertSame('II. kerület', $this->elsoNev('Budapest II. kerület'));
    }

    public function testAzArabSzamRomaivaValik(): void {
        self::assertSame('II. kerület', $this->elsoNev('2. kerület'));
        self::assertSame('XI. kerület', $this->elsoNev('11. kerület'));
        self::assertSame('XXIII. kerület', $this->elsoNev('23. kerület'));
    }

    public function testAPontElhagyhato(): void {
        self::assertSame('II. kerület', $this->elsoNev('2 kerület'));
        self::assertSame('II. kerület', $this->elsoNev('II kerület'));
    }

    public function testAKerRovidites(): void {
        self::assertSame('II. kerület', $this->elsoNev('II. ker'));
        self::assertSame('II. kerület', $this->elsoNev('ii. ker.'));
    }

    public function testARomaiSzamOnmagabanIsEleg(): void {
        self::assertSame('XI. kerület', $this->elsoNev('XI'));
    }

    /**
     * A puszta arab szám NEM elég: lehet irányítószám-részlet, házszám vagy bármi.
     * Ha ebből kerületre következtetnénk, minden „11"-re a XI. kerületet ajánlanánk.
     */
    public function testAPusztaArabSzamNemKerulet(): void {
        self::assertSame([], $this->variansok('11'));
        self::assertSame([], $this->variansok('2'));
    }

    public function testNemLetezoKeruletnelNincsVariant(): void {
        self::assertSame([], $this->variansok('99. kerület'));
        self::assertSame([], $this->variansok('0. kerület'));
    }

    public function testHelynevreNemAdVariant(): void {
        self::assertSame([], $this->variansok('Szentendre'));
        self::assertSame([], $this->variansok('kerület'));
    }

    /**
     * SZÓHATÁRRA kell illeszteni: a `%II. kerület%` a XII.-t és a XXII.-t is
     * megfogná, mert azok tartalmazzák a „II. kerület" részletet — egyetlen kerület
     * keresésére húsz találat jött.
     */
    public function testAMintakSzohatarraIllenek(): void {
        $mintak = $this->variansok('II. kerület');

        self::assertContains('II. kerület', $mintak, 'a pontos egyezésnek benne kell lennie');
        foreach ($mintak as $minta) {
            self::assertStringNotContainsString('%II. kerület%', $minta,
                'a mindkét oldalon nyitott minta a XII.-t és XXII.-t is megfogná');
        }
    }

    /** A régi, oszlopokból gyártott boundary neve „Budapest II. kerület". */
    public function testASzokozUtaniAlakotIsKeresi(): void {
        self::assertContains('% II. kerület%', $this->variansok('II. kerület'));
    }
}
