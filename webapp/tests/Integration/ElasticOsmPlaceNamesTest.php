<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #496 / #497 / #498: az OSM-határláncból származó helynevek a keresőindexben.
 *
 * A kereső kulcsszavas ága nagy súllyal a `varos` mezőre megy (term 18, match 15,
 * wildcard 12), az pedig eddig KIZÁRÓLAG a régi `templomok.varos` oszlopból jött.
 * A `boundaries` mező csak belső azonosítókat tartalmazott, szövegesen
 * kereshetetlenül — vagyis egy csak az OSM-ben létező név egyáltalán nem volt
 * megtalálható. Éles példa: a budapesti XI. kerület alternatív neve "Újbuda".
 *
 * Ezek a tesztek azt rögzítik, hogy a régi érték SOSEM veszhet el (a kivezetés
 * több lépcsős, addig a két forrás egymás mellett él), és hogy az OSM-nevek
 * tényleg bekerülnek.
 */
class ElasticOsmPlaceNamesTest extends TestCase {

    private int $churchId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $this->churchId = (int) DB::table('templomok')->max('id') + 1;
        $minta['id'] = $this->churchId;
        $minta['nev'] = 'Helynév Teszt-templom';
        $minta['varos'] = 'Régi Város';
        $minta['ok'] = 'i';
        $minta['lat'] = 47.5;
        $minta['lon'] = 19.05;
        DB::table('templomok')->insert($minta);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * @param int $level OSM admin_level
     * @param string $nev a határ neve
     * @param string $altNev alternatív név (itt ülnek a magyar névváltozatok)
     * @param string $iso ISO3166-1 országkód, csak a 2-es szinten értelmes
     */
    private function hatar(int $level, string $nev, string $altNev = '', string $iso = ''): void {
        $id = DB::table('boundaries')->insertGetId([
            'boundary'    => 'administrative',
            'admin_level' => $level,
            'name'        => $nev,
            'alt_name'    => $altNev,
            'iso3166_1'   => $iso,
            'osmtype'     => 'relation',
            'osmid'       => 900000 + $level,
        ]);
        DB::table('lookup_boundary_church')->insert([
            'boundary_id' => $id,
            'church_id'   => $this->churchId,
        ]);
    }

    /** @return array<string,mixed> a templom Elasticsearch-dokumentuma */
    private function dokumentum(): array {
        return \Eloquent\Church::find($this->churchId)->toElasticArray();
    }

    /** @return array<int,string> a mező értékei tömbként, akkor is, ha string */
    private function ertekek($mezo): array {
        return is_array($mezo) ? $mezo : [$mezo];
    }

    public function testAzOsmTelepulesnevBekerulAKeresomezobe(): void {
        $this->hatar(8, 'Budapest');

        self::assertContains('Budapest', $this->ertekek($this->dokumentum()['varos']));
    }

    /** A magyar névváltozatok jellemzően az alt_name-ben ülnek — pl. "Újbuda". */
    public function testAzAlternativNevIsBekerul(): void {
        $this->hatar(9, 'XI. kerület', 'Újbuda');

        $varos = $this->ertekek($this->dokumentum()['varos']);

        self::assertContains('Újbuda', $varos,
            'Az alt_name nélkül a csak OSM-ben létező névre nulla találat jön.');
        self::assertContains('XI. kerület', $varos);
    }

    /**
     * A kivezetés több lépcsős: amíg az oszlop él, a régi értéknek is
     * kereshetőnek kell maradnia, különben a mai találatok elvesznének.
     */
    public function testARegiOszlopErtekeMegmarad(): void {
        $this->hatar(8, 'Budapest');

        self::assertContains('Régi Város', $this->ertekek($this->dokumentum()['varos']));
    }

    public function testAMegyeEsAzOrszagIsMegkapjaAzOsmNevet(): void {
        $this->hatar(2, 'Magyarország', '', 'HU');
        $this->hatar(6, 'Pest vármegye');

        $doc = $this->dokumentum();

        self::assertContains('Magyarország', $this->ertekek($doc['orszag']));
        self::assertContains('Pest vármegye', $this->ertekek($doc['megye']));
    }

    /** #498: a statisztika és az Angular naptár országKÓDot vár, nem nevet. */
    public function testAzOrszagkodAHatarbolJon(): void {
        $this->hatar(2, 'Magyarország', '', 'HU');

        self::assertSame('HU', $this->dokumentum()['orszagkod']);
    }

    public function testOrszaghatarNelkulAzOrszagkodUres(): void {
        $this->hatar(8, 'Budapest');

        self::assertSame('', $this->dokumentum()['orszagkod']);
    }

    /** Ugyanaz a név többször ne hizlalja a dokumentumot. */
    public function testAzAzonosNevekOsszevonodnak(): void {
        $this->hatar(8, 'Régi Város');

        $varos = $this->ertekek($this->dokumentum()['varos']);

        self::assertSame(count($varos), count(array_unique($varos)),
            'Duplikált helynév került a dokumentumba.');
    }

    /**
     * Ahol nincs mit hozzátenni, a mező alakja NE változzon tömbre — az ES ugyan
     * mindkettőt elfogadja, de a fölösleges alakváltás minden dokumentumot átír.
     */
    public function testEgyetlenErtekEseténAMezoStringMarad(): void {
        self::assertIsString($this->dokumentum()['varos']);
    }

    public function testHatarNelkulIsEloallADokumentum(): void {
        $doc = $this->dokumentum();

        self::assertSame('Régi Város', $doc['varos']);
        self::assertSame('', $doc['orszagkod']);
    }

    /**
     * A budapesti kerület-kibontás (Budapest XI. kerület -> arab számos alak +
     * "Budapest") eddig is működött; az OSM-nevek nem törhetik el.
     */
    public function testABudapestiKeruletKibontasMegmarad(): void {
        DB::table('templomok')->where('id', $this->churchId)
            ->update(['varos' => 'Budapest XI. kerület']);
        $this->hatar(9, 'XI. kerület', 'Újbuda');

        $varos = $this->ertekek($this->dokumentum()['varos']);

        self::assertContains('Budapest XI. kerület', $varos);
        self::assertContains('Budapest 11. kerület', $varos);
        self::assertContains('Budapest', $varos);
        self::assertContains('Újbuda', $varos);
    }
}
