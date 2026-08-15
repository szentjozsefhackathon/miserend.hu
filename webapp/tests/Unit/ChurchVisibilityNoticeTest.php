<?php

use PHPUnit\Framework\TestCase;

/**
 * #409: „Templom gondnok kezelhesse saját nem publikus templomát."
 *
 * A jogosultság maga rendben volt: a Church::checkWriteAccess() SOHA nem nézi az `ok`
 * mezőt, tehát egy `allowed` gondnok eddig is megnyithatta és szerkeszthette a
 * nem-publikus templomát. Csak épp azt olvasta közben, hogy „Csak adminisztrátorok
 * számára látható ez az oldal" — ami neki nem igaz, és pont az ellenkezőjét hitette el.
 *
 * A visibilityNotice() dönti el, kinek mit írunk ki. Tiszta függvény, itt közvetlenül
 * tesztelhető.
 */
class ChurchVisibilityNoticeTest extends TestCase {

    public function testNyilvanosTemplomnalNincsUzenet(): void {
        $this->assertNull(\Eloquent\Church::visibilityNotice('i', false, false));
        $this->assertNull(\Eloquent\Church::visibilityNotice('i', true, true));
        $this->assertNull(\Eloquent\Church::visibilityNotice('i', false, true));
    }

    public function testAdminnakAMegszokottSzoveg(): void {
        [$uzenet, $szint] = \Eloquent\Church::visibilityNotice('f', true, true);
        $this->assertStringContainsString('Csak adminisztrátorok számára látható', $uzenet);
        $this->assertSame('warning', $szint);

        [$uzenet] = \Eloquent\Church::visibilityNotice('n', true, true);
        $this->assertStringContainsString('le van tiltva', $uzenet);
        $this->assertStringContainsString('Csak adminisztrátorok számára látható', $uzenet);
    }

    /**
     * A lényeg: a gondnoknak NEM azt mondjuk, hogy csak adminisztrátorok látják.
     */
    public function testGondnoknakNemAztMondjukHogyNemLathatja(): void {
        [$uzenet, $szint] = \Eloquent\Church::visibilityNotice('f', false, true);

        $this->assertStringNotContainsString('Csak adminisztrátorok', $uzenet);
        $this->assertStringContainsString('nem nyilvános', $uzenet);
        $this->assertStringContainsString('szerkeszd', $uzenet);
        $this->assertSame('info', $szint, 'Ez nem hiba, csak állapot — ne piros figyelmeztetés legyen.');
    }

    public function testLetiltottTemplomGondnokanakSajatSzoveg(): void {
        [$uzenet, $szint] = \Eloquent\Church::visibilityNotice('n', false, true);

        $this->assertStringNotContainsString('Csak adminisztrátorok', $uzenet);
        $this->assertStringContainsString('le van tiltva', $uzenet);
        $this->assertStringContainsString('gondnokként', $uzenet);
        $this->assertSame('warning', $szint, 'A letiltás tényleg figyelmeztetés.');
    }

    /**
     * Írási jog nélkül ide amúgy sem lehet bejutni (checkReadAccess), de ha mégis,
     * maradjon a semleges szöveg.
     */
    public function testIrasiJogNelkulAMegszokottSzoveg(): void {
        [$uzenet] = \Eloquent\Church::visibilityNotice('f', false, false);
        $this->assertStringContainsString('Csak adminisztrátorok számára látható', $uzenet);
    }

    /**
     * Ismeretlen `ok` értéknél se maradjon néma az oldal.
     */
    public function testIsmeretlenAllapotnalIsAdUzenetet(): void {
        $this->assertNotNull(\Eloquent\Church::visibilityNotice('x', false, true));
    }
}
