<?php

namespace Html\Church;

class Church extends \Html\Html {

    /**
     * Hány óráig ne próbáljuk újra oldalletöltéskor a területi adat pótlását.
     * Két cron-fordulónyi (a \OSM::checkBoundaries 3 óránként fut).
     */
    const BOUNDARY_RECHECK_HOURS = 6;

    // Database fields from templomok table
    public $id;
    public $nev;
    public $ismertnev;
    public $orszag;
    public $megye;
    public $varos;
    public $cim;
    public $megkozelites;
    public $plebania;
    public $pleb_url;
    public $pleb_eml;
    public $egyhazmegye;
    public $espereskerulet;
    public $leiras;
    public $megjegyzes;
    public $miseaktiv;
    public $misemegj;
    public $bucsu;
    public $frissites;
    public $kontakt;
    public $kontaktmail;
    public $adminmegj;
    public $letrehozta;
    public $megbizhato;
    public $created_at;
    public $modositotta;
    public $moddatum;
    public $log;
    public $ok;
    public $eszrevetel;
    public $updated_at;
    public $deleted_at;
    public $osmid;
    public $osmtype;
    public $lat;
    public $lon;
    
    // Computed/relationship properties
    public $writeAcess;
    public $readAcess;
    public $photos;
    public $kozossegek;
    public $confessions;
    public $adorations;
    public $religious_administration;
    public $names;
    public $alternative_names;
    public $fullName;
    public $location;
    public $links;
    public $readAccess;
    public $writeAccess;
    public $accessibility;
    public $cardDonation;
    public $glutenFreeCommunion;
    public $hasWorkAccess;
    public $church;
    public $neighbours;
    public $updated;
    public $isChurchHolder;
    public $favorite;
    public $alert;
    public $ancestors;
    public $descendants;
    public $fullNetwork;

