<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #671: a keresési eredmény mellé kiírt tájékoztatás arról, hány misézőhelyről
 * tudunk egyáltalán valamit az adott témában.
 *
 * Azért kell, mert a szűrő szinte üres adathalmazon dolgozik (a seedben egyetlen
 * misézőhelynek sincs `wheelchair` attribútuma), és a nulla találatot a felhasználó
 * hibának hinné.
 */
final class FacilityCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
    }

    /* Olyan aktív misézőhely, amiről MÉG nem tudunk semmit az adott témában. */
    private function churchWithoutAttribute(array $keys): int
    {
        $id = DB::table('templomok')
            ->where('ok', 'i')
            ->whereNull('deleted_at')
            ->whereNotIn('id', function ($q) use ($keys) {
                $q->select('church_id')->from('attributes')->whereIn('key', $keys);
            })
            ->value('id');

        if ($id === null) {
            self::markTestSkipped('Minden aktív misézőhelynek van már adata ebben a témában.');
        }
        return (int) $id;
    }

    private function setAttribute(int $churchId, string $key, string $value): void
    {
        \Eloquent\Attribute::updateOrCreate(
            ['church_id' => $churchId, 'key' => $key],
            ['value' => $value, 'fromOSM' => 0]
        );
    }

    /* Szűrő nélkül nincs mit magyarázni — ne szemeteljük tele az oldalt. */
    public function testNoMessageWithoutAnActiveFilter(): void
    {
        self::assertSame([], \Eloquent\Church::facilityCoverageMessages(false, false));
    }

    public function testOnlyTheFilteredTopicIsExplained(): void
    {
        $wheelchairOnly = \Eloquent\Church::facilityCoverageMessages(true, false);
        self::assertCount(1, $wheelchairOnly);
        self::assertStringContainsString('akadálymentes', $wheelchairOnly[0]);

        $glutenOnly = \Eloquent\Church::facilityCoverageMessages(false, true);
        self::assertCount(1, $glutenOnly);
        self::assertStringContainsString('glutén', $glutenOnly[0]);

        self::assertCount(2, \Eloquent\Church::facilityCoverageMessages(true, true));
    }

    /*
     * Akadálymentességnél a „nem akadálymentes" IS adat: azt is tudjuk a helyről.
     * Ezért minden kitöltött érték beleszámít, nem csak a pozitív.
     */
    public function testNegativeWheelchairValueCountsAsKnownData(): void
    {
        $church = $this->churchWithoutAttribute(['wheelchair']);
        $before = \Eloquent\Church::facilityCoverage()['wheelchair'];

        $this->setAttribute($church, 'wheelchair', 'no');

        self::assertSame($before + 1, \Eloquent\Church::facilityCoverage()['wheelchair']);
    }

    /* A két gluténmentes kulcs ugyanazt a misézőhelyet ne számolja kétszer. */
    public function testAChurchIsCountedOnceEvenWithBothGlutenKeys(): void
    {
        $church = $this->churchWithoutAttribute([
            \GlutenFreeCommunion::HOLIDAYS_KEY,
            \GlutenFreeCommunion::WEEKDAYS_KEY,
        ]);
        $before = \Eloquent\Church::facilityCoverage()['gluten_free'];

        $this->setAttribute($church, \GlutenFreeCommunion::HOLIDAYS_KEY, 'at_end');
        $this->setAttribute($church, \GlutenFreeCommunion::WEEKDAYS_KEY, 'always');

        self::assertSame($before + 1, \Eloquent\Church::facilityCoverage()['gluten_free']);
    }

    /* Csak az aktív (ok='i') misézőhelyek számítanak — a rejtettek nem kereshetők. */
    public function testHiddenChurchesAreNotCounted(): void
    {
        $hidden = DB::table('templomok')->where('ok', '!=', 'i')->value('id');
        if ($hidden === null) {
            self::markTestSkipped('Nincs nem-aktív templom a tesztadatban.');
        }

        $before = \Eloquent\Church::facilityCoverage()['wheelchair'];
        $this->setAttribute((int) $hidden, 'wheelchair', 'yes');

        self::assertSame($before, \Eloquent\Church::facilityCoverage()['wheelchair']);
    }
}
