<?php

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;

/**
 * #872: napi/heti összefoglaló az esemény-értesítőkből.
 *
 * borazslo mérése az éles adatbázison (30 nap): a legterheltebb címek 157-162 értesítőt
 * kaptak, a legrosszabb napokon 21-et EGYETLEN nap alatt, és 41 felhasználó már ki is
 * kapcsolta az értesítést. A döntése: „Legyen rögtön a B (azonnal / napi / heti), és
 * persze mindenki napira állítva."
 *
 * Amit ez a teszt rögzít: a halasztás nem veszít el értesítést, csak összevonja — és
 * aki az azonnalit választja, annak semmi nem változik.
 */
final class NotificationDigestTest extends TestCase {

    private int $uid;
    private int $churchId;

    protected function setUp(): void {
        DB::beginTransaction();

        $this->uid = (int) DB::table('user')->insertGetId([
            'login' => 'digest' . bin2hex(random_bytes(3)),
            'nev' => 'Digest Teszt',
            'email' => 'digest' . bin2hex(random_bytes(3)) . '@example.invalid',
            'jelszo' => 'x',
            'notifications' => 1,
            'notification_frequency' => 'daily',
        ]);

        $this->churchId = (int) DB::table('templomok')->insertGetId([
            'nev' => 'Digest teszt templom', 'ok' => 'i', 'lat' => 47.0, 'lon' => 19.0,
            'cim' => '', 'plebania' => '', 'leiras' => '', 'megjegyzes' => '',
            'misemegj' => '', 'bucsu' => '', 'kontakt' => '', 'kontaktmail' => '',
            'adminmegj' => '', 'log' => '', 'letrehozta' => '', 'modositotta' => '',
            'moddatum' => '0000-00-00 00:00:00', 'frissites' => date('Y-m-d'),
        ]);
    }

    protected function tearDown(): void {
        DB::rollBack();
    }

    private function cimzett(string $gyakorisag = 'daily'): stdClass {
        DB::table('user')->where('uid', $this->uid)->update(['notification_frequency' => $gyakorisag]);

        return DB::table('user')->where('uid', $this->uid)->first();
    }

    private function tetelek(): int {
        return DB::table('notification_digest_items')->where('uid', $this->uid)->count();
    }

    /**
     * A NEKÜNK szóló összefoglalók száma.
     *
     * A `sendDue()` szándékosan MINDENKINEK kiküldi az esedékes listáját, tehát a
     * suite többi tesztje által keltett tételek is kimennek. Globális darabszámot
     * számolni ezért félrevezető lenne — a saját címünkre szűkítünk.
     */
    private function nekemSzoloDigestek(): int {
        return DB::table('emails')
            ->where('type', 'user_digest')
            ->where('to', DB::table('user')->where('uid', $this->uid)->value('email'))
            ->count();
    }

    /** Az alapérték napi — a meglévő felhasználókra is, oszlop-alapértékkel. */
    public function testTheDefaultIsTheDailyDigest(): void {
        $user = DB::table('user')->where('uid', $this->uid)->first();

        self::assertSame('daily', $user->notification_frequency);
        self::assertSame('daily', \DigestQueue::gyakorisag($user));
    }

    /** A LÉNYEG: napi beállításnál nem megy külön levél, hanem sor kerül a listára. */
    public function testWithTheDailySettingTheNotificationIsQueued(): void {
        $halasztva = \DigestQueue::halaszt(
            $this->cimzett('daily'), 'remark', $this->churchId, 'Rossz a miserend', '/templom/1/eszrevetelek');

        self::assertTrue($halasztva, 'a hivonak NEM szabad azonnal kuldenie');
        self::assertSame(1, $this->tetelek());
    }

    /** Aki az azonnalit választja, annál semmi nem változik. */
    public function testWithTheInstantSettingNothingChanges(): void {
        $halasztva = \DigestQueue::halaszt(
            $this->cimzett('instant'), 'remark', $this->churchId, 'Rossz a miserend', '/x');

        self::assertFalse($halasztva, 'azonnali beallitasnal a hivo kuld');
        self::assertSame(0, $this->tetelek());
    }

    /**
     * Akit nem tudunk azonosítani, annak inkább menjen a levél.
     *
     * Az összefoglaló felhasználóhoz kötött; uid nélkül nincs kihez kötni. Ilyenkor a
     * régi, azonnali út a helyes válasz — az értesítés elvesztése sokkal rosszabb, mint
     * egy fölös levél.
     */
    public function testAnUnidentifiableRecipientIsNotQueued(): void {
        $nevtelen = (object) ['email' => 'valaki@example.invalid'];

        self::assertFalse(\DigestQueue::halaszt($nevtelen, 'remark', $this->churchId, 'x', '/x'));
    }

