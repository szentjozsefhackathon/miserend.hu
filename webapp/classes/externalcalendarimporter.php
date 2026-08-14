<?php

/**
 * Detect and convert content to UTF-8
 * Handles charset from headers and validates UTF-8
 *
 * @param string $content Raw content from download
 * @param string $contentType HTTP Content-Type header (optional)
 * @return string UTF-8 encoded content
 * @throws \Exception
 */
function ensureUtf8($content, $contentType = '') {
    // 1. Extract charset from Content-Type header if provided
    $charset = 'UTF-8';
    if (!empty($contentType) && preg_match('/charset\s*=\s*([^\s;]+)/i', $contentType, $matches)) {
        $charset = strtoupper(trim($matches[1], '"\''));
    }
    
    // 2. Remove UTF-8 BOM if present
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }
    
    // 3. Convert to UTF-8 if not already
    if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
        $content = iconv($charset, 'UTF-8//IGNORE', $content);
        if ($content === false) {
            throw new \Exception("Failed to convert charset from $charset to UTF-8");
        }
    }
    
    // 4. Validate and sanitize UTF-8
    if (!mb_check_encoding($content, 'UTF-8')) {
        // Remove invalid UTF-8 sequences
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
    }
    
    return $content;
}

/**
 * Sanitize string for database storage
 * Ensures valid UTF-8 encoding without null bytes or control characters
 *
 * @param string $text
 * @return string
 */
function sanitizeUtf8($text) {
    // Remove null bytes
    $text = str_replace("\0", '', $text);
    
    // Validate/fix UTF-8
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    
    return trim($text);
}

/**
 * External Calendar Importer
 * Imports masses from Google Calendar iCalendar (ICS) format
 */
class ExternalCalendarImporter {

    public const IMPORT_MARKER = 'External calendar import';
    private const CRON_CLASS = '\\ExternalCalendarImporter';

