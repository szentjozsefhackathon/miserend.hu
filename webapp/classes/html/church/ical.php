<?php

namespace Html\Church;

use Eloquent\CalMass;
use Eloquent\Church ;
use Eloquent\CalPeriod;
use Eloquent\CalGeneratedPeriod;
use SimpleRRule;

class Ical extends \Html\Html {

    /** #831: a szerkesztett templom — az események helyszínének tartaléka. */
    public $church;

    public function __construct($path) {
        // Expect path like [id]
        if (empty($path[0]) || !is_numeric($path[0])) {
            throw new \Exception('Hiányzó templom azonosító az iCal generáláshoz.');
        }
        $tid = (int)$path[0];

        // Fetch church and masses
        $church = Church::find($tid);
        // #831: az eseményekbe a templom helye is bekerül, ha az alkalomnak nincs
        // sajátja — a `createCalendarEvent()` innen veszi.
        $this->church = $church;
        $masses = CalMass::where('church_id', $tid)->get()->all();

        $massPeriods = CalMass::generateMassPeriodInstancesForYears( $masses, [], [date('Y'),date('Y')+1]);
        foreach($massPeriods as $k => $mass) {
            $rrule = new SimpleRRule($mass['rrule']);
            $occ = reset($rrule->getOccurrences());            
            
            $massPeriods[$k]['start_date'] = $occ->toString();
            

        }
        $ical = $this->generateIcal($massPeriods, $church, $tid);

        // Output headers for .ics
        if(!\Request::Boolean('text')) {
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: inline; filename="miserend_church_' . $tid . '.ics"');
        }
  
        echo $ical;
        exit;
    }

