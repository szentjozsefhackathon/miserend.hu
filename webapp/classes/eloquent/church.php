<?php

namespace Eloquent;

use Illuminate\Database\Capsule\Manager as DB;
use ExternalApi\ElasticsearchApi;

/*
 ALTER TABLE `miserend`.`templomok` 
 ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`;
 */

class Church extends \Illuminate\Database\Eloquent\Model {

    use \Illuminate\Database\Eloquent\SoftDeletes;
    
    protected $table = 'templomok';
    protected $appends = array('names', 'alternative_names', 'fullName','location','links','fullNetwork');
    protected $fillable = [
        'nev', 'cim', 'orszag', 'megye', 'varos', 'plebania', 'pleb_eml', 'leiras',
        'lat', 'lon', 'miseaktiv', 'ok', 'frissites', 'misemegj','osmid','osmtype',
        'accessibility'
    ];
	protected $attributesCache = null;
	
    // TODO FIXME #174 sémakisimítás. Mindegyikben engedélyezni kéne a null-t vagy default érték
    protected $attributes = [        
        'plebania' => '',
        'leiras' => '',
        'megjegyzes' => '',
        'misemegj' => '',
        'bucsu' => '',
        'kontakt' => '',
        'kontaktmail' => '',
        'adminmegj' => '',
        'log' => '',
        'osmid' => false,
        'osmtype' => false,
        'lat' => 0.0,
        'lon' => 0.0,
        'nev'   => '',
        'frissites' => false,
        'ok' => 'n', // n: nem engedélyezett, i: engedélyezett, f: feltöltött, áttekintésre vár
    ];

    public function adorations()
    {
        return $this->hasMany(Adoration::class);
    }

    // -------------------------------------------------------------------------
    // Church relationships (hierarchia)
    // -------------------------------------------------------------------------

    public function parentRelationships() {
        return $this->hasMany(ChurchRelationship::class, 'child_church_id');
    }

    public function childRelationships() {
        return $this->hasMany(ChurchRelationship::class, 'parent_church_id');
    }

    /**
     * Rekurziv felfelé járás: az összes ős-lánc.
     * Max 10 szint, ciklus-védelem visited set-tel.
     * Visszatér: [ ['church' => Church, 'children' => [...]], ... ]
     */
    public function getAncestorsAttribute(): array {
        return $this->_getAncestors([$this->id]);
    }

    private function _getAncestors(array $visited, int $depth = 0): array {
        if ($depth >= 10) return [];
        $result = [];
        $rels = ChurchRelationship::where('child_church_id', $this->id)->get();
        foreach ($rels as $rel) {
            if (in_array($rel->parent_church_id, $visited)) continue;
            $parent = Church::find($rel->parent_church_id);
            if (!$parent) continue;
            $newVisited = array_merge($visited, [$rel->parent_church_id]);
            $result[] = [
                'church'   => $parent,
                'children' => $parent->_getAncestors($newVisited, $depth + 1),
            ];
        }
        return $result;
    }

    /**
     * Rekurziv lefelé járás: az összes leszármazott.
     * Max 10 szint, ciklus-védelem visited set-tel.
     */
    public function getDescendantsAttribute(): array {
        return $this->_getDescendants([$this->id]);
    }

    private function _getDescendants(array $visited, int $depth = 0): array {
        if ($depth >= 10) return [];
        $result = [];
        $rels = ChurchRelationship::where('parent_church_id', $this->id)->get();
        foreach ($rels as $rel) {
            if (in_array($rel->child_church_id, $visited)) continue;
            $child = Church::find($rel->child_church_id);
            if (!$child) continue;
            $newVisited = array_merge($visited, [$rel->child_church_id]);
            $result[] = [
                'church'   => $child,
                'children' => $child->_getDescendants($newVisited, $depth + 1),
            ];
        }
        return $result;
    }

    /**
     * Egyesített hálózat: felfelé az összes ős, majd a jelenlegi templom, majd lefelé a leszármazottak.
     * Egy flat lista, amely az indentálást és nyilakat a template-ben jeleníti meg.
     *
     * Struktura: [
     *   ['church' => Church, 'level' => 0, 'isCurrent' => false, 'isLast' => true],
     *   ...
     * ]
     */
    public function getFullNetworkAttribute(): array {
        $network = [];
        $visited = [$this->id];
        
        // 1. Gyűjtsük össze az összes őst, felülről lefelé haladó sorrendben.
        $ancestors = $this->_collectAllAncestors([], $visited);
        
        // Őseink hozzáadása
        $level = 0;
        foreach ($ancestors as $ancestor) {
            $network[] = [
                'church' => $ancestor['church'],
                'level' => $level,
                'isCurrent' => false,
                'isLast' => false
            ];
            $level++;
        }
        
        // 2. Hozzáadjuk a jelenlegi templomot
        $currentChurch = Church::find($this->id);
        $network[] = [
            'church' => $currentChurch,
            'level' => $level,
            'isCurrent' => true,
            'isLast' => false
        ];
        
        // 3. Hozzáadjuk a leszármazottakat
        $descendants = $this->_collectAllDescendants([], $visited, $level + 1);
        foreach ($descendants as $descendant) {
            $network[] = [
                'church' => $descendant['church'],
                'level' => $descendant['level'],
                'isCurrent' => false,
                'isLast' => false
            ];
        }
        
        // 4. Jelöljük meg az utolsó elemeket az egyes szinteken
        if (!empty($network)) {
            for ($i = count($network) - 1; $i >= 0; $i--) {
                // Az utolsó elem mindig utolsó
                if ($i === count($network) - 1) {
                    $network[$i]['isLast'] = true;
                } else {
                    // Ha a következő elem szintje <= mint a jelenlegi, akkor ez utolsó
                    if ($network[$i + 1]['level'] <= $network[$i]['level']) {
                        $network[$i]['isLast'] = true;
                    }
                }
            }
        }
        
        return $network;
    }
    
    /**
     * Segéd: összes őst lapos listában gyűjt.
     */
    private function _collectAllAncestors(array $result, array &$visited, int $depth = 0): array {
        if ($depth >= 10) return $result;
        $rels = ChurchRelationship::where('child_church_id', $this->id)->get();
        foreach ($rels as $rel) {
            if (in_array($rel->parent_church_id, $visited)) continue;
            $visited[] = $rel->parent_church_id;
            $parent = Church::find($rel->parent_church_id);
            if (!$parent) continue;
            
            // Az ős ancestorjait először gyűjtjük
            $result = $parent->_collectAllAncestors($result, $visited, $depth + 1);
            
            // Majd az őt magát
            $result[] = [
                'church' => $parent
            ];
        }
        return $result;
    }
    
    /**
     * Segéd: összes leszármazottat lapos listában gyűjt, szintinformációval.
     */
    private function _collectAllDescendants(array $result, array &$visited, int $depth = 1): array {
        if ($depth >= 10) return $result;
        $rels = ChurchRelationship::where('parent_church_id', $this->id)->get();
        foreach ($rels as $rel) {
            if (in_array($rel->child_church_id, $visited)) continue;
            $visited[] = $rel->child_church_id;
            $child = Church::find($rel->child_church_id);
            if (!$child) continue;
            
            $result[] = [
                'church' => $child,
                'level' => $depth
            ];
            
            // Az ezt követő leszármazottakat rekurzívan
            $result = $child->_collectAllDescendants($result, $visited, $depth + 1);
        }
        return $result;
    }

    /**
     * Lapos ID lista: saját id + összes leszármazott id (Angular hierarchia URL-hez).
     */
    public function getDescendantIdsAttribute(): array {
        $ids = [$this->id];
        $this->_collectDescendantIds($this->id, $ids, [$this->id]);
        return $ids;
    }

    private function _collectDescendantIds(int $churchId, array &$ids, array $visited, int $depth = 0): void {
        if ($depth >= 10) return;
        $rels = ChurchRelationship::where('parent_church_id', $churchId)->get();
        foreach ($rels as $rel) {
            if (in_array($rel->child_church_id, $visited)) continue;
            $ids[] = $rel->child_church_id;
            $visited[] = $rel->child_church_id;
            $this->_collectDescendantIds($rel->child_church_id, $ids, $visited, $depth + 1);
        }
    }
    
    /** #866: a gyóntatást az AJTÓÉRZÉKELŐ jelzi (Mód 1) — a vízszivárgás (Mód 2) nem. */
    const CONFESSION_MOD_DOOR = 1;

    public function getConfessionStatusAttribute() {
        /*
         * #866: CSAK az ajtóérzékelő sorai számítanak.
         *
         * A LoRaWAN-végpont két módot ismer, és mindkettő 'ON'/'OFF'-ot ír ugyanabba a
         * `status` mezőbe. Ez a lekérdezés a templom legutolsó sorát vette, módra való
         * szűrés nélkül — egy jelzett VÍZSZIVÁRGÁSBÓL tehát „Most van gyóntatás a
         * helyszínen!" lett a templom oldalán.
         *
         * A `device_mode` alapértéke 1, tehát a régi sorok (mind ajtóérzékelőtől) továbbra
         * is beleszámítanak.
         */
        $lastConfessionData = \Eloquent\Confession::where('church_id', $this->id)
            ->where('device_mode', self::CONFESSION_MOD_DOOR)
            ->orderBy('timestamp', 'desc')->limit(1)->get();
        // Ha sosem kaptunk még ilyen adatot, az azt jelenti, hogy a templomban nincs telepítve gyóntatási kapcsoló
        
        if ($lastConfessionData->isEmpty()) {            
            return false;
        }
        
        $confession = $lastConfessionData->first();
        
        $toleranceString = '20 hours'; // tolerance window as a string
        $toleranceSeconds = strtotime($toleranceString) - time(); // convert to seconds

        if ($confession->status === 'ON' && ( time() - strtotime($confession->timestamp) ) <= $toleranceSeconds ) {
            $status = 'ON';
        } else {
            $status = 'OFF';
        }

        return $status;
    }

    public function getConfessionsAttribute()
    {
        $toleranceString = '20 hours'; // tolerance window as a string

        $status = $this->confessionStatus;
        if($status === false) {
            return false;
        }

        // Get all confession status changes for this church, ordered by timestamp DESC
        $periods = $this->getConfessions('-40 days', $toleranceString);
        $periods = array_reverse($periods);    
        //$periods = array_slice($periods, 0, 10);
        
        return [
            'status' => $this->confessionStatus, 
            'last_periods' => $periods
        ];
    }

