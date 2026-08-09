<?php

use PHPUnit\Framework\TestCase;

/**
 * #334: több nyelven bemutatott mise.
 *
 * Van szlovák-latin (3315, 3318), szlovák-magyar és német-magyar (2567) mise is, a `lang`
 * oszlopba viszont egyetlen kód fért. Mostantól vesszővel elválasztva több is elfér benne.
 *
 * Külön kapcsolótábla helyett azért vesszős lista, mert a `lang` értéket az
 * Elasticsearch-index, a sqlite export és a naptár-alkalmazás is egyszerű mezőként
 * olvassa — így mind működik tovább. A szétbontás szabálya egyetlen helyen él, hogy az
 * indexelés, a templom-nyelvek, a statisztika és a megjelenítés ne csúszhasson szét.
 */
class MassLanguagesTest extends TestCase {

    public function testEgyetlenNyelv(): void {
        $this->assertSame(['hu'], \Eloquent\CalMass::splitLanguages('hu'));
    }

    public function testTobbNyelv(): void {
        $this->assertSame(['sk', 'va'], \Eloquent\CalMass::splitLanguages('sk,va'));
        $this->assertSame(['de', 'hu'], \Eloquent\CalMass::splitLanguages('de,hu'));
    }

    public function testSzokozokAVesszokKorul(): void {
        $this->assertSame(['sk', 'va'], \Eloquent\CalMass::splitLanguages(' sk , va '));
    }

    public function testUresErtekek(): void {
        $this->assertSame([], \Eloquent\CalMass::splitLanguages(''));
        $this->assertSame([], \Eloquent\CalMass::splitLanguages(null));
        $this->assertSame([], \Eloquent\CalMass::splitLanguages(',,'));
    }

    public function testDuplikatumotNemAdVisszaKetszer(): void {
        $this->assertSame(['hu', 'de'], \Eloquent\CalMass::splitLanguages('hu,de,hu'));
    }

    /**
     * A visszafelé kompatibilitás a lényeg: a meglévő, egynyelvű sorok pontosan úgy
     * viselkednek, mint eddig.
     */
    public function testAMeglevoEgynyelvuErtekekValtozatlanok(): void {
        foreach (['hu', 'sk', 'de', 'ro', 'hr', 'en', 'va', 'ua', 'pl', 'it'] as $lang) {
            $this->assertSame([$lang], \Eloquent\CalMass::splitLanguages($lang));
        }
    }

    /**
     * A modell accessora ugyanazt adja, mint a statikus szétbontó.
     */
    public function testALangsAccessor(): void {
        $mass = new \Eloquent\CalMass();
        $mass->lang = 'sk,va';
        $this->assertSame(['sk', 'va'], $mass->langs);

        $mass->lang = 'hu';
        $this->assertSame(['hu'], $mass->langs);
    }
}
