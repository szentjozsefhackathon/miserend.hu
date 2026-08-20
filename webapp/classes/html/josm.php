<?php

namespace Html;

use Illuminate\Database\Capsule\Manager as DB;

class Josm extends Html {
    public $cron;
    public $multipleOSMids;
    public $osmWBadChurch;
    public $countOsmData;
    public $churchesWNoOsm;
    public $churchesWBadOsm;
    public $churchesWBad;
    public $osmtags;

    public function __construct($path) {

        if (\Request::Boolean('update')) {
            set_time_limit('300');

            // Szeretnénk, ha may a syncUrlMiserendFromOSM() a legfrissebb változatot töltené le
            // Ezért amit ott le fog húzni, azt most gyorsan lehúzzuk neki előre. 
            // Mert itt tudjuk jól állítani a cache idejét 
            $overpass = new \ExternalApi\OverpassApi();
            $overpass->cache = '1 sec';
            $overpass->downloadUrlMiserend();
            
            $job = \Eloquent\Cron::where('class','\OSM')->where('function','syncUrlMiserendFromOSM')->first();
            $job->run();                       
        }

        $this->setTitle('OSM összeköttetés');
        $this->template = 'josm.twig';        

        // Behúzzuk az adatot, hogy lássuk mikor futott le utoljára
        $this->cron = \Eloquent\Cron::where('class','\OSM')
                ->where('function','syncUrlMiserendFromOSM')->first();

       $overpass = new \ExternalApi\OverpassApi();
       $overpass->downloadUrlMiserend();
        // #573: az Overpass API gyakran túlterhelt és üres/hibás választ ad. Korábban
        // ilyenkor kivételt dobtunk → az EGÉSZ /josm oldal elszállt. Most inkább
        // barátságos üzenettel, az OSM-függő részeket üresen hagyva betöltjük az oldalt
        // (a DB-alapú rész — osmtags, cron-utolsó-futás — így is látszik).
        if (empty($overpass->jsonData->elements)) {
            addMessage('Az OSM (Overpass) adatok most nem elérhetők (valószínűleg túlterhelt). Az OSM-összevetés átmenetileg üres — próbáld újra pár perc múlva.', 'error');
            $this->multipleOSMids = [];
            $this->osmWBadChurch = [];
            $this->countOsmData = 0;
            $this->churchesWNoOsm = collect([]);
            $this->churchesWBadOsm = collect([]);
            $this->churchesWBad = collect([]);
        } else {

		$urlmiserends = [];
		foreach($overpass->jsonData->elements as $element) {
			preg_match("/(\/|=)([0-9]{1,})(\/|)$/i",$element->tags->{'url:miserend'},$match);
			if(!isset($match[2])) {
				// Ezt igazából megvizsgálja a checkOsmElements tehát nem kell ide.
			} else {
				if(!isset($urlmiserends[$match[2]])) $urlmiserends[$match[2]] = [];
				$urlmiserends[$match[2]][] = $element;		
			}
		}
		
		$this->multipleOSMids = [];
		foreach ($urlmiserends as $church_id => $id) {
			if(count($id) > 1) {
				$this->multipleOSMids[] = [
					'church' => \Eloquent\Church::find($church_id),
					'OSMids' => $id
				];
			}
		}		
		
        list($goodIDs, $this->osmWBadChurch) = $this->checkOsmElements($overpass->jsonData->elements);
        $this->countOsmData = count($overpass->jsonData->elements);
        
        $this->churchesWNoOsm = \Eloquent\Church::where('ok','i')
                ->whereNotIn('id',$goodIDs)
                ->where(function ($query) {
                    $query->whereNull('osmtype')
                        ->orWhereNull('osmid');
                })
                ->orderByCity()->orderBy('nev')
                ->get();
        
        $this->churchesWBadOsm = \Eloquent\Church::where('ok','i')
                ->whereNotIn('id',$goodIDs)
                ->whereNotNull('osmtype')->whereNotNull('osmid')
                ->orderByCity()->orderBy('nev')
                ->get();
        
        $this->churchesWBad = \Eloquent\Church::where('ok','i')
                ->whereNotIn('id',$goodIDs)
                ->get();
        }
			
    
        /*
         * OSM tag variácók
         *
         * #840: a `fromOSM = 1` MOSTANTÓL azt jelenti, hogy a kulcs OSM-címke — nem azt,
         * hogy ki írta a sort utoljára (l. `\Eloquent\Attribute::isOsmKey()`). A szűrés
         * tehát maradhat, és most már azt is jelenti, amit mond.
         *
         * Miért kell egyáltalán szűrni: a lenti overpass-turbo link és a sablonban lévő
         * taginfo-hivatkozás a KULCSBÓL épül. A saját névterű kulcsaink
         * (`communion:gluten_free:*`) OSM-címkeként jelennének meg, halott taginfo-linkkel
         * és nulla találatot adó Overpass-lekérdezéssel.
         */
		$attributes = DB::table('attributes')
			->select('attributes.*','templomok.osmtype', 'templomok.osmid')
			->join('templomok','templomok.id', '=', 'attributes.church_id')
			->where('fromOSM',1)
			->orderBy('key')
			->orderBy('value')
			->orderBy('church_id')
			->get();
			
		$osmtags = [];
		foreach($attributes as $item) {
			if(!isset($osmtags[$item->key])) {
				$osmtags[$item->key] = [
					'count' => 0,
					'dist' => 0,
					'name' => $item->key,
					'overpassturbo' => "http://overpass-turbo.eu/?Q=". urlencode('	[out:json][timeout:25];nwr["url:miserend"]["'.$item->key.'"];out geom;')."&R",
					'values' => []
				];
			}
	
			$osmtags[$item->key]['count']++;
			
			if(!array_key_exists($item->value, $osmtags[$item->key]['values']) ) {
				$osmtags[$item->key]['values'][$item->value] = [
					'value' =>  $item->value,
					'overpassturbo' => "http://overpass-turbo.eu/?Q=". urlencode('	[out:json][timeout:25];nwr["url:miserend"]["'.$item->key.'"="'.$item->value.'"];out geom;')."&R",
					'churches' => []
					
				];
				$osmtags[$item->key]['dist']++;
			}
			$osmtags[$item->key]['values'][$item->value]['churches'][] = $item;	
				
			
		}
		//printr($osmtags);
		
		$this->osmtags = $osmtags;
				
		
               
    }


