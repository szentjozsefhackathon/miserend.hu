<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #884: két óra a leghosszabb megjelenített gyóntatás.
 *
 * A `getConfessions()` a le nem zárt szakaszt a toleranciával zárta le — 20 órával.
 * Élő státusznak („most van gyóntatás") ez elmegy, megjelenített eseménynek viszont
 * nem: a naptárban 20 órás gyóntatás-blokk keletkezett belőle. Mérve, valódi adaton:
 *
 *   GET /ajax/calendar/church/1252
 *     { id: "confession_1", title: "Gyóntatás", duration: 72000 }   <- 20 óra
 *
 * Ez az az eset, amikor a pap bekapcsolta és kikapcsolni elfelejtette. borazslo
 * döntése: legyen két óra.
 */
final class ConfessionDisplayLengthTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        DB::beginTransaction();

        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Gyóntatás teszt', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    /** Egy jelzés az érzékelőtől. `$mikor` a mostanihoz képest (strtotime-alak). */
    private function jelzes(string $status, string $mikor, int $localId = 1): void {
        DB::table('confessions')->insert([
            'deduplicationId' => bin2hex(random_bytes(8)) . '-' . bin2hex(random_bytes(4)),
            'church_id' => $this->churchId,
            'local_id' => $localId,
            'status' => $status,
            'timestamp' => date('Y-m-d H:i:s', strtotime($mikor)),
            'fulldata' => '{}',
        ]);
    }

    private function szakaszok($max = \Eloquent\Church::CONFESSION_MAX_DISPLAY): array {
        return \Eloquent\Church::find($this->churchId)->getConfessions('-40 days', '20 hours', $max);
    }

    /** A LÉNYEG: a le nem zárt szakasz két óra, nem húsz. */
    public function testAnUnclosedSessionIsCappedAtTwoHours(): void {
        $this->jelzes('ON', '-30 hours');

        $szakaszok = $this->szakaszok();

        self::assertCount(1, $szakaszok);
        self::assertSame(7200, $szakaszok[0]['duration'], 'ket ora, nem husz');
    }

    /** A korlát tényleg a beállított érték, nem véletlen szám. */
    public function testTheCapIsTheDeclaredConstant(): void {
        self::assertSame('2 hours', \Eloquent\Church::CONFESSION_MAX_DISPLAY);
    }

    /** A rövid, valóban lezárt szakaszhoz nem nyúlunk. */
    public function testAShortClosedSessionIsUntouched(): void {
        $this->jelzes('ON',  '-3 hours');
        $this->jelzes('OFF', '-3 hours +45 minutes');

        $szakaszok = $this->szakaszok();

        self::assertCount(1, $szakaszok);
        self::assertSame(45 * 60, $szakaszok[0]['duration']);
    }

    /** A hosszú, lezárt szakasz is visszavágódik — a vég a kezdethez igazodik. */
    public function testALongClosedSessionIsCappedAndTheEndMovesBack(): void {
        $this->jelzes('ON',  '-10 hours');
        $this->jelzes('OFF', '-5 hours');

        $szakaszok = $this->szakaszok();

        self::assertCount(1, $szakaszok);
        self::assertSame(7200, $szakaszok[0]['duration']);
        self::assertSame(
            strtotime($szakaszok[0]['start']) + 7200,
            strtotime($szakaszok[0]['end']),
            'a vegnek a kezdet + ket ora-nak kell lennie'
        );
    }

    /**
     * A tényleg folyamatban lévő szakasz marad „még tart".
     *
     * A panel abból tudja, hogy nincs `end` kulcsa. Ha ezt is lezárnánk, a látogató
     * azt látná, hogy vége, holott épp most gyóntatnak.
     */
    public function testAGenuinelyOngoingSessionStaysOpen(): void {
        $this->jelzes('ON', '-20 minutes');

        $szakaszok = $this->szakaszok();

        self::assertCount(1, $szakaszok);
        self::assertArrayNotHasKey('end', $szakaszok[0], 'meg tart — nincs vege');
        self::assertGreaterThan(0, $szakaszok[0]['duration']);
        self::assertLessThanOrEqual(7200, $szakaszok[0]['duration']);
    }

    /** Korlát nélkül a nyers, toleranciával lezárt érték jön — a kiskapu megmarad. */
    public function testWithoutACapTheRawToleranceValueComesBack(): void {
        $this->jelzes('ON', '-30 hours');

        $szakaszok = $this->szakaszok(null);

        self::assertCount(1, $szakaszok);
        self::assertSame(20 * 3600, $szakaszok[0]['duration'], 'korlat nelkul a 20 oras tolerancia');
    }

    /* ---- Az ÉLŐ állapot (a templomoldal jelzője) ---- */

    /**
     * A LÉNYEG: az elfelejtett kikapcsolás nem hirdet gyóntatást másnap délig.
     *
     * Eddig 20 óra volt a „még tart?" ablak, tehát egy reggel bekapcsolt és kikapcsolni
     * elfelejtett jelzés estig-másnapig azt írta ki, hogy „Most van gyóntatás a
     * helyszínen!". Ez a látogatót odaviszi hiába — ennél rosszabbat nem is tehetünk.
     */
    public function testAForgottenSwitchDoesNotAdvertiseConfession(): void {
        $this->jelzes('ON', '-5 hours');

        self::assertSame('OFF', \Eloquent\Church::find($this->churchId)->confessionStatus);
    }

    /** A valóban futó gyóntatás továbbra is látszik. */
    public function testAnOngoingConfessionIsStillOn(): void {
        $this->jelzes('ON', '-20 minutes');

        self::assertSame('ON', \Eloquent\Church::find($this->churchId)->confessionStatus);
    }

    /** A határ pontosan a konstans: két órán belül igen, azon túl nem. */
    public function testTheLiveWindowIsExactlyTwoHours(): void {
        $this->jelzes('ON', '-119 minutes');
        self::assertSame('ON', \Eloquent\Church::find($this->churchId)->confessionStatus);

        DB::table('confessions')->where('church_id', $this->churchId)->delete();

        $this->jelzes('ON', '-121 minutes');
        self::assertSame('OFF', \Eloquent\Church::find($this->churchId)->confessionStatus);
    }

    /** Kikapcsolt jelzés: nem gyóntatnak. */
    public function testAnOffSignalMeansNoConfession(): void {
        $this->jelzes('ON',  '-40 minutes');
        $this->jelzes('OFF', '-10 minutes');

        self::assertSame('OFF', \Eloquent\Church::find($this->churchId)->confessionStatus);
    }

    /**
     * Adat nélkül `false` — ez NEM ugyanaz, mint az 'OFF'.
     *
     * A `false` azt jelenti, hogy ebben a templomban nincs érzékelő; a felület ilyenkor
     * mást ír ki („Nincs jelzőkapcsoló"), mint amikor van, de épp nem gyóntatnak.
     */
    public function testWithoutAnySignalTheStatusIsFalse(): void {
        self::assertFalse(\Eloquent\Church::find($this->churchId)->confessionStatus);
    }

    /** Az élő ablak konstansból jön, és két óra. */
    public function testTheLiveToleranceIsTheDeclaredConstant(): void {
        self::assertSame('2 hours', \Eloquent\Church::CONFESSION_TOLERANCE);
    }

    /**
     * A „20 hours" nem maradhat beégetve sehol.
     *
     * A #884-ben pont az volt a baj, hogy ugyanaz az érték három helyen állt külön
     * (`church.php` kétszer, `ajax/calendar/church.php` egyszer). Ha valaki
     * visszacsempészi, itt derül ki, nem élesben.
     */
    public function testTheToleranceIsNotHardcodedAnywhere(): void {
        foreach (['classes/eloquent/church.php', 'classes/html/ajax/calendar/church.php'] as $fajl) {
            self::assertStringNotContainsString("'20 hours'", file_get_contents(PATH . $fajl),
                "beégetett tolerancia: $fajl");
        }
    }

    /** Több szakasz esetén mindegyikre külön érvényes a korlát. */
    public function testEveryPeriodIsCappedSeparately(): void {
        $this->jelzes('ON',  '-3 days');
        $this->jelzes('OFF', '-3 days +30 minutes');
        $this->jelzes('ON',  '-2 days');
        $this->jelzes('OFF', '-2 days +9 hours');

        $szakaszok = $this->szakaszok();

        self::assertCount(2, $szakaszok);
        self::assertSame(30 * 60, $szakaszok[0]['duration'], 'a rovid marad');
        self::assertSame(7200, $szakaszok[1]['duration'], 'a hosszu visszavagodik');
    }
}
