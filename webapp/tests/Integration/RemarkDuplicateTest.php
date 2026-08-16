<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #755: „Dupla (tripla) észrevétel".
 *
 * Ugyanaz az észrevétel néha két-háromszor futott be, pár másodperc különbséggel.
 * Három ok vezet ide, és mind ugyanoda: a beküldő többször nyomja a gombot, a
 * /remark/add POST-ra nincs átirányítás (tehát az F5 újraküldi), illetve a
 * mobilkliens is ismételhet. Ezért kiszolgáló oldalon szűrünk.
 */
class RemarkDuplicateTest extends TestCase
{
    private int $churchId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();

        $this->churchId = DB::table('templomok')->insertGetId([
            'nev'        => '755 Teszt templom',
            'frissites'  => '2020-01-01',
            'ok'         => 'i',
            'plebania'   => '',
            'leiras'     => '',
            'megjegyzes' => '',
            'misemegj'   => '',
            'bucsu'      => '',
            'adminmegj'  => '',
            'log'        => '',
            'lat'        => 47.5,
            'lon'        => 19.0,
        ]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function addRemark(string $email, string $leiras, ?string $createdAt = null): int
    {
        return DB::table('remarks')->insertGetId([
            'church_id'  => $this->churchId,
            'allapot'    => 'u',
            'nev'        => 'Teszt Elek',
            'email'      => $email,
            'leiras'     => $leiras,
            'created_at' => $createdAt ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function testAnIdenticalRemarkSecondsLaterIsRecognisedAsDuplicate(): void
    {
        $this->addRemark('teszt@example.invalid', 'A vasárnapi mise 10 órakor van, nem 9-kor.');

        $found = \Eloquent\Remark::findRecentDuplicate(
            $this->churchId,
            'teszt@example.invalid',
            'A vasárnapi mise 10 órakor van, nem 9-kor.'
        );

        self::assertNotNull($found);
    }

    public function testDifferentTextIsNotADuplicate(): void
    {
        $this->addRemark('teszt@example.invalid', 'A vasárnapi mise 10 órakor van.');

        $found = \Eloquent\Remark::findRecentDuplicate(
            $this->churchId,
            'teszt@example.invalid',
            'A szombati mise is elmarad.'
        );

        self::assertNull($found, 'Más mondanivalót nem szabad elnyomni.');
    }

    public function testDifferentSenderIsNotADuplicate(): void
    {
        $this->addRemark('elso@example.invalid', 'Ugyanaz a hiba a miserendben.');

        $found = \Eloquent\Remark::findRecentDuplicate(
            $this->churchId,
            'masodik@example.invalid',
            'Ugyanaz a hiba a miserendben.'
        );

        self::assertNull($found, 'Két különböző ember ugyanazt is jelezheti.');
    }

    public function testDifferentChurchIsNotADuplicate(): void
    {
        $this->addRemark('teszt@example.invalid', 'Rossz a miserend.');

        $found = \Eloquent\Remark::findRecentDuplicate(
            $this->churchId + 100000,
            'teszt@example.invalid',
            'Rossz a miserend.'
        );

        self::assertNull($found);
    }

    /** Az ablakon kívül már valódi, új észrevétel — nem nyomjuk el. */
    public function testAnOldIdenticalRemarkIsNotADuplicate(): void
    {
        $old = date('Y-m-d H:i:s', time() - \Eloquent\Remark::DUPLICATE_WINDOW_SECONDS - 60);
        $this->addRemark('teszt@example.invalid', 'Megint rossz a miserend.', $old);

        $found = \Eloquent\Remark::findRecentDuplicate(
            $this->churchId,
            'teszt@example.invalid',
            'Megint rossz a miserend.'
        );

        self::assertNull($found);
    }

    /** Hiányos adatnál ne szűrjünk vakon — ott a mentés validációja dönt. */
    public function testIncompleteInputIsNeverTreatedAsDuplicate(): void
    {
        $this->addRemark('teszt@example.invalid', 'Valami.');

        self::assertNull(\Eloquent\Remark::findRecentDuplicate(null, 'teszt@example.invalid', 'Valami.'));
        self::assertNull(\Eloquent\Remark::findRecentDuplicate($this->churchId, '', 'Valami.'));
        self::assertNull(\Eloquent\Remark::findRecentDuplicate($this->churchId, 'teszt@example.invalid', ''));
    }
}
