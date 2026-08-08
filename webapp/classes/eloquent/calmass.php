<?php

namespace Eloquent;
use Carbon\Carbon;

/**
 * #428: a `manual_experiod` oszlop a felhasználó által kézzel beállított kizárt
 * időszakokat tárolja (az `experiod` marad az automatikusan számolt kizárásoknak).
 * A projektben nincs migrációs rendszer, ezért éles adatbázison kézzel kell felvenni
 * (ahogy annak idején az `experiod` is):
 *
 *   ALTER TABLE cal_masses ADD COLUMN manual_experiod json DEFAULT NULL AFTER experiod;
 */
class CalMass extends CalModel
{
    protected $table = 'cal_masses';

    protected $fillable = [
        'church_id',
        'period_id',
        'title',
        'types',
        'rite',
        'start_date',
        'duration',
        'rrule',
        'experiod',
        'manual_experiod',
        'exdate',
        'lang',
        'comment',
    ];

    protected $casts = [
        'church_id' => 'integer',
        'period_id' => 'integer',
        'title' => 'string',
        'types' => 'array',     // JSON stringből PHP tömb
        'rite' => 'string',
        'start_date' => 'string',
        'duration' => 'array',     // JSON
        'rrule' => 'array',     // JSON
        'experiod' => 'array',     // JSON – automatikusan számolt (ütközés-elkerülés) kizárt időszakok
        'manual_experiod' => 'array',     // JSON – #428: a felhasználó által KÉZZEL beállított kizárt időszakok
        'exdate' => 'array',     // JSON
        'lang' => 'string',
        'comment' => 'string',
    ];

    protected $primaryKey = 'id';
    protected $keyType = 'int';

    /*
     * #592: az importált miséket a külső naptár szinkronja írja és törli teljes
     * cserével, ezért a szerkesztő nem nyúlhat hozzájuk — a következő import úgyis
     * felülírná. A többi (kézzel felvitt) miséhez viszont hozzá KELL férni: a
     * kettő megfér egymás mellett.
     *
     * A tulajdonos-jelölés ma az IMPORT_MARKER a comment mezőben. A frontend és a
     * jogosultság-ellenőrzés ezen a származtatott mezőn / az importedIdsAmong()-on
     * keresztül kérdez, hogy a jelölés módja (később: külön DB-oszlop, több naptár
     * támogatásához) egy helyen legyen cserélhető.
     */
    protected $appends = ['imported'];

    public function getImportedAttribute(): bool
    {
        return $this->getAttribute('comment') === \ExternalCalendarImporter::IMPORT_MARKER;
    }

    /**
     * @param  int[] $ids
     * @return int[] a megadott ID-k közül azok, amelyek importált miséhez tartoznak
     */
    public static function importedIdsAmong(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return static::whereIn('id', $ids)
            ->where('comment', \ExternalCalendarImporter::IMPORT_MARKER)
            ->pluck('id')
            ->map('intval')
            ->all();
    }

    public function period()
    {
        return $this->belongsTo(CalPeriod::class, 'period_id');
    }

