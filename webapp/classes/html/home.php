<?php


namespace Html;
//use Illuminate\Database\DatabaseManager as DB;
//use \Illuminate\Support\Facades\DB as DB;
use Illuminate\Database\Capsule\Manager as DB;

class Home extends Html {
    public $photo;
    public $favorites;
    public $searchform;
    public $admindashboard;
    public $boundaryDataJson;
    public $churchDataJson;
    public $rites;
    public $categories;
    public $langs;

    public function __construct() {
        global $user, $config;

        $espkers = DB::table('espereskerulet')
                    ->select('id','ehm','nev')
                    ->get();
            
        foreach ($espkers as $espker) {
            $espkerT[$espker->ehm][$espker->id] = $espker->nev;# code...
        }
    
        //MISEREND űRLAP
       
        $boundaryIds =  \Request::StringArray('boundaries', []);
        if (!empty($boundaryIds)) {
            $this->boundaryDataJson = json_encode(\Eloquent\Boundary::whereIn('id', $boundaryIds)->get()->map->toSimpleArray());
        }

        // Pre-populate selected church badges for back-navigation state
        $churchIds = \Request::IntegerArray('church_ids') ?: [];
        if (!empty($churchIds)) {
            $this->churchDataJson = json_encode(
                \Eloquent\Church::select('id', 'nev')
                    ->whereIn('id', $churchIds)
                    ->get()
                    // #497: a település a boundary-ból, visszaeséssel a régi oszlopra.
                    ->map(fn($c) => ['id' => $c->id, 'name' => $c->nev, 'city' => $c->locationCityName()])
            );
        }
         

        $searchform = array(
            'kulcsszo' => array(
                'name' => "kulcsszo",
                'id' => 'keyword',
                'value' => \Request::Text('kulcsszo'),
                'size' => 20,
                'class' => 'keresourlap',
                'placeholder' => 'név, település, kulcsszó'),           
            'hely' => array(
                'name' => "hely",
                'size' => 20,
                'id' => 'varos',
                'class' => 'keresourlap',
                'value' => \Request::Text('hely')
            ),
            'tavolsag' => array(
                'name' => "tavolsag",
                'size' => 1,
                'id' => 'tavolsag',
                'class' => 'keresourlap',
                // #89: az alapértelmezés 0 („bárhol"). A 4 km-es korábbi alapérték
                // sosem érvényesült — az űrlapon nem is volt mező hozzá —, viszont a
                // legördülőben nem szerepel, tehát semmi nem látszana kiválasztva.
                'value' => \Request::IntegerwDefault('tavolsag', 0)
            )
        );
        
        $searchform['ehm'] = array(
            'name' => "ehm",
            'selected' => \Request::IntegerwDefault('ehm', 0),
            'class' => 'keresourlap'
        );
        $searchform['ehm']['options'][0] = 'mindegy';
        
        $egyhmegyes = DB::table('egyhazmegye')
                    ->select('id','nev')
                    ->where('ok','i')
                    ->orderBy('sorrend')
                    ->get();
                    foreach ($egyhmegyes as $egyhmegye) {
                        $searchform['ehm']['options'][$egyhmegye->id] = $egyhmegye->nev;
                    }                               
        
        // Összegyűjtés: hányféle calmass.lang van az eloquent-ben, csökkenő sorrend szerint
        // #334: a `lang` vesszővel elválasztva több nyelvet is tartalmazhat ("sk,la"),
        // ezért a csoportosítás után szét kell bontani — különben a szűrősávban egy
        // "sk,la" nevű álnyelv jelenne meg, zászló nélkül.
        $langRows = \Eloquent\CalMass::select('lang')
            ->selectRaw('COUNT(*) as count')
            ->whereNotNull('lang')
            ->where('lang', '!=', '')
            ->groupBy('lang')
            ->orderBy('count', 'desc')
            ->get();

        $langCounts = [];
        foreach ($langRows as $row) {
            foreach (\Eloquent\CalMass::splitLanguages($row->lang) as $lang) {
                $langCounts[$lang] = ($langCounts[$lang] ?? 0) + (int) $row->count;
            }
        }
        arsort($langCounts);
        $this->langs = array_keys($langCounts);
        
        $this->photo = \Eloquent\Photo::big()->vertical()->where('flag', 'i')->orderbyRaw('RAND()')->first();
        // A védés marad, de a mögötte álló jegyzet („van, hogy a random képhez nem is
        // tartozik templom, valami régi hiba miatt") már nem igaz: 29 451 képből NULLA
        // az árva, és nulla a törölt templomhoz tartozó. A `first()` viszont null-t adhat,
        // ha egyáltalán nincs megfelelő kép — ezért a `photo` létét is nézzük.
        if($this->photo AND $this->photo->church)
            $this->photo->church->location;

        $this->favorites = $user->getFavorites();
        $this->searchform = $searchform;
        
        $massDefinitions = new \MassDefinitions();
        $this->rites = $massDefinitions->rites();
        $this->categories = $massDefinitions->categories();
		try {
            $this->alert = (new \ExternalApi\NapilelkibatyuApi())->liturgicalAlert();            
        } catch (\Exception $e) {
            addMessage('Nem sikerült a Napi Lelki Batyuból megtudni, hogy van-e ma különleges ünnep.', "warning");
        }
		
							
		// Adminok számára "dashboard"
		if ( $user->checkrole('miserend') ) {
		
			$this->admindashboard = [];
		
			$this->admindashboard['holders'] = \Eloquent\ChurchHolder::where('updated_at', '>', $user->lastlogin)
                             ->orWhere('status', 'asked')
                             ->orderBy('created_at', 'asc')
                             ->get();

            $this->admindashboard['suggestion_packages'] = \Eloquent\CalSuggestionPackage::where('updated_at', '>', $user->lastlogin)
                             ->orWhere('state', 'PENDING')
                             ->orderBy('created_at', 'asc')
                             ->get();
                                              
		}
        
    }

}
