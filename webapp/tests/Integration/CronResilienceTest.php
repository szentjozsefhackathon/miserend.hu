<?php

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Az éles /health szerint a \User::deleteNonActivatedUsers() 2026-03-27 óta nem futott le
 * sikeresen. Az ok egy sosem definiált $ids2delete változó volt a metódus végén: a
 * whereIn(null) PHP 8-ban TypeError-t dob, ami \Error és nem \Exception, ezért a
 * cron-futtató catch-e sem fogta el — a job némán fatalra futott, miután már törölte a
 * felhasználókat és kiküldte az értesítőket.
 */
class CronResilienceTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * A hibás sor akkor is lefutott, ha egyetlen törlendő felhasználó sem volt — ezért
     * itt szándékosan kiürítjük a jelölteket: így a teszt mellékhatás (törlés, kiküldött
     * email) nélkül fogja meg pontosan a hibát.
     */
    public function testDeleteNonActivatedUsersNemSzallElHaNincsTorlendo(): void {
        DB::table('user')
            ->where('lastlogin', '0000-00-00 00:00:00')
            ->update(['lastlogin' => date('Y-m-d H:i:s')]);

        $this->assertSame(
            0,
            \User::deleteNonActivatedUsers(),
            'Nincs törlendő felhasználó, tehát 0-t kell visszaadnia — és nem fatalra futnia.'
        );
    }

    /**
     * A NULL deadline_at-es sorok soha nem kerülnek sorra (a scopeNextJobs
     * `deadline_at < NOW()` feltétele NULL-ra nem igaz), ezért állt évek óta az éles
     * \Crons::rollPeriodYears(). A healUnschedulable() ezt teszi esedékessé.
     */
    public function testHealUnschedulableEsedekesseTesziANullDeadlineSorokat(): void {
        $cron = new \Eloquent\Cron();
        $cron->class = '\TesztOsztaly';
        $cron->function = 'tesztFuggveny';
        $cron->frequency = '1 day';
        $cron->attempts = 0;
        $cron->deadline_at = null;
        $cron->save();

        $this->assertSame(
            0,
            \Eloquent\Cron::nextJobs()->where('id', $cron->id)->count(),
            'NULL deadline_at mellett a munka nem kerülhet sorra.'
        );

        $healed = \Eloquent\Cron::healUnschedulable();
        $this->assertContains('\TesztOsztaly->tesztFuggveny()', $healed);

        $this->assertSame(
            1,
            \Eloquent\Cron::nextJobs()->where('id', $cron->id)->count(),
            'A gyógyítás után esedékesnek kell lennie.'
        );
    }

    public function testHealUnschedulableNemToljaElAMukodoUtemezest(): void {
        $deadline = date('Y-m-d H:i:s', strtotime('+3 hours'));
        $cron = new \Eloquent\Cron();
        $cron->class = '\TesztOsztaly';
        $cron->function = 'epFuggveny';
        $cron->frequency = '1 day';
        $cron->attempts = 0;
        $cron->deadline_at = $deadline;
        $cron->save();

        \Eloquent\Cron::healUnschedulable();

        $this->assertSame(
            $deadline,
            (string) \Eloquent\Cron::find($cron->id)->deadline_at,
            'Akinek van deadline-ja, azt nem szabad átírni.'
        );
    }

    /**
     * A registry (#638) az egyetlen forrás arra, mely cronoknak kell léteznie. A
     * szentségimádás-importáló élesben évek óta fut (id 39), de a listából kimaradt —
     * egy újrahúzott adatbázisban tehát soha nem jött volna létre.
     */
    public function testSzentsegimadasCronBenneVanARegistryben(): void {
        $registry = \Eloquent\Cron::registry();
        $found = array_filter(
            $registry,
            fn($job) => ($job['class'] ?? '') === '\ExternalApi\szentsegimadasApi'
                && ($job['function'] ?? '') === 'cron'
        );
        $this->assertCount(1, $found, 'A szentsegimadasApi::cron() hiányzik a registryből.');
    }

    /**
     * A registry minden bejegyzésének létező osztályra és metódusra kell mutatnia,
     * különben a cron-futtató "Class does not exists" hibával bukik minden futáskor.
     */
    public function testRegistryMindenBejegyzeseHivhato(): void {
        foreach (\Eloquent\Cron::registry() as $job) {
            $class = $job['class'];
            $function = $job['function'];
            $this->assertTrue(class_exists($class), "Nincs ilyen osztály: {$class}");
            $this->assertTrue(
                method_exists($class, $function),
                "Nincs ilyen metódus: {$class}->{$function}()"
            );
        }
    }
}
