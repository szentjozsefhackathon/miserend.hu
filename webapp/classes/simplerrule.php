<?php

use Carbon\Carbon;
use Carbon\CarbonInterface;

class SimpleRRule
{
    private $start;
    private $until;
    private $count;
    private $freq;
    private $interval;
    private $byMonth;
    private $byMonthDay;
    private $byWeekday;
    private $bySetpos;
    private $byWeekNo;
    private $exDate;
    private $debugCallback;
    private $timezone;


    public function __construct(array $rrule, ?callable $debugCallback = null, string $timezone = 'Europe/Budapest')
    {
        $this->timezone   = $timezone;
        $this->start      = Carbon::parse($rrule['dtstart'], $this->timezone);
        $this->until      = !empty($rrule['until']) ? Carbon::parse($rrule['until'], $this->timezone) : null;
        $this->count      = $rrule['count'] ?? null;
        $this->freq       = strtoupper($rrule['freq'] ?? 'DAILY');
        $this->interval   = $rrule['interval'] ?? 1;
        $this->byMonth    = $rrule['bymonth'] ?? [];
        $this->byMonthDay = $rrule['bymonthday'] ?? [];
        $this->byWeekday  = $this->normalizeByWeekday($rrule['byweekday'] ?? []);
        $this->bySetpos   = $rrule['bysetpos'] ?? null;
        $this->byWeekNo   = $rrule['byweekno'] ?? [];
        $this->exDate     = $rrule['exdate'] ?? [];
        $this->debugCallback = $debugCallback;
    }

