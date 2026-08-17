<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #819: a gondnokság öröklődése.
 *
 * borazslo célja: „elég a plébánosnak a legfölső plébániához kérnie a gondnokságot és
 * megkapja minden alárendelthez is a hozzáférést."
 *
 * A szerkesztési jog öröklődése már megvolt — de CSAK EGY SZINTIG. Az `ancestors`
 * beágyazott listát ad (minden elem a `children` alatt hordozza a saját őseit), a
 * `checkWriteAccess()` viszont csak a legfelső szintjét járta be. Plébánia →
 * oldallagosan ellátott plébánia → fília láncnál tehát a plébános NEM fért hozzá a
 * fíliához, pedig épp ez a jegy lényege.
 *
 * A másik két hiány a felületen és az értesítőkben volt: a gondnok-lista és a „van
 * aktív gondnok" felirat csak a közvetlen gondnokot ismerte, így egy saját gondnok
 * nélküli fília „nincs gondnok"-ot mutatott, és a búcsújáról SENKI nem kapott
 * értesítést.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class DerivedHoldersTest extends TestCase {

    private int $plebaniaId;
    private int $koztesId;
    private int $filiaId;
    private int $idegenId;
    private int $userId;

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();

        $this->plebaniaId = $this->templom('Teszt plébánia');
        $this->koztesId   = $this->templom('Teszt oldallagos');
        $this->filiaId    = $this->templom('Teszt fília');
        $this->idegenId   = $this->templom('Idegen templom');

        $this->kapcsolat($this->plebaniaId, $this->koztesId);
        $this->kapcsolat($this->koztesId, $this->filiaId);

        $this->userId = (int) DB::table('user')->max('uid') + 1;
        DB::table('user')->insert([
            'uid'           => $this->userId,
            'login'         => 'teszt_plebanos',
            'nev'           => 'Teszt Plébános',
            'email'         => 'teszt.plebanos@example.invalid',
            'notifications' => 1,
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function templom(string $nev): int {
        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $id = max(
            (int) DB::table('templomok')->max('id'),
            (int) DB::table('lookup_boundary_church')->max('church_id')
        ) + 1;
        $minta['id'] = $id;
        $minta['nev'] = $nev;
        $minta['ok'] = 'i';
        DB::table('templomok')->insert($minta);

        return $id;
    }

    private function kapcsolat(int $szuloId, int $gyerekId): void {
        DB::table('church_relationships')->insert([
            'parent_church_id' => $szuloId,
            'child_church_id'  => $gyerekId,
        ]);
    }

    private function gondnok(int $churchId, string $status = 'allowed'): void {
        DB::table('church_holders')->insert([
            'church_id'  => $churchId,
            'user_id'    => $this->userId,
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function templomObjektum(int $id): \Eloquent\Church {
        return \Eloquent\Church::find($id);
    }

    // ---- az ős-lánc bejárása -------------------------------------------------

    public function testAKozvetlenSzuloBenneVanALancban(): void {
        self::assertContains($this->koztesId, $this->templomObjektum($this->filiaId)->ancestorChurchIds());
    }

    /**
     * Ez volt a hiba: a bejárás egy szinten megállt, tehát a plébánia nem szerepelt a
     * fília ősei között — pedig két lépéssel feljebb ott van.
     */
    public function testANagyszuloIsBenneVanALancban(): void {
        self::assertContains($this->plebaniaId, $this->templomObjektum($this->filiaId)->ancestorChurchIds(),
            'A lánc nem állhat meg a közvetlen szülőnél.');
    }

    public function testAzIdegenTemplomNincsALancban(): void {
        self::assertNotContains($this->idegenId, $this->templomObjektum($this->filiaId)->ancestorChurchIds());
    }

    public function testALeszarmazottakLancaIsTeljes(): void {
        $leszarmazottak = $this->templomObjektum($this->plebaniaId)->descendantChurchIds();

        self::assertContains($this->koztesId, $leszarmazottak);
        self::assertContains($this->filiaId, $leszarmazottak, 'Az unoka is leszármazott.');
    }

    // ---- szerkesztési jog ----------------------------------------------------

    /** A jegy lényege: a legfölső plébánia gondnoka a fíliát is szerkesztheti. */
    public function testAPlebaniaGondnokaKetSzinttelLejjebbIsSzerkeszthet(): void {
        $this->gondnok($this->plebaniaId);

        self::assertTrue($this->templomObjektum($this->filiaId)->checkWriteAccess(new \User($this->userId)));
    }

    public function testAFiliaGondnokaNemSzerkesztheiAPlebaniat(): void {
        $this->gondnok($this->filiaId);

        self::assertFalse($this->templomObjektum($this->plebaniaId)->checkWriteAccess(new \User($this->userId)),
            'Az öröklődés csak lefelé megy.');
    }

    /** A nem engedélyezett (kért, visszavont) gondnokság nem ad jogot lefelé sem. */
    public function testACsakKertGondnoksagNemOroklodik(): void {
        $this->gondnok($this->plebaniaId, 'asked');

        self::assertFalse($this->templomObjektum($this->filiaId)->checkWriteAccess(new \User($this->userId)));
    }

    // ---- a származtatott gondnokok listája -----------------------------------

    public function testASzarmaztatottGondnokMegjelenikAFilianal(): void {
        $this->gondnok($this->plebaniaId);

        $szarmaztatott = $this->templomObjektum($this->filiaId)->derivedHolders();

        self::assertCount(1, $szarmaztatott);
        self::assertSame($this->userId, (int) $szarmaztatott->first()->user_id);
        self::assertSame($this->plebaniaId, (int) $szarmaztatott->first()->church_id,
            'Látszódnia kell, honnan örökli.');
    }

    /**
     * Aki közvetlen gondnok is, az ne jelenjen meg kétszer: ott a közvetlen a valódi
     * viszony, a származtatott csak ismételné.
     */
    public function testAKozvetlenGondnokNemJelenikMegSzarmaztatottkentIs(): void {
        $this->gondnok($this->plebaniaId);
        $this->gondnok($this->filiaId);

        self::assertCount(0, $this->templomObjektum($this->filiaId)->derivedHolders());
    }

    public function testGondnokNelkuliLancnalNincsSzarmaztatott(): void {
        self::assertCount(0, $this->templomObjektum($this->filiaId)->derivedHolders());
    }

    // ---- a „van aktív gondnok" számláló --------------------------------------

    /**
     * A felirat eddig az ellenkezőjét állította annak, ami igaz: a fília „nincs
     * gondnok"-ot mutatott, holott a plébánosa hozzáfér.
     */
    public function testASzamlaloBeleszamoljaASzarmaztatottat(): void {
        $this->gondnok($this->plebaniaId);

        self::assertSame(1, $this->templomObjektum($this->filiaId)->activeHolderCount());
    }

    public function testASzamlaloNemDuplazAKettosGondnokot(): void {
        $this->gondnok($this->plebaniaId);
        $this->gondnok($this->filiaId);

        self::assertSame(1, $this->templomObjektum($this->filiaId)->activeHolderCount());
    }

    public function testGondnokNelkulANullaMarad(): void {
        self::assertSame(0, $this->templomObjektum($this->filiaId)->activeHolderCount());
    }

    // ---- értesítés -----------------------------------------------------------

    /**
     * A `responsible['church']` csak a közvetlen gondnokságot ismeri, ezért egy saját
     * gondnok nélküli fíliáról egyetlen értesítő sem ment ki.
     */
    public function testAFelelosTemplomokKozottOttVanAFiliaIs(): void {
        $this->gondnok($this->plebaniaId);

        $ids = (new \User($this->userId))->responsibleChurchIds();

        self::assertContains($this->plebaniaId, $ids);
        self::assertContains($this->filiaId, $ids, 'A fília a plébánoshoz tartozik.');
    }

    public function testAFelelosTemplomokKozeNemKerulIdegen(): void {
        $this->gondnok($this->plebaniaId);

        self::assertNotContains($this->idegenId, (new \User($this->userId))->responsibleChurchIds());
    }
}
