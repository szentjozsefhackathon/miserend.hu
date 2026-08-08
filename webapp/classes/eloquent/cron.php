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
        $cron->deadline_at = date('Y-m-d H:i:s');
        $cron->save();
        return true;
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
