<?php

use PHPUnit\Framework\TestCase;

/**
 * #747 a NYILVÁNOS belépési ponton mérve.
 *
 * A lefedés-logika tesztjei a privát metódusokat hívják Reflectionnel. Az viszont nem
 * bizonyítja, hogy a felhasználó tényleg látja a miséjét: a naptár, az iCal-export, a
 * templomoldal és a KERESŐINDEX mind a `generateMassPeriodInstancesForYears()`-en
 * keresztül jut adathoz —
 *
 *   webapp/classes/externalapi/elasticsearchapi.php:794   (keresőindex)
 *   webapp/classes/html/church/ical.php:24                (iCal)
 *   webapp/classes/eloquent/church.php:495                (templomoldal)
 *
 * — tehát a mérésnek is ott kell történnie. borazslo pont ezt kérte a #753-ban: „a
 * felhasználó be tudja állítani azt a miserendet amire gondolt ÉS a kereső azokat az
 * időpontokat találja meg, amit a felhasználó gondolt."
 *
 * A kizárás úgy hatályosul, hogy a kizárt időszak napjai `exdate`-be kerülnek. A hiba
 * tehát pontosan megfogható: javítás előtt a szűkebb időszak miséjének MIND A 63 napja
 * kizárásra került (06-30 – 08-31), vagyis egyetlen alkalma sem maradt — ez az
 * „átmásolom, és az eredeti eltűnik".
 *
 * Élő adatpáros: Nyári szünet (13, súly 3, 06-30–08-31) és Nyári időszámítás
 * (8, súly 5, 03-29–10-25) — a bővebb teljesen lefedi a szűkebbet.
 */
class CalMassPeriodCoverageEndToEndTest extends TestCase
{
    private const EV = 2026;

    /** Nyári szünet: a lefedett, szűkebb időszak. */
    private const SZUNET = 13;
    /** Nyári időszámítás: a lefedő, bővebb időszak. */
    private const IDOSZAMITAS = 8;

    protected function setUp(): void
    {
        parent::setUp();
        // A lefedés-vizsgálat statikus gyorsítótárból dolgozik; a mérés induljon tisztán.
        \Eloquent\CalMass::forgetPeriodCache();
    }

    private function mise(int $id, int $periodId, string $ora): \Eloquent\CalMass
    {
        $mass = new \Eloquent\CalMass();
        $mass->id = $id;
        $mass->church_id = 1;
        $mass->title = 'Szentmise';
        $mass->start_date = self::EV . '-07-05T' . $ora . ':00';
        $mass->rite = 'ROMAN_CATHOLIC';
        $mass->lang = 'hu';
        $mass->period_id = $periodId;
        $mass->rrule = ['freq' => 'weekly', 'byweekday' => ['SU'], 'dtstart' => self::EV . '-07-05T' . $ora . ':00'];
        return $mass;
    }

    /** @return array<int,array> a generált sor mise-azonosító szerint */
    private function generalt(array $masses): array
    {
        $sorok = \Eloquent\CalMass::generateMassPeriodInstancesForYears(
            $masses,
            [1 => 'Europe/Budapest'],
            [self::EV]
        );

        $szerint = [];
        foreach ($sorok as $sor) {
            $szerint[$sor['mass_id']] = $sor;
        }
        return $szerint;
    }

    private function kizartNapok(array $sor): int
    {
        return count($sor['rrule']['exdate'] ?? []);
    }

    /** A két mise együtt, ahogy élesben is egy templomon vannak. */
    private function aPáros(): array
    {
        return $this->generalt([
            $this->mise(9001, self::SZUNET, '08'),
            $this->mise(9002, self::IDOSZAMITAS, '10'),
        ]);
    }

    /**
     * A jegy magja. Javítás előtt itt 63 kizárt nap volt — a szűkebb időszak teljes
     * hossza —, tehát a misének egyetlen alkalma sem maradt.
     */
    public function testALefedettMisebolEgyetlenNapotSemVeszunkEl(): void
    {
        $sorok = $this->aPáros();

        self::assertArrayHasKey(9001, $sorok, 'a szűkebb időszak miséje eltűnt a generált adatból');
        self::assertSame(0, $this->kizartNapok($sorok[9001]),
            'a lefedett miséből napokat zártunk ki — a naptárban és a keresőben is hiányozna');
    }

    /** A helyet a LEFEDŐ mise adja át: az ő tartományából esik ki a szűkebb időszak. */
    public function testALefedoMiseLepHatra(): void
    {
        $sorok = $this->aPáros();

        self::assertArrayHasKey(9002, $sorok);
        self::assertGreaterThan(0, $this->kizartNapok($sorok[9002]),
            'a lefedő mise nem lépett hátra — a két mise duplán jelenne meg a szünet idején');
    }

    /** A szűkebb mise a teljes saját tartományát megtartja. */
    public function testALefedettMiseTartomanyaSertetlen(): void
    {
        $sorok = $this->aPáros();

        self::assertSame(self::EV . '-06-30', substr((string) $sorok[9001]['start_date'], 0, 10));
        self::assertSame(self::EV . '-08-31', substr((string) $sorok[9001]['end_date'], 0, 10));
    }

    /**
     * A TÁROLT `experiod` nem élheti túl az újraszámolást.
     *
     * Az oszlopban lévő érték egy régi implementáció maradéka: ma semmi nem számítja,
     * csak takarítja. Ha a számolás erre épülne rá (és csak hozzáadna), a javítás
     * élesben hatástalan maradna — a lefedett misén ottmaradna a régi, kiürítő kizárás.
     * Pontosan ez volt a helyzet a két érintett templomnál: a tárolt érték [7, 8].
     */
    public function testATaroltKizarasNemEliTulAzUjraszamolast(): void
    {
        $lefedett = $this->mise(9001, self::SZUNET, '08');
        // Ahogy élesben állt: a lefedő időszak kizárva, tehát a mise sehol nem látszik.
        $lefedett->experiod = [7, self::IDOSZAMITAS];

        $sorok = $this->generalt([$lefedett, $this->mise(9002, self::IDOSZAMITAS, '10')]);

        self::assertSame(0, $this->kizartNapok($sorok[9001]),
            'a tárolt kizárás átjött az újraszámoláson — élesben a mise továbbra sem látszana');
    }

    /**
     * Egyedül maradva sincs kizárása — a pár jelenléte tehát semmit nem vesz el tőle.
     * Ez zárja ki, hogy a fenti nulla csak azért jöjjön ki, mert a kizárás egyáltalán
     * nem működik ezen az ágon.
     */
    public function testEgyedulUgyanazAzEredmeny(): void
    {
        $egyedul = $this->generalt([$this->mise(9001, self::SZUNET, '08')]);
        \Eloquent\CalMass::forgetPeriodCache();
        $parban = $this->aPáros();

        self::assertSame(
            $this->kizartNapok($egyedul[9001]),
            $this->kizartNapok($parban[9001]),
            'a lefedő időszak jelenléte elvett napokat a szűkebb időszak miséjéből'
        );
    }
}
