<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #781: javaslat-beküldés több templomra egyszerre.
 *
 * A #506 óta a szerkesztő a plébánia és a fíliái miséit EGY naptárban mutatja, tehát egy
 * beküldés több templomot is érinthet. A javaslat-csomag viszont templomonként egy: a
 * `cal_suggestion_packages` táblában `church_id` van, és a jóváhagyó felület is így
 * gondolkodik.
 *
 * Szétbontás nélkül a fília miserendjére tett javaslat a plébánia csomagjába kerülne, és
 * a jóváhagyás is ott történne — a fília gondnoka nem is látná a saját templomát érintő
 * javaslatot.
 *
 * A csoportosítás kulcskérdése, hogy MELYIK templomhoz tartozik egy javaslat. Meglévő
 * misénél ezt az adatbázisból nézzük meg, nem a kliens szavából: a javaslat egy konkrét
 * misére vonatkozik, és hogy az melyik templomé, azt nem a beküldő dönti el.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class MultiChurchSuggestionTest extends TestCase {

    private const PLEBANIA = 1;
    private const FILIA = 2;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function makeMass(int $churchId): int {
        return DB::table('cal_masses')->insertGetId([
            'church_id'  => $churchId,
            'title'      => 'Teszt mise',
            'rite'       => 'ROMAN_CATHOLIC',
            'lang'       => 'hu',
            'start_date' => date('Y-m-d\TH:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A csoportosítás a valódi templom-feloldóval. */
    private function csoportok(array $suggestions, int $alapertelmezett = self::PLEBANIA): array {
        $osztaly = new ReflectionClass(\Html\Ajax\Calendar\Suggestions::class);
        $feloldo = $osztaly->getMethod('churchIdOfSuggestion');
        $feloldo->setAccessible(true);
        $peldany = $osztaly->newInstanceWithoutConstructor();

        return \Html\Ajax\Calendar\Suggestions::groupSuggestionsByChurch(
            $suggestions,
            $alapertelmezett,
            fn(array $s, int $a): int => $feloldo->invoke($peldany, $s, $a)
        );
    }

    /* Egy templom: pontosan a mai viselkedés — egyetlen csoport. */
    public function testEgyTemplomEsetenEgyCsoportKeletkezik(): void {
        $miseId = $this->makeMass(self::PLEBANIA);

        $csoportok = $this->csoportok([
            ['massId' => $miseId, 'massState' => 'MODIFIED'],
            ['massId' => $miseId, 'massState' => 'DELETED'],
        ]);

        self::assertSame([self::PLEBANIA], array_keys($csoportok));
        self::assertCount(2, $csoportok[self::PLEBANIA]);
    }

    /**
     * A lényeg: a fília miséjére tett javaslat a FÍLIA csoportjába kerül, akkor is, ha a
     * beküldés a plébánia felől indult.
     */
    public function testAFiliaMisejereTettJavaslatAFiliahozKerul(): void {
        $plebaniaMise = $this->makeMass(self::PLEBANIA);
        $filiaMise = $this->makeMass(self::FILIA);

        $csoportok = $this->csoportok([
            ['massId' => $plebaniaMise, 'massState' => 'MODIFIED'],
            ['massId' => $filiaMise, 'massState' => 'MODIFIED'],
        ]);

        $kulcsok = array_keys($csoportok);
        sort($kulcsok);
        self::assertSame([self::PLEBANIA, self::FILIA], $kulcsok);
        self::assertCount(1, $csoportok[self::PLEBANIA]);
        self::assertCount(1, $csoportok[self::FILIA]);
    }

    /**
     * A meglévő mise templomát az ADATBÁZISBÓL nézzük, nem a kliens szavából — különben
     * a beküldő eldönthetné, melyik templom javaslatai közé kerül a módosítása.
     */
    public function testAMeglevoMiseTemplomatNemAKlienstolFogadjukEl(): void {
        $filiaMise = $this->makeMass(self::FILIA);

        $csoportok = $this->csoportok([
            // A kliens a plébániát állítja, a mise viszont a fíliáé.
            ['massId' => $filiaMise, 'massState' => 'MODIFIED', 'changes' => ['churchId' => self::PLEBANIA]],
        ]);

        self::assertSame([self::FILIA], array_keys($csoportok));
    }

    /** Új misénél nincs mihez nyúlni: ott a beküldött templom számít. */
    public function testUjMisenelABekuldottTemplomSzamit(): void {
        $csoportok = $this->csoportok([
            ['massState' => 'NEW', 'changes' => ['churchId' => self::FILIA, 'title' => 'Új mise']],
        ]);

        self::assertSame([self::FILIA], array_keys($csoportok));
    }

    /** A snake_case alakot is elfogadjuk: a kliens mindkettőt küldheti. */
    public function testUjMisenelASnakeCaseAlakotIsElfogadjuk(): void {
        $csoportok = $this->csoportok([
            ['massState' => 'NEW', 'changes' => ['church_id' => self::FILIA]],
        ]);

        self::assertSame([self::FILIA], array_keys($csoportok));
    }

    /** Templom nélküli új javaslat az útvonal templomához tartozik. */
    public function testTemplomNelkuliUjJavaslatAzUtvonalTemplomahozKerul(): void {
        $csoportok = $this->csoportok([['massState' => 'NEW', 'changes' => ['title' => 'Új mise']]]);

        self::assertSame([self::PLEBANIA], array_keys($csoportok));
    }

    /** Törölt/ismeretlen mise-azonosítónál sem veszhet el a javaslat. */
    public function testIsmeretlenMiseEseténAzUtvonalTemplomaAzAlap(): void {
        $csoportok = $this->csoportok([['massId' => 999999999, 'massState' => 'DELETED']]);

        self::assertSame([self::PLEBANIA], array_keys($csoportok));
    }

    /** A beküldött sorrend megmarad a csoportokon belül. */
    public function testACsoportonBeluliSorrendMegmarad(): void {
        $mise = $this->makeMass(self::FILIA);

        $csoportok = $this->csoportok([
            ['massId' => $mise, 'massState' => 'MODIFIED', 'periodId' => 10],
            ['massId' => $mise, 'massState' => 'MODIFIED', 'periodId' => 20],
        ]);

        self::assertSame([10, 20], array_column($csoportok[self::FILIA], 'periodId'));
    }

    /** Üres beküldésből nem keletkezik csoport — a hívó ág külön kezeli. */
    public function testUresBekuldesbolNincsCsoport(): void {
        self::assertSame([], $this->csoportok([]));
    }
}
