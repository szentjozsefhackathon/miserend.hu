<?php

namespace Eloquent;

class Cron extends \Illuminate\Database\Eloquent\Model {

    protected $fillable = array('class', 'function');

    public function success() {
        $this->attempts = 0;
        $this->lastsuccess_at = date('Y-m-d H:i:s');
        $this->renewDeadline();
        $this->save();
    }

    public function renewDeadline() {
        $this->deadline_at = date('Y-m-d H:i:s', strtotime('now +' . $this->frequency));
    }

    public function scopeNextJobs($query) {
        return $query->where('deadline_at', '<', date('Y-m-d H:i:s'))
                        ->where(function($query) {
                            $query->where('attempts', '<', 10)
                            ->orWhere('updated_at', '<', date('Y-m-d H:i:s', strtotime('-12 hour')));
                        })
                        ->orderBy('attempts', 'ASC')->orderBy('deadline_at', 'ASC');
    }

    public function initialize() {
       return true;
    }

    /**
     * "Most esedékes" határidő. Szándékosan egy másodperccel korábbi: a scopeNextJobs
     * szigorú `deadline_at < NOW()`-ot vár, tehát a pontosan mostani időbélyeg még egy
     * teljes másodpercig nem esedékes.
     */
    private static function dueNow(): string {
        return date('Y-m-d H:i:s', time() - 1);
    }

    /**
     * Elakadt-e a munka? A /health eddig csak az attempts oszlopot színezte, de az egy
     * bukott futás után is pirosodik — így nem tűnt fel, hogy volt olyan cron, ami
     * hónapok óta nem futott le sikeresen (\User::deleteNonActivatedUsers 2026-03-27,
     * \ExternalApi\szentsegimadasApi 2025-12-31 óta).
     *
     * Szándékosan DB-független és statikus, hogy unit-tesztelhető legyen.
     *
     * @param  string|null $lastSuccessAt  a lastsuccess_at mező nyers értéke
     * @param  string      $frequency      strtotime-kompatibilis gyakoriság ('1 day', ...)
     * @param  int|null    $now            időbélyeg (teszthez); null = most
     * @return string|null                 az elakadás oka, vagy null ha rendben van
     */
    public static function stuckReason(?string $lastSuccessAt, string $frequency, ?int $now = null): ?string {
        $now = $now ?? time();

        // A régi sorokban a "soha" nem NULL-ként, hanem nulla-dátumként szerepel.
        $never = $lastSuccessAt === null
            || trim($lastSuccessAt) === ''
            || str_starts_with(trim($lastSuccessAt), '0000-00-00');
        if ($never) {
            return 'soha nem futott le sikeresen';
        }

        $last = strtotime($lastSuccessAt);
        if ($last === false) {
            return 'értelmezhetetlen lastsuccess_at: ' . $lastSuccessAt;
        }

        // A gyakoriságot másodpercre váltjuk: 'now +1 day' - 'now'. Ha a frequency
        // értelmezhetetlen, nem találgatunk, inkább nem jelzünk elakadást.
        $period = strtotime('+' . $frequency, $now);
        if ($period === false || $period <= $now) {
            return null;
        }
        $periodSeconds = $period - $now;

        // Háromszoros periódus a türelmi határ: egy-két kimaradt futás még belefér
        // (ütközhetett másik jobbal, vagy kívül esett a from/until ablakon).
        $elapsed = $now - $last;
        if ($elapsed <= 3 * $periodSeconds) {
            return null;
        }

        $days = (int) floor($elapsed / 86400);
        if ($days >= 1) {
            return $days . ' napja nem futott le sikeresen';
        }
        return (int) floor($elapsed / 3600) . ' órája nem futott le sikeresen';
    }

    /**
     * #638: az igényelt cron-munkák listája (webapp/fajlok/crons.php). Egyetlen forrás:
     * a MySQL-seed ezek után csak fejlesztői kezdőállapot, nem definíció.
     */
    public static function registry(): array {
        $file = PATH . 'fajlok/crons.php';
        if (!file_exists($file)) {
            return [];
        }
        $registry = require $file;
        return is_array($registry) ? $registry : [];
    }

