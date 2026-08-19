<?php

use PHPUnit\Framework\TestCase;

/**
 * A visszajelzés-beküldő végpont két mezője `string`-ként volt megadva, mellettük egy-egy
 * TODO-val („timestamp validation", „email validation"). Vagyis bármit elfogadtunk.
 *
 * Egyik sem formaság:
 *
 *  - az EMAIL-re válasz megy a beküldőnek. Rossz cím esetén a beküldő némán nem kap
 *    semmit, mi meg egy kézbesíthetetlen sort — pontosan azt a hibaosztályt, amit a
 *    user_pleaselogin-nál is takarítottunk.
 *  - a TIMESTAMP naptár-tudatos ellenőrzés nélkül átfordul: a `DateTime` a 2023-02-31-ből
 *    március 3-át, a 25:00-ból másnap 01:00-t csinál, tehát a bejegyzés MÁS NAPRA kerül,
 *    mint amit a beküldő állított.
 */
class ReportValidationTest extends TestCase {

    /** @dataProvider ervenyesIdopontok */
    public function testAzErvenyesIdopontotElfogadjuk(string $ertek): void {
        self::assertNull(\Validate::timestampError($ertek), $ertek);
    }

    public static function ervenyesIdopontok(): array {
        return [
            'szokőév'      => ['2024-02-29 12:34:56'],
            'éjfél'        => ['2026-01-01 00:00:00'],
            'nap vége'     => ['2026-12-31 23:59:59'],
        ];
    }

    /** @dataProvider ervenytelenIdopontok */
    public function testAzErvenytelenIdopontotElutasitjuk($ertek): void {
        self::assertNotNull(\Validate::timestampError($ertek), var_export($ertek, true));
    }

    public static function ervenytelenIdopontok(): array {
        return [
            'nem létező nap'   => ['2023-02-31 10:00:00'],
            'nem szökőév'      => ['2023-02-29 10:00:00'],
            'huszonötödik óra' => ['2026-01-01 25:00:00'],
            'hatvanadik perc'  => ['2026-01-01 10:60:00'],
            'csak dátum'       => ['2026-01-01'],
            'ISO alak'         => ['2026-01-01T10:00:00'],
            'üres'             => [''],
            'szöveg'           => ['tegnap'],
            'szám'             => [1737024896],
            'null'             => [null],
        ];
    }

    /** @dataProvider ervenyesCimek */
    public function testAzErvenyesEmailtElfogadjuk(string $ertek): void {
        self::assertNull(\Validate::emailError($ertek), $ertek);
    }

    public static function ervenyesCimek(): array {
        return [
            'egyszerű'      => ['valaki@example.com'],
            'aldomain'      => ['valaki@mail.example.com'],
            'pont a névben' => ['vezetek.kereszt@example.com'],
            'plusz jel'     => ['valaki+cimke@example.com'],
            'körülötte szóköz' => ['  valaki@example.com  '],
        ];
    }

    /** @dataProvider ervenytelenCimek */
    public function testAzErvenytelenEmailtElutasitjuk($ertek): void {
        self::assertNotNull(\Validate::emailError($ertek), var_export($ertek, true));
    }

    public static function ervenytelenCimek(): array {
        return [
            'nincs kukac'  => ['valaki.example.com'],
            'nincs domain' => ['valaki@'],
            'nincs név'    => ['@example.com'],
            'szóköz benne' => ['ez nem cim'],
            'üres'         => [''],
            'null'         => [null],
            'szám'         => [42],
        ];
    }

    /**
     * A végpont tényleg ezeket a szabályokat használja — enélkül a `\Validate` helyes
     * lehetne, miközben a beküldés változatlanul bármit elfogad.
     */
    public function testAVegpontAMegfeleloSzabalyokatHasznalja(): void {
        $mezok = (new \Api\Report())->fields;

        self::assertSame('timestamp', $mezok['timestamp']['validation']);
        self::assertSame('email', $mezok['email']['validation']);
    }
}
