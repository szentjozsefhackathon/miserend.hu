<?php

namespace Html;


class Cron extends Html {

    public $template = 'layout_empty.twig';

    public function __construct($path = false) {
        set_time_limit('300');
        ini_set('memory_limit', '512M');

        // #638: az éles adatbázis nem futtatja újra az initdb seedet, ezért új cron-függvénynél
        // eddig kézzel kellett INSERT INTO-t nyomni. A registry (webapp/fajlok/crons.php)
        // hiányzó sorait itt vesszük fel; meglévőt nem módosít, nem töröl.
        // Deploy után célzottan is futtatható: /index.php?q=cron&cron_init=1
        $created = \Eloquent\Cron::init();
        foreach ($created as $job) {
            echo "Új cron felvéve: " . htmlspecialchars($job) . "<br>\n";
        }

        $healed = \Eloquent\Cron::healUnschedulable();
        foreach ($healed as $job) {
            echo "Ütemezhetővé tett cron (hiányzott a deadline_at): " . htmlspecialchars($job) . "<br>\n";
        }

        if (\Request::Integer('cron_init')) {
            if ($created === [] && $healed === []) {
                echo "Minden cron a helyén van, nem kellett újat felvenni.<br>\n";
            }
            return;
        }

        if($jobId = \Request::Integer('cron_id')) {
            $nextjob = \Eloquent\Cron::find($jobId);
			if (!$nextjob) return;
        }
		
		else {
            $jobs = \Eloquent\Cron::nextJobs()->get();
			
			if(count($jobs) < 1) 
				return;
			
			foreach($jobs as $job) {
				if( 
					( $job->from == '' AND $job->until == '' ) or
					( strtotime($job->from) < time() AND time() < strtotime($job->until) )
				) {
						// Itt az idő vagy nem kell idő
						$nextjob = $job; 
						break;
				} 				
			}			
		}
		if(!$nextjob) return;
		
		$job = $nextjob;
        $job->attempts++;
        $job->save();
        $start = microtime(true);
        try {
            $this->runJob($job);
        } catch (\Throwable $exception) {
            // \Throwable, nem \Exception: a TypeError/Error a PHP 8-ban NEM \Exception,
            // ezért eddig átment ezen a catch-en, megölte az egész kérést, és a job
            // némán annyiban maradt — se hibaüzenet, se success. Így akadt el hónapokra
            // a \User::deleteNonActivatedUsers() is.
            $this->error = true;
            echo "<strong>" . $job->class . "->" . $job->function . "() futtatása sikertelen.</strong>\n";
            $this->printExceptionVerbose($exception);
        }

        if (!isset($this->error)) {
            $job->success();
        }
        $elapsed = microtime(true) - $start;
        // A `%` egészre vár, az $elapsed viszont float: PHP 8.1 óta minden cron-futás
        // "Implicit conversion from float ... to int loses precision" figyelmeztetést
        // hagyott maga után. fmod()-dal ugyanaz az eredmény, zaj nélkül.
        $hours = (int) floor($elapsed / 3600);
        $minutes = (int) floor(fmod($elapsed, 3600) / 60);
        $seconds = $elapsed - ($hours * 3600) - ($minutes * 60);
        $s = round($seconds, 2);

        $parts = [];
        if ($hours > 0) $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        if ($minutes > 0) $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        $parts[] = $s . ' second' . ($s != 1 ? 's' : '');

        echo "Cron job " . $job->class . "->" . $job->function . "() completed in " . implode(' ', $parts) . ".<br>\n";
    }

    function runJob($job) {
        $className = $job->class;
        $functionName = $job->function;
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
    }
	    

}