    public function __construct($path) {
        global $user;

        $tid = $path[0];

        $church = \Eloquent\Church::find($tid);
        if(!$church AND $user->checkRole('miserend')) {
            $church = \Eloquent\Church::withTrashed()->find($tid);
            if($church)
                addMessage ('Ez a templom törölve van. Nem létezik. Elhunyt. Vége.','danger');            
        }
            
        if(!$church) {
            throw new \Exception("Church with tid = '$tid' does not exist.");
        }

        $church = $church->append(['readAccess','writeAccess','accessibility','cardDonation','glutenFreeCommunion']);
        
        if (!$church->readAccess) {
            throw new \Exception("Read access denied to church tid = '$tid'");
        }

        // #409: a gondnok eddig is szerkeszthette a nem-publikus templomát, de azt
        // olvasta közben, hogy „Csak adminisztrátorok számára látható" — neki mást kell
        // mondani, mint egy adminisztrátornak.
        $notice = \Eloquent\Church::visibilityNotice(
            (string) $church->ok,
            (bool) $user->checkRole('miserend'),
            (bool) $church->writeAccess
        );
        if ($notice !== null) {
            addMessage($notice[0], $notice[1]);
        }

        $church->photos = $church->photos()->get();
		
				
		$church->kozossegek = $church->kozossegek;
       $church->confessions = $church->confessions; 

        $church->adorations = $church->adorations()
            ->where('date', '>=', date('Y-m-d'))
            ->orderBy('date', 'ASC')
            ->orderBy('starttime', 'ASC')
            ->limit(5)
            ->get()
            ->toArray();
        		   		
        /*
         * Ha a templomnak még nincs területi adata, megpróbáljuk itt helyben pótolni —
         * de csak akkor, ha nem próbáltuk nemrég.
         *
         * Enélkül minden egyes oldalletöltés kiment az Overpasshoz, és mivel a
         * területi adat épp azoknál hiányzik, ahol a lekérés rendre nem jár sikerrel,
         * ez ugyanazt a hiábavaló hívást ismételgette a látogató várakoztatásával. Az
         * Overpass ráadásul mindössze 2 párhuzamos slotot ad, tehát a felesleges
         * hívásokkal a saját cronunk elől vettük el a helyet.
         *
         * A rendszeres pótlás a cron dolga (\OSM::checkBoundaries, 3 óránként); ez itt
         * csak az „épp most vitték fel" esetet gyorsítja.
         */
        try {
            $recentlyChecked = $church->boundaries_checked_at
                && strtotime($church->boundaries_checked_at) > strtotime('-' . self::BOUNDARY_RECHECK_HOURS . ' hours');

            if( $church->lat != '' AND !isset($church->location->city) AND !$recentlyChecked) {
                (new \OSM())->checkBoundariesForOne($church);
            }
        } catch (\Throwable $e) {
            // A látogatót nem érdekli, és nem is tud vele mit kezdeni: a területi adat
            // hiánya nem akadályozza meg abban, hogy megnézze a miserendet.
            error_log('Templomoldal: az OSM területi adatok lekérése nem sikerült (templom '
                . $church->id . '): ' . $e->getMessage());
        }

        $church->MgetReligious_administration();
        
        if( count($church->neighbours) < 1 ) {
           // $distance = new \Distance();        
           // $distance->MupdateChurch($church);
        }        
  
								
        copyArrayToObject($church->toArray(), $this);

        // #505: az adatlap admin/szerkesztő nézetében jelezzük, van-e a templomnak
        // aktív (engedélyezett) gondnoka. A részletes lista a _panelholders.twig-ben marad.
        $this->activeHolderCount = \Eloquent\ChurchHolder::where('church_id', $church->id)
            ->where('status', 'allowed')->count();

        global $_tidsToWorkWith;
        if(in_array($this->id, $_tidsToWorkWith)) {
            $this->hasWorkAccess = false;
        } else {
            $this->hasWorkAccess = true;
        }
		$this->church = ['hasPendingSuggestionPackage' => $church->hasPendingSuggestionPackage, 'remarksicon' => $church->remarksicon, 'id' => $church->id, 'church:type' => $church['church:type'] ?? null]; // A church/_adminlinks.twig számára kell ez. Bocsi.
        $this->neighbours = $church->neighbours;
        
        
        if(isset($this->location->city))
            $this->setTitle($this->names[0] . " (" . $this->location->city['name'] . ")");
        else 
            $this->setTitle($this->names[0]);
        
        $this->updated = $this->frissites ? str_replace('-', '.', $this->frissites) . '.' : ''; // #174-B: frissites nullable

        /*
          $staticmap = "kepek/staticmaps/" . $tid . "_227x140.jpeg";
          if (file_exists($staticmap))
          $cim .= "<a href=\"http://www.openstreetmap.org/?mlat=$lat&mlon=$lng#map=15/$lat/$lng\" target=\"_blank\"><img src='/kepek/staticmaps/" . $tid . "_227x140.jpeg'></a>";
          else
          $cim .= "<br/>";
         */
                
        $this->photos;
        if (isset($this->photos[0])) {
            $this->addExtraMeta("og:image", "/kepek/templomok/" . $tid . "/" . $this->photos[0]->fajlnev);
        }

        if ($user->checkFavorite($tid)) {
            $this->favorite = 1;
        }
                        
  $this->alert = (new \ExternalApi\NapilelkibatyuApi())->LiturgicalAlert();
        
        $this->isChurchHolder = $user->getHoldingData($this->id);

        // Hierarchikus kapcsolatok
        $this->ancestors   = $church->ancestors;
        $this->descendants = $church->descendants;
        $this->fullNetwork = $church->fullNetwork;
  

  
    }

    static function factory($path) {
        if (isset($path[1])) {
            $urlmapping = ['new' => 'edit'];
            if (array_key_exists($path[1], $urlmapping)) {
                $class = $urlmapping[$path[1]];
            } else {
                $class = $path[1];
            }
            $className = "\Html\\Church\\" . $class;
        } else {
            $className = "\Html\\Church\\Church";
        }
        return new $className($path);
    }

}