    /**
     * Main cron entry point: Import all active external calendars
     * This is called daily from the cron system
     */
    public static function importAllExternalCalendars(?callable $calendarImporter = null) {
        set_time_limit(600);
        $calendars = \Eloquent\ExternalCalendar::where('active', 1)->get();

        if ($calendars->isEmpty()) {
            echo "ℹ No active external calendars to import.<br>\n";
            return;
        }

        $import = $calendarImporter ?? static function ($calendar): void {
            self::importFromUrl($calendar->url, (int)$calendar->church_id);
        };
        $failures = [];

        foreach ($calendars as $calendar) {
            try {
                echo "▶ Importing: Church #{$calendar->church_id} - {$calendar->name}...<br>\n";
                $import($calendar);

                $calendar->last_import_at = \Carbon\Carbon::now();
                $calendar->save();
                echo "✓ Import successful: Church #{$calendar->church_id}<br>\n";
            } catch (\Throwable $e) {
                $message = "Church #{$calendar->church_id}: " . $e->getMessage();
                $failures[] = $message;
                echo "✗ Import failed for {$message}<br>\n";
                if (class_exists('Log')) {
                    \Log::error('ExternalCalendarImporter: ' . $message);
                } else {
                    error_log('ExternalCalendarImporter: ' . $message);
                }
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException('External calendar import failed: ' . implode('; ', $failures));
        }
    }

    /*
     * #638: ez a függvény volt a kiindulópont ("jó-e ez a cron-kezelés?"). A felvétel
     * logikája átkerült a \Eloquent\Cron::ensureRegistered()-be, a munka maga pedig a
     * webapp/fajlok/crons.php registrybe — így új cron-függvénynél nincs kézi INSERT.
     * Ez a metódus megmarad kényelmi belépőnek (és a teszteknek).
     */
    public static function ensureCronRegistered(): \Eloquent\Cron {
        \Eloquent\Cron::ensureRegistered(self::CRON_CLASS, 'importAllExternalCalendars', '1 day');

        return \Eloquent\Cron::whereIn('class', [self::CRON_CLASS, self::class])
            ->where('function', 'importAllExternalCalendars')
            ->firstOrFail();
    }

    /**
     * Import a single calendar from URL
     * 
     * Flow:
     * 1. Download ICS content from URL
     * 2. Delete existing external calendar masses (period_id IS NULL)
     * 3. Parse iCalendar and create new CalMass objects
     * 4. Refresh Elasticsearch index
     */
    private static function importFromUrl($url, $churchId) {
        // 1. Download ICS content
        $icsContent = self::downloadIcsFromUrl($url);
        
        if (empty($icsContent)) {
            throw new \Exception("Failed to download iCalendar from URL: " . substr($url, 0, 50) . "...");
        }
        
        // 2-3. Parse the complete feed, then replace only earlier imported masses atomically.
        $feedModifiedOn = null;
        $eventsCreated = self::replaceFromIcs($icsContent, $churchId, $feedModifiedOn);

        echo "  Created $eventsCreated masses from iCalendar<br>\n";

        // #723: ha a naptárban van frissebb módosítás, mint a templom frissesség-dátuma,
        // vegyük át. Így a rendszeresen karbantartott külső naptár akkor is frissen tartja
        // a templomot, ha a gazdája sosem lép be a miserend.hu-ra — és fordítva: az évek
        // óta érintetlen naptár helyesen marad réginek.
        if ($feedModifiedOn !== null && self::touchChurchFreshness($churchId, $feedModifiedOn)) {
            echo "  Frissesség dátuma átvéve a naptárból: $feedModifiedOn<br>\n";
        }

        // 4. Refresh Elasticsearch index for this church
        $years = self::extractIndexedYears($icsContent);
        \ExternalApi\ElasticsearchApi::updateMasses($years, [$churchId],
            function($msg) { echo "  " . $msg . "<br>\n"; }
        );

        echo "  Elasticsearch index updated<br>\n";
    }

    /**
     * Parse a complete iCalendar feed and atomically replace this church's earlier import.
     * Manually entered one-off masses also have a null period_id, so ownership is identified
     * exclusively by IMPORT_MARKER.
     */
    public static function replaceFromIcs(string $icsContent, int $churchId, ?string &$feedModifiedOn = null): int {
        if (!preg_match('/BEGIN:VCALENDAR/i', $icsContent) || !preg_match('/END:VCALENDAR/i', $icsContent)) {
            throw new \InvalidArgumentException('Invalid iCalendar document.');
        }

        $events = self::parseIcsEvents($icsContent);
        $masses = [];
        foreach ($events as $event) {
            $masses[] = self::createCalMassFromEvent($event, $churchId);
        }
        $feedModifiedOn = self::lastModifiedDate($events);

        $connection = \Eloquent\CalMass::getConnectionResolver()->connection();
        $connection->transaction(function () use ($churchId, $masses): void {
            \Eloquent\CalMass::where('church_id', $churchId)
                ->where('comment', self::IMPORT_MARKER)
                ->delete();

            foreach ($masses as $mass) {
                $mass->save();
            }
        });

        return count($masses);
    }

    /**
     * #723: a feed legkésőbbi LAST-MODIFIED értéke, `Y-m-d` alakban.
     *
     * Jövőbe mutató értéket nem fogadunk el: egy elrontott naptár nem tolhatja előre a
     * templom frissesség-dátumát. Ha egyetlen esemény sem hordoz LAST-MODIFIED-et,
     * null jön vissza, és a `frissites`-hez nem nyúlunk.
     *
     * @param object[] $events
     */
    private static function lastModifiedDate(array $events): ?string {
        $today = date('Y-m-d');
        $latest = null;

        foreach ($events as $event) {
            $raw = $event->{'LAST-MODIFIED'} ?? null;
            if (empty($raw)) {
                continue;
            }
            try {
                $date = substr(self::parseIcsDateTime($raw), 0, 10);
            } catch (\Throwable $e) {
                continue;
            }
            if ($date > $today) {
                continue;
            }
            if ($latest === null || $date > $latest) {
                $latest = $date;
            }
        }

        return $latest;
    }

    /**
     * #723: a templom frissesség-dátumát csak ELŐRE mozgatjuk. Ha a kézi frissítés
     * újabb, mint a naptáré, marad a kézi.
     */
    public static function touchChurchFreshness(int $churchId, string $modifiedOn): bool {
        $church = \Eloquent\Church::find($churchId);
        if (!$church) {
            return false;
        }

        // #174-B: a '0000-00-00' truthy string, ezért nem elég a sima üresség-vizsgálat.
        $current = (string) $church->frissites;
        $hasCurrent = $current !== '' && strpos($current, '0000-00-00') !== 0;
        if ($hasCurrent && substr($current, 0, 10) >= $modifiedOn) {
            return false;
        }

        $church->frissites = $modifiedOn;
        $church->save();

        return true;
    }

    /**
     * #756: az indexelendő évek — ÉSSZERŰ ablakra vágva.
     *
     * Eddig a feed MINDEN `DTSTART` évét indexeltük. Egy régóta vezetett Google
     * naptárban viszont ott van a teljes múlt is, sőt hibás/epoch dátumból 1970 is —
     * a templom/276 naplója ezért volt tele „... in year 1970" sorokkal. Az 1970-es
     * misékre senki nem keres, viszont minden ilyen év végigfut a teljes
     * mise-generáláson, tehát csak a futásidőt szorozza.
     *
     * Az ablak alja a tavalyi év (ennyi kell a „mikor volt utoljára" jellegű
     * kérdésekhez), a teteje pedig egy felső korlát: enélkül egyetlen elgépelt
     * évszám (pl. 29999) beláthatatlanul sok kört jelentene.
     */
    private const INDEX_YEARS_BACK = 1;
    private const INDEX_YEARS_AHEAD = 5;

    /** @return int[] */
    private static function extractIndexedYears(string $icsContent): array {
        $thisYear = (int)date('Y');
        $min = $thisYear - self::INDEX_YEARS_BACK;
        $max = $thisYear + self::INDEX_YEARS_AHEAD;

        preg_match_all('/^DTSTART(?:;[^:]*)?:(\d{4})/mi', $icsContent, $matches);
        $years = array_filter(
            array_map('intval', $matches[1] ?? []),
            static fn (int $y): bool => $y >= $min && $y <= $max
        );

        // A kereséshez ez a három év mindig kell, akkor is, ha a feed egyetlen
        // eseménye sem esik ide (pl. csak régi vagy csak nagyon távoli dátumok).
        $years[] = $thisYear - 1;
        $years[] = $thisYear;
        $years[] = $thisYear + 1;

        $years = array_values(array_unique($years));
        sort($years);
        return $years;
    }

    /**
     * Download ICS content from URL using ExternalApi
     */
    private static function downloadIcsFromUrl($url) {
        try {
            $host = parse_url($url, PHP_URL_HOST);
            $ips = self::resolveHostIps((string)$host);
            if (!self::isAllowedCalendarUrl($url, $ips)) {
                throw new \Exception('Only public HTTPS iCalendar URLs are allowed.');
            }
            
            // Download raw ICS content
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'miserend.hu/ExternalCalendarImporter');
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_RESOLVE, [sprintf('%s:443:%s', $host, $ips[0])]);
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode !== 200 || empty($content)) {
                throw new \Exception("HTTP $httpCode: Failed to download from $url" . ($curlError ? " ($curlError)" : ''));
            }
            
