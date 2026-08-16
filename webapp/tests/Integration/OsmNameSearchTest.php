<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #257: az OSM-ből gyűjtött neveket mindenütt használni kell.
 *
 * A templom neve az adatbázisban EGY oszlop (`nev`), az OSM viszont többet is tárol:
 * `name`, `name:hu`, `alt_name`, `old_name`, `official_name` — és ezek nyelvi
 * változatai. Épp ezek „ahogy a helybeliek ismerik" nevek: a jegy 4-es súgószövege is
 * ezt írja le („izbégi templom", ami már Szentendréhez tartozik).
 *
 * A katalógus keresője eddig kizárólag a helyi oszlopokra keresett, tehát a helyi néven
 * érkező látogató nem találta meg a templomot. A helyi oszlopokra szóló feltételek
 * megmaradnak: ahol nincs OSM-adat, a keresés ugyanúgy működik, mint eddig.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class OsmNameSearchTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $this->churchId = (int) DB::table('templomok')->max('id') + 1;

        $minta['id'] = $this->churchId;
        $minta['nev'] = 'Hivatalos Teszt-templom';
        $minta['ismertnev'] = '';
        $minta['varos'] = 'Tesztfalu';
        $minta['ok'] = 'i';
        DB::table('templomok')->insert($minta);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function osmNev(string $kulcs, string $ertek): void {
        DB::table('attributes')->insert([
            'church_id' => $this->churchId,
            'key'       => $kulcs,
            'value'     => $ertek,
            'fromOSM'   => 1,
        ]);
    }

    /** A katalógus keresőjének megfelelő lekérdezés. */
    private function talal(string $kulcsszo): bool {
        $minta = '%' . $kulcsszo . '%';

        return \Eloquent\Church::where('templomok.id', $this->churchId)
            ->where(function ($query) use ($minta) {
                $query->where('nev', 'LIKE', $minta)
                    ->orWhere('varos', 'LIKE', $minta)
                    ->orWhere('ismertnev', 'LIKE', $minta)
                    ->orWhereHas('attributes', function ($q) use ($minta) {
                        $q->where('value', 'LIKE', $minta)
                          ->where('key', 'REGEXP', '^(alt_|old_|official_)?name(:|$)');
                    });
            })
            ->exists();
    }

    /* A régi viselkedés megmarad. */
    public function testAHelyiNevreTovabbraIsTalal(): void {
        self::assertTrue($this->talal('Hivatalos Teszt'));
    }

    public function testATelepulesreTovabbraIsTalal(): void {
        self::assertTrue($this->talal('Tesztfalu'));
    }

    public function testOsmAdatNelkulNemTalalIdegenSzora(): void {
        self::assertFalse($this->talal('Izbégi'));
    }

    /* És ami új: az OSM-nevekre is. */
    public function testAzOsmNevreTalal(): void {
        $this->osmNev('name', 'Szent Miklós-templom');

        self::assertTrue($this->talal('Miklós'));
    }

    public function testAMagyarNevreTalal(): void {
        $this->osmNev('name:hu', 'Nagyboldogasszony-templom');

        self::assertTrue($this->talal('Nagyboldogasszony'));
    }

    /**
     * A helyi név ezt nem tartalmazza — épp ez a jegy lényege: a látogató azon a néven
     * keres, ahogy a templomot a faluban hívják.
     */
    public function testARegiNevreTalal(): void {
        $this->osmNev('old_name', 'Izbégi templom');

        self::assertTrue($this->talal('Izbégi'));
    }

    public function testAHivatalosNevreTalal(): void {
        $this->osmNev('official_name', 'Szűz Mária Szeplőtelen Szíve-plébániatemplom');

        self::assertTrue($this->talal('Szeplőtelen'));
    }

    public function testAzAlternativNevreTalal(): void {
        $this->osmNev('alt_name:hu', 'Öreg templom');

        self::assertTrue($this->talal('Öreg'));
    }

    /**
     * Csak a NEVEKRE keresünk. Az OSM sok más címkét is tárol ugyanabban a táblában
     * (nyitvatartás, felekezet, kerekesszék) — ha azokra is illesztenénk, a kereső
     * értelmetlen találatokat adna.
     */
    public function testNemNevJellegumCimkereNemTalal(): void {
        $this->osmNev('denomination', 'roman_catholic');

        self::assertFalse($this->talal('roman_catholic'));
    }

    public function testAzOpeningHoursSemNev(): void {
        $this->osmNev('opening_hours', 'Mo-Fr 08:00-18:00');

        self::assertFalse($this->talal('08:00'));
    }
}