    private function logDebug(string $msg, array $ctx = []): void
    {
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, $msg, $ctx);
        }
    }

    /**
     * #765: a `byweekday` STRINGKÉNT is érkezhet, nem csak tömbként.
     *
     * A külső naptár importálója a `BYDAY=2SU` alakot (minden hónap 2. vasárnapja)
     * egyetlen stringgel adta vissza, míg a `BYDAY=SU,MO` alakot tömbbel — a típus-
     * kikötés miatt az előbbi TypeError-ral megölte az egész import futását.
     *
     * Az importálót külön javítom, de itt is elfogadjuk: az adatbázisban MÁR ott vannak
     * a korábbi futások stringes sorai, és azok különben minden generáláskor újra
     * elhasalnának.
     *
     * @param array|string|null $days
     */
    private function normalizeByWeekday($days): array
    {
        if ($days === null || $days === '') {
            return [];
        }
        if (!is_array($days)) {
            $days = [$days];
        }

        $map = [
            'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4,
            'FR' => 5, 'SA' => 6, 'SU' => 7,
        ];

        $out = [];
        foreach ($days as $d) {
            if ($d === null || $d === '') {
                continue;
            }
            $out[] = $map[strtoupper((string) $d)] ?? $d;
        }
        return $out;
    }

    public function getOccurrences(): array
    {
        $occurrences = [];
        $current = clone $this->start;
        $generated = 0;

        $weekdayMap = [
            1 => CarbonInterface::MONDAY,
            2 => CarbonInterface::TUESDAY,
            3 => CarbonInterface::WEDNESDAY,
            4 => CarbonInterface::THURSDAY,
            5 => CarbonInterface::FRIDAY,
            6 => CarbonInterface::SATURDAY,
            7 => CarbonInterface::SUNDAY,
        ];

        $this->logDebug("getOccurrences indul", [
            'start'     => $this->start->toIso8601String(),
            'until'     => $this->until?->toIso8601String(),
            'freq'      => $this->freq,
            'count'     => $this->count,
            'interval'  => $this->interval,
            'byMonth'   => $this->byMonth,
            'byMonthDay'=> $this->byMonthDay,
            'byWeekday' => $this->byWeekday,
            'bySetpos'  => $this->bySetpos,
        ]);

        $maxIterationsPerFreq = [
            'DAILY' => 100000,
            'WEEKLY' => 10000,
            'MONTHLY' => 2000,
            'YEARLY' => 3  // Limit to 3 iterations to support count up to 3
        ];
        $iterations = 0;
        $maxIterations = $maxIterationsPerFreq[$this->freq] ?? 10000;
        
        while (true) {
            // Check if we should stop
            if ($this->count !== null && $generated >= $this->count) {
                break;
            }
            if ($this->until && $current->gt($this->until)) {
                break;
            }
            // Safety check: prevent infinite loops with iteration limit
            if (++$iterations > $maxIterations) {
                break;
            }
            
            switch ($this->freq) {
                case 'DAILY':
                    // Ha van BYDAY, akkor csak a megadott napokon generálunk
                    $weekday = $current->dayOfWeek === 0 ? 7 : $current->dayOfWeek;
                    if (empty($this->byWeekday) || in_array($weekday, $this->byWeekday)) {
                        $occurrences[] = clone $current;
                        $generated++;
                        $this->logDebug("Daily occurrence", [
                            'date' => $current->toIso8601String(),
                            'weekday' => $weekday,
                            'generated' => $generated
                        ]);
                    }
                    // Use addDay on a clone to avoid timezone issues with direct mutation
                    $current = $current->copy()->addDays($this->interval)->setTimezone($this->timezone);
                    break;

                case 'WEEKLY':
                    // Heti frekvencia: minden héten BYDAY szerinti napokat generálunk
                    $weekStart = $current->copy()->startOfWeek(Carbon::MONDAY);

                    // ha van megadva byWeekNo, és az aktuális hét száma nincs benne, akkor ugorjuk át
                    if (!empty($this->byWeekNo) && !in_array((int)$weekStart->format('W'), $this->byWeekNo, true)) {
                        $current = $weekStart->copy()->addWeeks($this->interval)->setTimezone($this->timezone);
                        break;
                    }

                    // Ha nincs BYDAY megadva, akkor az indulás napját használjuk
                    $weekdaysToProcess = !empty($this->byWeekday) ? $this->byWeekday : [($this->start->dayOfWeek === 0 ? 7 : $this->start->dayOfWeek)];
                    
                    foreach ($weekdaysToProcess as $weekday) {
                        // Create occurrence by first getting the date, then explicitly setting time components
                        // This avoids DST issues by not relying on setTimeFrom() after date arithmetic
                        $occurrence = $weekStart->copy()->addDays($weekday - 1);
                        $occurrence->setTime($this->start->hour, $this->start->minute, $this->start->second, 0);
                        $occurrence->setTimezone($this->timezone); // Ensure timezone is consistent
                        if ($occurrence->gte($current->copy()->startOfWeek()) &&
                            $occurrence->lte($current->copy()->endOfWeek()) &&
                            (!$this->until || $occurrence->lte($this->until)) &&
                            (!$this->count || $generated < $this->count) &&
                            $occurrence->gte($this->start)) {
                            $occurrences[] = $occurrence;
                            $generated++;
                            $this->logDebug("Weekly occurrence", [
                                'date' => $occurrence->toIso8601String(),
                                'weekday' => $weekday,
                                'weekNo' => (int)$weekStart->format('W'),
                                'generated' => $generated
                            ]);
                        }
                    }
                    $current = $weekStart->copy()->addWeeks($this->interval)->setTimezone($this->timezone); 
                    break;

                case 'MONTHLY':
                    // Havi frekvencia: ha van BYDAY → hónapon belüli napokat számoljuk
                    if (!empty($this->byWeekday)) {
                        foreach ($this->byWeekday as $weekday) {
                            $monthStart = $current->copy()->startOfMonth();
                            if ($this->bySetpos) {
                                if($this->bySetpos > 0) {
                                    // Pozitív BYSETPOS esetén az adott hónap elejétől számolunk
                                    $occurrence = $monthStart->copy()->nthOfMonth($this->bySetpos, $weekdayMap[$weekday]);
                                } elseif($this->bySetpos == -1)  {
                                    // -1 BYSETPOS esetén a hónap utolsóját számoljuk
                                    $occurrence = $monthStart->copy()->lastOfMonth($weekdayMap[$weekday]);
                                } else {
                                    throw new Exception("Unsupported BYSETPOS value: {$this->bySetpos}");
                                }
                                // pySetpos 5 esetén gyarkan előfordulhat, hogy nincs ilyen nap a hónapban
                                if($occurrence) {
                                    $occurrence->setTime($this->start->hour, $this->start->minute, $this->start->second, 0);
                                    $occurrence->setTimezone($this->timezone); // Ensure timezone is consistent
                                }

                            } else {
                                // Ha nincs BYSETPOS → alapértelmezés: első ilyen nap
                                $occurrence = $monthStart->copy()->nthOfMonth(1, $weekdayMap[$weekday]);
                                $occurrence->setTime($this->start->hour, $this->start->minute, $this->start->second, 0);
                                $occurrence->setTimezone($this->timezone); // Ensure timezone is consistent
                            }

                            if ($occurrence &&
                                (!$this->until || $occurrence->lte($this->until)) &&
                                (!$this->count || $generated < $this->count) &&
                                $occurrence->gte($this->start)) {
                                $occurrences[] = $occurrence;
                                $generated++;
                                $this->logDebug("Monthly occurrence", [
                                    'date' => $occurrence->toIso8601String(),
                                    'weekday' => $weekday,
                                    'bySetpos' => $this->bySetpos,
                                    'generated' => $generated
                                ]);
                            }
                        }
                        // For BYDAY case, move to start of next month interval
                        $current = $current->copy()->addMonths($this->interval)->startOfMonth()->setTimezone($this->timezone);  
                    } else {
                        // Nincs BYDAY → simán ugyanaz a nap minden hónapban
                        $occurrence = $current->copy();
                        if ((!$this->until || $occurrence->lte($this->until)) &&
                            (!$this->count || $generated < $this->count)) {
                            $occurrences[] = $occurrence;
                            $generated++;
                            $this->logDebug("Monthly occurrence (no BYDAY)", [
                                'date' => $occurrence->toIso8601String(),
                                'generated' => $generated
                            ]);
                        }
                        // For no-BYDAY case, just add months without resetting time/day
                        $current = $current->copy()->addMonths($this->interval)->setTimezone($this->timezone);
                    }
                    break;
                
                case 'YEARLY':
                    // Éves ismétlődés: csak byMonth és byMonthDay tömbök alapján generálunk konkrét dátumokat
                    // Use start date's month/day when not explicitly specified to avoid changing them across years
                    $months = !empty($this->byMonth) ? $this->byMonth : [$this->start->month];
                    $days = !empty($this->byMonthDay) ? $this->byMonthDay : [$this->start->day];

                    $yearToProcess = (int)$current->year;
                    
                    // Generate all valid date combinations for this year
                    foreach ($months as $m) {
                        if ($this->count && $generated >= $this->count) break; // Early exit if count reached
                        foreach ($days as $d) {
                            if ($this->count && $generated >= $this->count) break; // Early exit if count reached
                            
                            $m = (int)$m; $d = (int)$d;
                            // Ellenőrizzük, hogy létezik-e ilyen nap az adott évben
                            if (!checkdate($m, $d, $yearToProcess)) {
                                continue;
                            }

                            try {
                                // Create date without time first, then set time explicitly to avoid DST issues
                                $occurrence = Carbon::createFromDate($yearToProcess, $m, $d, $this->timezone);
                                $occurrence->setTime($this->start->hour, $this->start->minute, $this->start->second, 0);
                                $occurrence->setTimezone($this->timezone); // Ensure timezone is consistent
                            } catch (Exception $e) {
                                continue;
                            }

                            if ($occurrence->gte($this->start) &&
                                (!$this->until || $occurrence->lte($this->until)) &&
                                (!$this->count || $generated < $this->count)) {
                                $occurrences[] = $occurrence;
                                $generated++;
                                $this->logDebug("Yearly occurrence", [
                                    'date' => $occurrence->toIso8601String(),
                                    'month' => $m,
                                    'day' => $d,
                                    'generated' => $generated
                                ]);
                            }
                        }
                    }

                    // Tovább lépünk az év intervallumával
                    // Simply advance by the interval - this preserves month and day
                    $current = $current->copy()->addYears($this->interval)->setTimezone($this->timezone);
                    break;

                default:
                    throw new Exception("Unsupported frequency: {$this->freq}");
            }
        }

        $occurrences = $this->filterDates($occurrences);

        $this->logDebug("getOccurrences vége", ['total' => count($occurrences)]);
        return $occurrences;
    }

    function filterDates(array $occurrences) {
        return array_filter($occurrences, function($date) {
            return !in_array($date->toDateString(), $this->exDate);
        });
    }
    

    // EZt át kéne venni a miserend.hu/calendar/src/app/components/church-calendar/church-calendar.component.ts 
    // private getReadableRRule(mass: Mass) függvénye alapján

    function toText(): string {
        $parts = [];
        $parts[] = "Freq: " . $this->freq;
        $parts[] = "Interval: " . $this->interval;
        if (!empty($this->byWeekday)) {
            $parts[] = "ByWeekday: " . implode(',', $this->byWeekday);
        }
        if ($this->bySetpos) {
            $parts[] = "BySetpos: " . $this->bySetpos;
        }
        if (!empty($this->byWeekNo)) {
            $parts[] = "ByWeekNo: " . implode(',', $this->byWeekNo);
        }
        // Nem kell, mert időszakok végéig tart mindig.        
        //if ($this->until) {
        //    $parts[] = "Until: " . $this->until->toIso8601String();
        //}
        if(!empty($this->byMonth)) {
            if(!is_array($this->byMonth)) {
                $this->byMonth = [$this->byMonth];
            }   
            $parts[] = "ByMonth: " . implode(',', $this->byMonth);
        }
        if(!empty($this->byMonthDay)) {
            $parts[] = "ByMonthDay: " . implode(',', $this->byMonthDay);
        }   
        if ($this->count) {
            $parts[] = "Count: " . $this->count;
        }
        if (!empty($this->exDate)) {
            $parts[] = "ExDate: " . implode(',', $this->exDate);
        }
        return implode('; ', $parts);
    }

    /**
     * #307/#536: az RRULE emberi nyelvre fordítása — mert a
     * „FREQ=WEEKLY;BYDAY=MO,WE,FR;BYSETPOS=-1" éjfél után legfeljebb egy
     * elszánt naptár-szerzetesnek árul el bármit. A gondnok inkább azt
     * olvassa: „hétfőn, szerdán, pénteken, minden héten".
     *
     * Az Angular getReadableRRule() ikertestvére, statikus és Carbon nélkül
     * (tiszta tömb→szöveg). Ha ott változik a logika, ITT is pofozd meg,
     * különben a két oldal szép lassan hazudni kezd egymásnak, és senki nem
     * érti majd, miért mást mutat a naptár, mint az e-mail. (A húsvét a
     * period-kontextust igényli, ami itt nincs → csak a karácsonyt fejtjük.)
     *
     * @param array|null $rrule freq, byweekday, bysetpos, bymonth, bymonthday,
     *                          byweekno, count, dtstart
     * @return string emberi-olvasható magyar leírás, vagy '' ha nincs rrule
     */
    static function humanText($rrule): string
    {
        if (empty($rrule) || !is_array($rrule)) {
            return '';
        }
        $parts = [];

        $christmas = self::humanChristmas($rrule);
        if ($christmas !== null) {
            $parts[] = $christmas;
        } else {
            $days = self::humanDays($rrule);
            if ($days !== '') $parts[] = $days;
            $week = self::humanWeek($rrule);
            if ($week !== null) $parts[] = $week;
            $month = self::humanMonth($rrule);
            if ($month !== null) $parts[] = $month;
            $yearCombined = array_filter([
                self::humanYear($rrule),
                self::humanMonths($rrule),
                self::humanMonthDays($rrule),
            ], fn($p) => $p !== null && $p !== '');
            if (!empty($yearCombined)) $parts[] = implode(' ', $yearCombined);
            $simple = self::humanSimpleEvent($rrule);
            if ($simple !== null) $parts[] = $simple;
        }

        return implode(', ', $parts);
    }

    private static function humanDays($rrule): string
    {
        $days = $rrule['byweekday'] ?? null;
        if (empty($days)) return '';
        if (!is_array($days)) $days = [$days];
        $map = ['MO' => 'hétfőn', 'TU' => 'kedden', 'WE' => 'szerdán',
                'TH' => 'csütörtökön', 'FR' => 'pénteken', 'SA' => 'szombaton', 'SU' => 'vasárnap'];
        $codes = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];
        $out = [];
        foreach ($days as $d) {
            // Elfogad 'MO'.. kódot és 1..7 (MO=1) számot is.
            if (is_numeric($d)) {
                $i = (int) $d;
                $code = ($i >= 1 && $i <= 7) ? $codes[$i - 1] : null;
            } else {
                $u = strtoupper((string) $d);
                $code = in_array($u, $codes, true) ? $u : null;
            }
            if ($code !== null) $out[] = $map[$code];
        }
        return implode(', ', $out);
    }

    private static function humanWeek($rrule): ?string
    {
        if (strtolower($rrule['freq'] ?? '') !== 'weekly') return null;
        $byWeekNo = $rrule['byweekno'] ?? [];
        if (!empty($byWeekNo)) {
            if (!is_array($byWeekNo)) $byWeekNo = [$byWeekNo];
            $allEven = true; $allOdd = true;
            foreach ($byWeekNo as $n) {
                if ((int) $n % 2 === 0) $allOdd = false; else $allEven = false;
            }
            if ($allEven) return 'páros heteken';
            if ($allOdd) return 'páratlan heteken';
            return null;
        }
        return 'minden héten';
    }

    private static function humanMonth($rrule): ?string
    {
        if (!isset($rrule['bysetpos']) || $rrule['bysetpos'] === '' || $rrule['bysetpos'] === null) {
            return null;
        }
        $map = [
            1 => 'minden hónapban az első', 2 => 'minden hónapban a második',
            3 => 'minden hónapban a harmadik', 4 => 'minden hónapban a negyedik',
            5 => 'minden hónapban az ötödik', -1 => 'minden hónapban az utolsó',
        ];
        return $map[(int) $rrule['bysetpos']] ?? null;
    }

    private static function humanYear($rrule): ?string
    {
        return strtolower($rrule['freq'] ?? '') === 'yearly' ? 'minden évben' : null;
    }

    private static function humanMonths($rrule): ?string
    {
        $byMonth = $rrule['bymonth'] ?? null;
        if ($byMonth === null || $byMonth === '' || $byMonth === []) return null;
        if (!is_array($byMonth)) $byMonth = [$byMonth];
        $names = [1 => 'január', 2 => 'február', 3 => 'március', 4 => 'április',
                  5 => 'május', 6 => 'június', 7 => 'július', 8 => 'augusztus',
                  9 => 'szeptember', 10 => 'október', 11 => 'november', 12 => 'december'];
        $out = [];
        foreach ($byMonth as $m) {
            $mi = (int) $m;
            if (isset($names[$mi])) $out[] = $names[$mi];
        }
        return empty($out) ? null : implode(', ', $out);
    }

    private static function humanMonthDays($rrule): ?string
    {
        $md = $rrule['bymonthday'] ?? null;
        if ($md === null || $md === '' || $md === []) return null;
        if (!is_array($md)) $md = [$md];
        $out = [];
        foreach ($md as $d) {
            if (is_numeric($d)) $out[] = ((int) $d) . '-én';
        }
        return empty($out) ? null : implode(', ', $out);
    }

    private static function humanChristmas($rrule): ?string
    {
        $byMonth = $rrule['bymonth'] ?? null;
        if (is_array($byMonth)) {
            $byMonth = count($byMonth) === 1 ? (int) $byMonth[0] : null;
        }
        if ((int) $byMonth !== 12) return null;

        $md = $rrule['bymonthday'] ?? null;
        if (!is_array($md)) $md = ($md === null || $md === '') ? [] : [$md];
        if (count($md) !== 1) return null;

        $map = [24 => 'Szenteste', 25 => 'Karácsony', 26 => 'Karácsony másnapja'];
        return $map[(int) $md[0]] ?? null;
    }

    private static function humanSimpleEvent($rrule): ?string
    {
        $count = $rrule['count'] ?? null;
        $countIsOne = ($count === 1 || $count === '1');
        if (strtolower($rrule['freq'] ?? '') === 'daily' && $countIsOne && !empty($rrule['dtstart'])) {
            $ts = strtotime($rrule['dtstart']);
            if ($ts !== false) {
                return 'Egyszeri alkalom: ' . date('Y.m.d', $ts);
            }
        }
        return null;
    }

}
