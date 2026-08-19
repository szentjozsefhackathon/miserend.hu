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
        /*
         * #431: az alkalom SAJÁT helyszíne, ha nem a templomban van.
         *
         * vlacko0930 kérése: „Jó lenne, ha templomtól távoleső szabadtéri alkalmakat
         * lehetne templom nélkül is felvenni pl koordinátákkal." A használati eset:
         * „Röszke plébánia biciklitúrát szervez időnként, és van mise valami random
         * pusztai helyen."
         *
         * A helyet ezért az ALKALOMHOZ kötjük, nem új misézőhelyhez. Így a mise a
         * szervező plébániáé marad (van gazdája, van gondnoka, oda tartozik), és nem
         * keletkezik minden szabadtéri alkalomból egy örökre ottmaradó, mise nélküli
         * pont a térképen. borazslo kérdéseire is ez válaszol:
         * „ki hozhat létre" -> a plébánia gondnoka, a saját miséjéhez;
         * „mi legyen az elmúlt eseményekkel" -> ugyanaz, mint bármely elmúlt misével.
         *
         * Mindhárom mező opcionális: ha nincs kitöltve, a mise a templomban van.
         */
        'location_lat',
        'location_lon',
        'location_name',
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
        // #431: az alkalom saját helyszíne; null = a templomban van
        'location_lat' => 'float',
        'location_lon' => 'float',
        'location_name' => 'string',
    ];

    /**
     * #431: van-e az alkalomnak SAJÁT helyszíne, a templomtól eltérő?
     *
     * Csak akkor, ha mindkét koordináta megvan — fél koordinátával nem lehet
     * térképre tenni, és a féligkész adat rosszabb, mint a hiányzó.
     */
    public function hasOwnLocation(): bool {
        return $this->location_lat !== null && $this->location_lon !== null
            && (float) $this->location_lat !== 0.0 && (float) $this->location_lon !== 0.0;
    }

    /**
     * #431: az alkalom tényleges helyszíne — a sajátja, ha van, egyébként a templomé.
     *
     * @return array{lat: ?float, lon: ?float, name: ?string, sajat: bool}
     */
    public function effectiveLocation(): array {
        if ($this->hasOwnLocation()) {
            return [
                'lat' => (float) $this->location_lat,
                'lon' => (float) $this->location_lon,
                'name' => $this->location_name !== '' ? $this->location_name : null,
                'sajat' => true,
            ];
        }

        $templom = $this->church_id ? \Eloquent\Church::find($this->church_id) : null;

        return [
            'lat' => $templom && $templom->lat ? (float) $templom->lat : null,
            'lon' => $templom && $templom->lon ? (float) $templom->lon : null,
            'name' => $templom->nev ?? null,
            'sajat' => false,
        ];
    }

    protected $primaryKey = 'id';
    protected $keyType = 'int';

    /**
     * #334: egy mise több nyelven is lehet (szlovák-latin, német-magyar, ...). A `lang`
     * oszlop ezért vesszővel elválasztva több kódot is tartalmazhat ("sk,la"). A nyers
     * mező marad string — a sqlite export és a naptár-alkalmazás így olvassa —, ez az
     * accessor pedig a tisztított listát adja.
     *
     * @return string[]
     */
    public function getLangsAttribute(): array {
        return self::splitLanguages($this->lang);
    }

    /**
     * A `lang` mező listává bontása. Tiszta függvény, hogy a szétbontás szabálya egy
     * helyen legyen (ES-indexelés, templom-nyelvek, statisztika, megjelenítés).
     *
     * @param  string|null $lang
     * @return string[]
     */
    public static function splitLanguages($lang): array {
        if ($lang === null) {
            return [];
        }
        $parts = array_map('trim', explode(',', (string) $lang));
        $parts = array_filter($parts, static fn($p) => $p !== '');
        return array_values(array_unique($parts));
    }

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
    /*
     * #832: itt állt a `generateMassInstancesForYears()` — TÖRÖLVE.
     *
     * Halott kód volt: egyetlen hívója sem akadt sem a PHP-ben, sem a naptárban,
     * sem a cron-regiszterben, sem az éles `crons` táblában. A saját naplósora is a
     * lenti függvény nevét írta ki — másolás nyoma.
     *
     * Nem csak fölösleges volt, hanem veszélyes is: szinte szó szerint ugyanazt a
     * generálást tartalmazta, mint a lenti `generateMassPeriodInstancesForYears()`,
     * a kettő pedig már el is csúszott egymástól. A kizárt időszakok határhibája
     * (#832) mindkettőben megvolt, de csak az élőt javítottuk — aki legközelebb a
     * halott példányból indul ki, a javítás előtti logikát másolja tovább.
     */

    /**
     
     */
    static function generateMassPeriodInstancesForYears($masses, array $churchTimezones, array $years): array
    {
        $massPeriods = [];

        /*
        $this::logDebug("generateMassPeriodInstancesForYears indul", [
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
                // Körönként nullázni kell, különben átszivárog az előző miséről.
                // A period nélküli, importált sorozatok ága ugyanis NEM állítja be
                // ($massesFromImport-ba teszi a misét, és külön, lentebb dolgozza fel) —
                // a lenti `foreach ($periods ...)` viszont utána is lefut. Így az ilyen
                // mise az ELŐZŐ mise periódusaival is legenerálódott, rossz dátumokkal.
                // (Az első ilyennél `$periods` egyszerűen null volt: "foreach() argument
                // must be of type array|object, null given".)
                $periods = collect([]);

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

                // A hossz átszámítása egy helyen él, l. durationInMinutes().
                $durationMinutes = self::durationInMinutes($mass->duration);

                
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
                                // Egynapos esemény. Az `end_date` KIZÁRÓ, ezért a következő
                                // nap — pontosan úgy, ahogy a
                                // `CalPeriod::generateCalGeneratedPeriods()` is `addDay()`-t
                                // ad az egynapos időszakokra (Szenteste: 12-24 → 12-25).
                                // Enélkül ez a szintetikus időszak a MÁSIK konvenciót
                                // követné, és ugyanaz a ciklus kétféle adatot kapna.
                                'end_date' => Carbon::parse($mass->start_date)->addDay()->toDateString(),
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
                                    // Kizáró vég, mint fent.
                                    'end_date' => Carbon::parse($dateString)->addDay()->toDateString(),
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
                    /*
                     * Az `end_date` KIZÁRÓ: az időszak `[start_date, end_date)`.
                     *
                     * A #832-ben beleértendőnek olvastam, és eszerint vettem ki innen a
                     * `subDay()`-t. Rosszul. A bizonyíték a tárolt adatban van: a
                     * `CalPeriod::generateCalGeneratedPeriods()` `addDay()`-t ad, ha a
                     * záró nap BELETARTOZIK az időszakba — épp ez fordítja a szándékot
                     * kizáró tárolási formára. Ezért a Szenteste (12-24, egyetlen nap)
                     * tárolt vége 12-25, a Májusé pedig 06-01.
                     *
                     * Beleértendő olvasattal tehát MINDEN időszak egy nappal hosszabb:
                     * a májusi miserend június 1-jén is generálódott.
                     */
                    $end = Carbon::parse($generatedPeriod->end_date)->subDay()->endOfDay()->setTimezone($timezone);

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
                    /*
                     * A kizárt időszak határai ugyanazzal a kizáró olvasattal, mint fent.
                     *
                     * A #832-ben azt hittem, hogy a két ág eltérése (itt `subDay()`, a
                     * mise saját időszakánál nem) hiba, és a `subDay()`-t vettem ki.
                     * Fordítva kellett volna: a `subDay()` volt a helyes, a másik ágból
                     * hiányzott. A mérésem is emiatt tévedett — a fixtúráimat magam
                     * írtam beleértendő alakban, tehát a saját feltevésemet mértem
                     * vissza, nem a rendszer tárolási formáját.
                     *
                     * A `>` szintén ezt követi: ha a kizárt időszak épp a mise
                     * időszakának első napján ér véget, akkor a kizárás az előző nappal
                     * bezárólag tartott — nincs átfedés.
                     */
                    foreach($excludedPeriods as $exPeriodString) {
                        $exGeneratedPeriods = CalGeneratedPeriod::where('period_id', $exPeriodString)
                                            ->where('start_date', '<=', $end->toDateString())
                                            ->where('end_date', '>', $start->toDateString())
                                            ->get();
                        //Nagyon nagyon furcsa lenne, ha kettő is lenne belőle, de ugye....                                            
                        foreach($exGeneratedPeriods as $exGeneratedPeriod) {
                            
                            // ExGeneratedPeriod intervallum (igazítva napokra, ugyanabban a timezone-ban mint $start/$end)
                            $exStart = Carbon::parse($exGeneratedPeriod->start_date)->startOfDay()->setTimezone($timezone);
                            // Ugyanaz a kizáró olvasat, mint fent.
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
                        'location_lat' => $mass->location_lat,
                        'location_lon' => $mass->location_lon,
                        'location_name' => $mass->location_name,
                        'lang' => $mass->langs, // #334: lista, mert egy mise több nyelvű is lehet
                        'comment' => $mass->comment,
                        'rrule' => $rrule,
                    ];

                }
            }

            // Periódus nélküli RRULE-os események kezelése. Ez főleg importált naptáraknál fordulhat elő, ahol nem tudjuk biztosan megkövetelni a period_id-t, de lehetnek RRULE-juk.
            foreach($massesFromImport as $mass) {
                // #756: itt korábban egy bennfelejtett `echo` írta ki minden importált,
                // period nélküli RRULE-os misét — pedig ez a NORMÁLIS eset (l. a fenti
                // megjegyzést: importnál nem követelhetjük meg a period_id-t). Hibának
                // látszott, közben csak zaj volt, és mise × év darabszámban ömlött.

                // A ciklusváltozókat KÖRÖNKÉNT nullázni kell. Korábban az `if` blokkokon
                // belül keletkeztek, tehát az előző mise értéke átszivárgott a következőre:
                // egy `until` nélküli szabály némán az előző mise záródátumát kapta meg,
                // az első ilyennél pedig — amikor még semmi nem volt beállítva — a
                // `$until->toIso8601String()` nullra futott:
                //   "Call to a member function toIso8601String() on null"
                // Élesben ez két darabot buktatott az újraindexelésből (~200 templom).
                $until = null;
                $dtstart = null;

                // A nevek korábban félrevezetők voltak: itt az a kérdés, hogy a szabály
                // teljes egészében kívül esik-e az indexelt éven.
                $endsBeforeWindow = false;
                if(isset($mass->rrule['until'])) {
                    $until = Carbon::parse($mass->rrule['until']);
                    if($until->lt($globalStart)) {
                        $endsBeforeWindow = true;
                    }
                }
                $startsAfterWindow = false;
                if(isset($mass->rrule['dtstart'])) {
                    $dtstart = Carbon::parse($mass->rrule['dtstart']);
                    if($dtstart->gt($globalEnd)) {
                        $startsAfterWindow = true;
                    }
                }

                if(!$endsBeforeWindow and !$startsAfterWindow) {

                $newRrule = $mass->rrule;  // Az aktuális tömb lekérése
                // Nyitott végű szabálynál (nincs UNTIL — pl. "minden vasárnap", ami a
                // Google-naptárakban a leggyakoribb) az indexelt év vége a záródátum.
                // Az évet úgyis évenként külön generáljuk, tehát ez nem vág le semmit.
                $newRrule['until'] = ($until ?? $globalEnd)->toIso8601String();
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
                    'duration_minutes' => self::durationInMinutes($mass->duration),
                    'location_lat' => $mass->location_lat,
                    'location_lon' => $mass->location_lon,
                    'location_name' => $mass->location_name,
                    'lang' => $mass->langs, // #334: lista, mert egy mise több nyelvű is lehet
                    'comment' => "extra ".$mass->comment,
                    'rrule' => $mass->rrule, 
                    
                ];
                $massPeriods[] = $newMassPeriod;
                }
            }
        }

        return $massPeriods;
    }

    /**
     * Értelmezhető-e a mise kezdete? Tiszta függvény (se DB, se HTTP), hogy tesztelhető
     * legyen, és hogy a mentés meg az import ugyanazt a határt húzza meg.
     *
     * A kiváltó eset: a naptárszerkesztőből `2026-01-01TNaN:NaN:NaN` érkezett — üresen
     * hagyott időpontból —, és a mentés ellenőrzés nélkül kiírta. Az ilyen mise némán
     * eltűnik: a generátor kihagyja ("Invalid RRULE dtstart, skipping mass ID …"), tehát
     * a keresőbe soha nem kerül be, a szerkesztőben viszont ott van.
     *
     * @param array $massData snake_case kulcsokkal, ahogy a mentés kapja
     * @return string|null a hiba oka, vagy null ha rendben van
     */
    public static function invalidDateTimeReason(array $massData): ?string {
        $start = $massData['start_date'] ?? null;
        if ($start !== null && $start !== '' && !self::isParsableDateTime($start)) {
            return 'A kezdés nem értelmezhető: ' . $start;
        }

        $rrule = $massData['rrule'] ?? null;
        if (is_string($rrule) && $rrule !== '') {
            $rrule = json_decode($rrule, true);
        }
        if (!is_array($rrule)) {
            return null;
        }

        foreach (['dtstart', 'until'] as $mezo) {
            $ertek = $rrule[$mezo] ?? null;
            if ($ertek !== null && $ertek !== '' && !self::isParsableDateTime($ertek)) {
                return 'Az ismétlődés ' . $mezo . ' értéke nem értelmezhető: ' . $ertek;
            }
        }

        return null;
    }

    /**
     * A Carbon a "2026-01-01TNaN:NaN:NaN"-t is elfogadná (a NaN-t szemétként eldobva,
     * éjfélre kerekítve), ezért nem elég ráhagyni: a nyilvánvalóan hibás alakot külön
     * kizárjuk. Így a hibás időpont a mentésnél derül ki, nem hónapokkal később a
     * keresőben.
     */
    private static function isParsableDateTime($value): bool {
        if ($value instanceof \DateTimeInterface) {
            return true;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        // Csak a ténylegesen használt alakokat fogadjuk el. Szabad formátumnál a Carbon
        // nagyvonalú: a "2026-01-01TNaN:NaN:NaN"-ból is éjfelet csinál, tehát önmagában
        // ráhagyva a hibás időpont csendben átcsúszna.
        $alak = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+\-]\d{2}:?\d{2})?$/', $value) === 1;
        if (!$alak) {
            return false;
        }

        try {
            Carbon::parse($value);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
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
     * A mise hossza percben.
     *
     * A `duration` JSON: `{"days": d, "hours": h, "minutes": m}`, bármelyik mezője
     * hiányozhat vagy lehet null. A modell tömbbé alakítja, de a hívó kaphat nyers
     * sztringet is, ezért mindkettőt elviseljük.
     *
     * Ugyanez a számítás eddig kétszer szerepelt szó szerint, egy harmadik helyen pedig
     * beégetett 0 állt helyette — ott az „extra" (időszak nélküli) miséknél a hossz
     * elveszett, és az iCal-export emiatt egyórásnak vette őket.
     */
    public static function durationInMinutes($duration): int
    {
        if (empty($duration)) {
            return 0;
        }

        if (is_string($duration)) {
            $decoded = json_decode($duration, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return 0;
            }
            $duration = $decoded;
        }

        if (!is_array($duration)) {
            return 0;
        }

        return (int) ($duration['days'] ?? 0) * 24 * 60
            + (int) ($duration['hours'] ?? 0) * 60
            + (int) ($duration['minutes'] ?? 0);
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
