<?php

namespace Html\Ajax;

use Illuminate\Database\Capsule\Manager as DB;

class AutocompleteKeyword extends Ajax {

	public $format = "json";

    public function __construct() {
        $kulcsszo = \Request::Text('text');
		// TODO: kezeljük azért valahogy, nehogy bajt csináljon!

		$limit = 9;
		
		$return = [];
		
		// Use Search class for church searching
		$search = new \Search('churches');
		if ($kulcsszo) {
			$search->keyword($kulcsszo);
		}
		
		$results = $search->getResults(0, $limit, false);
		$totalCount = $search->total;
		
		// FIXME for Issue #257
		foreach ($results as $key => $result) {
			$label = $result->nev . " (";
			if($result->ismertnev) $label .= $result->ismertnev . ", ";
			$label .= (is_array($result->varos) ? $result->varos[0] : $result->varos) . ")";
			//$label .= " (score: ".$result->score.")";

			$return[] = ['label' => $label, 'value' => $result->nev . ' id:' . $result->id];
		}

		if($totalCount > $limit) {
			$return[] = ['label' => 'Van még további ' . ($totalCount - $limit) . ' találat ...', 'value' => $kulcsszo];
		}

		$this->content = json_encode(array('results' => $return));
		
		return;
				
    }

}