    /**
     * #638: "van-e vagy nincs, és ha nincs, vedd fel" — általános, bárhonnan hívható.
     * A MEGLÉVŐ sort szándékosan nem módosítja: a lastsuccess_at/attempts előzmény és a
     * kézzel hangolt frequency az adatbázis dolga, nem a kódé.
     *
     * @return bool true, ha most jött létre a sor
     */
    public static function ensureRegistered(
        string $class,
        string $function,
        string $frequency = '1 day',
        ?string $from = null,
        ?string $until = null
    ): bool {
        // A régebbi sorok kettőzött backslash-sel is bekerülhettek — mindkettőt elfogadjuk.
        $exists = self::whereIn('class', array_unique([$class, str_replace('\\', '\\\\', $class)]))
            ->where('function', $function)
            ->exists();
        if ($exists) {
            return false;
        }

        $cron = new self();
        $cron->class = $class;
        $cron->function = $function;
        $cron->frequency = $frequency;
        $cron->from = $from;
        $cron->until = $until;
        $cron->attempts = 0;
        $cron->lastsuccess_at = null;
        // Azonnal esedékes: az új munka ne várjon egy teljes periódust az első futásra.
        $cron->deadline_at = self::dueNow();
        $cron->save();
        return true;
    }

    /**
     * A NULL deadline_at-es sorok soha nem kerülnek sorra: a scopeNextJobs
     * `deadline_at < NOW()` feltétele NULL-ra NULL-t ad, ami SQL-ben nem igaz. Egy ilyen
     * sor csak kézzel, cron_id-vel futtatható — ezért állt évek óta az éles
     * \Crons::rollPeriodYears() (deadline_at üres, lastsuccess_at üres, 31 próbálkozás).
     *
     * Csak azt bántja, aminek NINCS deadline-ja, tehát működő ütemezést nem tol el.
     *
     * @return string[] a most esedékessé tett munkák leírása
     */
    public static function healUnschedulable(): array {
        $healed = [];
        foreach (self::whereNull('deadline_at')->get() as $cron) {
            $cron->deadline_at = self::dueNow();
            $cron->save();
            $healed[] = $cron->class . '->' . $cron->function . '()';
        }
        return $healed;
    }

    /**
     * #638: deploy után futtatható — a registry hiányzó sorait felveszi, semmit nem
     * módosít és nem töröl. Így új cron-függvénynél nem kell kézzel INSERT INTO-zni.
     *
     * @return string[] a most felvett munkák leírása
     */
    public static function init(): array {
        $created = [];
        foreach (self::registry() as $job) {
            if (empty($job['class']) || empty($job['function'])) {
                continue;
            }
            $isNew = self::ensureRegistered(
                $job['class'],
                $job['function'],
                $job['frequency'] ?? '1 day',
                $job['from'] ?? null,
                $job['until'] ?? null
            );
            if ($isNew) {
                $created[] = $job['class'] . '->' . $job['function'] . '()';
            }
        }
        return $created;
    }

    /**
     * #724: a registryből KIVETT munkák sorát is el kell takarítani.
     *
     * Az init() csak felvesz, sosem töröl. Ha egy függvény megszűnik (mint a
     * `\Api\NearBy::cleanOldLogs()` a nearby.log megszüntetésekor), az éles adatbázisban
     * ottmarad a sora, a futtató pedig minden esedékességnél elhasal rajta:
     * "Function \Api\NearBy->cleanOldLogs() does not exists." — naponta, örökre.
     *
     * Ez a #638 elvének a másik fele: ha a registry az EGYETLEN forrás, akkor amit onnan
     * kivettünk, annak az adatbázisban sincs helye.
     *
     * Üres vagy olvashatatlan registrynél szándékosan nem törlünk semmit: egy hiányzó
     * fájl miatt nem szabad az összes ütemezést elveszíteni.
     *
     * @return string[] a most eltávolított munkák leírása
     */
    public static function pruneRemoved(): array {
        $wanted = [];
        foreach (self::registry() as $job) {
            if (empty($job['class']) || empty($job['function'])) {
                continue;
            }
            // A régebbi sorok kettőzött backslash-sel is bekerülhettek — mindkettő számít.
            foreach (array_unique([$job['class'], str_replace('\\', '\\\\', $job['class'])]) as $class) {
                $wanted[$class . '::' . $job['function']] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $removed = [];
        foreach (self::all() as $cron) {
            if (isset($wanted[$cron->class . '::' . $cron->function])) {
                continue;
            }
            $removed[] = $cron->class . '->' . $cron->function . '()';
            $cron->delete();
        }
        return $removed;
    }


    function run() {
        $className = $this->class;
        $functionName = $this->function;
        if (class_exists($className)) {
            $object = new $className();
        } else {
            throw new \Exception("Class '$className' does not exists.");
        }
        if (method_exists($object, $functionName)) {
            $object->$functionName();
        } else {
            throw new \Exception("Function " . $className . "->" . $functionName . "() does not exists.");
        }
        $this->success();
    }

}