    /**
     * #428: A ténylegesen kizárandó időszakok azonosítói – az automatikusan
     * számolt `experiod` (ütközés-elkerülés) ÉS a kézzel beállított
     * `manual_experiod` uniója. A kézi kizárásokat sem az `optimizeExperiods`,
     * sem a collision-logika nem bántja, ezért itt, olvasáskor egyesítjük őket.
     * Egy mise sosem zárja ki a saját időszakát.
     */
    static private function effectiveExperiod($mass): array
    {
        $decode = function ($value) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }
            return is_array($value) ? $value : [];
        };

        $merged = array_values(array_unique(array_merge(
            $decode($mass->experiod ?? []),
            $decode($mass->manual_experiod ?? [])
        )));

        if (!empty($mass->period_id)) {
            $merged = array_values(array_diff($merged, [$mass->period_id]));
        }

        return $merged;
    }
    /**
     * Generálja a miseidőpontokat adott évekre,
     * és templomonként csoportosítva visszaadja őket.
     *
     * @param array $masses
     * @param array $years
     * @return array [templom_id => miseidőpontok tömbje]
     */
    static function generateMassInstancesForYears($masses, array $churchTimezones, array $years): array
    {
        $instancesByChurch = [];

        /*
        $this::logDebug("generateMassInstancesForYears indul", [
            'mass_count' => count($masses),
            'years'      => $years,
        ]);
*/
        if (empty($masses) || empty($years)) {
            //$this->logDebug("Nincs mise vagy év");
            return $instancesByChurch;
        }

        // --- 0) Ütközés elkerülés alkalmazása ---
        $masses = self::applyCollisionAvoidance($masses);
        /*
        $this->logDebug("applyCollisionAvoidance lefutott", [
            'after_count' => count($masses),
        ]);
*/


        foreach ($years as $year) {
            $globalStart = Carbon::create($year, 1, 1)->startOfDay();
            $globalEnd = Carbon::create($year, 12, 31)->endOfDay();

            foreach ($masses as $mass) {
                /*
                $this->logDebug("Mise feldolgozás indul", [
                    'mass_id' => $mass->id,
                    'title' => $mass->title,
                    'period_id' => $mass->period_id,
                ]);
*/
                if (empty($mass->period_id)) {
                    //$this->logDebug("Egyszeri mise", ['mass_id' => $mass->id]);
                } else if (empty($mass->rrule)) {
                    //$this->logDebug("Nincs RRULE", ['mass_id' => $mass->id]);
                    continue;
                }
                $timezone = $churchTimezones[$mass->church_id] ?? 'Europe/Budapest';

                // ---- duration konvertálása percekre ----
                $durationMinutes = 0;
                if (!empty($mass->duration)) {
                    if (is_string($mass->duration)) {
                        $decoded = json_decode($mass->duration, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $mass->duration = $decoded;
                        }
                    }
                    if (is_array($mass->duration)) {
                        $days = (int)($mass->duration['days'] ?? 0);
                        $hours = (int)($mass->duration['hours'] ?? 0);
                        $minutes = (int)($mass->duration['minutes'] ?? 0);
                        $durationMinutes = $days * 24 * 60 + $hours * 60 + $minutes;
                    }
                }

                // --- ha nincs period_id: egyszeri esemény ---
                if (empty($mass->period_id)) {
                    $startDate = Carbon::parse($mass->start_date ?? now())->setTimezone($timezone);
                    if ($startDate->between($globalStart, $globalEnd)) {
                        $instancesByChurch[$mass->church_id][] = [
                            'church_id' => $mass->church_id,
                            'mass_id' => $mass->id,
                            'start_date' => $startDate->copy()->setTimezone('UTC')->format('c'),
                            'start_minutes' => $startDate->copy()->setTimezone('UTC')->hour * 60 + $startDate->copy()->setTimezone('UTC')->minute,
                            'title' => $mass->title,
                            'types' => $mass->types,
                            'rite' => $mass->rite,
                            'duration_minutes' => $durationMinutes,
                            'lang' => $mass->lang,
                            'comment' => $mass->comment,
                        ];
                    }
                    continue;
                }

                if (empty($mass->rrule)) {
                    continue;
                }

                // --- RRULE feldolgozás ---
                $rrule = $mass->rrule;
                if (is_string($rrule)) {
                    $decoded = json_decode($rrule, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $rrule = $decoded;
                    }
                }
                if (!is_array($rrule) || empty($rrule)) {
                    continue;
                }

                // --- kizárt dátumokkal  ---
                $excludedDatesRaw = $mass->exdate ?? [];
                if (is_string($excludedDatesRaw)) {
                    $decoded = json_decode($excludedDatesRaw, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $excludedDatesRaw = $decoded;
                    }
                }
                                                                    
                // --- kizárt periódusok (auto `experiod` ∪ kézi `manual_experiod`) #428 ---
                $excludedPeriods = self::effectiveExperiod($mass);

                // --- az adott miséhez való legeneráltperiódusok betöltése ---
                $periods = CalGeneratedPeriod::where('period_id', $mass->period_id)
                    ->where('start_date', '<=', $globalEnd->toDateString())
                    ->where('end_date', '>', $globalStart->toDateString())
                    ->get();

                foreach ($periods as $generatedPeriod) {
                    $start = Carbon::parse($generatedPeriod->start_date)->startOfDay()->setTimezone($timezone);
                    $end = Carbon::parse($generatedPeriod->end_date)->subDay()->endOfDay()->setTimezone($timezone);

                    if ($start->lt($globalStart)) $start = (clone $globalStart)->setTimezone($timezone);
                    if ($end->gt($globalEnd))     $end   = (clone $globalEnd)->setTimezone($timezone);
                    if ($start->gt($end)) continue;

                    // Exdate feldolgozása: csak az adott időszakba eső dátumok legyenek exdate-ben
                    $rrule['exdate'] = [];
                    foreach($excludedDatesRaw as $exDateString) {
                        $exDate = Carbon::parse($exDateString)->setTimezone($timezone);
                        if($exDate->between($start,$end)) {
                            $rrule['exdate'][] = $exDate;
                        }
                    }
                    $rrule['exdate'] = collect(is_array($excludedDatesRaw) ? $excludedDatesRaw : [])
                    ->map(fn($d) => Carbon::parse($d)->toDateString())->toArray();

                    // Experiod feldolgozása: csak az adott időszakba eső periódusok érdeklnek
                    // Aztán a beleeső napokat áttesszük exDate-be
                    foreach($excludedPeriods as $exPeriodString) {
                        $exGeneratedPeriods = CalGeneratedPeriod::where('period_id', $exPeriodString)
                                            ->where('start_date', '<=', $end->toDateString())
                                            ->where('end_date', '>', $start->toDateString())
                                            ->get();
                        //Nagyon nagyon furcsa lenne, ha kettő is lenne belőle, de ugye....                                            
                        foreach($exGeneratedPeriods as $exGeneratedPeriod) {
                            
                            // ExGeneratedPeriod intervallum (igazítva napokra, ugyanabban a timezone-ban mint $start/$end)
                            $exStart = Carbon::parse($exGeneratedPeriod->start_date)->startOfDay()->setTimezone($timezone);
                            $exEnd   = Carbon::parse($exGeneratedPeriod->end_date)->subDay()->endOfDay()->setTimezone($timezone);

                            // Ha nincs átfedés, kihagyjuk
                            if ($exEnd->lt($start) || $exStart->gt($end)) {
                                continue;
                            }

                            // Átfedés kezdete és vége
                            $overlapStart = $exStart->lt($start) ? $start->copy()->startOfDay() : $exStart->copy()->startOfDay();
                            $overlapEnd   = $exEnd->gt($end)   ? $end->copy()->endOfDay()   : $exEnd->copy()->endOfDay();

                            // Az átfedő napokat hozzáadjuk exdate-hez (YYYY-MM-DD formátumban)
                            for ($d = $overlapStart->copy(); $d->lte($overlapEnd); $d->addDay()) {
                                $rrule['exdate'][] = $d->toDateString();
                            }
                        }                    
                    }
                    // Duplikátumok eltávolítása                    
                    $rrule['exdate'] = array_values(array_unique($rrule['exdate']));
                    sort($rrule['exdate']);

                    $recurrences = self::generateDatesFromRrule($rrule, $start, $end);
                   /* $this->logDebug("Recurrence generálás", [
                        'mass_id' => $mass->id,
                        'count' => count($recurrences),
                    ]); */

                    foreach ($recurrences as $date) {
                        /*$this->logDebug("Generált dátum", [
                            'mass_id' => $mass->id,
                            'date' => $date->toIso8601String(),
                        ]); */                        
                       
                        $startUTC = $date->copy()->setTimezone('UTC');
                        $instancesByChurch[$mass->church_id][] = [
                            'church_id' => $mass->church_id,
                            'mass_id' => $mass->id,
                            'start_date' => $startUTC->format('c'),
                            'start_minutes' => $startUTC->hour * 60 + $startUTC->minute,
                            'title' => $mass->title,
                            'types' => $mass->types,
                            'rite' => $mass->rite,
                            'duration_minutes' => $durationMinutes,
                            'lang' => $mass->lang,
                            'comment' => $mass->comment,
                        ];
                    }
                }
            }
        }

        // Rendezés start_date szerint (növekvő)
        foreach($instancesByChurch as $churchId => &$recurrences) {            
            usort($recurrences, function($a, $b) {
                return $a['start_date'] <=> $b['start_date'];
            });
        }

        return $instancesByChurch;
    }

    /**
     
     */
    static function generateMassPeriodInstancesForYears($masses, array $churchTimezones, array $years): array
    {
        $massPeriods = [];

        /*
        $this::logDebug("generateMassInstancesForYears indul", [
            'mass_count' => count($masses),
            'years'      => $years,
        ]);
*/
        if (empty($masses) || empty($years)) {
            //$this->logDebug("Nincs mise vagy év");
            return $massPeriods;
        }

        // --- 0) Ütközés elkerülés alkalmazása ---
        $masses = self::applyCollisionAvoidance($masses);
        /*
        $this->logDebug("applyCollisionAvoidance lefutott", [
            'after_count' => count($masses),
        ]);
*/


        foreach ($years as $year) {
            $globalStart = Carbon::create($year, 1, 1)->startOfDay();
            $globalEnd = Carbon::create($year, 12, 31)->endOfDay();
            $massesFromImport = [];

            foreach ($masses as $mass) {
                /*
                $this->logDebug("Mise feldolgozás indul", [
                    'mass_id' => $mass->id,
                    'title' => $mass->title,
                    'period_id' => $mass->period_id,
                ]);*/

                if (empty($mass->period_id)) {
                    //$this->logDebug("Egyszeri mise", ['mass_id' => $mass->id]);
                } else if (empty($mass->rrule)) {
                    //$this->logDebug("Nincs RRULE", ['mass_id' => $mass->id]);
                    continue;
                }
                $timezone = $churchTimezones[$mass->church_id] ?? 'Europe/Budapest';

                // ---- duration konvertálása percekre ----
                $durationMinutes = 0;
                if (!empty($mass->duration)) {
                    if (is_string($mass->duration)) {
                        $decoded = json_decode($mass->duration, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $mass->duration = $decoded;
                        }
                    }
                    if (is_array($mass->duration)) {
                        $days = (int)($mass->duration['days'] ?? 0);
                        $hours = (int)($mass->duration['hours'] ?? 0);
                        $minutes = (int)($mass->duration['minutes'] ?? 0);
                        $durationMinutes = $days * 24 * 60 + $hours * 60 + $minutes;
                    }
                }

                
                // --- RRULE feldolgozás ---
                $rrule = $mass->rrule;
                if (is_string($rrule)) {
                    $decoded = json_decode($rrule, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $rrule = $decoded;
                    }
                }
                
                if (!is_array($rrule) || empty($rrule)) {
                    
                    // throw new \Exception('Ilyennek igazából nem szabadna lennie, mert egy napos rrule-t kapnak az egyedi alkalmak. ');
                    //echo "Ilyennek igazában nem szabadna lennie, mert egy napos rrule-t kapnak az egyedi alkalmak. ".$mass->id;
                    
                    // DIAGNOSTIC LOG: mass object has invalid/empty RRULE
                    error_log("DEBUG: Mass ID {$mass->id} has empty/invalid RRULE. start_date={$mass->start_date}, church_id={$mass->church_id}");
                    
                    $rrule = [
                        "freq" => "daily",
                        "count" => 1,
                        "dtstart" => $mass->start_date ? Carbon::parse($mass->start_date)->format('Y-m-d\TH:i:s') : date('Y-m-d\TH:i:s'),
                    ];

                    
                }

                if (isset($rrule['dtstart'])) {
                    try {
                        $rrule['dtstart'] instanceof \DateTimeInterface
                            ? Carbon::instance($rrule['dtstart'])
                            : Carbon::parse($rrule['dtstart'], $timezone);
                    } catch (\Throwable $e) {
                        error_log(
                            "Invalid RRULE dtstart, skipping mass ID {$mass->id}: "
                            . var_export($rrule['dtstart'], true)
                        );
                        continue;
                    }
                }

                
                // --- kizárt dátumokkal  ---
                $excludedDatesRaw = $mass->exdate ?? [];
                if (is_string($excludedDatesRaw)) {
                    $decoded = json_decode($excludedDatesRaw, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $excludedDatesRaw = $decoded;
                    }
                }
                                                                    
                // --- kizárt periódusok (auto `experiod` ∪ kézi `manual_experiod`) #428 ---
                $excludedPeriods = self::effectiveExperiod($mass);


                // Ha nincsen periódusa, akkor ő vagy egyedi esemény vagy évenként ismétlődő egyedi esemény!
                // Általában kap RRULE-t is egynaposat. Ezért inkább végig visszük, persze csak a saját évében                
              
                if(!$mass->period_id) { 

                    /* Egyetlen egyszer előforduló eseményeinket tipikusen így adjuk meg
                        { freq: daily,
                           count: 1
                           dtstart: ...
                        }
                    */
                    if( isset($rrule['freq']) and $rrule['freq'] == 'daily' AND
                        isset($rrule['count']) and $rrule['count'] == 1 ) {

                        $periods = collect([]);                                                
                        if($year == Carbon::parse($mass->start_date)->format('Y')) {
                            
                            $periods->push((object)[
                                'id' => $mass->id."-".Carbon::parse($mass->start_date)->format('YmdHis'), // ideiglenes id
                                'start_date' => $mass->start_date,
                                'end_date' => $mass->start_date, // Ezek egy napos események ugye
                                'name' => 'Ideiglenes időszak egyetlen napra',
                                'color' => false
                            ]);                            
                        }
                    } else if (
                        isset($rrule['freq']) and $rrule['freq'] == 'yearly' AND
                        isset($rrule['bymonth']) and is_array($rrule['bymonth']) AND
                        isset($rrule['bymonthday']) and is_array($rrule['bymonthday'])
                    ) {
                        foreach($rrule['bymonth'] as $month) {
                            foreach($rrule['bymonthday'] as $day) {
                                $periods = collect([]);                                                
                                $dateString = sprintf('%04d-%02d-%02d', $year, $month, $day);                                                                                                       
                                $periods->push((object)[
                                    'id' => $mass->id."-".str_replace(['-',' ',':'], '', $dateString), // ideiglenes id
                                    'start_date' => $dateString,
                                    'end_date' => $dateString, // Ezek egy napos események ugye
                                    'name' => 'Ideiglenes időszak egyetlen napra',
                                    'color' => false
                                ]); 



                            }
                        }

                    } else {
                        // Nincs period_id mégis van több napos RRULE. A szerkesztő felületből nem szabad ilyennek létrejönnie
                        // De külső naptár importjakor szabad ilyennek történnie.
                        $massesFromImport[] = $mass;                   
                           
                        //throw new \Exception('Nincs period_id és az RRULE sem egy napos eseményre van beállítva. Ez nem támogatott. Mass: '.print_r($mass->toArray(),1));
                    }
                }
                else {
                    // --- az adott miséhez való legeneráltperiódusok betöltése ---
                    $periods = CalGeneratedPeriod::where('period_id', $mass->period_id)
                        ->where('start_date', '<=', $globalEnd->toDateString())
                        ->where('end_date', '>', $globalStart->toDateString())
                        ->get();
                }
                foreach ($periods as $generatedPeriod) {
                    $start = Carbon::parse($generatedPeriod->start_date)->startOfDay()->setTimezone($timezone);
                    // TODO / FIXME: valamiért itt volt egy subDay. Talán mert az alap periods-nak van inclusive beállítása. 
                    // Passz. Az egy napo hossszú periódusoknál biztos nem kel subDay(). Majd meglátjuk a töbi határnál mi a helyzet.
                    // $end = Carbon::parse($generatedPeriod->end_date)->subDay()->endOfDay()->setTimezone($timezone);
                    $end = Carbon::parse($generatedPeriod->end_date)->endOfDay()->setTimezone($timezone);

                    /*
                    Ezt nem tudom pontosan mit akart itt cisnálni. De gondot okozott.*/
                    if ($start->lt($globalStart)) $start = (clone $globalStart)->setTimezone($timezone);
                    if ($end->gt($globalEnd))     $end   = (clone $globalEnd)->setTimezone($timezone);
                    /*if ($start->gt($end)) continue;                    
                    */
                    
                    // Exdate feldolgozása: csak az adott időszakba eső dátumok legyenek exdate-ben
                    $rrule['exdate'] = [];
                    foreach($excludedDatesRaw as $exDateString) {
                        $exDate = Carbon::parse($exDateString)->setTimezone($timezone);
                        if($exDate->between($start,$end)) {
                            $rrule['exdate'][] = $exDate;
                        }
                    }
                    $rrule['exdate'] = collect(is_array($excludedDatesRaw) ? $excludedDatesRaw : [])
                    ->map(fn($d) => Carbon::parse($d)->toDateString())->toArray();

                    // Experiod feldolgozása: csak az adott időszakba eső periódusok érdeklnek
                    // Aztán a beleeső napokat áttesszük exDate-be
                    foreach($excludedPeriods as $exPeriodString) { //TODO és FIXME az átfedésre nem biztos hogy ez jó!
                        $exGeneratedPeriods = CalGeneratedPeriod::where('period_id', $exPeriodString)
                                            ->where('start_date', '<=', $end->toDateString())
                                            ->where('end_date', '>', $start->toDateString())
                                            ->get();
                        //Nagyon nagyon furcsa lenne, ha kettő is lenne belőle, de ugye....                                            
                        foreach($exGeneratedPeriods as $exGeneratedPeriod) {
                            
                            // ExGeneratedPeriod intervallum (igazítva napokra, ugyanabban a timezone-ban mint $start/$end)
                            $exStart = Carbon::parse($exGeneratedPeriod->start_date)->startOfDay()->setTimezone($timezone);
                            $exEnd   = Carbon::parse($exGeneratedPeriod->end_date)->subDay()->endOfDay()->setTimezone($timezone);

                            // Ha nincs átfedés, kihagyjuk
                            if ($exEnd->lt($start) || $exStart->gt($end)) {
                                continue;
                            }

                            // Átfedés kezdete és vége
                            $overlapStart = $exStart->lt($start) ? $start->copy()->startOfDay() : $exStart->copy()->startOfDay();
                            $overlapEnd   = $exEnd->gt($end)   ? $end->copy()->endOfDay()   : $exEnd->copy()->endOfDay();

                            // Az átfedő napokat hozzáadjuk exdate-hez (YYYY-MM-DD formátumban)
                            for ($d = $overlapStart->copy(); $d->lte($overlapEnd); $d->addDay()) {
                                $rrule['exdate'][] = $d->toDateString();
                            }
                        }                    
                    }
                    // Duplikátumok eltávolítása                    
                    if(count($rrule['exdate']) > 0) {
                        $rrule['exdate'] = array_values(array_unique($rrule['exdate']));
                        sort($rrule['exdate']);
                    } else {
                        unset($rrule['exdate']);
                    }
                    
                    // RRULE dstart & until
                    $tz = $start->getTimezone()->getName();
                    $origDtStart = $rrule['dtstart'] instanceof \DateTimeInterface
                        ? Carbon::instance($rrule['dtstart'])->setTimezone($tz)
                        : Carbon::parse($rrule['dtstart'], $tz)->setTimezone($tz);
                    $hh = $origDtStart->hour;
                    $mm = $origDtStart->minute;
                    $ss = $origDtStart->second;
                    $alignedDtStart = $start->copy()->setTime($hh, $mm, $ss);
                    $effectiveDtStart = $alignedDtStart;
                    $rrule['dtstart'] = $effectiveDtStart->toIso8601String();
                    $rrule['until']   = $end->toIso8601String();
                    

                    $massPeriods[] = [
                        'mass_id' => $mass->id,
                        'period_id' => $mass->period_id,
                        'generated_period_id' => $generatedPeriod->id,
                        'color' => $generatedPeriod->color,
                        'church_id' => $mass->church_id,
                        'start_date' => $start->toDateString(),
                        'end_date' => $end->toDateString(),
                        'rite' => $mass->rite,
                        'types' => $mass->types,
                        'title' => $mass->title,
                        'duration_minutes' => $durationMinutes,
                        'lang' => $mass->lang,
                        'comment' => $mass->comment,
                        'rrule' => $rrule,
                    ];

                }
            }

            // Periódus nélküli RRULE-os események kezelése. Ez főleg importált naptáraknál fordulhat elő, ahol nem tudjuk biztosan megkövetelni a period_id-t, de lehetnek RRULE-juk.
            foreach($massesFromImport as $mass) {
echo "Period nélküli RRULE-os mise: ".$mass->id." - ".$mass->title." in year ".$year."<br>\n";

                $endsBeforeGlobalEnd = false;
                if(isset($mass->rrule['until'])) {
                    $until = Carbon::parse($mass->rrule['until']);
                    if($until->lt($globalStart)) {
                        $endsBeforeGlobalEnd = true;
                    }
                }
                $startsBeforeGlobalStart = false;
                if(isset($mass->rrule['dtstart'])) {
                    $dtstart = Carbon::parse($mass->rrule['dtstart']);
                    if($dtstart->gt($globalEnd)) {
                        $startsBeforeGlobalStart = true;
                    }
                }

                if(!$endsBeforeGlobalEnd and !$startsBeforeGlobalStart) {
                    
                $newRrule = $mass->rrule;  // Az aktuális tömb lekérése
                $newRrule['until'] = $until->toIso8601String();  // Módosítás
                $mass->rrule = $newRrule;  // Explicit reasszignálás az Eloquentnek
               
                $newMassPeriod = [
                    'mass_id' => $mass->id,
                    'period_id' => null,
                    'generated_period_id' => null,
                    
                    'church_id' => $mass->church_id,
                    'start_date' =>  $globalStart->toDateString(),
                    'end_date' =>  $globalEnd->toDateString(),
                    'rite' => $mass->rite,
                    'types' => $mass->types,
                    'title' => $mass->title,
                    'duration_minutes' => 0, // A $mass->duration-ből ki tudnánk találni. TODO
                    'lang' => $mass->lang,
                    'comment' => "extra ".$mass->comment,
                    'rrule' => $mass->rrule, 
                    
                ];
                $massPeriods[] = $newMassPeriod;
                }
            }
        }

        return $massPeriods;
    }

    static private function applyCollisionAvoidance(array $masses): array
    {
        // Kevés CalPeriod van, és minden misénél kell, ezért inkább előre egyszer töltjük be mindet.
        $calPeriods = CalPeriod::all()->keyBy('id');        
        // Aránylag kevés (kb 100) CalGeneratedPeriod van, ezért ezeket is betöltjük egyszerre.
        $calGeneratedPeriods = CalGeneratedPeriod::all()->groupBy('period_id');
        
        // Amikor nagyon sok misét kell egyszerre kezelni, akkor végtelenbe lelassulunk,
        // ezért inkább csak templomonként nézzük meg
        $massesByChurch = [];
        foreach($masses as $mass) {
            if(!isset($massesByChurch[$mass->church_id])) {
                $massesByChurch[$mass->church_id] = [];
            }
            $massesByChurch[$mass->church_id][] = $mass;
        }

        $results = [];
        foreach($massesByChurch as $masses) {
            $massesWithoutCollision = [];
            $noPeriodMasses = [];

            foreach ($masses as $mass) {
                if (empty($mass->period_id) or !isset($calPeriods[$mass->period_id])) {
                    $noPeriodMasses[] = $mass;
                    continue;
                }
                $weight = $calPeriods[$mass->period_id]->weight;
                $massesWithoutCollision[$weight][] = $mass;
            }
            
            foreach ($massesWithoutCollision as $currentWeight => $currentMasses) {
                if ($currentWeight > 1) {
                    $lowerPeriodMasses = [];

                    foreach (range(0, $currentWeight - 1) as $lowerWeight) {
                        if (isset($massesWithoutCollision[$lowerWeight])) {
                            $lowerPeriodMasses = array_merge($lowerPeriodMasses, $massesWithoutCollision[$lowerWeight]);
                        }
                    }

                    foreach ($lowerPeriodMasses as $lowerMass) {
                        foreach ($currentMasses as $higherMass) {
                            $mPeriodExists = $calGeneratedPeriods[$lowerMass->period_id] ?? false;
                            if ($mPeriodExists) {
                                $experiod = $lowerMass->experiod ?? [];
                                if (!in_array($higherMass->period_id, $experiod)) {
                                    $experiod[] = $higherMass->period_id;
                                    $lowerMass->experiod = $experiod; // csak a tömbben frissítjük
                                }
                            }
                        }
                    } 
                }
            }
            
            foreach ($massesWithoutCollision as $group) {
                $results = array_merge($results, $group);
            }

            $results = array_merge($results, $noPeriodMasses);
        }
        return $results;
    }

      /**
     * rrule alapján generál dátumokat adott időintervallumban.
     *
     * @param array $rrule
     * @param Carbon $start
     * @param Carbon $end
     * @return Carbon[]
     */
    static function generateDatesFromRrule(array $rrule, Carbon $start, Carbon $end): array
    {
        /*
        $this->logDebug("generateDatesFromRrule hívva", [
            'rrule_in' => $rrule,
            'window_start' => $start->toIso8601String(),
            'window_end'   => $end->toIso8601String(),
        ]);
        */
        $tz = $start->getTimezone()->getName();
        $origDtStart = $rrule['dtstart'] instanceof \DateTimeInterface
            ? Carbon::instance($rrule['dtstart'])->setTimezone($tz)
            : Carbon::parse($rrule['dtstart'], $tz)->setTimezone($tz);

        $hh = $origDtStart->hour;
        $mm = $origDtStart->minute;
        $ss = $origDtStart->second;


        $alignedDtStart = $start->copy()->setTime($hh, $mm, $ss);
        /*
        $this->logDebug("dtstart igazítva, idő megőrizve", [
            'orig_dtstart'   => $origDtStart->toIso8601String(),
            'aligned_dtstart'=> $alignedDtStart->toIso8601String(),
        ]);
        */
        $effectiveDtStart = $alignedDtStart;

        $rrule['dtstart'] = $effectiveDtStart;
        $rrule['until']   = $end->toIso8601String();
        /*
        $this->logDebug("RRULE normalizálva időmegőrzéssel", [
            'dtstart' => $rrule['dtstart'] instanceof Carbon ? $rrule['dtstart']->toIso8601String() : (string)$rrule['dtstart'],
            'until'   => $rrule['until'],
            'freq'    => $rrule['freq'] ?? null,
            'byweekday' => $rrule['byweekday'] ?? null,
        ]);
        */
        
        //$simpleRRule = new \SimpleRRule($rrule, fn($msg, $ctx = []) => $this->logDebug($msg, $ctx));
        $simpleRRule = new \SimpleRRule($rrule);
        $occurrences = $simpleRRule->getOccurrences();

        $occurrences = array_values(array_filter(
            $occurrences,
            fn($dt) => $dt->between($start, $end)
        ));
        /*
        $this->logDebug("generateDatesFromRrule eredmény", [
            'count' => count($occurrences),
            'first'=> isset($occurrences[0]) ? $occurrences[0]->toIso8601String() : null,
            'last' => !empty($occurrences) ? end($occurrences)->toIso8601String() : null,
        ]);
        */
        return array_map(fn($dt) => Carbon::instance($dt), $occurrences);
    }



}
