<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * #315 / borazslo észrevételei a PR-hez.
 *
 * Két dolgot mér:
 *
 *  1. A kiosztás a NYITOTT észrevétel mellett a FÜGGŐ javaslat-csomagot is kizárja.
 *     Mindkettő folyamatban lévő munka; aki azt feldolgozza, arra ne dolgozzon rá az
 *     önkéntes. A javaslat ráadásul a másik — várhatóan bővebb — beküldési csatorna.
 *
 *  2. Az inaktiválás VALÓDI tevékenységet mér. Korábban az `updates` tábla is a
 *     feltétel része volt, de abba egyedül az assignUpdates() ír, amikor kiosztja a
 *     templomokat: az a kiosztás naplója, nem a munkáé. Minden önkéntes hetente kapott
 *     hét sort, tehát a feltétel gyakorlatilag soha nem teljesült — a takarítás sosem
 *     fogott meg senkit.
 *
 * Tranzakcióban fut, tearDown-ban rollback.
 */
class CampaignVolunteerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
        $this->isolateFromExistingVolunteers();
    }

    /**
     * A kiosztás MINDEN önkéntesnek dolgozik, és a templom-mintát is az ő számukhoz
     * méretezi. Hogy a mérés a saját sorainkról szóljon, a meglévő önkénteseket a
     * mérés idejére kikapcsoljuk — a rollback visszaállítja.
     */
    private function isolateFromExistingVolunteers(): void {
        DB::table('user')->where('volunteer', 1)->update(['volunteer' => 0]);
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    private function makeVolunteer(): object {
        $uid = (int) DB::table('user')->max('uid') + 1;
        DB::table('user')->insert([
            'uid'       => $uid,
            'login'     => 'onkentes' . $uid,
            'jelszo'    => 'x',
            'jogok'     => 'user',
            'regdatum'  => date('Y-m-d H:i:s', strtotime('-2 years')),
            'lastlogin' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'email'     => 'onkentes' . $uid . '@example.com',
            'becenev'   => 'Önkéntes',
            'nev'       => 'Önkéntes Felhasználó',
            'volunteer' => 1,
        ]);
        return (object) ['uid' => $uid, 'login' => 'onkentes' . $uid];
    }

    private function isVolunteer(int $uid): bool {
        return (int) DB::table('user')->where('uid', $uid)->value('volunteer') === 1;
    }

    /**
     * Egy régen frissített, kiosztható templom.
     *
     * Meglévő sort másolunk: a `templomok` tábla tele van alapérték nélküli NOT NULL
     * oszloppal, azokat felsorolni törékeny volna. A `frissites` szándékosan a lehető
     * legrégebbi — a kiosztás `frissites` szerint rendez, tehát így biztosan az elejére
     * kerül, és nem a fixture négyezer templomán múlik, bekerül-e a mintába.
     */
    private function makeStaleChurch(): int {
        // #496: a `where('orszag', 12)` szűrő megszűnt az oszloppal együtt.
        $minta = (array) DB::table('templomok')->where('ok', 'i')->first();
        $id = (int) DB::table('templomok')->max('id') + 1;

        $minta['id'] = $id;
        $minta['nev'] = 'Teszt templom ' . $id;
        $minta['ok'] = 'i';
        $minta['frissites'] = '1900-01-01';

        DB::table('templomok')->insert($minta);

        /*
         * #498: a kiosztás magyarországi templomokra szűkít. Ez eddig az `orszag = 12`
         * oszlopon ment, most az országHATÁRON — a fixtúrának tehát kapnia kell egyet,
         * különben kiesik a mintából.
         */
        $orszagId = DB::table('boundaries')
            ->where('boundary', 'administrative')->where('admin_level', 2)
            ->where(function ($q) {
                $q->where('iso3166_1', 'HU')->orWhere('name', 'Magyarország');
            })->value('id');

        if ($orszagId === null) {
            $orszagId = DB::table('boundaries')->insertGetId([
                'boundary' => 'administrative', 'admin_level' => 2,
                'name' => 'Magyarország', 'iso3166_1' => 'HU',
                'osmtype' => 'relation', 'osmid' => 21335,
            ]);
        }
        DB::table('lookup_boundary_church')->insert(['boundary_id' => $orszagId, 'church_id' => $id]);

        return $id;
    }

    private function makePackage(int $churchId, string $state, ?int $senderUid = null, string $when = '-2 days'): void {
        DB::table('cal_suggestion_packages')->insert([
            'church_id'      => $churchId,
            'sender_name'    => 'Teszt Beküldő',
            'sender_email'   => 'bekuldo@example.com',
            'sender_user_id' => $senderUid,
            'state'          => $state,
            'created_at'     => date('Y-m-d H:i:s', strtotime($when)),
            'updated_at'     => date('Y-m-d H:i:s', strtotime($when)),
        ]);
    }

    /**
     * A kiosztható templomok lekérdezése az assignUpdates() belsejében van, ezért a
     * kiosztás EREDMÉNYÉN mérünk: kapott-e updates sort a templom.
     */
    private function assignedChurchIds(): array {
        \Campaign::assignUpdates();
        return DB::table('updates')
            ->where('timestamp', '>', date('Y-m-d H:i:s', strtotime('-1 minute')))
            ->pluck('tid')->all();
    }

    public function testAFuggoJavaslatuTemplomotNemOsztjukKi(): void {
        $this->makeVolunteer();
        $churchId = $this->makeStaleChurch();
        $this->makePackage($churchId, 'PENDING');

        $this->assertNotContains($churchId, $this->assignedChurchIds(),
            'függő javaslat mellett folyamatban lévő munka van a templomon');
    }

    /* A lezárt javaslat viszont nem akadály: az a munka már kész. */
    public function testALezartJavaslatuTemplomKiosztható(): void {
        $this->makeVolunteer();
        $churchId = $this->makeStaleChurch();
        $this->makePackage($churchId, 'ACCEPTED');

        $this->assertContains($churchId, $this->assignedChurchIds());
    }

    /*
     * Ez az a kör, ami korábban SOHA nem futott le: a kiosztás maga írt az `updates`
     * táblába, amit aztán tevékenységnek olvastunk.
     */
    public function testAzUpdatesSorokNemVedenekMegATakaritastol(): void {
        $user = $this->makeVolunteer();
        $churchId = $this->makeStaleChurch();
        DB::table('updates')->insert(['uid' => $user->uid, 'tid' => $churchId]);

        \Campaign::clearoutVolunteers();

        $this->assertFalse($this->isVolunteer($user->uid),
            'a kiosztás naplója nem tevékenység — az inaktív önkéntesnek ki kell esnie');
    }

    /* Aki beküldött javaslatot, az aktív: nem esik ki. */
    public function testAJavaslatotBekuldoOnkentesMarad(): void {
        $user = $this->makeVolunteer();
        $churchId = $this->makeStaleChurch();
        $this->makePackage($churchId, 'PENDING', $user->uid, '-3 days');

        \Campaign::clearoutVolunteers();

        $this->assertTrue($this->isVolunteer($user->uid),
            'a beküldött javaslat ugyanúgy tevékenység, mint az észrevétel');
    }

    /* A régi javaslat viszont nem véd: egy hónapnál idősebb beküldés már nem aktivitás. */
    public function testARegiJavaslatNemVed(): void {
        $user = $this->makeVolunteer();
        $churchId = $this->makeStaleChurch();
        $this->makePackage($churchId, 'PENDING', $user->uid, '-6 months');

        \Campaign::clearoutVolunteers();

        $this->assertFalse($this->isVolunteer($user->uid));
    }

    /* Az észrevétel változatlanul véd. */
    public function testAzEszrevetelTovabbraIsVed(): void {
        $user = $this->makeVolunteer();
        DB::table('remarks')->insert([
            'login'      => $user->login,
            'church_id'  => $this->makeStaleChurch(),
            'allapot'    => 'u',
            'leiras'     => 'Teszt észrevétel',
            'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'updated_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
        ]);

        \Campaign::clearoutVolunteers();

        $this->assertTrue($this->isVolunteer($user->uid));
    }
}