    private function formatIcsDate(string $iso): string {
        // Accept YYYY-MM-DD or ISO datetime
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            return str_replace('-', '', $iso);
        }
        try {
            $dt = new \DateTime($iso);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis');
        } catch (\Exception $e) {
            return preg_replace('/[^0-9T]/', '', $iso) ;
        }
    }

    private function escapeString($s) {
        $s = str_replace(["\\", "\n", "\r", ";", ","], ["\\\\", "\\n", "\\r", "\\;", "\\,"], $s);
        return $s;
    }

    private function rruleToString($rrule) {
        // Accept either the rrule array or an array that also contains 'exdate'
        if (!$rrule || !is_array($rrule)) return ['rrule' => '', 'exdate' => ''];
        $parts = [];
        foreach ($rrule as $k => $v) {
            // skip exdate here; handle later
            if ($k === 'exdate') continue;

            if($k == 'byweekday') $k = 'byday';

            if ($k === 'dtstart') continue;
            if ($k === 'until') {
                $parts[] = 'UNTIL=' . $this->formatIcsDate($v);
                continue;
            }
            if (is_array($v)) {
                $parts[] = strtoupper($k) . '=' . implode(',', array_map(function($x){ return strtoupper((string)$x); }, $v));
                continue;
            }
            if (strtolower($k) === 'freq') {
                $parts[] = 'FREQ=' . strtoupper((string)$v);
            } else {
                $parts[] = strtoupper($k) . '=' . (string)$v;
            }
        }

        $rruleStr = implode(';', $parts);

        // handle exdate if present: build a single EXDATE line with TZID
        $exdateLine = '';
        if (isset($rrule['exdate']) && is_array($rrule['exdate']) && count($rrule['exdate']) > 0) {
            $formatted = array_map(function($d){ 
                $dt = new \DateTime($d);
                return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\\THis');
            }, $rrule['exdate']);
            $exdateLine = 'EXDATE;TZID=Europe/Budapest:' . implode(',', $formatted);
        }

        return ['rrule' => $rruleStr, 'exdate' => $exdateLine];
    }

    /**
     * Az iCal-esemény UID-jének hoszt-része.
     *
     * A UID az esemény GLOBÁLIS AZONOSSÁGA a feliratkozó naptáralkalmazásában. Eddig
     * beégetve `miserend.hu` volt, tehát a staging és az éles ugyanazt az azonosítót adta
     * ugyanarra a misére — aki mindkettőre feliratkozott (jellemzően épp a tesztelő), annak
     * a naptára a kettőt EGY eseménynek látta, és az egyik felülírta a másikat.
     *
     * A beállított domainből vesszük a hosztot; ha az bármiért használhatatlan, marad a
     * régi érték, mert a UID-nek mindenképpen stabilnak kell lennie.
     */
    private function uidHost(): string {
        $domain = defined('DOMAIN') ? (string) DOMAIN : '';
        $host = $domain !== '' ? parse_url($domain, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : 'miserend.hu';
    }

    /**
     * #831: a TEMPLOM helyszíne, tartaléknak az alkalom saját helyszíne mellé.
     *
     * A `LOCATION`/`GEO` eddig CSAK akkor került az eseménybe, ha az alkalomnak volt
     * saját koordinátája (#431). A misék túlnyomó része viszont a templomban van —
     * azokban az eseményekben tehát semmilyen helyszín nem szerepelt.
     *
     * Aki feliratkozik a naptárra, pontosan ezt veszíti el: a telefonja nem tudja
     * megmutatni térképen, és nem tud útvonalat tervezni oda. Márpedig a naptár-export
     * fő haszna épp az, hogy a mise bekerül a saját naptáradba — hely nélkül fél adat.
     *
     * @return array{name: string, lat: ?float, lon: ?float}
     */
    private function churchLocation(): array {
        $church = $this->church ?? null;
        if (!$church) {
            return ['name' => '', 'lat' => null, 'lon' => null];
        }

        $mezo = static function ($kulcs) use ($church) {
            return is_array($church) ? ($church[$kulcs] ?? null) : ($church->$kulcs ?? null);
        };

        // A megnevezés a naptáralkalmazásban egy sorban jelenik meg: név, majd cím.
        $reszek = array_filter([
            trim((string) $mezo('nev')),
            trim((string) $mezo('cim')),
        ], fn($resz) => $resz !== '');

        $lat = $mezo('lat');
        $lon = $mezo('lon');

        return [
            'name' => implode(', ', $reszek),
            // A 0 nem koordináta: az adatbázisban a hiányzó érték jelölése (#497).
            'lat' => ($lat !== null && (float) $lat != 0.0) ? (float) $lat : null,
            'lon' => ($lon !== null && (float) $lon != 0.0) ? (float) $lon : null,
        ];
    }

    /** #831: iCal `GEO` alak — szélesség és hosszúság pontosvesszővel, tizedesponttal. */
    private function geoLine(float $lat, float $lon): string {
        $szam = static fn(float $ertek): string =>
            rtrim(rtrim(number_format($ertek, 6, '.', ''), '0'), '.');

        return sprintf('GEO:%s;%s', $szam($lat), $szam($lon));
    }

    private function createCalendarEvent($mass) {
        $lines = [];
           //printr($mass);         
        if (empty($mass['start_date'])) return [];
        $start = date('Y-m-d\TH:i:s\Z',strtotime($mass['start_date']));
        $lines[] = 'BEGIN:VEVENT';
        $uid = $mass['mass_id']."-".$mass['generated_period_id']."@".$this->uidHost();
        $lines[] = 'UID:' . $uid;
        $lines[] = 'DTSTAMP:' . gmdate('Ymd\\THis\\Z');         
        $lines[] = 'DTSTART;TZID=Europe/Budapest:' . $this->formatIcsDate($start);
                    
        $duration = ($mass['duration_minutes']!=0) ? $mass['duration_minutes'] : 60;
        $dt = new \DateTime($start);
        $dt->modify('+' . $duration . ' minutes');
        $dtend = $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $lines[] = 'DTEND;TZID=Europe/Budapest:' . $this->formatIcsDate($dtend);

        $lines[] = 'SUMMARY:' . $this->escapeString($mass['title'] ?? '');
        // A megjegyzés a DESCRIPTION-be, a típusok a CATEGORIES-be mennek (lentebb) —
        // a régi „TODO: TYPES, COMMENT, ETC." jegyzet mellől a kikommentezett, UID-et
        // kiíró sor ezért kikerült: az sem nem leírás, sem nem típus.

        if (!empty($mass['types'])) {
            $lines[] = 'CATEGORIES:' . implode(',', $this->escapeString($mass['types']));
        }

        // Color
        if (!empty($mass['color'])) {
            $lines[] = 'COLOR:' . $this->escapeString($mass['color']);
        }

        /*
         * #431: az alkalom SAJÁT helyszíne, ha nem a templomban van.
         *
         * A használati eset: „Röszke plébánia biciklitúrát szervez időnként, és van
         * mise valami random pusztai helyen." Ilyenkor a naptárba felvett esemény
         * helye NEM a templom — épp ez a lényeg, hiszen a látogatónak oda kell mennie.
         *
         * A GEO az iCal szabvány szerint `szélesség;hosszúság`, tizedesponttal. A
         * LOCATION a megnevezés, ha van; enélkül a naptáralkalmazások a koordinátát
         * mutatják, ami használható, csak csúnya.
         */
        if (!empty($mass['location_lat']) && !empty($mass['location_lon'])) {
            $lines[] = $this->geoLine((float) $mass['location_lat'], (float) $mass['location_lon']);

            if (!empty($mass['location_name'])) {
                $lines[] = 'LOCATION:' . $this->escapeString($mass['location_name']);
            }
        } else {
            /*
             * #831: nincs saját helyszín -> a TEMPLOM helye kerül be.
             *
             * Enélkül a misék túlnyomó része hely nélkül került a feliratkozó
             * naptárába: nem lehetett térképen megnézni, se útvonalat tervezni oda.
             */
            $templom = $this->churchLocation();
            if ($templom['lat'] !== null && $templom['lon'] !== null) {
                $lines[] = $this->geoLine($templom['lat'], $templom['lon']);
            }
            if ($templom['name'] !== '') {
                $lines[] = 'LOCATION:' . $this->escapeString($templom['name']);
            }
        }

        // RRULE and EXDATE
        if (!empty($mass['rrule'])) {
            $rrInput = $mass['rrule'];
            if (!empty($mass['exdate'])) $rrInput['exdate'] = $mass['exdate'];
            $rrRes = $this->rruleToString($rrInput);
            if (!empty($rrRes['rrule'])) $lines[] = 'RRULE:' . $rrRes['rrule'];
            if (!empty($rrRes['exdate'])) $lines[] = $rrRes['exdate'];
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private function generateIcal(array $masses, $church, int $churchId): string {
        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//miserend.hu//'.$church['id'].'//HU';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';

        $nev = is_array($church) ? ($church['nev'] ?? '') : ($church->nev ?? '');
        $ismertnev = is_array($church) ? ($church['ismertnev'] ?? '') : ($church->ismertnev ?? '');
        // #497: tömb esetén a toAPIArray már származtatott értéket ad; objektumnál
        // magunknak kell a boundary-ból kérni.
        $varos = is_array($church)
            ? ($church['varos'] ?? '')
            : ($church instanceof \Eloquent\Church ? $church->locationCityName() : ($church->varos ?? ''));
        $calName = $nev;
        if ($ismertnev) $calName .= $calName ? ' (' . $ismertnev . ')' : $ismertnev;
        if ($varos) $calName .= $calName ? ' - ' . $varos : $varos;

        $lines[] = 'X-WR-CALNAME:' . $this->escapeString($calName);
        $lines[] = 'X-WR-CALDESC:Frissített miserend automatikusan';
        $lines[] = 'X-WR-TIMEZONE:Europe/Budapest';

        $lines[] = 'BEGIN:VTIMEZONE';
        $lines[] = 'TZID:Europe/Budapest';
        $lines[] = 'X-LIC-LOCATION:Europe/Budapest';
        $lines[] = 'BEGIN:DAYLIGHT';
        $lines[] = 'TZOFFSETFROM:+0100';
        $lines[] = 'TZOFFSETTO:+0200';
        $lines[] = 'TZNAME:CEST';
        $lines[] = 'DTSTART:19700329T020000';
        $lines[] = 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU';
        $lines[] = 'END:DAYLIGHT';
        $lines[] = 'BEGIN:STANDARD';
        $lines[] = 'TZOFFSETFROM:+0200';
        $lines[] = 'TZOFFSETTO:+0100';
        $lines[] = 'TZNAME:CET';
        $lines[] = 'DTSTART:19701025T030000';
        $lines[] = 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU';
        $lines[] = 'END:STANDARD';
        $lines[] = 'END:VTIMEZONE';


        $dtstamp = gmdate('Ymd\\THis\\Z');
        $counter = 1;
        
        foreach ($masses as $m) {
            // #431/#831: a LOCATION és a GEO az eseményben van — az alkalom saját
            // helyszíne, ha van, egyébként a templomé.
            $lines = array_merge($lines, $this->createCalendarEvent($m));
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines);
    }
}