            return $content;
        } catch (\Exception $e) {
            throw new \Exception("Download failed: " . $e->getMessage());
        }
    }

    /**
     * SSRF guard for editor-provided calendar URLs. Resolved addresses may be supplied
     * by tests; production resolves and pins the same public address for cURL.
     */
    public static function isAllowedCalendarUrl(string $url, ?array $resolvedIps = null): bool {
        if (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '' || strtolower($host) === 'localhost') {
            return false;
        }

        $ips = $resolvedIps ?? self::resolveHostIps($host);
        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    public static function saveCalendarUrl(int $churchId, string $url, ?array $resolvedIps = null): ?\Eloquent\ExternalCalendar {
        $url = trim($url);
        if ($url === '') {
            \Eloquent\ExternalCalendar::where('church_id', $churchId)->update(['active' => 0]);
            return null;
        }
        if (!self::isAllowedCalendarUrl($url, $resolvedIps)) {
            throw new \InvalidArgumentException('Csak publikus HTTPS iCalendar URL adható meg.');
        }

        return \Eloquent\ExternalCalendar::updateOrCreate(
            ['church_id' => $churchId, 'name' => 'Google Calendar'],
            ['url' => $url, 'active' => 1]
        );
    }

    /** @return string[] */
    private static function resolveHostIps(string $host): array {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = gethostbynamel($host);
        return $ips === false ? [] : array_values(array_unique($ips));
    }

    /**
     * Custom iCalendar parser - extracts VEVENT blocks and their properties
     * Returns array of event objects with SUMMARY, DTSTART, DTEND, DURATION, RRULE properties
     */
    private static function parseIcsEvents($icsContent) {
        $events = [];
        
        // Split by VEVENT blocks
        $pattern = '/BEGIN:VEVENT\s*(.*?)\s*END:VEVENT/is';
        if (!preg_match_all($pattern, $icsContent, $matches)) {
            return [];
        }
        
        foreach ($matches[1] as $eventData) {
            // RFC 5545 line unfolding: a line starting with space/tab continues the previous one.
            $eventData = preg_replace("/\r?\n[ \t]/", '', $eventData);
            $event = (object) [
                'SUMMARY' => null,
                'DTSTART' => null,
                'DTEND' => null,
                'DURATION' => null,
                'RRULE' => null,
                'EXDATES' => [],  // To store EXDATE values if needed in the future
                // #723: az esemény utolsó VALÓDI módosítása. Ebből lesz a templom
                // frissesség-dátuma. Szándékosan NEM a DTSTAMP: azt a Google az
                // exportáláskor tölti ki, tehát minden lekérésnél mai — attól minden
                // naptár örökké "frissnek" látszana, akkor is, ha évek óta hozzá se nyúltak.
                'LAST-MODIFIED' => null,
            ];
            
            // Parse EXDATE (capture the full parameter+value part, e.g. ;TZID=Europe/Budapest:20241222T183000)
            if (preg_match_all('/^EXDATE\;\b(.*)$/im', $eventData, $exdateMatches)) {
                foreach ($exdateMatches[1] as $exdate) {
                    // Store the whole right-hand side (including parameters and the ':' + datetime)
                    $event->EXDATE[] = trim($exdate);
                }
            }

            // Parse SUMMARY
            if (preg_match('/^SUMMARY:(.*)$/im', $eventData, $m)) {
                $event->SUMMARY = trim(preg_replace('/\\\\,/', ', -', $m[1]));
            }
            
            // Parse DTSTART
            if (preg_match('/^DTSTART(?:;|:)(.*)$/im', $eventData, $m)) {
                $event->DTSTART = trim($m[1]);
            }
            
            // Parse DTEND
            if (preg_match('/^DTEND(?:;|:)(.*)$/im', $eventData, $m)) {
                $event->DTEND = trim($m[1]);
            }
            
            // Parse DURATION
            if (preg_match('/^DURATION\s*:\s*(.*)$/im', $eventData, $m)) {
                $event->DURATION = trim($m[1]);
            }
            
            // Parse RRULE
            if (preg_match('/^RRULE\s*:\s*(.*)$/im', $eventData, $m)) {
                $event->RRULE = trim($m[1]);
            }

            // Parse LAST-MODIFIED (#723)
            if (preg_match('/^LAST-MODIFIED(?:;|:)(.*)$/im', $eventData, $m)) {
                $event->{'LAST-MODIFIED'} = trim($m[1]);
            }

            $events[] = $event;
        }
        
        return $events;
    }

    /**
     * #756: a `cal_masses.title` varchar(255) — a hosszabb SUMMARY-tól az import
     * elhasalt („Data too long"). Élő eset a templom/282 gyászmise-bejegyzése, ami a
     * teljes gyászjelentést beleírta a címbe (~430 karakter).
     *
     * Nem az egész eseményt dobjuk el egy hosszú cím miatt: levágjuk. Szóhatáron,
     * hogy ne maradjon csonka szó, és többájtos-biztosan (mb_*), különben egy
     * kettévágott ékezetes karakter érvénytelen UTF-8-at adna.
     *
     * A `comment` mezőbe NEM tehetjük át a maradékot: azt az IMPORT_MARKER foglalja,
     * és pontos egyezéssel dolgozik a `replaceAll()` törlése és az `isImported()` is.
     */
    private const TITLE_MAX_LENGTH = 255;

    public static function trimSummary(string $summary): string {
        // Az iCal SUMMARY tartalmazhat sortörést és folytatósort; egy sorba hozzuk.
        $summary = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);

        if (mb_strlen($summary) <= self::TITLE_MAX_LENGTH) {
            return $summary;
        }

        $cut = mb_substr($summary, 0, self::TITLE_MAX_LENGTH - 1);

        // Csak akkor vágunk vissza szóhatárig, ha nem veszítjük el a cím felét.
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > (int)(self::TITLE_MAX_LENGTH / 2)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " ,;:-–—") . '…';
    }

    /**
     * Convert iCalendar VEVENT to CalMass object
     */
    private static function createCalMassFromEvent($event, $churchId) {
        
        $summary = isset($event->SUMMARY) ? (string)$event->SUMMARY : 'External Calendar Event';
        $startDate = self::extractStartDate($event);        
        $duration = self::extractDuration($event);
        $rrule = null;

        if($event->RRULE) {
            $rrule = self::extractRRule($event);
            $rruleString = $rrule ? json_encode($rrule) : 'none';
            if($rrule and !isset($rrule['dtstart'])) {
                echo "  ⚠ Warning: RRULE is missing DTSTART reference, skipping RRULE: " . json_encode($rrule) . "<br>\n";    
                $rrule = null;
            }
        }
    
        if(!empty($event->EXDATE)) {            
            $exdates = self::extractExDates($event);
        }

        $title = self::trimSummary($summary);
        if ($title !== trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary)) {
            // A vágás nem hiba, de nem is némán történik: a naptár gazdája így
            // megtudja, hogy a bejegyzés címe hosszabb, mint amit meg tudunk jeleníteni.
            echo "  ⚠ A cím túl hosszú volt, levágtam: " . htmlspecialchars($title) . "<br>\n";
        }

        $calMass = \Eloquent\CalMass::make([
            'church_id' => $churchId,
            'title' => $title,
            'start_date' => $startDate,
            'rrule' => $rrule,
            'exdate' => !empty($exdates) ? $exdates : null,
            'duration' => $duration,
            'rite' => 'ROMAN_CATHOLIC',  // Default rite
            'lang' => 'hu',     // Default language
            'period_id' => null,  // External calendars don't belong to periods
            'comment' => self::IMPORT_MARKER,
        ]);

        return $calMass;
    }

    /**
     * Extract EXDATE from iCalendar event
     * Returns array of ISO 8601 datetime strings
     */
    private static function extractExDates($event) {
        if (!isset($event->EXDATE)) {
            return [];
        }

        $exdates = [];
        foreach ($event->EXDATE as $exdate) {            
            try {
                $exdate = trim($exdate);

                // If format contains TZID=Zone:YYYYMMDDTHHMM[SS]
                if (preg_match('/TZID=([^:;]+):(.+)$/i', $exdate, $m)) {
                    $tzid = $m[1];
                    $dtStr = $m[2];

                    // Date-only (YYYYMMDD)
                    if (preg_match('/^\d{8}$/', $dtStr)) {
                        $exdates[] = substr($dtStr, 0, 4) . '-' . substr($dtStr, 4, 2) . '-' . substr($dtStr, 6, 2);
                        continue;
                    }

                    // If seconds missing (YYYYMMDDTHHMM), append seconds
                    if (preg_match('/^\d{8}T\d{4}$/', $dtStr)) {
                        $dtStr .= '00';
                    }

                    // Try to create DateTime in the given TZ and convert to UTC
                    $dt1 = \DateTime::createFromFormat('Ymd\THis', $dtStr, new \DateTimeZone($tzid));
                    $dt = \DateTime::createFromFormat('Ymd\THis', $dtStr);
                    if ($dt === false) {
                        // Fallback: try generic parser and take date part
                        $iso = self::parseIcsDateTime($dtStr);
                        $exdates[] = substr($iso, 0, 10);
                        continue;
                    }

                    $dt->setTimezone(new \DateTimeZone('UTC'));
                    $exdates[] = $dt->format('Y-m-d\TH:i:s');
                    $exdates[] = $dt1->format('Y-m-d\TH:i:s');
                    $exdates[] = $dt1->format('Y-m-d');
                    continue;
                }

                // No TZID present: parse normally and return date part (YYYY-MM-DD)
                $iso = self::parseIcsDateTime($exdate);
                $exdates[] = substr($iso, 0, 10);
            } catch (\Exception $e) {
                // Best-effort fallback: try generic parse and extract date, otherwise skip
                try {
                    $iso = self::parseIcsDateTime($exdate);
                    $exdates[] = substr($iso, 0, 10);
                } catch (\Exception $ignored) {
                    throw new \Exception("Failed to parse EXDATE: $exdate - " . $e->getMessage());
                }
            }
            $exdates[] = self::parseIcsDateTime($exdate);
        }
        return $exdates;
    }

    /**
     * Extract DTSTART from iCalendar event
     * Returns ISO 8601 datetime string
     */
    private static function extractStartDate($event) {
        if (!isset($event->DTSTART)) {
            throw new \Exception("DTSTART is missing from event: ".json_encode($event));
        }
        
        $dtstart = $event->DTSTART;
        // Parse iCalendar datetime format (YYYYMMDDTHHMMSS or YYYYMMDD)
        return self::parseIcsDateTime($dtstart);
    }

    /**
     * Extract DURATION or calculate from DTEND
     * Returns JSON: {"hours": int, "minutes": int}
     */
    private static function extractDuration($event) {
        try {
            // Try DURATION field first
            if (isset($event->DURATION)) {
                return self::parseDurationString($event->DURATION);
            }
            
            // Try DTSTART and DTEND
            if (isset($event->DTSTART) && isset($event->DTEND)) {
                $start = self::parseIcsDateTime($event->DTSTART);
                $end = self::parseIcsDateTime($event->DTEND);
                
                $startDt = new \DateTime($start);
                $endDt = new \DateTime($end);
                $diff = $endDt->diff($startDt);
                
                $hours = $diff->h + ($diff->days * 24);
                $minutes = $diff->i;
                return ['hours' => $hours, 'minutes' => $minutes];
            }
            
            // Default: 1 hour
            return ['hours' => 1, 'minutes' => 0];
        } catch (\Exception $e) {
            // On any duration parsing error, default to 1 hour
            return ['hours' => 1, 'minutes' => 0];
        }
    }

    /**
     * Extract RRULE from iCalendar event
     * Returns JSON: {"rule": "FREQ=WEEKLY;BYDAY=SU,MO;..."}
     */
    private static function extractRRule($event) {
        if (!isset($event->RRULE)) {
            return null;
        }
        
        try {
            $ruleArray = [];
            $rrule = (string)$event->RRULE;
            $rules = explode(';', $rrule);
            foreach ($rules as $rule) {
                [$key, $value] = explode('=', $rule);
                if ($value) {
                    if($key == 'FREQ') $value = strtolower($value);
                    
                    if($key == 'BYDAY' AND preg_match('/^(SU|MO|TU|WE|TH|FR|SA)(,(SU|MO|TU|WE|TH|FR|SA))*$/', $value)) {
                        // Convert BYDAY=SU,MO to ["SU", "MO"]                        
                        $ruleArray["byweekday"] = explode(',', $value);
                    }

                    else if($key === 'BYDAY' AND preg_match('/^(1|2|3|4|5|-1)(SU|MO|TU|WE|TH|FR|SA)*$/', $value, $match)) {
                        $ruleArray["bysetpos"] = (int)$match[1];
                        $ruleArray["byweekday"] = $match[2];                        
                    }
                    else {
                        
                        if($key == "BYDAY" OR $key == "BYMONTHDAY") {
                        throw new \Exception("Unsupported RRULE parameter: $key - skipping RRULE: ". $rrule);
                        }

                        $ruleArray[strtolower($key)] = $value;                
                    }
                }
            }
            if(!empty($ruleArray)) {
                $ruleArray['dtstart'] = self::parseIcsDateTime($event->DTSTART);  // Include DTSTART for reference
            }

            // Convert dtstart and until FROM 20220913T183000 or 20220913T183000 TO 2026-12-23T23:59:00            
            if(isset($ruleArray['until'])) {
                $ruleArray['until'] = self::parseIcsDateTime($ruleArray['until']);
            }
            return $ruleArray;
        } catch (\Exception $e) {
            echo "  ⚠ Failed to parse RRULE: " . $e->getMessage() . "<br>\n";
            return null;
        }
    }

    /**
     * Szétválasztja egy iCalendar tulajdonság paramétereit az értékétől (RFC 5545 3.2).
     *
     * A hívók a `DTSTART`/`DTEND`/`EXDATE` sorok jobb oldalát adják át, ami bármennyi
     * paramétert hordozhat, tetszőleges sorrendben:
     *   TZID=Europe/Budapest:20221201T060000
     *   VALUE=DATE:20260326
     *   VALUE=DATE;TZID=Europe/Budapest:20260326
     * A parser eddig csak a TZID-t ismerte fel, minden más paramétert az értékbe
     * számolt bele — egyetlen egész napos esemény (`VALUE=DATE`) az egész naptár
     * importját megbuktatta.
     *
     * Csak akkor eszik paramétert, ha a sor tényleg `NEV=` alakkal kezdődik, így a
     * paraméter nélküli értékek (`20221201T060000`, `2026-12-23T23:59:00`) érintetlenek
     * maradnak — utóbbiban a `:` az időhöz tartozik, nem elválasztó.
     *
     * @return array{0: array<string,string>, 1: string} [paraméterek nagybetűs kulccsal, érték]
     */
    private static function splitIcsParameters($raw) {
        $params = [];
        $rest = trim((string) $raw);

        while (preg_match('/^([A-Za-z0-9-]+)=("[^"]*"|[^";:]*)([;:])(.*)$/s', $rest, $m)) {
            $params[strtoupper($m[1])] = trim($m[2], '"');
            $rest = $m[4];
            if ($m[3] === ':') {
                break;
            }
        }

        return [$params, trim($rest)];
    }

    /**
     * Parse iCalendar datetime string with support for property parameters
     * Handles formats like:
     * - TZID=Europe/Budapest:20221201T060000
     * - VALUE=DATE:20260326
     * - 20221201T060000
     * - 20221201T060000Z
     * - YYYY-MM-DDTHH:MM:SS
     *
     * Returns ISO 8601 datetime string in UTC (Y-m-d\TH:i:s format)
     */
    private static function parseIcsDateTime($dateStr) {
        $dateStr = trim($dateStr);

        [$params, $dtString] = self::splitIcsParameters($dateStr);
        $tzid = $params['TZID'] ?? null;

        // Handle date-only format (YYYYMMDD)
        if (strlen($dtString) == 8 && ctype_digit($dtString)) {
            $year = substr($dtString, 0, 4);
            $month = substr($dtString, 4, 2);
            $day = substr($dtString, 6, 2);
            
            // Create Carbon instance in the specified timezone or default to UTC
            try {
                if ($tzid) {
                    $carbon = \Carbon\Carbon::createFromFormat('Y-m-d', "$year-$month-$day", $tzid);
                    return $carbon->setTimezone('Europe/Budapest')->format('Y-m-d\TH:i:s');
                } else {
                    return "$year-$month-$day" . "T00:00:00";
                }
            } catch (\Exception $e) {
                return "$year-$month-$day" . "T00:00:00";
            }
        }
        
        // Handle datetime format (YYYYMMDDTHHMMSS or YYYYMMDDTHHMMSSZ)
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z)?$/', $dtString, $matches)) {
            $year = $matches[1];
            $month = $matches[2];
            $day = $matches[3];
            $hour = $matches[4];
            $minute = $matches[5];
            $second = $matches[6];
            
            try {
                // Create Carbon instance with the datetime string
                $dateTimeStr = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
                
                if ($tzid) {
                    // Create in the specified timezone and convert to UTC
                    $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $dateTimeStr, $tzid);
                    return $carbon->setTimezone('Europe/Budapest')->format('Y-m-d\TH:i:s');
                } elseif (!empty($matches[7])) {
                    $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $dateTimeStr, 'UTC');
                    return $carbon->setTimezone('Europe/Budapest')->format('Y-m-d\TH:i:s');
                } else {
                    // Floating local time: keep the wall-clock value in the application timezone.
                    $carbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $dateTimeStr, 'Europe/Budapest');
                    return $carbon->format('Y-m-d\TH:i:s');
                }
            } catch (\Exception $e) {
                // Fallback: return formatted without timezone conversion
                return "$year-$month-$day" . "T$hour:$minute:$second";
            }
        }
        
        // If already in ISO 8601 format (YYYY-MM-DDTHH:MM:SS, optionally Z or ±HH:MM), parse and return in UTC
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+\-]\d{2}:\d{2})?$/', $dtString)) {
            try {
                $carbon = \Carbon\Carbon::parse($dtString);
                return $carbon->setTimezone('Europe/Budapest')->format('Y-m-d\TH:i:s');
            } catch (\Exception $e) {
                return $dtString;
            }
        }
        
        // Fallback: try to parse with Carbon
        try {
            if ($tzid) {
                $carbon = \Carbon\Carbon::parse($dtString, $tzid);
                return $carbon->setTimezone('Europe/Budapest')->format('Y-m-d\TH:i:s');
            } else {
                $carbon = \Carbon\Carbon::parse($dtString);
                return $carbon->setTimezone('Europe/Budapest')->format('Y-m-d\TH:i:s');
            }
        } catch (\Exception $e) {
            throw new \Exception("Unable to parse datetime: $dateStr - " . $e->getMessage());
        }
    }

    /**
     * Parse iCalendar duration string (e.g., "PT1H30M" means 1 hour 30 minutes)
     * Returns JSON: {"hours": int, "minutes": int}
     */
    private static function parseDurationString($durationStr) {
        $durationStr = trim($durationStr);
        
        // Initialize hours and minutes
        $hours = 0;
        $minutes = 0;
        
        // Parse ISO 8601 duration format: P[n]D[T[n]H[n]M[n]S]
        // Examples: PT1H, PT30M, PT1H30M, P1DT2H
        
        // Extract time part (after T)
        if (preg_match('/T(.+)/', $durationStr, $timeMatch)) {
            $timePart = $timeMatch[1];
            
            // Extract hours
            if (preg_match('/(\d+)H/', $timePart, $hMatch)) {
                $hours = (int)$hMatch[1];
            }
            
            // Extract minutes
            if (preg_match('/(\d+)M/', $timePart, $mMatch)) {
                $minutes = (int)$mMatch[1];
            }
        }
        
        // Extract days part (before T) and add to hours
        if (preg_match('/P(\d+)D/', $durationStr, $dMatch)) {
            $days = (int)$dMatch[1];
            $hours += $days * 24;
        }
        
        return ['hours' => $hours, 'minutes' => $minutes];
    }
}
