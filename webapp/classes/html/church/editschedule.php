<?php

namespace Html\Church;

use Illuminate\Database\Capsule\Manager as DB;

class EditSchedule extends \Html\Html {
    public $tid;
    public $church;
    public $elasticMassesCount;
    public $elasticMassesExamples;
    public $tids;
    public $isOutdated;
    public $monthsSinceUpdate;
    public $yearsSinceUpdate;

    public function __construct($path) {
        $this->tid = $path[0];

        // Handle POST request to mark schedule as current
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_current') {
            // #873: POST-ot már eddig is követelt, tokent nem — egy rejtett űrlappal
            // idegen oldalról is „naprakésszé" lehetett nyilvánítani a miserendet.
            \Csrf::guard();

            $church = \Eloquent\Church::find($this->tid);
            if ($church && $church->writeAccess) {
                $church->frissites = date('Y-m-d H:i:s');
                $church->save();
                // Redirect to refresh the page
                header("Location: /templom/{$this->tid}/editschedule");
                exit;
            }
        }

        $this->church = \Eloquent\Church::find($this->tid)->append(['writeAccess']);;
        if (!$this->church) {
            throw new \Exception('Nincs ilyen templom.');
        }
        
        if (!$this->church->writeAccess) {
            throw new \Exception('Hiányzó jogosultság!');
            return;
        }

        // Check if church schedule is outdated (>6 months)
        $this->isOutdated = false;
        $this->monthsSinceUpdate = 0;
        $this->yearsSinceUpdate = 0;
        
        if ($this->church->frissites) {
            $lastUpdate = strtotime($this->church->frissites);
            $now = time();
            $daysSinceUpdate = ($now - $lastUpdate) / (60 * 60 * 24);
            
            if ($daysSinceUpdate > 180) { // 6 months ≈ 180 days
                $this->isOutdated = true;
                $this->monthsSinceUpdate = floor($daysSinceUpdate / 30);
                $this->yearsSinceUpdate = floor($daysSinceUpdate / 365);
            }
        }

        // DIAGNOSTIC LOG: Check if the church has masses in ElasticSearch
        $search = new \Search('masses');
        $search->tids([$this->tid]);
        $search->dateRange(date('Y') . '-01-01', date('Y') + 1 . '-01-01');
        $results = $search->getResults(0, 10);
        $this->elasticMassesCount = $search->total;
        $this->elasticMassesExamples = $results;
                
        global $_tidsToWorkWith;
        $this->tids = $_tidsToWorkWith;
    }

}
