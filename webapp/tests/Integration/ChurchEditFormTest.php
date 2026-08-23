<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #391: a `church/edit.php` a nyers `$this->input['church']` mezőcsoportból menti a
 * templom adatait. Ez a projekt legérzékenyebb írási útvonala, ezért a refaktor előtt
 * rögzítjük a MOSTANI viselkedést — a tesztnek előtte és utána is zöldnek kell lennie.
 *
 * Valódi HTTP-hívások bejelentkezett adminisztrátorként.
 */
class ChurchEditFormTest extends TestCase {

    private CsrfFormClient $client;
    private ?int $churchId = null;

    protected function setUp(): void {
        $this->client = new CsrfFormClient();
        if (!$this->client->login('admin', 'miserend')) {
            $this->markTestSkipped('Nem sikerült adminisztrátorként bejelentkezni.');
        }
        $this->churchId = $this->createChurch();
    }

    protected function tearDown(): void {
        if ($this->churchId !== null) {
            DB::table('church_relationships')->where('child_church_id', $this->churchId)->delete();
            DB::table('templomok')->where('id', $this->churchId)->delete();
            $this->churchId = null;
        }
    }

    private function createChurch(): int {
        return DB::table('templomok')->insertGetId([
            'nev'        => 'Szerkesztés teszt',
            'cim'        => 'Régi cím 1.',
            'frissites'  => '2020-01-01',
            'ok'         => 'i',
            'plebania'   => '', 'leiras' => '', 'megjegyzes' => '', 'misemegj' => '',
            'bucsu'      => '', 'adminmegj' => '', 'log' => '',
            'lat'        => 47.5, 'lon' => 19.0,
        ]);
    }

    /**
     * #873: a beküldés a CsrfFormClient-en át megy, ami úgy viselkedik, mint a böngésző:
     * betölti a szerkesztő lapot, elteszi a `csrf` sütit, és a POST-hoz mellékeli a
     * lapról kiolvasott tokent. Enélkül a mentés — helyesen — meg sem történne.
     */
    private function post(string $path, array $fields): string {
        return $this->client->post($path, $fields, true, '/templom/' . $this->churchId . '/edit');
    }

    private function churchRow() {
        return DB::table('templomok')->where('id', $this->churchId)->first();
    }

    /** A szerkesztő űrlap minimális, érvényes kitöltése. */
    private function editFields(array $override = []): array {
        return array_merge([
            'submit'               => 'Mentés',
            'modosit'              => 'adatok',
            'church[id]'           => (string) $this->churchId,
            'church[nev]'          => 'Szerkesztés teszt',
            'church[varos]'        => 'Budapest',
            'church[cim]'          => 'Új cím 2.',
            'church[lat]'          => '47.5',
            'church[lon]'          => '19.0',
        ], $override);
    }

    public function testMentesAtirjaAzAdatokat(): void {
        $this->post('/templom/' . $this->churchId . '/edit', $this->editFields());

        $row = $this->churchRow();
        $this->assertSame('Új cím 2.', $row->cim, 'A címnek frissülnie kellett volna.');
    }

    public function testTobbMezoEgyszerreMentheto(): void {
        $this->post('/templom/' . $this->churchId . '/edit', $this->editFields([
            'church[cim]'        => 'Harmadik cím',
            'church[megjegyzes]' => 'Teszt megjegyzés',
        ]));

        $row = $this->churchRow();
        $this->assertSame('Harmadik cím', $row->cim);
        $this->assertSame('Teszt megjegyzés', $row->megjegyzes);
    }

    /*
     * #496 / #497 / #498: a #798-ban itt egy teszt állt arról, hogy az űrlapról
     * beküldött `varos`/`megye`/`orszag` NEM írja felül a mentett adatot. Az
     * oszlopok azóta megszűntek, tehát nincs mit felülírni — a teszt tárgytalan.
     */

    /**
     * A LÉNYEG: idegen azonosítóval nem lehet más templomot átírni.
     */
    public function testIdegenAzonositovalNemMenthet(): void {
        $masik = $this->createChurch();
        try {
            $html = $this->post('/templom/' . $this->churchId . '/edit', $this->editFields([
                'church[id]'  => (string) $masik,
                'church[cim]' => 'Betört cím',
            ]));

            $this->assertStringContainsString('azonosítójával', $html, 'Hibát kell jeleznie.');
            $this->assertNotSame(
                'Betört cím',
                DB::table('templomok')->where('id', $masik)->value('cim'),
                'A másik templom adatai nem változhatnak.'
            );
        } finally {
            DB::table('templomok')->where('id', $masik)->delete();
        }
    }

    /**
     * Nem engedélyezett mezőt nem szabad átvenni az űrlapról.
     */
    public function testNemEngedelyezettMezoNemMentodik(): void {
        $eredetiOsmId = DB::table('templomok')->where('id', $this->churchId)->value('osmid');

        $this->post('/templom/' . $this->churchId . '/edit', $this->editFields([
            'church[osmid]' => '999999999',
        ]));

        $this->assertSame(
            $eredetiOsmId,
            DB::table('templomok')->where('id', $this->churchId)->value('osmid'),
            'Az osmid nincs az engedélyezett mezők között, nem módosulhat az űrlapról.'
        );
    }

    /**
     * `church` mezőcsoport nélkül nincs mentés, de hiba sincs.
     */
    public function testChurchFormNelkulNincsMentes(): void {
        $eredeti = $this->churchRow()->cim;

        $this->post('/templom/' . $this->churchId . '/edit', ['submit' => 'Mentés', 'modosit' => 'adatok']);

        $this->assertSame($eredeti, $this->churchRow()->cim);
    }
}
