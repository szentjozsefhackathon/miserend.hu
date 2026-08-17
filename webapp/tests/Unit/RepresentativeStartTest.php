<?php

use PHPUnit\Framework\TestCase;

/**
 * #832: melyik NAPON kezdődik ez a mise?
 *
 * Ahol csak ez a kérdés (mise-lista, rendezés, külső export), ott a teljes sorozat
 * legenerálása fölösleges — és félrevezető is. A `getOccurrences()` a szabály saját
 * tartományában keres, és ha az szűk, ÜRESET ad.
 *
 * Élesben mérve 4000 ismétlődő miséből 6 ilyen volt. Az `Api\ServiceTimes` náluk szó
 * szerint ezt írta ki: `(ERROR/BUG no start_date)`. A `Church` kódjában ott is állt a
 * jelzés: „Itt ez hiba, mert nem egy konkrét legenerált Periodban nézünk szét […]
 * Nekünk amúgy is csak azért kell, hogy tudjuk milyen napon kezdődik."
 *
 * A szabály maga megmondja: a `dtstart` az időt, a `byweekday` a napot.
 */
final class RepresentativeStartTest extends TestCase {

    private function kezdet(array $rrule): string {
        return (new \SimpleRRule($rrule))->representativeStart()->toDateTimeString();
    }

    private function nap(array $rrule): string {
        return (new \SimpleRRule($rrule))->representativeStart()->format('l');
    }

    /** `byweekday` nélkül maga a `dtstart` a válasz. */
    public function testNapMegkotesNelkulADtstartAKezdet(): void {
        self::assertSame('2026-03-04 07:00:00', $this->kezdet([
            'freq' => 'daily', 'dtstart' => '2026-03-04T07:00:00',
        ]));
    }

    /** Ha a `dtstart` már jó napra esik, nem mozdulunk. */
    public function testAMarJoNapotNemMozditjaEl(): void {
        // 2026-03-01 vasárnap.
        self::assertSame('Sunday', $this->nap([
            'freq' => 'weekly', 'dtstart' => '2026-03-01T09:00:00', 'byweekday' => ['SU'],
        ]));
    }

    /** Egyébként előrelépünk az első megengedett napra. */
    public function testAKovetkezoMegengedettNapraLep(): void {
        // 2026-03-04 szerda; a szabály vasárnapot kér -> 2026-03-08.
        self::assertSame('2026-03-08 09:00:00', $this->kezdet([
            'freq' => 'weekly', 'dtstart' => '2026-03-04T09:00:00', 'byweekday' => ['SU'],
        ]));
    }

    /** Az IDŐ a `dtstart`-ból marad — a nap változhat, az óra nem. */
    public function testAzIdoValtozatlanMarad(): void {
        self::assertStringEndsWith('18:30:00', $this->kezdet([
            'freq' => 'weekly', 'dtstart' => '2026-03-04T18:30:00', 'byweekday' => ['SA'],
        ]));
    }

    /** Több nap közül a legelőbb elérhető nyer — ez volt a régi viselkedés is. */
    public function testTobbNapKozulALegkozelebbi(): void {
        // 2026-03-04 szerda; a szabály hétfő és péntek -> péntek (03-06) a közelebbi.
        self::assertSame('Friday', $this->nap([
            'freq' => 'weekly', 'dtstart' => '2026-03-04T07:00:00', 'byweekday' => ['MO', 'FR'],
        ]));
    }

    // ---- a hiba, ami miatt ez az egész készült -------------------------------

    /**
     * A bejelentett eset: „minden hónap 4. vasárnapja", szűk tartománnyal. A
     * `getOccurrences()` itt üres, tehát a régi kód `start_date` NÉLKÜL hagyta a
     * misét — a külső export pedig hibaüzenetet írt ki a miserend helyére.
     */
    public function testASzukTartomanyuSzabalyIsAdKezdetet(): void {
        $rrule = [
            'freq' => 'monthly', 'dtstart' => '2026-11-29T18:00:00',
            'until' => '2026-12-24T23:59:59', 'bysetpos' => 4, 'byweekday' => ['SU'],
        ];

        self::assertSame([], (new \SimpleRRule($rrule))->getOccurrences(),
            'a szűk tartomány miatt tényleg nincs előfordulás — ez a kiindulás');
        self::assertSame('Sunday', $this->nap($rrule),
            'a NAP viszont a szabályból mindig kiolvasható');
    }

    /**
     * A `bysetpos` szándékosan nem számít: a kérdés a nap, nem a konkrét dátum — és a
     * negyedik vasárnap is vasárnap.
     */
    public function testABysetposNemValtoztatANapon(): void {
        self::assertSame('Sunday', $this->nap([
            'freq' => 'monthly', 'dtstart' => '2026-03-04T09:00:00',
            'bysetpos' => 2, 'byweekday' => ['SU'],
        ]));
    }

    /** #765: a `byweekday` stringként is érkezhet — azon se hasaljon el. */
    public function testAStringesNapMegkotestIsElfogadja(): void {
        self::assertSame('Sunday', $this->nap([
            'freq' => 'weekly', 'dtstart' => '2026-03-04T09:00:00', 'byweekday' => 'SU',
        ]));
    }

    /** Ismeretlen nap-jelölésnél inkább a `dtstart`, mint a semmi. */
    public function testIsmeretlenNapjelolesnelADtstartMarad(): void {
        self::assertSame('2026-03-04 09:00:00', $this->kezdet([
            'freq' => 'weekly', 'dtstart' => '2026-03-04T09:00:00', 'byweekday' => ['XX'],
        ]));
    }
}