   function checkOsmElements($elements) {
       $osmWBadTag = array();
       $goodOsmChurchIds = array();
       
        $c = 0;
        foreach ($elements as $element) {
            //$c++; if($c > 1900) break;
            //printr($element);
            if (isset($element->center->lat)) {
            $element->lat = $element->center->lat;
            }
            if (isset($element->center->lon)) {
                $element->lon = $element->center->lon;
            }
            
            // #410: ugyanaz a robusztusabb regex mint az osm.php/churchesinboundary.php-ban:
            // http/https/www (nem horgonyzott), opcionális `?`, =/ szeparátor,
            // 6+ jegyű ID, trailing path engedélyezve.
            // #510: az uj.miserend.hu-t NEM matcheljük (negatív lookbehind) ->
            // $osmWBadTag-ba kerül, borazslo kézzel javítja azt a néhányat.
            preg_match('#(?<!uj\.)miserend\.hu/?\??templom(?:=|/)(\d+)#i', $element->tags->{'url:miserend'} ?? '', $match);
            if(!isset($match[1])) {
                $osmWBadTag[] = $element;
            } else {
                $church = \Eloquent\Church::find($match[1]);
                if($church) {
                    $goodOsmChurchIds[] = $match[1];
                } else {
                    $osmWBadTag[] = $element;
                }
                
            }
        }
       return array($goodOsmChurchIds, $osmWBadTag);
       
   } 
   
                     
}

?>