    /** Ismeretlen gyakoriság-érték: napi. Nem veszíthet el értesítést, csak késleltet. */
    public function testAnUnknownFrequencyFallsBackToDaily(): void {
        self::assertSame('daily', \DigestQueue::gyakorisag((object) ['notification_frequency' => 'sohanapjan']));
        self::assertSame('daily', \DigestQueue::gyakorisag((object) []));
    }

    /** A napi összefoglaló kimegy, és a tételek elkeltnek. */
    public function testTheDailyDigestIsSentAndTheItemsAreMarked(): void {
        \DigestQueue::halaszt($this->cimzett('daily'), 'remark', $this->churchId, 'Első', '/a');
        \DigestQueue::halaszt($this->cimzett('daily'), 'image', $this->churchId, 'Második', '/b');

        $elotte = $this->nekemSzoloDigestek();
        \DigestQueue::sendDue();

        self::assertSame($elotte + 1, $this->nekemSzoloDigestek(),
            'ket esemenyre EGY levelet varunk');
        self::assertSame(0, DB::table('notification_digest_items')
            ->where('uid', $this->uid)->whereNull('sent_at')->count());
    }

    /** A levél mindkét eseményt felsorolja — az összevonás nem veszíthet el semmit. */
    public function testTheDigestListsEveryEvent(): void {
        \DigestQueue::halaszt($this->cimzett('daily'), 'remark', $this->churchId, 'Elveszett mise', '/a');
        \DigestQueue::halaszt($this->cimzett('daily'), 'image', $this->churchId, 'Új fotó a toronyról', '/b');

        \DigestQueue::sendDue();

        $level = DB::table('emails')
            ->where('type', 'user_digest')
            ->where('to', DB::table('user')->where('uid', $this->uid)->value('email'))
            ->orderByDesc('id')->first();

        self::assertNotNull($level);
        self::assertStringContainsString('Elveszett mise', $level->body);
        self::assertStringContainsString('Új fotó a toronyról', $level->body);
        self::assertStringContainsString('Digest teszt templom', $level->body);
        self::assertStringContainsString('2 újdonság', $level->subject);
    }

    /** Aki közben kikapcsolta az értesítést, annak a felgyűlt lista sem megy ki. */
    public function testASwitchedOffUserGetsNothing(): void {
        \DigestQueue::halaszt($this->cimzett('daily'), 'remark', $this->churchId, 'x', '/a');
        DB::table('user')->where('uid', $this->uid)->update(['notifications' => 0]);

        $elotte = $this->nekemSzoloDigestek();
        \DigestQueue::sendDue();

        self::assertSame($elotte, $this->nekemSzoloDigestek());
        self::assertSame(1, DB::table('notification_digest_items')
            ->where('uid', $this->uid)->whereNull('sent_at')->count(), 'a tetel varakozzon tovabb');
    }

    /** A heti összefoglaló a hét megadott napján megy. */
    public function testTheWeeklyDigestGoesOnTheChosenDay(): void {
        $ma = date('Y-m-d H:i:s');

        self::assertTrue(\DigestQueue::hetiEsedekes(\DigestQueue::HETI_NAP, $ma));
        self::assertFalse(\DigestQueue::hetiEsedekes(\DigestQueue::HETI_NAP + 1, $ma));
        self::assertFalse(\DigestQueue::hetiEsedekes(\DigestQueue::HETI_NAP + 3, $ma));
    }

    /**
     * ...de egy kimaradt futás nem tolhatja a következő hétre.
     *
     * Enélkül egyetlen elmaradt hétfő azt jelentené, hogy a gondnok két hétig nem tud a
     * hozzá érkezett észrevételről.
     */
    public function testAnOldItemGoesOutEvenOffDay(): void {
        $regi = date('Y-m-d H:i:s', strtotime('-8 days'));

        self::assertTrue(\DigestQueue::hetiEsedekes(\DigestQueue::HETI_NAP + 2, $regi));
    }

    /** A cím levágva, HTML nélkül kerül a listára — a levélben egy sor lesz belőle. */
    public function testTheTitleIsStrippedAndTruncated(): void {
        \DigestQueue::halaszt($this->cimzett('daily'), 'remark', $this->churchId,
            '<b>Kalap</b> ' . str_repeat('a', 400), '/a');

        $tetel = DB::table('notification_digest_items')->where('uid', $this->uid)->first();

        self::assertStringStartsWith('Kalap a', $tetel->title);
        self::assertLessThanOrEqual(250, mb_strlen($tetel->title));
    }
}
