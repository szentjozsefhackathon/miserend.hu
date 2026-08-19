<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #821: egyetlen rossz OSM-elem megállította a határ-ellenőrző cront.
 *
 * Élesben ez történt:
 *
 *   SQLSTATE[HY000]: General error: 1364 Field 'boundary' doesn't have a default value
 *   insert into `boundaries` (`osmtype`, `osmid`, `name`, ...)
 *   values (relation, 18357156, Dublin Metropolitan District Court, ...)
 *
 * Az ok: a lekérdezés az OSM `type=boundary` RELÁCIÓ-TÍPUSRA szűr, ami nem ugyanaz,
 * mint a `boundary=*` tag. Van olyan elem, amin az előbbi ott van, az utóbbi nincs —
 * a `boundaries.boundary` oszlop viszont NOT NULL, alapérték nélkül.
 *
 * A kivételt senki nem fogta el, ezért EGYETLEN ilyen elem megállította az egész
 * cront: élesben 21 órán át (6 sikertelen próbálkozás) egyetlen templom határa sem
 * frissült. A health oldal ezt „elakadt időzített feladat"-ként mutatta.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class BoundaryElementSaveTest extends TestCase {

    private int $osmIdAlap;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        // Az éles azonosítókkal ne ütközzünk: jóval a létezők fölött kezdünk.
        $this->osmIdAlap = ((int) DB::table('boundaries')->max('osmid')) + 1000;
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /** @param array<string,mixed> $tags */
    private function elem(array $tags, int $eltolas = 0): object {
        return (object) [
            'type' => 'relation',
            'id'   => $this->osmIdAlap + $eltolas,
            'tags' => (object) $tags,
        ];
    }

    // ---- a valódi éles eset --------------------------------------------------

    /**
     * A bejelentett elem: `type=boundary` reláció, `boundary` tag NÉLKÜL.
     * Korábban kivétellel elszállt; most egyszerűen kimarad.
     */
    public function testABoundaryTagNelkuliElemNemSzallEl(): void {
        $elem = $this->elem(['name' => 'Dublin Metropolitan District Court']);

        $eredmeny = \OSM::saveBoundaryElements([$elem]);

        self::assertSame([], $eredmeny);
    }

    public function testABoundaryTagNelkuliElemNemKerulAzAdatbazisba(): void {
        $elem = $this->elem(['name' => 'Dublin Metropolitan District Court']);

        \OSM::saveBoundaryElements([$elem]);

        self::assertSame(0, DB::table('boundaries')->where('osmid', $elem->id)->count(),
            'Besorolás nélküli sor csak az "Orphan Boundaries" számlálót hizlalná.');
    }

    /** Az üres string sem besorolás. */
    public function testAzUresBoundaryTagIsKimarad(): void {
        \OSM::saveBoundaryElements([$this->elem(['boundary' => '   ', 'name' => 'Semmi'])]);

        self::assertSame(0, DB::table('boundaries')->where('osmid', $this->osmIdAlap)->count());
    }

    // ---- a lényeg: a többi elem attól még megy -------------------------------

    /**
     * Ez a hiba igazi ára: a rossz elem nem csak magát vitte el, hanem az egész
     * futást — utána egyetlen templom határa sem frissült.
     */
    public function testARosszElemUtanAJoElemekMegMentodnek(): void {
        $eredmeny = \OSM::saveBoundaryElements([
            $this->elem(['name' => 'Rossz elem'], 0),
            $this->elem(['boundary' => 'administrative', 'admin_level' => 8, 'name' => 'Jó település'], 1),
        ]);

        self::assertCount(1, $eredmeny);
        self::assertSame(1, DB::table('boundaries')->where('osmid', $this->osmIdAlap + 1)->count());
    }

    // ---- a rendes eset változatlan -------------------------------------------

    public function testARendesHatarMentodik(): void {
        $eredmeny = \OSM::saveBoundaryElements([
            $this->elem(['boundary' => 'administrative', 'admin_level' => 8, 'name' => 'Szentendre']),
        ]);

        self::assertCount(1, $eredmeny);

        $sor = DB::table('boundaries')->where('osmid', $this->osmIdAlap)->first();
        self::assertSame('administrative', $sor->boundary);
        self::assertSame('Szentendre', $sor->name);
        self::assertSame(8, (int) $sor->admin_level);
    }

    /** #498: az országkód a kötőjeles OSM-tagból jön. */
    public function testAzOrszagkodAtjon(): void {
        \OSM::saveBoundaryElements([
            $this->elem(['boundary' => 'administrative', 'admin_level' => 2,
                         'name' => 'Magyarország', 'ISO3166-1' => 'hu']),
        ]);

        self::assertSame('HU', DB::table('boundaries')->where('osmid', $this->osmIdAlap)->value('iso3166_1'));
    }

    /** A magyar név elsőbbséget élvez — ez a régi viselkedés. */
    public function testAMagyarNevFelulirjaAzAlapNevet(): void {
        \OSM::saveBoundaryElements([
            $this->elem(['boundary' => 'administrative', 'name' => 'Wien', 'name:hu' => 'Bécs']),
        ]);

        self::assertSame('Bécs', DB::table('boundaries')->where('osmid', $this->osmIdAlap)->value('name'));
    }

    /**
     * Név nélküli, de besorolt határnál a besorolás lesz a név — enélkül a NOT NULL
     * `name` oszlopon hasalna el a mentés.
     */
    public function testNevNelkulABesorolasLeszANev(): void {
        \OSM::saveBoundaryElements([$this->elem(['boundary' => 'administrative'])]);

        self::assertSame('administrative', DB::table('boundaries')->where('osmid', $this->osmIdAlap)->value('name'));
    }

    /** Ugyanaz az elem kétszer: egy sor, nem kettő. */
    public function testUgyanazAzElemNemDuplazodik(): void {
        $elem = $this->elem(['boundary' => 'administrative', 'admin_level' => 8, 'name' => 'Szentendre']);

        \OSM::saveBoundaryElements([$elem]);
        \OSM::saveBoundaryElements([$elem]);

        self::assertSame(1, DB::table('boundaries')->where('osmid', $elem->id)->count());
    }

    public function testUresListaraUresValasz(): void {
        self::assertSame([], \OSM::saveBoundaryElements([]));
    }
}
