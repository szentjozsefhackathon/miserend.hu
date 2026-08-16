<?php

namespace Api;

use Illuminate\Database\Capsule\Manager as DB;

class Service_times extends Api {

	public $title = 'Kapcsolat a "Horarios de Misa" API-val';	
	public $fields = [];


	public function docs() {

        $docs = [];
        
        $docs['description'] = <<<HTML
        <p>Olyan kimenetet próbálunk adni, ami a <a href="https://horariosdemisa.com/" target="_blank">Horario de Misa</a> nemzetközi miserend honlap számára értelmes adatot tud közvetíteni.</p>
        HTML;

        $docs['response'] = <<<HTML
        0, ha nem volt változás és 1, ha volt változás.
        HTML;

        return $docs;
    }

    public function run() {
        parent::run();

        $this->return = [];

		
        $churches = \Eloquent\Church::limit(1000000000)->get();
		set_time_limit('600');
        foreach($churches as $church ) {
			
			$church->loadAttributes();
		                        
			$syntax = 'horariosdemisa';

			
			$serviceTimes = '';
			foreach($church->MassRRulesByPeriod as $period) {
				$serviceTimes .= isset($period['name']) ? $period['name']."\n" : '';	
				
				foreach($period['massrules'] as $mass) {
					if(isset($mass['start_date'])) {
						$serviceTimes .= !isset($period['name']) ? date('Y-m-d', strtotime($mass['start_date'])).", " : '';
						$serviceTimes .= date('l H:i', strtotime($mass['start_date']))." ";
					} else {
						$serviceTimes .= "(ERROR/BUG no start_date) ";
					}
					if($mass['rite'] != 'ROMAN_CATHOLIC') $serviceTimes .= $mass['rite']." ";
					// #334: a nyelv lista lehet (szlovák-latin mise), a kimenet viszont szöveg.
					$massLangs = is_array($mass['lang']) ? $mass['lang'] : \Eloquent\CalMass::splitLanguages($mass['lang']);
					$serviceTimes .= $mass['title']." (".implode('-', $massLangs).")";	
					if(!empty($mass['types'])) {
						$serviceTimes .= ', '.implode(', ', $mass['types']);
					}
					if($mass['comment']) $serviceTimes .= ' - '.$mass['comment'];
					$serviceTimes .= "\n ".$mass['rrule']['readable']."\n";

				}
				$serviceTimes .= "\n\n";
			

			}

			if($syntax == 'horariosdemisa') {
				$return = [
					'church_id' => $church->id,
					'name' => $church->names[0],
					'address' => $church->location->address,
					// #497/#498: a visszaesés eddig a NYERS oszlopra ment — az `orszag`
					// ott azonosító, tehát hiányzó boundary-nál "12" ment ki országnévként.
					'city, state' => isset($church->location->city['name']) ? $church->location->city['name'] : $church->locationCityName(),
					'country' => isset($church->location->country['name']) ? $church->location->country['name'] : $church->locationCountryName(),
					'phone' => false,
					'email' => $church->pleb_eml,
					'url' => false,
					'location' => [$church->location->lat,$church->location->lon],
					'service_times' => $serviceTimes,
					'confessions' => false,
					'adoration' => false,
					'additional_information' => $church->misemegj,
					'last_confirmation' => $church->frissites,
				];
				
				if(count($church->links) > 1) {
					foreach($church->links as $link)
						$return['url'][] = $link->href;
				} elseif ( isset($church->link[0]) ) {
					$return['url'] = $church->links[0]->href;					
				}
				
				// Mivel ez egy nemzetközi oldalra megy ki, ezért a "nev" helyett az angol ill. eredeti nevét használjuk a helynek
				if(isset($church->{"name"})) {
					$return['name'] = $church->{"name"};
					if(isset($church->{"name:en"}) AND $church->{"name:en"} != $church->{"name"} )	{
						$return['name'] .= ' / '.$church->{"name:en"};
					}
				}
				if($return['additional_information'] != '')	$return['additional_information'] .= "\n";		
				$return['additional_information'] .= 'Source: https://miserend.hu/templom/'.$church->id. " (".date('Y-m-d').")";
				
				$this->return[] = $return;
			} else {
				$this->return[] = [
					'church_id' => $church->id,
					'service_times' => $serviceTimes->string
				];
            }
        }
        
    }

}