    /**
     * Get confession periods for this church starting from a given date, with a specified tolerance for determining ON/OFF status.
     *
     * @param string $fromString - The starting point for fetching confession data (e.g., '-40 days').
     * @param string $toleranceString - The time window to consider for determining if the confession is still ON (e.g., '20 hours').
     * @return array - An array of confession periods with their start and end times, and duration.
     */
    public function getConfessions($fromString, $toleranceString)
    {
        $toleranceSeconds = strtotime($toleranceString) - time();

        $startTime2 = strtotime($fromString);
        $confessions = \Eloquent\Confession::where('church_id', $this->id)
            ->where('timestamp', '>=', date('Y-m-d H:i:s', $startTime2))
            ->orderBy('timestamp', 'asc')
            ->limit(50000)
            ->get(['status', 'timestamp', 'local_id','id']);

        $periods = [];
        $current = [];
        $currentIds = [];
        $lastTimeStamp = 0;
       
        foreach ($confessions as $conf) {   
            //echo "<br>-------------<br>NEXT: ".$conf->local_id." ".$conf->status."<br/>";
            //echo "CurrentTimestamp: " .strtotime($conf->timestamp). " - " . date('Y-m-d H:i:s', strtotime($conf->timestamp)) . " = ".$conf->timestamp."<br>";
            //echo "LastTimeStamp: ". $lastTimeStamp ." - " . date('Y-m-d H:i:s', $lastTimeStamp) . "<br>";
            // Calculate the difference between current and last timestamp in minutes
            $diffMinutes = ($lastTimeStamp > 0) ? round((strtotime($conf->timestamp) - strtotime(date('Y-m-d H:i:s',$lastTimeStamp))) / 60, 2) : 0;
            //echo "Diff from last: {$diffMinutes} minutes<br/>";
            //echo "CurrentIds: ".count($currentIds)."<br/>";
            // Rendezze a $currentIds tömböt érték szerint, a kulcsokat megtartva
            asort($currentIds);
            foreach ($currentIds as $id => $starttime) {
                $startDate = date('Y-m-d H:i:s', $starttime);
                $currentTimeStamp = strtotime($conf->timestamp);
                $currentTimeStampDate = date('Y-m-d H:i:s', $currentTimeStamp);
                $diff = $currentTimeStamp - $starttime;
                $diffMinutes = round($diff / 60, 2);
                //echo "currentIds[$id]: $startDate, diff: {$diffMinutes} minutes, conf->timestamp: {$currentTimeStampDate}";
                if( $diff > $toleranceSeconds) {                  
                    unset($currentIds[$id]);
                    $current['end'] = $toleranceSeconds + $starttime;
                } else {
                    
                }
                //echo "<br/>";
            }
            

            if(count($currentIds) < 1 AND $current !== []) {                                      
                    $periods[] = [
                        'start' => date('Y-m-d H:i:s', $current['start']),
                        'end' => date('Y-m-d H:i:s', $current['end']),
                        'duration' => $current['end'] - $current['start']
                    ];
                    $current = [];
                    $currentIds = [];
                }
           
            

            //Ha OFF akkor biztosan vége valaminek, vagy csak békében tovább lépünk.
            if ($conf->status === 'OFF') {
                
                //Ha ezzel a local_id-vel van folyamatban, akkor azt lezárjuk.
                if(isset($currentIds[$conf->local_id])) {
                    $current['end'] = strtotime($conf->timestamp); //Ez még nem biztos, hogy a tényleges vég. Majd következő OFF-nál kiderül.
                    unset($currentIds[$conf->local_id]);
                }
                // Ha már egyetlen local_id-vel sincs folyamatban semmi, akkor az egész periódust lezárjuk.
                if(count($currentIds) < 1 AND $current !== []) {                    
                    $periods[] = [
                        'start' => isset($current['start']) ? date('Y-m-d H:i:s', $current['start'] ) : 'error',
                        'end' => date('Y-m-d H:i:s', $current['end']),
                        'duration' => $current['end'] - $current['start']
                    ];
                    $current = [];
                    $currentIds = [];
                }
            }
            
            
            // Ha rendesen ON, akkor elindítjuk vagy folytatjuk.
            if($conf->status == 'ON') {
                //Ha nincs, akkor elindítjuk
                if(!isset($currentIds[$conf->local_id])) {
                    $current['start'] = strtotime($conf->timestamp);                    
                    $currentIds[$conf->local_id] = strtotime($conf->timestamp);
                } else {
                    //Ha már folyamatban van, akkor a korrábbi start idővel folytatjuk.
                    $currentIds[$conf->local_id] = strtotime($conf->timestamp);
                }

            }
            //echo "SO: current start: " . (isset($current['start']) ? date('Y-m-d H:i:s', $current['start']) : 'n/a') . ", current end: " . (isset($current['end']) ? date('Y-m-d H:i:s', $current['end']) : 'n/a') . "<br/>";
            $lastTimeStamp = strtotime($conf->timestamp);
            
        }

        //Ha az utolsó periódus még nem zárult le.
        if($current !== []) {
            if(time() - $toleranceSeconds > $current['start']) {                
                $periods[] = [
                    'start' => date('Y-m-d H:i:s',$current['start']),
                    'end' => date('Y-m-d H:i:s', $current['start'] + $toleranceSeconds),
                    'duration' => $toleranceSeconds
                ];
            } else {
                $current['end'] = time();
                $periods[] = [
                    'start' => date('Y-m-d H:i:s',$current['start']),                    
                    'duration' => time() - $current['start']
                ];
            }
            
        }

        return $periods;



    }

	public function attributes()
    {
        return $this->hasMany(Attribute::class);
    }

    /**
     * #257: az OSM-ből gyűjtött címkék kulcs => érték alakban.
     *
     * A `names` és az `alternative_names` innen épül fel. Eddig mindkettő KÜLÖN
     * lekérdezéssel olvasta be az attribútumokat, templomonként — egy ötven soros
     * listánál tehát ötven plusz lekérdezés. Emiatt maradt a katalógusban, a
     * gyűjteményekben és a szomszédos templomoknál a nyers `nev` oszlop: az OSM-nevekre
     * váltás a listákat használhatatlanná lassította volna.
     *
     * Ha a hívó `->with('attributes')`-szel tölt be, a betöltött kapcsolatot használjuk,
     * és a plusz lekérdezések eltűnnek. Enélkül a régi viselkedés marad, tehát az
     * egyedi templomoldal semmit nem veszít.
     */
    private function osmAttributeMap(): array
    {
        $attributes = $this->relationLoaded('attributes')
            ? $this->getRelation('attributes')
            : $this->attributes()->get();

        return $attributes->pluck('value', 'key')->toArray();
    }
	
	public function loadAttributes()
    {
        $attributes = $this->osmAttributeMap();
        foreach ($attributes as $key => $value) {
			if(!isset($this->$key))
				$this->setAttribute($key, $value);
			else {
				// throw new \Exception("The attribute '".$key."' has already existed.");
			}

        }
    }

	public function __call($method, $parameters)
    {
	
		$church = parent::__call($method, $parameters);
		
		if($church) {
			// Amikor leszóhívunk az adatbázisból egy templomot, akkor rögtön feltöltjük teljesen a tulajdonságaival
			if ($method == 'find') {			
				                
                // Minden OSM key->value betöltése
                $church->loadAttributes();
            }
        }

        return $church;
        }   
    public function photos() {
        return $this->hasMany('\Eloquent\Photo')->ordered();
    }

    public function getMassRRulesByPeriodAttribute()
    {
        $massRRules = $this->hasMany('\Eloquent\CalMass', 'church_id')->with('period')->get()->groupBy('period_id')->toArray();
        
        $RRulesByPeriods = [];
        foreach($massRRules as $periodId => $massRules) {
            $RRulesByPeriods[$periodId] = $massRules[0]['period'];
            $RRulesByPeriods[$periodId]['massrules'] = [];
            foreach($massRules as $k => $massRule) {
                if(!empty($massRule['rrule'])) {
                    $rrule = new \SimpleRRule($massRule['rrule']);                    
                    $massRule['rrule']['readable'] = $rrule->toText();
                    /*
                     * #832: a kezdőnap a SZABÁLYBÓL, nem legenerált előfordulásból.
                     *
                     * A régi jelzés helyben állt: „Itt ez hiba, mert nem egy konkrét
                     * legenerált Periodban nézünk szét, hanem csak egy általánosban.
                     * […] Nekünk amúgy is csak azért kell, hogy tudjuk milyen napon
                     * kezdődik."
                     *
                     * Igaza volt, és nem csak elvi kérdés: ha a szabály tartománya
                     * szűk, a `getOccurrences()` ÜRESET ad, és akkor a `start_date`
                     * meg sem születik. Élesben 4000 ismétlődő miséből 6 ilyen — az
                     * `Api\ServiceTimes` náluk szó szerint „(ERROR/BUG no start_date)"
                     * -et írt ki. A `representativeStart()` mindig ad választ, és
                     * olcsóbb is: nem generálja le a teljes sorozatot.
                     */
                    $massRule['start_date'] = $rrule->representativeStart()->toDateTimeString();
                    $RRulesByPeriods[$periodId]['massrules'][] = $massRule;
                }
            } 
                        
            // sort massrules by weekday of start_date (Sunday=0 .. Saturday=6), tie-breaker by datetime
            usort($RRulesByPeriods[$periodId]['massrules'], function($a, $b) {
                $wa = isset($a['start_date']) ? (int)date('w', strtotime($a['start_date'])) : 0;
                $wb = isset($b['start_date']) ? (int)date('w', strtotime($b['start_date'])) : 0;
                if ($wa === $wb) {
                    $ta = isset($a['start_date']) ? strtotime($a['start_date']) : 0;
                    $tb = isset($b['start_date']) ? strtotime($b['start_date']) : 0;
                    return $ta < $tb ? -1 : ($ta > $tb ? 1 : 0);
                }
                return $wa < $wb ? -1 : 1;
            });
            
        }

        return $RRulesByPeriods;
    }

    
    public function getGeneratedMassRRulesAttribute() {
        $masses = $this->hasMany('\Eloquent\CalMass', 'church_id');
        
        $massPeriods = \Eloquent\CalMass::generateMassPeriodInstancesForYears( $masses->get()->all(), [], [date('Y'),date('Y')+1]);
        foreach($massPeriods as $k => $mass) {
            $rrule = new \SimpleRRule($mass['rrule']);
            $occ = reset($rrule->getOccurrences());                        
            $massPeriods[$k]['start_date'] = $occ->toString();
            $massPeriods[$k]['readable_rrule'] = $rrule->toText();
        }
        return $massPeriods;
    }

    public function massrules() {
        return $this->hasMany('\Eloquent\CalMass', 'church_id');
    }

    /**
     * Hétvégi miserendet kérdez le (szombat 17:00-tól, vasárnap összes)
     * A keresési logika:
     * - Hétfő-péntek: jövő szombat-vasárnap
     * - Szombat-vasárnap: aktuális szombat-vasárnap
     *
     * @return array ['saturday' => [...masses], 'sunday' => [...masses]]
     */
    public function getWeekendMasses(): array
    {
        $targetSaturday = $this->getTargetSaturdayDate();
        $targetSunday = $targetSaturday->copy()->addDay();
        
        // Szombati misék: 17:00-tól a szombat végéig
        $saturdayMasses = $this->searchWeekendMasses(
            $targetSaturday->toDateString(),
            '17:00',
            '23:59'
        );
        
        // Vasárnapi misék: 00:00-tól a vasárnap végéig
        $sundayMasses = $this->searchWeekendMasses(
            $targetSunday->toDateString(),
            '00:00',
            '23:59'
        );
        
        return [
            'saturday' => array_slice($saturdayMasses, 0, 4),  // max 4 mise
            'sunday' => array_slice($sundayMasses, 0, 4),      // max 4 mise
        ];
    }

    /**
     * Meghatározza a keresendő szombat dátumát az aktuális nap alapján
     */
    private function getTargetSaturdayDate()
    {
        $today = \Carbon\Carbon::now('Europe/Budapest');
        $dayOfWeek = $today->dayOfWeek; // 0=vasárnap, 1=hétfő, 6=szombat
        
        if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
            // Hétfő-péntek: jövő szombat
            return $today->next(\Carbon\Carbon::SATURDAY);
        } else if ($dayOfWeek == 6) {
            // Szombat: ma
            return $today;
        } else {
            // Vasárnap: tegnapi szombat
            return $today->previous(\Carbon\Carbon::SATURDAY);
        }
    }

    /**
     * Hetvégi miserendet kérdez le a Search osztály segítségével
     *
     * @param string $date A keresendő dátum (YYYY-MM-DD formátumban)
     * @param string $minTime A keresés kezdete (HH:MM)
     * @param string $maxTime A keresés vége (HH:MM)
     * @return array Az adott napra és időintervallumra talált misék
     */
    private function searchWeekendMasses($date, $minTime, $maxTime): array
    {
        $search = new \Search('masses');
        $search->tids([$this->id]);
        
        // Időpontok UTC-re konvertálása (Budapest időzóna)
        $minUtc = \Carbon\Carbon::parse($date . 'T' . $minTime . ':00', 'Europe/Budapest')
            ->setTimezone('UTC')
            ->format('Y-m-d\TH:i:s') . 'Z';
        $maxUtc = \Carbon\Carbon::parse($date . 'T' . $maxTime . ':00', 'Europe/Budapest')
            ->setTimezone('UTC')
            ->format('Y-m-d\TH:i:s') . 'Z';
        
        // Időpont szűrés
        $search->addMust([
            'range' => [
                'start_date' => [
                    'gte' => $minUtc,
                    'lte' => $maxUtc
                ]
            ]
        ]);
        
        // Típus szűrés: csak MASS kategóriához tartozó misék
        $massTypeKeys = self::getMassTypeKeysFromDefinitions();
        if (!empty($massTypeKeys)) {
            /* $search->addMust([
                'terms' => ['types' => $massTypeKeys]
            ]); */
            $search->query['bool']['must'][] = [ 'terms' => ['title.keyword' => $massTypeKeys] ];
        }
                                

        $results = $search->getResults(0, 4);
        
        // Az eredmények konvertálása a frontend számára
        $formattedMasses = [];
        if (is_array($results)) {
            foreach ($results as $mass) {
                $formattedMasses[] = [
                    'time' => substr($mass->start_date, 11, 5), // HH:MM format
                    'date' => substr($mass->start_date, 0, 10), // YYYY-MM-DD format
                    'title' => $mass->title ?? '',
                ];
            }
        }
        
        return $formattedMasses;
    }

    /**
     * #641: hétvégi misék SOK templomra, egyetlen Elasticsearch-lekérdezéssel.
     *
     * A térkép bbox-végpontja templomonként hívta a getWeekendMasses()-t, az pedig
     * templomonként KÉT ES-kört futtat (szombat + vasárnap). 460 templomnál ez 920
     * hálózati kör — mérve ez tette 4 másodpercessé a térkép minden mozdítását.
     * A két időablak egybefüggő (szombat 17:00 → vasárnap 23:59), ezért egyben
     * kérjük le, és PHP-ben bontjuk napokra.
     *
     * A visszaadott alak SZÁNDÉKOSAN azonos a getWeekendMasses()-ével, hogy a
     * frontend-szerződés ne változzon.
     *
     * @param  int[] $churchIds
     * @return array [church_id => ['saturday' => [...], 'sunday' => [...]]]
     */
    public static function weekendMassesForChurches(array $churchIds): array
    {
        $empty = ['saturday' => [], 'sunday' => []];
        $result = [];
        foreach ($churchIds as $id) {
            $result[(int) $id] = $empty;
        }
        if (empty($churchIds)) {
            return $result;
        }

        $saturday = (new self())->getTargetSaturdayDate();
        $sunday = $saturday->copy()->addDay();

        $fromUtc = \Carbon\Carbon::parse($saturday->toDateString() . 'T17:00:00', 'Europe/Budapest')
            ->setTimezone('UTC')->format('Y-m-d\TH:i:s') . 'Z';
        $toUtc = \Carbon\Carbon::parse($sunday->toDateString() . 'T23:59:00', 'Europe/Budapest')
            ->setTimezone('UTC')->format('Y-m-d\TH:i:s') . 'Z';

        try {
            $byChurch = (new \ExternalApi\ElasticsearchApi())->massesByChurch(
                $churchIds,
                $fromUtc,
                $toUtc,
                self::getMassTypeKeysFromDefinitions()
            );
        } catch (\Throwable $e) {
            // A térkép a misék nélkül is használható — ne bukjon el az egész válasz.
            error_log('[#641] hétvégi misék lekérése nem sikerült: ' . $e->getMessage());
            return $result;
        }

        $saturdayDate = $saturday->toDateString();
        $sundayDate = $sunday->toDateString();

        foreach ($byChurch as $churchId => $masses) {
            foreach ($masses as $mass) {
                // Ugyanaz a helyi idejű formázás, mint a Search::prepareMassesResults()-ban.
                $local = \Carbon\Carbon::parse($mass['start_date'])->setTimezone('Europe/Budapest');
                $day = $local->toDateString();
                $entry = [
                    'time' => $local->format('H:i'),
                    'date' => $day,
                    'title' => $mass['title'],
                ];
                if ($day === $saturdayDate && count($result[$churchId]['saturday']) < 4) {
                    $result[$churchId]['saturday'][] = $entry;
                } elseif ($day === $sundayDate && count($result[$churchId]['sunday']) < 4) {
                    $result[$churchId]['sunday'][] = $entry;
                }
            }
        }

        return $result;
    }

    private static function getMassTypeKeysFromDefinitions(): array
    {
        $massTypeKeys = [];
        foreach ((new \MassDefinitions())->definitionKeysByCategory('MASS') as $key) {
            $massTypeKeys[] = $key;
            $massTypeKeys[] = t('MASS_TITLE.' . $key);
        }

        return $massTypeKeys;
    }
    
    /**
     * #667: mely rítusokban van (bármikor) liturgia ebben a templomban?
     *
     * A rítus nem a templom tulajdonsága, hanem a miséké — a keresőnek viszont
     * templomonként kell tudnia, hogy „van-e itt valaha görögkatolikus liturgia".
     * Pontosan úgy származtatjuk, ahogy a nyelveket (l. getLanguagesAttribute).
     *
     * @return string[]
     */
    public function getRitusokAttribute() {
        return $this->massrules()
                    ->pluck('rite')
                    ->filter(function($v) { return $v !== null && $v !== ''; })
                    ->unique()
                    ->values()
                    ->toArray();
    }

    public function getLanguagesAttribute() {
        // #334: egy mise `lang` mezője vesszővel elválasztva több nyelvet is tartalmazhat
        // ("sk,la"), ezért szét kell bontani — enélkül a templom nyelvei közé maga a
        // "sk,la" karakterlánc kerülne be.
        return $this->massrules()
                    ->pluck('lang')
                    ->flatMap(function($v) { return \Eloquent\CalMass::splitLanguages($v); })
                    ->unique()
                    ->values()
                    ->toArray();
    }

    public function keywordshortcuts() {
        return $this->hasMany('\Eloquent\KeywordShortcut');
    }

    public function remarks() {
        return $this->hasMany('\Eloquent\Remark')->orderBy('created_at', 'DESC');
    }

    public function suggestionPackages() {
        return $this->hasMany('\Eloquent\CalSuggestionPackage', 'church_id')->orderBy('created_at', 'DESC');
    }

    public function updateNeighbours() {
        // #172: a globális \Distance kell (azon van a MupdateChurch). Az Eloquent
        // névtérben a sima `new Distance()` a modellt (\Eloquent\Distance) hozná,
        // ami nem ismeri a metódust -> "Call to undefined method MupdateChurch()".
        $distance = new \Distance();
        $distance->MupdateChurch($this);
    }
    
    public function getNeighboursAttribute () {
        // #103: mindkét irányban keresünk. Egy pár a distances-ben csak EGYSZER szerepel
        // (from→to), a régi accessor viszont csak a `from = ez` sorokat nézte — ezért ha a
        // templomot a SZOMSZÉDJA dolgozta fel (ez volt a 'to'), 0 szomszédot mutatott. Most
        // a templom lehet 'from' VAGY 'to', és mindig a MÁSIK végpont templomát adjuk vissza.
        if (empty($this->lat) || empty($this->lon)) {
            return collect();
        }
        // #748: a mindkét irányú keresés miatt egy szomszéd KÉTSZER jött vissza. A
        // `distances` táblában ugyanaz a pár mindkét irányban szerepel (A->B és B->A),
        // mert a cron minden templomot külön dolgoz fel `from`-ként. Az egyik sort a
        // fenti `from = ez`, a másikat a `to = ez` ág kapja el -> ugyanaz a templom
        // kétszer került a listába. Ráadásul több koordinátapár is mutathat ugyanarra
        // a templomra. Ezért koordináta ÉS templom-azonosító szerint is szűrünk.
        // A limitet 30 -> 60-ra emelem, mert a soroknak kb. a fele duplikátum.
        $rows = \Eloquent\Distance::where(function($q) {
                    $q->where('fromLat', $this->lat)->where('fromLon', $this->lon);
                })->orWhere(function($q) {
                    $q->where('toLat', $this->lat)->where('toLon', $this->lon);
                })->orderBy('distance', 'ASC')->limit(60)->get();

        $result = collect();
        $seenCoords = [];
        $seenIds = [];
        foreach ($rows as $d) {
            $isFrom = ($d->fromLat == $this->lat && $d->fromLon == $this->lon);
            $lat = $isFrom ? $d->toLat : $d->fromLat;
            $lon = $isFrom ? $d->toLon : $d->fromLon;

            // A sorok távolság szerint növekvőek, tehát az első előfordulás a legkisebb.
            $coordKey = $lat . '|' . $lon;
            if (isset($seenCoords[$coordKey])) {
                continue;
            }
            $seenCoords[$coordKey] = true;

            // #257: az OSM-neveket a sablon olvassa (names / alternative_names), ezért
            // mindjárt a kapcsolattal együtt töltjük be — enélkül szomszédonként két
            // további lekérdezés menne el a névhalmazra.
            $church = \Eloquent\Church::with('attributes')
                ->where('lat', $lat)->where('lon', $lon)->where('ok', 'i')->first();
            if (!$church || $church->id == $this->id || isset($seenIds[$church->id])) {
                continue;
            }
            $seenIds[$church->id] = true;

            $church->distance = $d->distance;
            $result->push($church);
            if ($result->count() >= 10) break;
        }
        return $result;
    }
    
    
    /**
     * #174-B: a `frissites` biztonságos olvasása. NULL, üres string ÉS a régi
     * '0000-00-00[ 00:00:00]' szemét-érték egyaránt "nincs adat" (null). Így a
     * kód akkor is helyesen viselkedik, ha a 0000-00-00 -> NULL adatbázis-
     * migráció MÉG NEM futott le - a merge nem függ a migráció időzítésétől.
     * (A '0000-00-00' TRUTHY string, ezért a sima `$this->frissites ?` check
     * NEM kapná el, és strtotime('0000-00-00') === false-tól visszatérne a bug.)
     */
    private function frissitesOrNull(): ?string
    {
        $f = $this->frissites;
        if (empty($f) || strpos((string) $f, '0000-00-00') === 0) {
            return null;
        }
        return $f;
    }

    /** #174-B: a frissites formázva ('Y-m-d H:i:s'), vagy null ha nincs adat. */
    private function frissitesFormatted(): ?string
    {
        $f = $this->frissitesOrNull();
        return $f !== null ? date('Y-m-d H:i:s', strtotime($f)) : null;
    }

    /**
     * @param string    $length     minimal | medium | full | elastic
     * @param mixed     $whenMass   melyik napra kérjük a miséket
     * @param int|null  $apiVersion #56: az API-verzió; 5-től strukturált a mise-adat
     */
    public function toAPIArray($length = "minimal", $whenMass = false, ?int $apiVersion = null)
    {

        if($length == 'elastic') {
            $elastic = true;
            $length = 'medium';
        } else {
            $elastic = false;
        }

        if($length == false) $length = "minimal";
        if ($whenMass == false ) $whenMass = date('Y-m-d');
        
        $misek = [];
        if(!$elastic) {
            $search = new \Search('masses');
            $search->day($whenMass);
            
            $search->tids([$this->id]);
            $masses = $search->getResults(0,10);
                    
            if(isset($masses)) {
                foreach($masses as $key => $mise) {
                    $misek[$key]['idopont'] = date('Y-m-d H:i:s', strtotime($mise->start_date));
                    $info = trim( t($mise->rite)." ".t($mise->title));
                    // #334: az ES-ből tömbként jön (több nyelvű mise is lehet).
                    $miseLangs = \Eloquent\CalMass::splitLanguages(
                        is_array($mise->lang) ? implode(',', $mise->lang) : $mise->lang
                    );
                    if( !$this->isInHungary() or $miseLangs != ['hu'] ) {
                        $translated = array_map(function($l) { return t('LANGUAGES.'.$l); }, $miseLangs);
                        if ($translated) {
                            $info .= ' ' . implode('-', $translated)." nyelven";
                        }
                    }
                    if (!empty($mise->types)) {                        
                        $translatedTypes = array_map(function($type) { return t($type); }, $mise->types);
                        $info .= ', ' . implode(', ', $translatedTypes);                        
                    }
                    if($mise->comment) $info .= ' (' . $mise->comment.')';
                    if($info != '') $misek[$key]['informacio'] = $info;

                    /*
                     * #56: v5-től a nyers adat is kimegy.
                     *
                     * Az `informacio` egy előre összerakott MAGYAR mondat (rítus + cím +
                     * nyelvek + típusok + megjegyzés). A kliens ebből nem tud szűrni,
                     * nem tud fordítani, és a hosszt sem tudja. Márpedig mindez
                     * strukturáltan megvan a `cal_masses`-ben — csak eddig nem adtuk ki.
                     * Ez borazslo kérése: „az egész átadott mise adatok kövessék a nagy
                     * megújulás calendar típusú új állapotát."
                     *
                     * Az `informacio` a v5-ben is MEGMARAD: így a meglévő kliens
                     * (KAPP) mezőnként állhat át, nem egyszerre kell mindent átírnia.
                     */
                    if ($apiVersion !== null && $apiVersion >= 5) {
                        $misek[$key]['mise_id']    = (int) ($mise->mass_id ?? 0);
                        $misek[$key]['ritus']      = (string) ($mise->rite ?? '');
                        $misek[$key]['megnevezes'] = (string) ($mise->title ?? '');
                        // #334: egy misének több nyelve is lehet — ezért mindig lista.
                        $misek[$key]['nyelvek']    = array_values($miseLangs);
                        $misek[$key]['tipusok']    = array_values((array) ($mise->types ?? []));
                        $misek[$key]['megjegyzes'] = (string) ($mise->comment ?? '');
                        $misek[$key]['hossz_perc'] = (int) ($mise->duration_minutes ?? 0);
                    }
                }
            }

            /*
             * #431: az alkalom SAJÁT helyszíne, ha nem a templomban van.
             *
             * borazslo kérdése a #813-ra: „API-t nem érinti? (v5-ben talán és akkor
             * ha eltér a templom alap adatától)". De: csak akkor megy ki, ha tényleg
             * eltér — a templomi misére a `templom.koordinatak` a válasz, azt nem
             * ismételjük meg misénként.
             *
             * A forrás az ADATBÁZIS, nem az Elasticsearch. Az ES-ben nincs benne, és
             * odatenni teljes mise-újraindexelést jelentene (30+ perc, 500e esemény),
             * ami után a mező addig NÉMÁN hiányozna. Egy `whereIn` a válaszban lévő
             * legfeljebb 10 mise-azonosítóra olcsóbb és mindig friss.
             */
            if ($apiVersion !== null && $apiVersion >= 5 && $misek) {
                $misek = $this->attachOwnLocations($misek);
            }
        }

        $adorations = [];
        $results = $this->adorations()
				->where('date', '>=', date('Y-m-d'))
				->orderBy('date', 'ASC')
				->orderBy('starttime', 'ASC')
				->limit(5)
				->get()
				->toArray();
        foreach($results as $key => $adoration) {
            $adorations[$key]['kezdete'] = $adoration['date']." ".$adoration['starttime'];
            $adorations[$key]['vege'] = $adoration['date']." ".$adoration['endtime'];				
            $adorations[$key]['fajta'] = $adoration['type'];				
            if($adoration['info'] != '') $adorations[$key]['info'] =  $adoration['info'];
        }
        $this->loadAttributes();

        if($length == "minimal") {
            $return = [
                'id' => $this->id,
                'nev' => !empty($this->names) ? $this->names[0] : '',
                'frissitve' => $this->frissitesFormatted(),
                'ismertnev' => !empty($this->alternative_names) ? $this->alternative_names[0] : '',
                'orszag' => $this->locationCountryName(),
                'varos' => $this->locationCityName(),
                // #805: a v5-ben a fix mezők mellé a TELJES határlista is kimegy.
                ...(($apiVersion !== null && $apiVersion >= 5)
                    ? ['hatarok' => $this->administrativeBoundaryList()]
                    : []),
                'misek' => $misek,
                'adoraciok' => $adorations,
                'gyontatas' => $this->confessions ? $this->confessions['status'] : false,
                'koordinatak' => [ (float) $this->lat, (float) $this->lon ],
                'lat' => (float) $this->lat,
                'lon' =>(float) $this->lon,
                // #112: a templom honlapja(i) a minimal response-ban is - a /nearby
                // API alapból minimal-t ad vissza, és a mobil alkalmazásnak
                // (KAPP) szüksége van rá.
                'links' => $this->links->pluck('href')->toArray(),
                'tavolsag' => (int) $this->distance
            ];
            return $return;
        }

        $return = [
            'id' => $this->id,
            'names' => $this->names,
            'nev' => !empty($this->names) ? $this->names[0] : '',
            'ismertnev' => !empty($this->alternative_names) ? $this->alternative_names[0] : '',
            'alternative_names' => $this->alternative_names,
            'frissitve' => $this->frissitesFormatted(),            
            'orszag' => $this->locationCountryName(),
            'egyhazmegye' => ( DB::table('egyhazmegye')->where('id', $this->egyhazmegye)->value('nev') ?: "" ),
            'megye' => $this->locationCountyName(),
            'varos' => $this->locationCityName(),
            /*
             * #805: a v5-ben a fix orszag/megye/varos MELLÉ kimegy a teljes
             * határlista is, admin_level sorrendben. A fix mezőket nem vesszük el:
             * a v5 boríték egyébként is kompatibilis, és a meglévő kliensek
             * (KAPP) nem esnek szét egy verzióváltáson.
             */
            ...(($apiVersion !== null && $apiVersion >= 5)
                ? ['hatarok' => $this->administrativeBoundaryList()]
                : []),
            'cim' => $this->cim,
            'megkozelites' => '',
            'plebania' => str_replace('<br>', "\n", strip_tags($this->plebania, '<br>')),
            'leiras' => str_replace('<br>', "\n", strip_tags($this->leiras, '<br>')),
            'accessibility' => $this->accessibility,
            'email' => $this->pleb_eml,
            'links' => $this->links->pluck('href')->toArray(),
            'misek' => $misek,
            'nyelvek' => $this->languages,
            'miserend_megjegyzes' => str_replace('<br>', "\n", strip_tags($this->misemegj, '<br>')),
            'adoraciok' => $adorations,
            'gyontatas' => $this->confessions ? $this->confessions : false,
            'kozossegek' => array_map(function($kozosseg) {
                return [
                    'nev' => $kozosseg->name,
                    'link' => $kozosseg->link
                ];
            }, $this->kozossegek),
            'koordinatak' => [ (float) $this->lat, (float) $this->lon ],
            'lat' => (float) $this->lat,
            'lon' => (float) $this->lon,
            'tavolsag' => (int) $this->distance
        ];

        if($length == 'full') {
            /*
             * #56: v5-től rövid út a teljes URL helyett — `{templomid}/{fájlnév}`.
             * A teljes cím minden képnél megismételte ugyanazt az előtagot; a bázis
             * ismert és állandó: `{domain}/kepek/templomok/`. (borazslo másik ötlete,
             * az egyedi kép-azonosító, szerveroldali munkát is igényelne — az marad.)
             */
            $return = array_merge($return, [
                'photos' => ($apiVersion !== null && $apiVersion >= 5)
                    ? $this->photos->map(fn($kep) => $kep->church_id . '/' . $kep->filename)->values()->toArray()
                    : $this->photos->pluck('url')->toArray()
            ]);

        }
        
        if($elastic) {

            // Kiegészítjük Budapest kerületekkel
            $romai = ['0','I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII','XIII','XIV','XV','XVI','XVII','XVIII','XIX','XX','XXI','XXII','XXIII'];
            
            preg_match('/^Budapest (.*?)\. kerület$/',$return['varos'],$match);
            if($match) {
                $return['varos'] = [ $return['varos'], 'Budapest '.array_search($match[1], $romai).'. kerület', 'Budapest' ];
            }

            unset($return['adoraciok']);
            unset($return['miserend_deprecated']);
            if($return['gyontatas'] == null) {
                $return['gyontatas'] = [];
            }

            //görög
            if( isset($this->denomination) && $this->denomination == 'greek_catholic') {
                $return['gorog'] = 'true';
            } else {
                $return['gorog'] = 'false';
            }

            // boundaries
            $return['boundaries'] = $this->boundaries()->pluck('boundary_id')->toArray();

            // #498: az országKÓD. borazslo a #496-ban ezt kérte kifejezetten
            // ("Az orszag kell országkódilag"): a statisztika és az Angular naptár
            // kódot vár, ami ma csak a régi orszagok.id-n keresztül létezik.
            $return['orszagkod'] = (string) ($this->countryCode() ?? '');

            // #89: a `location` mező geo_point-ként SZEREPEL a mappingben
            // (fajlok/elasticsearch/mappings/church.json), de eddig SENKI nem töltötte
            // fel — nulla dokumentumban volt benne érték. Emiatt semmilyen távolság-alapú
            // szűrés nem működhetett, és a kereső `hely`+`tavolsag` paramétere néma
            // no-op maradt: a találatok teljesen figyelmen kívül hagyták a helyet.
            //
            // Csak érvényes koordinátánál írjuk ki: a 0,0 az Atlanti-óceán (Null Island),
            // az rosszabb lenne, mint a hiányzó adat.
            if ((float) $this->lat != 0.0 || (float) $this->lon != 0.0) {
                $return['location'] = ['lat' => (float) $this->lat, 'lon' => (float) $this->lon];
            }

            /*
             * #644: akadálymentesség és csökkentett gluténtartalmú áldozás — szűrhető,
             * LAPOS mezőként. Az `accessibility` tömb ugyan eddig is kiment, de üres
             * templomnál üres tömb, ezért az ES-ben mapping se jött rá létre, és nem
             * lehetett rá szűrni. Itt fix kulcsokkal, mindig kiírjuk (üres stringgel,
             * ha nincs adat), így a churches indexbe ÉS a mass_index church-részébe is
             * bekerül — a kereső mindkettőn tud szűrni.
             */
            // #667: mely rítusokban van itt liturgia — a `nyelvek` mintájára, hogy a
            // templomkereső rítusra is tudjon szűrni (eddig a felület gombjai megvoltak,
            // de a templom-index nem tudott róluk semmit).
            $return['ritusok'] = $this->ritusok;

            $return['wheelchair'] = (string) ($this->wheelchair ?? '');
            $return['gluten_free_holidays'] = (string) ($this->{\GlutenFreeCommunion::HOLIDAYS_KEY} ?? '');
            $return['gluten_free_weekdays'] = (string) ($this->{\GlutenFreeCommunion::WEEKDAYS_KEY} ?? '');
        }
        
        return $return;
    }

    public function toElasticArray()
    {
        $church = $this->toAPIArray('elastic');           
        return $church;
    }   
    

    /* 
     * scopes
     *  boundaries() 
     *  inBBox()
     *  churchesAndMore
     *  countByUpdatedMonth
     *  countByUpdatedYear
     *  selectUpdatedMonth- ?
     *  selectUpdatedYear
     *  whereShortcutLike($keyword, $type)

     */
    function scopeBoundaries($query) {
        return $query->belongsToMany('Eloquent\Boundary', 'lookup_boundary_church')
                ->withTimestamps();
    } 
    
    function scopeInBBox($query, $bbox) {
        return $query->whereBetween('lat', [$bbox['latMin'], $bbox['latMax']])
                            ->whereBetween('lon', [$bbox['lonMin'], $bbox['lonMax']]);
    }

    function scopeChurchesAndMore($query) {
        /*
         * #257: ez a szűrő a NÉV MINTÁJÁBÓL következtet a misézőhely fajtájára —
         * „kápolna" a névben, tehát nem templom. A helyi `nev` oszlopon fut, mert az
         * OSM-név külön táblában él, több sorban templomonként: illeszteni rá csak
         * alkérdéssel lehetne, és a találat attól függene, melyik nyelvi változat nyer.
         *
         * Ez azonban nem csak technikai kérdés. A besorolást ma a NÉV dönti el, pedig
         * az OSM erre külön címkét tart (`building`, `amenity`, `place_of_worship`
         * fajtája). A helyes megoldás nem a névhalmazra váltás, hanem a besorolás
         * kivezetése a névből — az viszont önálló jegy, mert megváltoztatja, mely
         * misézőhelyek számítanak templomnak a listákban és a statisztikában.
         */
        return $query->where('nev', 'NOT LIKE', '%kápolna%');
    }

    function scopeSelectUpdatedMonth($query) {
        return $query->addSelect(DB::raw('DATE_FORMAT(frissites,\'%Y-%m\') as updated_month'), DB::raw('COUNT(*) as count_updated_month'));
    }

    function scopeSelectUpdatedYear($query) {
        return $query->addSelect(DB::raw('DATE_FORMAT(frissites,\'%Y\') as updated_year'), DB::raw('COUNT(*) as count_updated_year'));
    }

    function scopeCountByUpdatedMonth($query) {
        return $query->selectUpdatedMonth()
                        ->groupBy('updated_month')->orderBy('updated_month');
    }

    function scopeCountByUpdatedYear($query) {
        return $query->selectUpdatedYear()
                        ->groupBy('updated_year')->orderBy('updated_year');
    }

    function scopeWhereShortcutLike($query, $keyword, $type) {
        return $query->whereHas('keywordshortcuts', function ($query) use ($keyword, $type) {
                    $query->where('type', $type)->where('value', 'like', $keyword);
                });
    }
    
    /*
     * getSomethingAttribute -> $this->something;
     * 
     * names
     * alternative_names
     * denomination
     * holders
     * links
     * readAcess (of current user)
     * writeAccess (of current user)
     * jelzes
     * fullName
     * remarksSatus
     * location
	 * kozossegek
     * accessibility
     */
    public function getNamesAttribute($value) {


        $attributes = $this->osmAttributeMap();
        
        // Collect all the possible names of the church
        $names = [];
        // Let's find the main / default name 
        if (isset($attributes['name:hu'])) {
            array_unshift($names, $attributes['name:hu']);
        } elseif (isset($attributes['name'])) {
            array_unshift($names, $attributes['name']);
        } else {
            if($this->nev == '') 
                $this->nev = '(Név nélküli misézőhely)';                        
            array_unshift($names, $this->nev);
        }
        // Let's find the other names
        foreach ($attributes as $key => $value) {
            if (preg_match('/^name(:.*)?$/', $key)) {
                $names[] = $value;
            }
        }
               
        return array_values(array_unique($names));
    }

    public function getAlternativeNamesAttribute($value) {
        $attributes = $this->osmAttributeMap();

       // Collect all alternative names of the church
       $alternativeNames = [];
       // Collect alternative names
       if (isset($attributes['official_name:hu'])) {
           array_unshift($alternativeNames, $attributes['official_name:hu']);
       } elseif (isset($attributes['alt_name:hu'])) {
           array_unshift($alternativeNames, $attributes['alt_name:hu']);
       } elseif (isset($attributes['old_name:hu'])) {
           array_unshift($alternativeNames, $attributes['old_name:hu']);
       } elseif (isset($attributes['official_name'])) {
           array_unshift($alternativeNames, $attributes['official_name']);
       } elseif (isset($attributes['alt_name'])) {
           array_unshift($alternativeNames, $attributes['alt_name']);
       } elseif (isset($attributes['old_name'])) {
           array_unshift($alternativeNames, $attributes['old_name']);
       }

       foreach ($attributes as $key => $value) {
           if (preg_match('/^(alt_|old_|official_)name(:.*)?$/', $key)) {
               $alternativeNames[] = $value;
           }
       }                
       return array_values(array_unique($alternativeNames));
       

    }

    public function getDenominationAttribute($value) {
        // #542 (borazslo): a denomination az OSM-ből származzon — az `attributes` tábla
        // 'denomination' kulcsából (fromOSM=1, az OSM-sync tölti) —, nem a törékeny
        // egyházmegye-id (17,18,34) heurisztikából. A `templomok.denomination` oszlop
        // kivezetésre szánt (mindig NULL, semmi nem írja).
        // ÁTMENETI fallback: amíg az OSM-sync nem fed le minden templomot, a korábbi
        // egyházmegye-alapú érték marad (regresszió-mentesség) — eltávolítható, ha az
        // OSM-denomination minden templomra megvan.
        $osm = $this->attributes()->where('key', 'denomination')->value('value');
        if (!empty($osm)) {
            return $osm;
        }
        return in_array($this->egyhazmegye, [34, 17, 18]) ? 'greek_catholic' : 'roman_catholic';
    }
    
    public function getHoldersAttribute($value) {
        $holders =  \Eloquent\ChurchHolder::where('church_id',$this->id)->orderBy('status')->orderBy('updated_at','desc')->get()->groupBy('status');
        return $holders;
    }
    
    public function getLinksAttribute($value) {
        $links =  $this->hasMany('\Eloquent\ChurchLink')->get();
        return $links;
    }
    
    public function getReadAccessAttribute($value) {
        global $user;
        return $this->checkReadAccess($user);
    }
    
    /**
     * Relationship: External calendars for this church
     */
    public function externalCalendars() {
        return $this->hasMany('\Eloquent\ExternalCalendar', 'church_id');
    }
    
    /**
     * Property: Check if this church has an active external calendar
     */
    public function getHasExternalCalendarAttribute() {
        return $this->externalCalendars()->where('active', 1)->exists();
    }
    
    public function getWriteAccessAttribute($value) {
        global $user;
        return $this->checkWriteAccess($user);
    }
    
    public function getJelzesAttribute() {
            $jelzes = ""; //$this->remarksStatus['html'];

            if ($this->miseaktiv == 1) {                
                $calMassCount = \Eloquent\CalMass::where('church_id', $this->id)->count();
                if ($calMassCount < 1) {
                    $jelzes .= ' <i class="fa fa-lightbulb-o fa-lg" title="Nincs hozzá mise!" style="color:#FDEE00"></i> ';
                }
                
            }

            if ($this->ok == 'n')
                $jelzes.=" <i class='fa fa-ban fa-lg red' title='Nem engedélyezett!' ></i> ";
            elseif ($this->ok == 'f')
                $jelzes.=" <img src=/img/ora.gif title='Feltöltött/módosított templom, áttekintésre vár!' align=absmiddle> ";

            if($this->ok == 'i' AND $this->miseaktiv == 1) {
                // #174-B: frissites lehet NULL (új sémában a 0000-00-00
                // helyett). strtotime(NULL) === false, ami a < strtotime()
                // összehasonlításban truthy-vá válna, és minden NULL templomra
                // hibásan "Több mint 10 éves" warningot adna ki. NULL = nincs
                // adat, nem adunk warningot.
                $f = $this->frissitesOrNull();
                $updatedTime = $f !== null ? strtotime($f) : null;
                if($updatedTime !== null && $updatedTime < strtotime("-10 years")) {
                    $jelzes.=" <i class='fa fa-exclamation-triangle fa-lg red' title='Több mint 10 éves adatok!' > </i> ";
                } elseif ($updatedTime !== null && $updatedTime < strtotime("-5 year")) {
                    $jelzes.=" <i class='fa fa-exclamation fa-lg red' title='Több mint öt éves adatok!'> </i> ";
                } 
            }
            if($this->lat <= 0 OR $this->lon <= 0)
                $jelzes .= '<span class="fa fa-map-marker" aria-hidden="true" style="color:red" title="Nincsen koordináta!"></span>';
            if($this->osmid == '' OR $this->osmtype == '')
                $jelzes .= '<span class="fa fa-map-marker" aria-hidden="true" style="color:grey" title="OSM adat hiányzik még"></span>';
            return $jelzes;
    }

    /* Észrevételekhez azaz Remarks-hez kapcsolódó attribútumok */
    public function getRemarksiconAttribute() {
        // Treat empty string allapot as 'j' for grouping
        $allapotok = $this->remarks->map(function($remark) {
            return ($remark->allapot === '' ? 'j' : $remark->allapot);
        })->unique()->toArray();
        //printr($allapotok);
        if (in_array('u', $allapotok))
            $remarksicon = "ICONS_REMARKS_NEW";
        elseif (in_array('f', $allapotok))
            $remarksicon = "ICONS_REMARKS_PROCESSING";
        elseif (count($allapotok) > 0)
            $remarksicon = "ICONS_REMARKS_ALLDONE";
        else
            $remarksicon = "ICONS_REMARKS_NO";
        return $remarksicon;
    }
	
    public function getRemarksStatusTextAttribute() {
        // Treat empty string allapot as 'j' for grouping
        $allapotok = $this->remarks->map(function($remark) {
            return ($remark->allapot === '' ? 'j' : $remark->allapot);
        })->unique()->toArray();
        if (in_array('u', $allapotok))
            $remarksStatusText = "Új észrevétel érkezett.";
        elseif (in_array('f', $allapotok))
            $remarksStatusText = "Van még feldolgozás alatt álló észrevétel.";
        elseif (count($allapotok) > 0)
            $remarksStatusText = "Minden észrevétel feldolgozva.";
        else
            $remarksStatusText = "Nem érkezett még észrevétel.";
        return $remarksStatusText;
    }

    function getRemarksStatusAttribute($value) {
        $return = false;
        $remark = $this->remarks()
                        ->select('allapot')
                        ->groupBy('allapot')
                        ->orderByRaw("FIND_IN_SET(allapot, 'u,f,j')")->first();

        if (!$remark) {
            $return['text'] = "Nincsenek észrevételek";
            $return['html'] = "<i class='fa fa-gift fa-lg' style='color:#D3D3D3'  title='" . $return['text'] . "'></i>";
            $return['mark'] = false;
        } else if ($remark->allapot == 'u') {
            $return['text'] = "Új észrevételt írtak hozzá!";
            $return['html'] = "<a href=\"javascript:OpenScrollWindow('/templom/$this->id/eszrevetelek',550,500);\"><img src=/img/csomag.gif title='" . $return['text'] . "' align=absmiddle border=0></a> ";
            $return['mark'] = 'u';
        } else if ($remark->allapot == 'f') {
            $return['text'] = "Észrevétel javítása folyamatban!";
            $return['html'] = "<a href=\"javascript:OpenScrollWindow('/templom/$this->id/eszrevetelek',550,500);\"><img src=/img/csomagf.gif title='" . $return['text'] . "' align=absmiddle border=0></a> ";
            $return['mark'] = 'f';
        } else if ($remark->allapot == 'j') {
            $return['text'] = "Észrevételek";
            $return['html'] = "<a href=\"javascript:OpenScrollWindow('/templom/$this->id/eszrevetelek',550,500);\"><img src=/img/csomag1.gif title='" . $return['text'] . "' align=absmiddle border=0></a> ";
            $return['mark'] = 'j';
        }
        return $return;
    }

    /* Javaslat csomagokhoz azaz suggestion_packages-hez kapcsolódó attribútumok */
    public function getHasPendingSuggestionPackageAttribute() {        
        $hasPendingSuggestionPackage = $this->suggestionPackages()
                        ->select('id')
                        ->where('state', 'PENDING')                        
                        ->first();                        
        if ($hasPendingSuggestionPackage) {
            return true;
        } 
        return false;
    }


    function getFullNameAttribute($value) {
        
        $return = $this->names[0];

        if (!empty($this->alternative_names)) {
            $return .= ' (' . $this->alternative_names[0] . ')';
        } else {
            $return .= ' (' . $this->locationCityName() . ')';
        }
        return $return;
    }


    
    /**
     * #568: Búcsú-emlékeztető a templomgondnokoknak, 21 nappal a búcsú előtt.
     *
     * borazslo spec-je: „A várható dátum előtt mondjuk 21 nappal küldjük ki az emailt
     * a templomgondnokoknak (nem kell egyházmegye felelős, se általános admin)."
     *
     * A metódus szándékosan ITT van, nem a User osztályban — borazslo javaslata:
     * „Szerintem sokkal inkább valami church osztályhoz tartozik, mert a közelgő
     * búcsúval rendelkező TEMPLOMNAK értesítjük a gondnokait." A kiindulópont
     * valóban a templom, a felhasználó csak a címzett.
     *
     * A dátum szabad szövegből jön, tehát lehet benne pontatlanság — borazslo
     * megengedte: „nem baj, ha +/- pár nap […] az értesítés nem kell pontosan menjen".
     *
     * @param int $napokElotte hány nappal a búcsú előtt szóljunk
     * @return int hány levelet tettünk sorba
     */
    public static function sendBucsuReminders(int $napokElotte = 21): int {
        $type = 'holder_bucsu_reminder';
        $celDatum = date('Y-m-d', strtotime("+$napokElotte days"));

        $erintettek = [];
        foreach (self::where('ok', 'i')->get() as $templom) {
            if ($templom->nextBucsuDate() === $celDatum) {
                $erintettek[$templom->id] = $templom;
            }
        }

        if ($erintettek === []) {
            return 0;
        }

        /*
         * Gondnok-kiválasztás: a #290 ünnep-emlékeztető mintája, az érintett
         * templomokra szűkítve. Egyházmegye-felelős és admin NEM kap — borazslo kérése.
         * A dedup a levelek táblájából megy, egy hónapos ablakkal: a napi cron ne
         * küldjön kétszer ugyanannak.
         */
        $users2notify = DB::table('templomok')
            ->select('user.*')
            ->join('church_holders', 'templomok.id', '=', 'church_holders.church_id')
            ->join('user', 'user.uid', '=', 'church_holders.user_id')
            ->whereIn('templomok.id', array_keys($erintettek))
            ->whereRaw(" NOT EXISTS ( SELECT 1 FROM emails WHERE `type` = ? AND `status` IN ('sent','queued','sending','error') AND emails.to = user.email AND emails.updated_at > ? ) ",
                [$type, date('Y-m-d H:i:s', strtotime('-1 month'))])
            ->where('church_holders.status', 'allowed')
            ->whereNull('church_holders.deleted_at')
            ->where('user.notifications', 1)
            ->whereNotNull('user.email')->where('user.email', '<>', '')
            ->groupBy('user.email')
            ->get();

        $kuldott = 0;
        foreach ($users2notify as $user2notify) {
            $user = new \User($user2notify->uid);
            $user->getResponsabilities();

            $sajatTemplomok = [];
            foreach ($user->responsible['church'] as $churchID) {
                if (isset($erintettek[$churchID])) {
                    $templom = $erintettek[$churchID];
                    $templom->bucsuDatum = $celDatum;
                    $sajatTemplomok[$churchID] = $templom;
                }
            }
            if ($sajatTemplomok === []) {
                continue;
            }

            $user->responsible['church'] = $sajatTemplomok;
            $user->bucsuDatum = $celDatum;

            $email = new \Eloquent\Email();
            $email->to = $user->email;
            $email->render($type, $user);
            $email->addToQueue();
            $kuldott++;
        }

        return $kuldott;
    }

    /**
     * #568: a `bucsu` szabad szöveg gépi alakja.
     *
     * @return array{bucsu: ?array, szentsegimadas: ?array, unparsed: string}
     */
    public function bucsuOccasions(): array {
        /*
         * #568: a búcsú a MEGJEGYZÉS mezőből jön, nem a `bucsu` oszlopból.
         *
         * borazslo javítása a #809-hez: „itt a `templomok.bucsu` mezőt használja. Mert
         * hát valóban mintha lenne ilyen mező, csak nem használjuk egyáltalán. Az osm
         * adatokból vesszük már a búcsút, vagy ha nincs, akkor a megjegyzés mezőből
         * próbáljuk kitalálni."
         *
         * Igaza volt, és a különbség nagy. Mérve az aktív templomokon:
         *
         *   bucsu oszlop kitöltve:            218   (ebből felismert dátum: 166)
         *   megjegyzés említ búcsút:         1472   (ebből felismert dátum: 1364)
         *
         * A `bucsu` oszlop ráadásul NEM szerkeszthető (nincs az edit.php
         * allowedFields listájában) és egyetlen sablonban sem jelenik meg — csak a
         * tábla-export adja ki. Vagyis nem karbantartott adat.
         *
         * A megjegyzésből CSAK akkor fogadjuk el a dátumot búcsúnak, ha a szöveg maga
         * is kimondja. Enélkül bármely ottani dátum búcsúnak számítana — mérve 135
         * templomnál olvastunk ki így olyan dátumot, aminek semmi köze a búcsúhoz, és
         * a gondnokuk téves értesítőt kapott volna.
         */
        $eredmeny = \Bucsu::containsBucsuLabel($this->megjegyzes)
            ? \Bucsu::parse($this->megjegyzes)
            : ['bucsu' => null, 'szentsegimadas' => null, 'unparsed' => ''];

        // A régi oszlop utolsó tartaléknak marad: néhány templomnál csak ott van adat.
        if ($eredmeny['bucsu'] === null) {
            $regi = \Bucsu::parse($this->bucsu);
            if ($regi['bucsu'] !== null) {
                $eredmeny = $regi;
            }
        }

        /*
         * #568: az OSM `patron_day` tagje MINDKETTŐT felülírja.
         *
         * borazslo vetette fel: „Sőt OSM ismeri az egyáltalán nem elterjed patron_day
         * kulcsot." Ez strukturált adat, tehát megbízhatóbb, mint amit egy szabad
         * szöveges mezőből ki tudunk olvasni — és nem kell hozzá se új oszlop, se új
         * szerkesztő-mező: a szinkron minden OSM-taget elment az `attributes` táblába.
         */
        $patronDay = \Bucsu::parsePatronDay($this->patronDayTag());
        if ($patronDay !== null) {
            $eredmeny['bucsu'] = $patronDay;
            $eredmeny['forras'] = 'patron_day';
        } elseif ($eredmeny['bucsu'] !== null) {
            $eredmeny['forras'] = 'bucsu_mezo';
        } else {
            $eredmeny['forras'] = null;
        }

        return $eredmeny;
    }

    /** Az OSM `patron_day` tag nyers értéke, ha a szinkron elmentette. */
    private function patronDayTag(): ?string {
        $attributum = $this->attributes()->where('key', 'patron_day')->first();

        return $attributum->value ?? null;
    }

    /**
     * A következő búcsú dátuma egy adott naptól számítva.
     *
     * Egy értesítő cronnak pontosan ez kell: "mikor lesz legközelebb". Ha az idei
     * dátum már elmúlt, a jövő évit adjuk — a mozgó ünnepek miatt ezt nem lehet
     * egyszerű hónap/nap összehasonlítással kiváltani.
     *
     * @param string|null $tol Y-m-d, alapból a mai nap
     * @return string|null Y-m-d, vagy null ha a mező nem értelmezhető
     */
    public function nextBucsuDate(?string $tol = null): ?string {
        $alkalom = $this->bucsuOccasions()['bucsu'];
        if ($alkalom === null) {
            return null;
        }

        $tol = $tol ?? date('Y-m-d');
        $ev = (int) substr($tol, 0, 4);

        foreach ([$ev, $ev + 1] as $vizsgaltEv) {
            $datum = \Bucsu::resolve($alkalom, $vizsgaltEv);
            if ($datum !== null && $datum >= $tol) {
                return $datum;
            }
        }

        return null;
    }

    function getLocationAttribute($value) {
        $location = new \stdClass();

        $location->lat = $this->lat;
        $location->lon = $this->lon;
        if($this->osmtype AND $this->osmid) {
            $location->osm = array(
                'type' => $this->osmtype, 
                'id'=>$this->osmid,
                'url' => 'https://www.openstreetmap.org/'.$this->osmtype.'/'.$this->osmid
                 );
				 
			$location->address = false;	 
			//if(isset($this->{'addr:postcode'}))
			//	 $location->address = $this->{'addr:postcode'}." ";
			//if(isset($this->{'addr:city'}))
			//	 $location->address .= $this->{'addr:city'}. " ";
			if(isset($this->{'addr:street'}))
				 $location->address .= $this->{'addr:street'}. " ";	 
			if(isset($this->{'addr:housenumber'}))
				 $location->address .= " ".$this->{'addr:housenumber'}.".";	 
				 
				 
        } else {            
            $location->address = $this->cim;
        }
				
        /* Adminisrative Boundaries(Country,County, City, District) */
        $boundaries = $this->boundaries()
                ->where('boundary','administrative')
                ->whereIn('admin_level',[2,4,6,8,9,10])
                ->orderBy('admin_level')
                ->get()->toArray();

        $boundaries = self::pickAdministrativeBoundaries($boundaries);

        if(array_key_exists(0, $boundaries)) $location->country = $boundaries[0];
        if(array_key_exists(1, $boundaries)) $location->county = $boundaries[1];
        if(array_key_exists(2, $boundaries)) $location->city = $boundaries[2];
        if(array_key_exists(3, $boundaries)) $location->district = $boundaries[3];

        return $location;
    }

    /**
     * #496/#497/#498: az admin_level ország/megye/település sorrendbe rendezése.
     *
     * A location() pozíció szerint címkéz: a rendezett lista 0., 1., 2., 3. eleme
     * lesz az ország, megye, település, kerület. Ez addig működik, amíg minden
     * ország ugyanazokat a szinteket használja — de nem használják:
     *
     *   Magyarország  2 ország | 4 nagyrégió | 6 vármegye | 8 település | 9 kerület
     *   Szlovákia     2 ország | 4 kraj      | 6 okres    | 8 obec
     *   Szerbia       2 ország | 4 tartomány | 6 okrug    | 8 opstina    | 9 település
     *   Ukrajna       2 ország | 4 oblaszty  | 6 rajon    |              | 9 település
     *   Románia       2 ország | 4 judet     |    -       | 8 comuna/oras
     *
     * Romániában NINCS 6-os szint: a megyét a 4-es hordozza. A korábbi
     * whereIn([2,6,8,9,10]) ezt kizárta, így a román templomoknál a lista
     * [ország, település] lett — vagyis a TELEPÜLÉS csúszott a megye helyére,
     * a location->city pedig NULL maradt. Ez 538 templomot érint (a határon túli
     * állomány 80%-a), és mindenhová továbbgyűrűzik, ahol location.city-t
     * használunk (home.twig ajánló, szomszédos templomok panel, Angular naptár).
     *
     * Ezért a 4-es szintet is behúzzuk, de CSAK akkor hagyjuk bent, ha nincs
     * 6-os. Magyarországon van 6-os (vármegye), így a nagyrégió kiesik és a
     * viselkedés bitre azonos marad a korábbival — a templomok 87%-át ez a
     * változás nem érinti.
     *
     * @param array $boundaries admin_level szerint növekvőn rendezve
     */
    static function pickAdministrativeBoundaries(array $boundaries): array {
        $hasCounty = false;
        foreach ($boundaries as $boundary) {
            if ((int) ($boundary['admin_level'] ?? 0) === 6) {
                $hasCounty = true;
                break;
            }
        }

        if (!$hasCounty) {
            return array_values($boundaries);
        }

        return array_values(array_filter(
            $boundaries,
            fn($boundary) => (int) ($boundary['admin_level'] ?? 0) !== 4
        ));
    }

    /**
     * #56 / #805: az összes adminisztratív határ, admin_level szerint rendezve.
     *
     * borazslo kérése: „Ha mi úgysem használunk fix orszag-megye-varos-t akkor nem
     * lenne helyesebb API v5 templomlekérdezéshez egyszerűen berakni az összes
     * adminisztrációs boundary-t admin_level sorrendben egy tömbben szépen listázva?
     * Flexibilisebb és hosszabb távon is működöbb."
     *
     * Igaza van, és pont ez a kivezetés lényege: az `admin_level` jelentése
     * országonként MÁS (Romániában nincs 6-os szint, Kölnben a 6-os maga a város),
     * tehát a fix három mező eleve hazugság volt. A lista nem próbál dönteni arról,
     * melyik szint „a megye" — a fogyasztó látja a szintet, és eldönti maga.
     *
     * @return array<int,array{szint: int, nev: string, alt_nev: ?string, osm: ?string}>
     */
    public function administrativeBoundaryList(): array {
        $hatarok = $this->boundaries()
                ->where('boundary', 'administrative')
                ->orderBy('admin_level')
                ->get();

        $lista = [];
        foreach ($hatarok as $hatar) {
            $nev = trim((string) $hatar->name);
            if ($nev === '') {
                continue;
            }

            $lista[] = [
                'szint' => (int) $hatar->admin_level,
                'nev' => $nev,
                'alt_nev' => trim((string) $hatar->alt_name) !== '' ? $hatar->alt_name : null,
                'osm' => ($hatar->osmtype && $hatar->osmid)
                    ? $hatar->osmtype . '/' . $hatar->osmid
                    : null,
            ];
        }

        return $lista;
    }

    /**
     * #496 / #497 / #498: a templom helynevei az OSM-határokból, a régi oszlopokra
     * való visszaeséssel.
     *
     * A három jegy a `templomok.varos`, `.megye` és `templomok.orszag` kivezetéséről
     * szól. Ahhoz, hogy az oszlopok eldobhatók legyenek, előbb minden fogyasztónak
     * ezeken a metódusokon kell keresztülmennie — utána a kivezetés annyi, hogy a
     * visszaesési ág kiesik innen, és nem kell 23 fájlt átírni.
     *
     * A visszaesés SZÁNDÉKOSAN bent van most: a szlovák állomány 23%-ának egyáltalán
     * nincs boundary-ja (szinkronhiány), és 47 templomnak nincs koordinátája sem.
     * Amíg ez így van, a régi oszlop a jobb válasz, mint az üres string.
     *
     * A besorolás szint szerint megy, nem pozíció szerint: a location() a rendezett
     * lista 0./1./2. elemét címkézi, ami hiányos határláncnál elcsúszik.
     */
    /**
     * #431: a válaszban lévő misékhez hozzáfűzi a SAJÁT helyszínüket, ha van.
     *
     * Csak akkor kerül be a `helyszin` kulcs, ha az alkalomnak tényleg van külön
     * koordinátája — a templomi misékre a `templom.koordinatak` a válasz. Így a
     * kliens egyetlen kulcs meglétéből tudja, hogy „ez máshol lesz", és nem kell
     * lebegőpontos számokat összehasonlítania.
     *
     * @param  array<int,array<string,mixed>> $misek
     * @return array<int,array<string,mixed>>
     */
    private function attachOwnLocations(array $misek): array {
        $ids = array_values(array_filter(array_map(
            fn($mise) => (int) ($mise['mise_id'] ?? 0),
            $misek
        )));
        if (!$ids) {
            return $misek;
        }

        $helyszinek = DB::table('cal_masses')
                ->whereIn('id', $ids)
                ->whereNotNull('location_lat')
                ->whereNotNull('location_lon')
                ->get(['id', 'location_lat', 'location_lon', 'location_name'])
                ->keyBy('id');

        if ($helyszinek->isEmpty()) {
            return $misek;
        }

        foreach ($misek as $key => $mise) {
            $hely = $helyszinek->get((int) ($mise['mise_id'] ?? 0));
            if (!$hely) {
                continue;
            }
            $misek[$key]['helyszin'] = [
                'koordinatak' => [(float) $hely->location_lat, (float) $hely->location_lon],
                'nev' => (string) ($hely->location_name ?? ''),
            ];
        }

        return $misek;
    }

    /**
     * #824: a település neve SQL-ből, tömeges lekérdezésekhez.
     *
     * A `locationCityName()` Eloquent-modellt kér. Több tömeges lekérdezés viszont
     * `DB::table()`-lel dolgozik, és nyers `stdClass` sorokat kap — azokon nincs
     * metódus. Ez már meg is bosszulta magát: a `Campaign::sendWeeklyEmail()`
     * `$c->locationCityName()`-t hívott egy `stdClass`-on, és a heti önkéntes-levél
     * emiatt SOHA nem ment ki („Call to undefined method stdClass::locationCityName()").
     *
     * A másik hiba ugyanebből a családból: a `User::sendUpdateNotification()` a
     * `templomok.varos` oszlopra esett vissza — arra, amit a kivezetés eldobott.
     *
     * Ezért van egy közös definíció: aki SQL-ben kéri a települést, innen vegye.
     *
     * @param string $churchIdColumn a templom azonosítója a külső lekérdezésben
     *                               (pl. `templomok.id` vagy `t.id`)
     */
    /**
     * #854: a legközelebbi misézőhelyek egy koordinátához.
     *
     * KÖZÖS lekérdezés a nyilvános API (`Api\NearBy`) és a főoldali „mi van a
     * közelemben" doboz (`Html\Ajax\NearBy`) között. A kettő SZÉTVÁLT — borazslo kérése:
     * a főoldal ne a nyilvános API-t hívja, mert az torzítja az API-statisztikát, és a
     * publikus szerződést sem lehet szabadon alakítani, amíg a saját oldalunk függ tőle.
     * A LEKÉRDEZÉS viszont maradjon egy: a távolságszámítás és a kizárások pontosan
     * ugyanazok, és nem szabad, hogy szétcsússzanak.
     *
     * A `(0,0)` KIZÁRÁSA szándékos, és nem elméleti: a #94-ben a mobilalkalmazás
     * GPS-fix nélkül (0,0)-t küldött, és erre minden magyar templom ~5000 km-re jött
     * vissza — érvényes találatnak látszó szemétként. A koordináta nélküli templomok
     * ugyanezen a nulla-ponton ülnek (a `lat`/`lon` DEFAULT 0.0), ezért őket is kizárjuk.
     *
     * @return \Illuminate\Database\Eloquent\Builder a `distance` mezővel (méter)
     */
    public static function nearestQuery(float $lat, float $lon, int $limit = 10) {
        return self::select()
            ->addSelect(DB::raw(
                "ST_distance_sphere( ST_GeomFromText('POINT ( " . (float) $lat . " " . (float) $lon . " )', 4326),"
                . " ST_GeomFromText(CONCAT('POINT ( ',lat,' ', lon, ')'), 4326) ) as distance"
            ))
            ->where('ok', 'i')
            ->where('lat', '<>', '')
            ->where('lon', '<>', '')
            ->whereRaw('NOT (lat = 0 AND lon = 0)')
            ->orderBy('distance', 'ASC')
            ->limit($limit);
    }

    /**
     * #854/#94: van-e egyáltalán értelmes helyzetünk?
     *
     * A (0,0) a Guineai-öbölben van; ott nincs katolikus misézőhely. Ha ez jön be, a
     * hívó nem helyzetet küldött, hanem a GPS-fix hiányát — és jobb ezt megmondani,
     * mint egy ötezer kilométeres listát adni érvényes válaszként.
     */
    public static function isUsablePosition(float $lat, float $lon): bool {
        return !($lat === 0.0 && $lon === 0.0);
    }

    public static function citySubquerySql(string $churchIdColumn = 'templomok.id'): string {
        return self::boundaryNameSubquerySql([8, 9, 10], $churchIdColumn);
    }

    /**
     * #824: egy adminisztratív határ neve alkérdésként, szint-sorrenddel.
     *
     * A `FIELD()` rendezés adja a preferenciát: a felsorolás sorrendje dönt, nem a
     * szint nagysága — így ugyanaz a szabály érvényesül, mint a modell-oldali
     * `adminBoundaryName()`-ben.
     *
     * @param int[] $szintek admin_level értékek, preferencia szerint
     */
    public static function boundaryNameSubquerySql(array $szintek, string $churchIdColumn = 'templomok.id'): string {
        $lista = implode(', ', array_map('intval', $szintek));

        return "(SELECT b.name FROM lookup_boundary_church lbc"
            . " JOIN boundaries b ON b.id = lbc.boundary_id"
            . " WHERE lbc.church_id = $churchIdColumn AND b.boundary = 'administrative'"
            . " AND b.admin_level IN ($lista)"
            . " ORDER BY FIELD(b.admin_level, $lista) LIMIT 1)";
    }

    private function adminBoundaryName(array $szintek): ?string {
        $talalat = $this->boundaries()
                ->where('boundary', 'administrative')
                ->whereIn('admin_level', $szintek)
                ->orderBy('admin_level', 'desc')
                ->first();

        $nev = trim((string) ($talalat->name ?? ''));

        return $nev !== '' ? $nev : null;
    }

    /**
     * Település. Ha van kerület is, azzal együtt — így a nagyvárosi templomoknál nem
     * vész el a pontosság.
     *
     * Ez SZÁNDÉKOSAN a régi oszlop alakját reprodukálja, mert az API-ban és a
     * felületen ez látszik. Két országfüggő eset van, mindkettőt valódi adaton mértem:
     *
     *   Budapest, Szent Imre-templom      8=Budapest  9=XI. kerület  10=Szentimreváros
     *       -> "Budapest XI. kerület", ami bitre a régi `templomok.varos`.
     *       A puszta legspecifikusabb elem "Szentimreváros" lenne: pontosabb ugyan,
     *       de a látogató szempontjából visszalépés ahhoz képest, amit ma lát.
     *
     *   Köln, St. Aposteln                6=Köln      9=Innenstadt   10=Altstadt-Nord
     *       Köln kreisfreie Stadt, tehát NINCS 8-as szintje. Ha vakon a 9-esre esnénk
     *       vissza, "Innenstadt" jönne ki "Köln" helyett.
     *
     * Ezért a kerületet CSAK akkor fűzzük hozzá, ha a település a 8-as szintről jött.
     * Ha a településnek a 6-osra kellett visszaesni, a 9-es már másfajta felosztás,
     * és a régi adat sem tartalmazta.
     */
    public function locationCityName(): string {
        $telepules = $this->adminBoundaryName([8]);
        if ($telepules !== null) {
            $kerulet = $this->adminBoundaryName([9]);
            return $kerulet !== null ? $telepules . ' ' . $kerulet : $telepules;
        }

        // Nincs 8-as szint (pl. német kreisfreie Stadt): a 6-os maga a település.
        return $this->adminBoundaryName([6])
            ?? $this->adminBoundaryName([9])
            ?? $this->adminBoundaryName([10])
            ?? '';
    }

    /** Megye. Romániában nincs 6-os szint, ott a 4-es (judet) hordozza. */
    public function locationCountyName(): string {
        return $this->adminBoundaryName([6]) ?? $this->adminBoundaryName([4]) ?? '';
    }

    public function locationCountryName(): string {
        return $this->adminBoundaryName([2]) ?? '';
    }

    /**
     * Magyarországi templom-e. A naptár ez alapján dönti el, kell-e nyelvi zászló a
     * magyar mise mellé, a statisztika pedig ez alapján szűkít.
     *
     * Elsődlegesen az ISO-kód, mert a `templomok.orszag = 12` a kivezetéssel eltűnik.
     */
    public function isInHungary(): bool {
        return $this->countryCode() === 'HU';
    }

    /**
     * #497: rendezés a település szerint, boundary-alapon.
     *
     * A katalógusok eddig `orderBy('varos')`-t használtak. Az oszlop megszűnt, a
     * település viszont továbbra is rendezési szempont — korrelált alkérdéssel
     * kérjük le. Nem olcsó, de ezek admin/katalógus oldalak, nem forgalmas útvonalak,
     * és a lista használhatatlan lenne település szerinti csoportosítás nélkül.
     */
    public function scopeOrderByCity($query, string $irany = 'asc') {
        return $query->orderByRaw(
            "(SELECT b.name FROM lookup_boundary_church lbc"
            . " JOIN boundaries b ON b.id = lbc.boundary_id"
            . " WHERE lbc.church_id = templomok.id AND b.boundary = 'administrative'"
            . " AND b.admin_level IN (8, 6, 9, 10)"
            . " ORDER BY FIELD(b.admin_level, 8, 6, 9, 10) LIMIT 1) " . ($irany === 'desc' ? 'DESC' : 'ASC')
        );
    }

    /**
     * #498: lekérdezés-szintű szűrés a magyarországi templomokra.
     *
     * A statisztika eddig `where('orszag', 12)`-vel szűkített — pont az az oszlop,
     * amit ki akarunk vezetni. A boundary-alapú megfelelője az országhatáron megy.
     *
     * NÉVRE és ISO-kódra IS illesztünk. Az ISO a helyes hosszú távon, de ma 7964
     * határból egyetlenegynél van kitöltve (a szinkronnak újra kell futnia), addig
     * a puszta ISO-szűrés üres statisztikát adna.
     */
    public function scopeInHungary($query) {
        return $query->whereIn('templomok.id', function ($sub) {
            $sub->select('lookup_boundary_church.church_id')
                ->from('lookup_boundary_church')
                ->join('boundaries', 'boundaries.id', '=', 'lookup_boundary_church.boundary_id')
                ->where('boundaries.admin_level', 2)
                ->where(function ($w) {
                    $w->where('boundaries.iso3166_1', 'HU')
                      ->orWhere('boundaries.name', 'Magyarország');
                });
        });
    }

    /**
     * #498: a templom országkódja (ISO 3166-1 alpha-2) az OSM-határból.
     *
     * A `templomok.orszag` oszlop kivezetésének az volt az egyik akadálya, hogy az
     * „ország -> kód" leképezés kizárólag rajta keresztül létezett: az `orszagok`
     * táblában nincs ISO-kód, csak `telkod`. A statisztika (`stat.php`, orszag=12)
     * és az Angular naptárnak átadott országkód is emiatt ragadt hozzá.
     *
     * Az OSM országrelációi hordozzák az `ISO3166-1` taget, ezt a boundary-szinkron
     * mostantól eltárolja. Itt csak kiolvassuk.
     *
     * NULL-t ad, ha a templomnak nincs országhatára (nincs koordinátája, vagy a
     * szinkron még nem ért oda), illetve ha a szinkron az oszlop bevezetése óta még
     * nem futott le rá. A hívónak KEZELNIE kell a NULL-t — ezért nem esünk vissza
     * csendben a régi oszlopra, hogy a hiányzó lefedettség látható maradjon.
     */
    public function countryCode(): ?string {
        $code = $this->boundaries()
                ->where('boundary', 'administrative')
                ->where('admin_level', 2)
                ->whereNotNull('iso3166_1')
                ->orderBy('boundaries.id')
                ->value('iso3166_1');

        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : $code;
    }


	public function getKozossegekAttribute($value) {
		$api = new \ExternalApi\KozossegekApi();		
		$api->query = "miserend/".$this->id;
		$api->run();
		if(isset($api->jsonData->data) > 0 ) {
			foreach($api->jsonData->data as $key => $data) {
				if(isset($data->age_group) ) {
					$api->jsonData->data[$key]->age_group = array_filter( explode(", ", $data->age_group), 'strlen' );
				}
				if(isset($data->tags)) {
					$api->jsonData->data[$key]->tags = array_filter( explode(", ", $data->tags), 'strlen' ); 
				}
			}
			return $api->jsonData->data;
		}
		else
			return [];			
	}

    public function getAccessibilityAttribute($value) {
        $return = [];
        foreach(['wheelchair','toilets:wheelchair','wheelchair:description','hearing_loop','disabled:description'] as $k=>$accessibility) {			
			if(isset($this->$accessibility)) {			
					$return[$accessibility] = $this->$accessibility;
			}
		}
        return $return;
    }


    /*
     * #284: a `payment:credit_cards` OSM-értékeinek EGYETLEN forrása. Ugyanez a mondat
     * a címke az /editosm legördülőjében és a nyilvános szöveg a templomlapon — így egy
     * szöveg egy helyen van megadva. Az üres kulcs a "nincs adat" ág: az /editosm-en
     * választható, a templomlapon viszont nem állítunk vele semmit (message = null).
     */
    public const CARD_DONATION_OPTIONS = [
        '' => 'Nincs információ.',
        'yes' => 'Bankkártyás, digitális persely is elérhető.',
        'limited' => 'Bankkártyás adományozás a sekrestyében vagy külön kérésre lehetséges.',
        'no' => 'Csak készpénzes adományozás lehetséges.',
    ];

    /* Mely `payment:credit_cards` értékek jelentenek tényleges kártyás lehetőséget. */
    public const CARD_DONATION_AVAILABLE = ['yes', 'limited'];

    public function getCardDonationAttribute(): array {
        $value = $this->getAttribute('payment:credit_cards');
        $message = ($value === null || $value === '')
            ? null
            : (self::CARD_DONATION_OPTIONS[$value] ?? null);

        return [
            'value' => $value,
            'message' => $message,
            'available' => in_array($value, self::CARD_DONATION_AVAILABLE, true),
        ];
    }

    public function getGlutenFreeCommunionAttribute(): array {
        return \GlutenFreeCommunion::details(
            $this->getAttribute(\GlutenFreeCommunion::HOLIDAYS_KEY),
            $this->getAttribute(\GlutenFreeCommunion::WEEKDAYS_KEY)
        );

    }

    /*
     * #671: hány aktív misézőhelyről tudunk EGYÁLTALÁN valamit az adott témában.
     *
     * Akadálymentességnél a „nem akadálymentes" IS adat — azt is tudjuk. Ezért itt
     * bármilyen kitöltött érték számít, nem csak a pozitív.
     *
     * Azért kell, mert a szűrő ma szinte üres adathalmazon dolgozik (a seedben egyetlen
     * templomnak sincs `wheelchair` attribútuma), és a felhasználó a nulla találatot
     * hibának hinné. Inkább mondjuk meg neki, hol tartunk.
     */
    public static function facilityCoverage(): array {
        $count = function (array $keys): int {
            return (int) \Eloquent\Attribute::whereIn('key', $keys)
                ->whereIn('church_id', function ($q) {
                    $q->select('id')->from('templomok')
                      ->where('ok', 'i')->whereNull('deleted_at');
                })
                ->distinct()
                ->count('church_id');
        };

        return [
            'wheelchair' => $count(['wheelchair']),
            'gluten_free' => $count([
                \GlutenFreeCommunion::HOLIDAYS_KEY,
                \GlutenFreeCommunion::WEEKDAYS_KEY,
            ]),
        ];
    }

    /**
     * #671: a keresési eredményhez tartozó tájékoztató üzenetek — csak azokra a
     * témákra, amikre a felhasználó ténylegesen szűrt.
     *
     * @param  bool $wheelchair  aktív-e az akadálymentesség-szűrő
     * @param  bool $glutenFree  aktív-e a gluténmentes szűrő
     * @return string[]
     */
    public static function facilityCoverageMessages(bool $wheelchair, bool $glutenFree): array {
        if (!$wheelchair && !$glutenFree) {
            return [];
        }

        $coverage = self::facilityCoverage();
        $messages = [];

        if ($wheelchair) {
            $messages[] = 'Az akadálymentességi adatokat még gyűjtjük, ez nálunk új dolog: eddig '
                . '<strong>' . $coverage['wheelchair'] . ' misézőhelyről</strong> tudjuk, hogy '
                . 'akadálymentes-e. Most ezek között keresünk. Ha tudsz másról, küldd el nekünk észrevételként!';
        }

        if ($glutenFree) {
            $messages[] = 'A csökkentett gluténtartalmú áldozás lehetőségeit csak nemrég kezdtük gyűjteni, '
                . 'ezért eddig <strong>' . $coverage['gluten_free'] . ' misézőhelynek</strong> van ilyen adata. '
                . 'Most ezek között keresünk. Ha tudsz másról, küldd el nekünk észrevételként!';
        }

        return $messages;
    }
	
    /*
     * What does 'M' mean?
     */
    public function MgetReligious_administration() {
        $this->religious_administration = new \stdClass();
        $this->religious_administration->diocese = new \Diocese();
        $this->religious_administration->diocese->getByChurchId($this->id);
        $this->religious_administration->deaconry = new \Deaconry();
        $this->religious_administration->deaconry->getByChurchId($this->id);
        $this->MgetParish();
    }

    function MgetParish() {
        if (!isset($this->religious_administration)) {
            $this->religious_administration = new \stdClass();
        }
        $parish = new \Parish();
        $parish->getByChurchId($this->id);
        $this->religious_administration->parish = $parish;
    }

    /**
     * #409: mit írjunk ki a nem-publikus templom oldalán, és kinek?
     *
     * A jogosultság maga rendben volt: a checkWriteAccess() SOHA nem nézi az `ok`-ot,
     * tehát egy `allowed` gondnok (vagy ős-templom gondnoka, vagy egyházmegyei felelős)
     * eddig is szerkeszthette a nem-publikus templomát. Csak épp azt olvasta közben, hogy
     * „Csak adminisztrátorok számára látható ez az oldal" — ami neki egyszerűen nem igaz,
     * és pont az ellenkezőjét hitette el vele.
     *
     * Szándékosan tiszta, statikus függvény (se DB, se globális), hogy tesztelhető legyen.
     *
     * @param  string $ok             a templom `ok` mezője: i = nyilvános, f = áttekintésre vár, n = letiltva
     * @param  bool   $isAdmin        van-e `miserend` jogosultsága
     * @param  bool   $hasWriteAccess szerkesztheti-e (gondnok / egyházmegyei felelős / admin)
     * @return array{0:string,1:string}|null  [üzenet, szint] vagy null, ha nincs mit mondani
     */
    public static function visibilityNotice(string $ok, bool $isAdmin, bool $hasWriteAccess): ?array {
        if ($ok === 'i') {
            return null;
        }

        // Akinek nincs írási joga, az ide amúgy sem jut be (checkReadAccess), de ha
        // mégis, maradjon a régi, semleges szöveg.
        if ($isAdmin || !$hasWriteAccess) {
            if ($ok === 'n') {
                return ['Ez a templom le van tiltva! Csak adminisztrátorok számára látható ez az oldal.', 'warning'];
            }
            return ['Ez a templom áttekintésre vár. Csak adminisztrátorok számára látható ez az oldal.', 'warning'];
        }

        if ($ok === 'n') {
            return [
                'Ez a misézőhely jelenleg le van tiltva, ezért a látogatók nem látják. '
                . 'Te gondnokként látod és szerkesztheted; ha szerinted tévedés, jelezd az adminisztrátoroknak.',
                'warning'
            ];
        }

        return [
            'Ez a misézőhely még nem nyilvános: áttekintésre vár, ezért egyelőre csak te '
            . 'és az adminisztrátorok látjátok. Nyugodtan szerkeszd — a jóváhagyás után '
            . 'ezek az adatok jelennek meg a látogatóknak.',
            'info'
        ];
    }

    function checkReadAccess($_user) {
        $access = false;
        if ($this->ok == 'i')
            $access = true;
       
        if($this->checkWriteAccess($_user)) 
            $access = true;       
        
        global $user;                
        if($user->uid == $_user->uid) {
            $this->readAcess = $access;
        }         
        return $access;
    }

    function checkWriteAccess($_user) {
        $access = false;

        if ($_user->checkRole('miserend'))
            $access = true;
        
        if(\Eloquent\ChurchHolder::where('church_id',$this->id)->where('user_id',$_user->uid)->where('status','allowed')->first())
            $access = true;
               
        if(DB::table('egyhazmegye')->where('id',$this->egyhazmegye)->where('felelos',$_user->username)->first())
            $access = true;

        // Örökölt gondnokság: ha a felhasználó bármely ős-templomnak 'allowed' gondnoka
        if (!$access) {
            foreach ($this->ancestors as $ancestor) {
                if (\Eloquent\ChurchHolder::where('church_id', $ancestor['church']->id)
                    ->where('user_id', $_user->uid)
                    ->where('status', 'allowed')
                    ->exists()) {
                    $access = true;
                    break;
                }
            }
        }
        
        global $user;
        if(isset($user) && $user->uid == $_user->uid) {
            $this->writeAcess = $access;
        }
        return $access;
    }

	
    public function boundaries()
    {
        return $this->belongsToMany('Eloquent\Boundary', 'lookup_boundary_church')
                ->withTimestamps();
    }
    
   
    
    
    /*
     * A régi templomok.egyhazmegye/espereskerulet/orszag/megye/varos -ból csinál
     * boundary értéket, ha még nincs. Ill. összekapcsolást.
     */
    function MmigrateBoundaries(array $referenceData = []) {
        
        // Ha a hívó (pl. OSM::checkBoundaries) már betöltötte a referencia táblákat,
        // használjuk azokat (50 templomnál 5 tábla = 250 query megtakarítás batch-enként).
        // Ha nincs átadva (pl. egyedi hívásból), akkor töltjük be helyben.
        $_egyhazmegyek     = $referenceData['egyhazmegyek']     ?? collect(DB::table('egyhazmegye')->get())->keyBy('id')->sortBy('sorrend');
        $_espereskeruletek = $referenceData['espereskeruletek'] ?? collect(DB::table('espereskerulet')->get())->keyBy('id');
        
        /* egyházmegye */
        $tmp = $this->boundaries()
                ->where('boundary','religious_administration')
                ->where('denomination','LIKE','%_catholic')
                ->where('admin_level',6)
                ->get()->toArray();
        if($tmp == array()) {
            if(isset($_egyhazmegyek[$this->egyhazmegye]) && $_egyhazmegyek[$this->egyhazmegye]->nev) {
                $boundary = \Eloquent\Boundary::firstOrNew(['boundary' => 'religious_administration', 'denomination' => $this->denomination, 'admin_level' => 6, 'name' => $_egyhazmegyek[$this->egyhazmegye]->nev]);
                $boundary->save();
                $this->boundaries()->attach($boundary->id);
            }
        }
        
        /* espereskerület */
        $tmp = $this->boundaries()
                ->where('boundary','religious_administration')
                ->where('denomination','LIKE','%_catholic')
                ->where('admin_level',7)
                ->get()->toArray();
        if($tmp == array()) {
            if(isset($_espereskeruletek[$this->espereskerulet]) && $_espereskeruletek[$this->espereskerulet]->nev) {
                $boundary = \Eloquent\Boundary::firstOrNew(['boundary' => 'religious_administration', 'denomination' => $this->denomination, 'admin_level' => 7, 'name' => $_espereskeruletek[$this->espereskerulet]->nev]);
                $boundary->save();
                $this->boundaries()->attach($boundary->id);
            }
        }
        
        /*
         * #496 / #497 / #498: itt épült ország-, megye- és város-boundary a régi
         * `templomok.orszag`, `.megye` és `.varos` oszlopokból, ha az OSM nem adott
         * ilyet. Az oszlopok megszűntek, tehát nincs miből.
         *
         * A már legyártott boundary-sorok a helyükön maradnak — csak új nem készül
         * belőlük. A földrajzi határok innentől kizárólag az OSM-ből jönnek
         * (OSM::checkBoundaries), a hiányzó lefedettséget pedig a /health mutatja és
         * a cron 497 próbálja újra.
         *
         * Az egyházmegye és az esperesi kerület MARAD: azok nem OSM-adatok, és nem is
         * ez a három jegy tárgya.
         */

    }

    public function delete() {

        #$this->neighbours()->delete();
        #Distance::where('church_to', $this->id)->delete(); fromLat, fromLon
        #Distance::where('church_from', $this->id)->delete(); toLat, toLon

        // Kapcsolatok törlése mindkét irányban (Eloquent eseménykezelők lefussanak)
        \Eloquent\ChurchRelationship::where('parent_church_id', $this->id)->delete();
        \Eloquent\ChurchRelationship::where('child_church_id', $this->id)->delete();
        
        \Eloquent\ChurchHolder::where('church_id',$this->id)->delete();
        \Eloquent\Favorite::where('tid',$this->id)->delete();
        \Eloquent\ChurchLink::where('church_id',$this->id)->delete();
        
        //Nem elegáns:
        DB::table('lookup_boundary_church')->where('church_id',$this->id)->delete();
                        
        // Calendar dolgok törlése
        \Eloquent\CalMass::where('church_id', $this->id)->delete();
        \Eloquent\CalSuggestionPackage::where('church_id', $this->id)->delete();

        $this->remarks()->delete();
        $this->photos()->delete();

        parent::delete();
    }

  
    public function save(array $options = [])
    {
        // Ha a koordináta megváltozott, a régi boundary hozzárendelések érvénytelenek.
        // Nullázzuk boundaries_checked_at-t, hogy a checkBoundaries cron újra lefuttassa.
        if ($this->isDirty(['lat', 'lon'])) {
            $this->boundaries_checked_at = null;
        }

        // Másolat készítése a modellről
        $model = $this;

        // Végigmegyünk az attribútumokon
        foreach ($model->getAttributes() as $key => $value) {
            // Ha az attribútum nem szerepel az eredeti attribútumok között, eltávolítjuk
            if (!in_array($key, array_keys($model->getOriginal()))) {
                unset($model->$key);
            }
        }

        // Meghívjuk az eredeti save() metódust
        $return = parent::save($options);

        // Miután már elmentettük, akkor
        // Elasticsearch frissítése
        // Option B: Only index churches with ok='i' status
        // Option A (with safety): If ok != 'i', delete from Elasticsearch
        if($this->ok === 'i') {
            ElasticsearchApi::updateChurches([$this->id]);
        } else {
            // Ha a templom nem engedélyezett (ok != 'i'), akkor töröljük az Elasticsearchből
            ElasticsearchApi::deleteChurches([$this->id]);
        }

        // A parent::save() visszatérési értéke eddig elveszett: a save() null-lal tért
        // vissza bool helyett, tehát a hívó nem tudta megnézni, sikerült-e a mentés.
        return $return;
    }

    /*
     * #670: a templom adatai a mise-indexbe is BE VANNAK ÁGYAZVA (a mass_index minden
     * dokumentumában ott ül a templom `church` alobjektumként). A save() csak a
     * `churches` indexet frissíti, ezért mentés után a MISE-kereső még a régi
     * templom-adatot látta — pl. az újonnan felvitt gluténmentes vagy akadálymentességi
     * adatra nem talált rá, amíg a napi cron le nem futott.
     *
     * SZÁNDÉKOSAN NEM a save()-ben van: azt az OSM-szinkron (akár több ezer templom) és a
     * boundary-cron (50 templom / 5 perc) is hívja, a mise-újraindexelés viszont mérve
     * ~0,5 másodperc templomonként — ott ez órákat jelentene. A felhasználói mentés-
     * útvonalak (/edit, /editosm) hívják, ahol egy ember épp most írt át valamit, és
     * joggal várja, hogy a kereső is tudjon róla.
     *
     * Hiba esetén csak naplózunk: egy ES-akadás ne buktassa a templom mentését.
     */
    /**
     * Tiszta döntés (se DB, se ES), hogy tesztelhető legyen: van-e értelme frissíteni.
     * Nem engedélyezett templom miséi nincsenek is az indexben — ott nincs mit tenni.
     */
    public static function shouldRefreshMassSearchIndex(?string $ok): bool {
        return $ok === 'i';
    }

    public function refreshMassSearchIndex(): void {
        if (!self::shouldRefreshMassSearchIndex($this->ok)) {
            return;
        }
        try {
            ElasticsearchApi::updateMasses([], [$this->id], function ($msg) {});
        } catch (\Throwable $e) {
            error_log('[#670] a misék újraindexelése nem sikerült a(z) ' . $this->id
                . ' templomnál: ' . $e->getMessage());
        }
    }
}
