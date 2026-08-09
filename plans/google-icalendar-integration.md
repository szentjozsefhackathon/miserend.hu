# 🎯 Google iCalendar Integráció - Implementációs Terv

**Verzió:** 1.0  
**Dátum:** 2026-03-08  
**Státusz:** Tervezés befejezve

---

## 📋 Áttekintés

Ezt a dokumentumot az alábbi követelmények alapján készítettük:
- Külső Google naptárak (iCalendar) adatainak behúzása a saját rendszerbe
- Az importált események keresésben való megjelenítése
- Read-only nézet az Angular naptárban (szerkesztés letiltása)
- Napi automata frissítés cron job-on keresztül

---

## 🏗️ Architektúra Áttekintés

```
Google Calendar (iCalendar)
         |
         v
ExternalCalendarImporter (PHP)
         |
    +----+----+
    |         |
    v         v
CalMass    Church
    |         |
    +----+----+
         v
  Elasticsearch
         |
         v
Angular UI (Read-only)
```

---

## 📊 Adatbázis Séma

### Új tábla: `external_calendars`

**Fájl:** `docker/mysql/initdb.d/03-external-calendars.sql`

```sql
CREATE TABLE external_calendars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    church_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    url VARCHAR(2048) NOT NULL,
    active BOOLEAN DEFAULT 1,
    last_import_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (church_id) REFERENCES templomok(id) ON DELETE CASCADE,
    UNIQUE KEY unique_church_external (church_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_hungarian_ci;
```

**Oszlopok:**
- `id`: Egyedi azonosító
- `church_id`: Templom azonosítója (FK → templomok.id)
- `name`: Naptár neve (pl. "Google Calendar", "Ünnepek")
- `url`: Az iCalendar ICS URL
- `active`: Aktív/inaktív flag (cron csak az aktívakat dolgozza fel)
- `last_import_at`: Utolsó sikeres import dátuma
- `created_at`, `updated_at`: Metaadatok

**Constraints:**
- `UNIQUE KEY (church_id, name)`: Egy templom nem vezethet azonos nevű naptárakat
- `FOREIGN KEY`: Templom törléskor a naptár is törlődik

---

### Mintaadatok

#### A) External Calendar Minta

**Fájl:** `docker/mysql/initdb.d/data/external-calendars-sample.sql`

(Megjegyzés: Az **initdb.d** mappában kell lennie, hogy az adatbázis inicializálásakor automatikusan lefusson)

```sql
INSERT INTO external_calendars (church_id, name, url, active, created_at) VALUES
(1254, 'Google Calendar', 'https://calendar.google.com/calendar/ical/c_qssbhpdrcj135o533mvm8d2ch4%40group.calendar.google.com/public/basic.ics', 1, NOW());
```

**Adat:**
- **Church ID:** 1254
- **Naptár név:** "Google Calendar"
- **URL:** Public Google Calendar iCalendar endpoint

#### B) Cron Job Bejegyzés

**Módosítandó fájl:** `docker/mysql/initdb.d/data/crons.sql`

A `crons` tábla INSERT utolsó sorában hozzáadni az alábbi bejegyzést (id=40):

```sql
(40,'\\ExternalCalendarImporter','importAllExternalCalendars','1 day',NULL,NULL,'0000-00-00 00:00:00',0,'0000-00-00 00:00:00','0000-00-00 00:00:00','0000-00-00 00:00:00')
```

**Teljes módosítás:**
```sql
INSERT INTO `crons` VALUES
...
(39,'\\ExternalApi\\ElasticsearchApi','updateMasses','6 hours',NULL,NULL,'2026-01-26 23:58:39',0,'2026-01-26 17:58:39','0000-00-00 00:00:00','2026-01-26 17:58:39'),
(40,'\\ExternalCalendarImporter','importAllExternalCalendars','1 day',NULL,NULL,'0000-00-00 00:00:00',0,'0000-00-00 00:00:00','0000-00-00 00:00:00','0000-00-00 00:00:00');
```

**Mezők:**
- `id`: 40
- `class`: `\\ExternalCalendarImporter`
- `function`: `importAllExternalCalendars`
- `frequency`: `1 day`
- `from`, `until`: `NULL` (nincs időintervallum)
- Egyéb timestamp mezők: `0000-00-00 00:00:00` vagy `NULL`

---

## 🔧 Backend Komponensek

### 1. ExternalCalendar Eloquent Model

**Fájl:** `webapp/classes/eloquent/externalcalendar.php`

```php
<?php
namespace Eloquent;

class ExternalCalendar extends \Illuminate\Database\Eloquent\Model {
    protected $table = 'external_calendars';
    protected $fillable = ['church_id', 'name', 'url', 'active', 'last_import_at'];
    protected $dates = ['last_import_at', 'created_at', 'updated_at'];

    public function church() {
        return $this->belongsTo(Church::class, 'church_id');
    }
}
```

**Kapcsolatok:**
- `church()`: BelongsTo relationship Church modellel

---

### 2. ExternalCalendarImporter Osztály

**Fájl:** `webapp/classes/externalcalendarimporter.php`

**Namespace:** Nincsen explicit namespace (a projekt gyökerében, vagy `Html\Calendar` alatt - ellenőrizd a projekt konvencióit)

**Felelősségek:**
- iCalendar URL-ből tartalom letöltése
- VEVENT elemek feldolgozása
- CalMass objektumok létrehozása
- Régi CalMass objektumok törlése
- CalMass-ok mentése az adatbázisba
- Elasticsearch index frissítése (`updateMasses()`)

**Függőségek:**
- `sabre/vobject` (Composer library iCalendar parse-oláshoz)
- `ExternalApi\ExternalApi` (HTTP lekérésekhez)
- `Eloquent\CalMass` (Mise modell)
- `ExternalApi\ElasticsearchApi` (Keresési index frissítéshez)

**Főbb metódusok:**

#### `public static function importAllExternalCalendars()`
Cron job belépési pont. Feldolgozza az összes aktív external calendar-t.

```php
// Pseudocode
foreach (ExternalCalendar::where('active', 1)->get() as $extCal) {
    try {
        self::importFromUrl($extCal->url, $extCal->church_id);
        $extCal->last_import_at = now();
        $extCal->save();
    } catch (Exception $e) {
        // Log error
    }
}
```

#### `private static function importFromUrl($url, $churchId)`
Letöltés és feldolgozás egy naptárhoz.

```php
// Flow
1. ICS tartalom letöltése URL-ből (HTTP GET via ExternalApi)
2. Jelenlegi templom összes external CalMass törlése (WHERE church_id = ? AND period_id IS NULL)
3. iCalendar feldolgozása (parseICalendar - VEVENT-ek kiolvasása)
4. CalMass objektumok létrehozása
5. Elasticsearch frissítés: ElasticsearchApi::updateMasses($years, [$churchId])
6. last_import_at timestamp frissítése az external_calendars táblában
```

#### `private static function parseICalendar($icsContent)`
iCalendar feldolgozása `sabre/vobject` lib-vel.

```php
// Pseudocode
$vCal = Reader::read($icsContent);
foreach ($vCal->VEVENT as $event) {
    $calMass = new CalMass([
        'church_id' => $churchId,
        'title' => (string)$event->SUMMARY,
        'start_date' => (string)$event->DTSTART->getDateTime(),
        'duration' => self::extractDuration($event),
        'rrule' => self::extractRRule($event),
        'rite' => 'latin',
        'lang' => 'hu',
        'period_id' => null,  // External cal-ek nem periódushoz kötöttek
        'comment' => 'External calendar import'
    ]);
    $calMass->save();
}
```

#### `private static function extractRRule($event)`
RRULE szó szerinti megtartása JSON formátumban.

```php
if (!isset($event->RRULE)) {
    return null;
}

$rrule = (string)$event->RRULE;
// Pl. "FREQ=WEEKLY;BYDAY=SU,MO;UNTIL=20261231T235959Z"
return json_encode(['rule' => $rrule]);
```

#### `private static function extractDuration($event)`
DURATION vagy DTEND alapján.

```php
if (isset($event->DURATION)) {
    return json_encode(['duration' => (string)$event->DURATION]);
}
if (isset($event->DTEND)) {
    $start = $event->DTSTART->getDateTime();
    $end = $event->DTEND->getDateTime();
    $diff = $end->diff($start);
    return json_encode([
        'hours' => $diff->h,
        'minutes' => $diff->i
    ]);
}
return json_encode(['hours' => 1, 'minutes' => 0]);  // Default: 1 óra
```

---

### 3. Church Model Módosítások

**Fájl:** `webapp/classes/eloquent/church.php`

**Relationship hozzáadása:**
```php
public function externalCalendars() {
    return $this->hasMany(ExternalCalendar::class, 'church_id');
}
```

**Computed property hozzáadása:**
```php
public function getHasExternalCalendarAttribute() {
    return $this->externalCalendars()->where('active', 1)->exists();
}
```

**WriteAccess Accessor módosítása:**

Az `getWriteAccessAttribute()` metódus-ban (vagy a `church.php` logikájában) hozzáadni az external calendar ellenőrzést:

```php
public function getWriteAccessAttribute() {
    // ... meglévő logika (jogosultság check) ...
    
    // NEW: Ha external calendar van, akkor read-only
    if ($this->hasExternalCalendar) {
        return false;
    }
    
    return $canWrite;
}
```

**Logika:** Ha a templomnak van aktív external calendar-ja, akkor senki sem szerkesztheti a naptárat, függetlenül a jogosultságoktól.

---

### 4. Composer Dependency

**Fájl:** `webapp/composer.json`

Hozzáadás a `require` szekciójához:
```json
"sabre/vobject": "^4.5"
```

**Futtatás:**
```bash
cd webapp
composer require sabre/vobject
```

---

## ⏰ Cron Job Integráció

### Regisztrálás

**Fájl módosítás:** `webapp/classes/eloquent/cron.php`

A `initialize()` metódusban az alábbi sor hozzáadása:

```php
['\ExternalCalendarImporter', 'importAllExternalCalendars', '1 day']
```

**Teljes bejegyzés:**
```php
$jobsToSave = [
    ['\Eloquent\Cron', 'initialize', '1 week'],
    ['\Message', 'clean', '1 hour'],
    // ... más jobsok ...
    ['\ExternalCalendarImporter', 'importAllExternalCalendars', '1 day'],  // NEW
];
```

### Cron Logika

**Flow:**
```
1. Összes external_calendars lekérése WHERE active = 1
2. Mindegyik naptárhoz: iCalendar import futtatása - importFromUrl 
    Ez tartalmazza már a parsert, a calmass created, törlést, elasticsearch frissítést is. Stb.
3. Log: Sikeres/sikertelen importok naplózása
```

**Pszeudokód:**
```php
public static function importAllExternalCalendars() {
    set_time_limit(600);
    
    $calendars = ExternalCalendar::where('active', 1)->get();
    
    foreach ($calendars as $calendar) {
        try {           
            self::importFromUrl($calendar->url, $calendar->church_id);            
            echo "✓ Import sikeres: Church #{$calendar->church_id} ({$calendar->name})\n";
        } catch (Exception $e) {
            echo "✗ Import hiba: {$e->getMessage()}\n";
        }
    }
}
```

---

## 🌐 API Integráció

### Church Detail Endpoint

**Módosítandó fájl:** `webapp/classes/html/ajax/calendar/church.php`

A Church API response-ba hozzáadása (GET metódus, 37-46. sor körül):

```php
case 'GET':
    $this->church->append(['hasExternalCalendar']);  // NEW
    
    $response = [
        'id' => $this->tid,
        'name' => $this->church->nev,
        'rite' => strtoupper($this->church->denomination),
        'timeZone' => 'Europe/Budapest',
        'hasExternalCalendar' => $this->church->hasExternalCalendar,  // NEW
        'masses' => $this->getByChurchId($this->tid)
    ];
    echo json_encode($response);
    break;
```

**API response minta:**
```json
{
    "id": 1254,
    "name": "Nagyvárad Székesegyháza",
    "rite": "LATIN",
    "timeZone": "Europe/Budapest",
    "hasExternalCalendar": true,
    "masses": [
        {
            "id": 1,
            "church_id": 1254,
            "title": "Vasárnapi mise",
            "start_date": "2026-03-15 10:00:00",
            ...
        }
    ]
}
```

**Használat az Angular-ban:**
Az `hasExternalCalendar` flag alapján az Angular komponens tudni fogja, hogy le kell-e tiltani a szerkesztést:
```typescript
if (this.churchData.hasExternalCalendar) {
    this.editable = false;
    // rejtés: szerkesztés/törlés gombok
}
```

### CalMass List Endpoint

**Módosítandó fájl:** `webapp/classes/html/ajax/calendar/masses.php`

A `getByChurchId()` metódus output-ja már tartalmazza a CalMass objektumokat, így az RRULE és egyéb mezők már benne vannak.

### Backend Védelem: Mise-Módosítás Végpontok

**Fájlok:** `webapp/classes/html/ajax/calendar/masses.php` és `webapp/classes/html/ajax/calendar/suggestions.php`

**Probléma:** Az Angular frontend levédi a szerkesztés lehetőségét, de a backend-nek is védekezni kell a direct API hívások ellen.

**Megoldás:** Minden POST/PUT/DELETE végponton (masse módosítás, javaslatok kezelése) az `writeAccess` check mellett hozzáadni egy ellenőrzést az `hasExternalCalendar` flag-hez.

#### masses.php - POST metódus (57. sor körül)

```php
case 'POST':
    $this->church->append(['writeAccess']);
    
    // NEW: External calendar check
    if (!$this->church->writeAccess || $this->church->hasExternalCalendar) {
        $this->sendJsonError('Hiányzó jogosultság! Ez a templom külső naptárra van csatlakoztatva.', 403);
        exit;
    }
    
    // ... meglévő POST logika ...
```

#### suggestions.php - GET metódus (52-62. sor)

```php
case 'GET':
    if ($this->modify) {
        $this->sendJsonError('Method not allowed', 405);
        exit;
    }
    $this->church->append(['writeAccess']);
    
    // NEW: External calendar check
    if (!$this->church->writeAccess || $this->church->hasExternalCalendar) {
        $this->sendJsonError('Hiányzó jogosultság! Ez a templom külső naptárra van csatlakoztatva.', 403);
        exit;
    }
    
    // ... meglévő GET logika ...
```

#### suggestions.php - POST metódus (81-91. sor)

```php
case 'POST':
    if ($this->modify) {
        // handleModifiedPost: modify accept/reject operáció
        // Erre is vonatkozik a check, ha az egyik endif-ből jön
        $input = json_decode(file_get_contents('php://input'), true);
        
        // NEW: Get church for external calendar check
        $modifyChurch = \Eloquent\Church::find($path[1]);
        if ($modifyChurch && $modifyChurch->hasExternalCalendar) {
            $this->sendJsonError('Ez a templom külső naptárra van csatlakoztatva, módosítás nem lehetséges.', 403);
            exit;
        }
        
        $this->handleModifiedPost($path[0], $path[1], $input);
    } else {
        // NEW: handleNewSuggestionPackage - check external calendar
        $this->church->append(['hasExternalCalendar']);
        if ($this->church->hasExternalCalendar) {
            $this->sendJsonError('Ez a templom külső naptárra van csatlakoztatva, módosítás nem lehetséges.', 403);
            exit;
        }
        
        $this->handleNewSuggestionPackage();
    }
    exit();
```

**Logika:**
- `writeAccess`: A felhasználónak van-e joga szerkeszteni (jogosultság)
- `hasExternalCalendar`: A templomnak van-e külső Google Calendar naptára

Ha **bármelyik** false → hibát dobunk 403-as válaszkóddal.

### Frontend Védelem Redundancia

Az Angular UI már levédi az edit/delete gombokat, de a backend sanity check-ek garantálják, hogy még ha a frontend "kikerülnék" (pl. hackelés, fejlesztői konzol), az adatok védelme továbbra is érvényes.

---

## 🖊️ Church/Edit Oldal Integráció

### Templomadat szerkesztési oldal módosítások

**Korlátozás:** Egy templomhoz maximum **1 külső naptár** kerülhet.

#### A) PHP Backend: `webapp/classes/html/church/edit.php`

**1. Form elem hozzáadása `preparePage()` metódusban:**
```php
// Az external calendar URL mező
$this->form['external_calendar_url'] = [
    'type' => 'text',
    'name' => 'church[external_calendar_url]',
    'id' => 'external_calendar_url',
    'class' => 'form-control',
    'placeholder' => 'https://calendar.google.com/calendar/ical/...',
    'value' => $this->getExternalCalendarUrl(),
    'labelback' => 'Külső naptár (iCalendar ICS URL) - maximum 1'
];
```

**2. Segéd metódus az aktuális URL lekéréséhez:**
```php
private function getExternalCalendarUrl() {
    $externalCal = \Eloquent\ExternalCalendar::where('church_id', $this->tid)
        ->where('active', 1)
        ->first();
    return $externalCal ? $externalCal->url : '';
}
```

**3. Modify metódus módosítása:**
```php
function modify() {
    // ... meglévő kód ...
    
    // External calendar URL kezelés
    if (isset($this->input['church']['external_calendar_url'])) {
        $newUrl = trim($this->input['church']['external_calendar_url']);
        
        if (!empty($newUrl)) {
            // URL validáció
            if (!filter_var($newUrl, FILTER_VALIDATE_URL)) {
                throw new \Exception('Érvénytelen URL formátum!');
            }
            
            // Meglévő naptár frissítése vagy új létrehozása
            $externalCal = \Eloquent\ExternalCalendar::where('church_id', $this->tid)
                ->where('active', 1)
                ->first();
            
            if ($externalCal) {
                $externalCal->url = $newUrl;
                $externalCal->save();
            } else {
                \Eloquent\ExternalCalendar::create([
                    'church_id' => $this->tid,
                    'name' => 'Google Calendar',  // Default név
                    'url' => $newUrl,
                    'active' => 1
                ]);
            }
        } else {
            // URL törléskor inaktiválás (nem törlés!)
            \Eloquent\ExternalCalendar::where('church_id', $this->tid)
                ->update(['active' => 0]);
        }
    }
    
    // ... meglévő Church mentés ...
    $this->church->save();
}
```

#### B) Twig Template: `webapp/templates/church/edit.twig`

**Form elem hozzáadása az editform-ban:**
```twig
<tr>
    <td bgcolor=#F5CC4C class=kiscim align=right>Külső naptár (Google Calendar):</td>
    <td bgcolor=#F5CC4C>
        {{ forms.text(form.external_calendar_url) }}
        <small style="display: block; margin-top: 5px; color: #666;">
            Egy templomhoz maximum 1 Google Calendar iCalendar URL adható meg.
            Az URL-t a Google Calendar megosztás beállításaiból másolhatod.
            <br/>
            Ha URL van beállítva, a naptár read-only lesz (szerkesztés nem lehetséges).
        </small>
    </td>
    <td></td>
</tr>
```

---

## 📊 Adatbázis Séma - Módosítások

### External Calendar tábla korlátozások

Az `external_calendars` tábla egyediségének garantálása:
```sql
UNIQUE KEY unique_church_external (church_id, name)
```

---

## 🎨 Frontend Integráció (Angular)

### church-calendar komponens módosítások

**Fájl:** `calendar/src/app/components/church-calendar/church-calendar.component.ts`

**Meglévő property:**
```typescript
@Input() editable: boolean = false;
```

**Módosítás 1: API response feldolgozása**
```typescript
ngOnInit() {
    // ... meglévő kód ...
    
    // hasExternalCalendar flag alapján editable beállítása
    if (this.currentChurch?.hasExternalCalendar) {
        this.editable = false;
    }
}
```

**Módosítás 2: Event Handler Disabling**

Az `editable` property már kontrollál:
- Dátumkattintásokat (új esemény hozzáadás)
- Edit/delete gombokat az Event Viewer Dialog-ban

Ezek a logikák már a meglévő kódban vannak, csak az `editable` flag-et kell `false`-ra állítani.

---

## ✅ Tesztelés

### Tesztelési Checklist

- [ ] MySQL tábla `external_calendars` létrehozve
- [ ] Mintaadatok beillesztve (church_id 1254 + URL)
- [ ] Cron tábla módosítva (id=40 bejegyzés)
- [ ] Composer lib telepítve (`sabre/vobject`)
- [ ] ExternalCalendar Eloquent model működik
- [ ] ExternalCalendarImporter osztály működik
- [ ] Church model `externalCalendars()` relationship működik
- [ ] Church model `hasExternalCalendar` property működik
- [ ] Church model `writeAccess` `false`-ra vált external calendar-nál
- [ ] Cron job regisztrálva (initialize-ben hozzáadva)
- [ ] Church/edit oldal form mező megjenik és működik
- [ ] API response `/calendar/church/1254` tartalmazza `hasExternalCalendar` flag-et
- [ ] Backend védelem: `/calendar/masses` POST 403-at ad external calendar-nál
- [ ] Backend védelem: `/calendar/suggestions` GET/POST 403-at ad external calendar-nál
- [ ] Angular UI read-only (edit/delete gombok rejtve)
- [ ] Import után CalMass-ok Elasticsearch-be indexelve
- [ ] Keresésben (`/search`) megjelenik az importált esemény
- [ ] Napi cron job manuálisan lefuttatható és működik

### Manuális Tesztelés

**1. Adatbázis validáció:**
```sql
SELECT * FROM external_calendars WHERE church_id = 1254;
SELECT COUNT(*) FROM cal_masses WHERE church_id = 1254 AND period_id IS NULL;
```

**2. Import futtatása:**
```php
$importer = new \ExternalCalendarImporter();
$importer->importAllExternalCalendars();
```

**3. Elasticsearch validáció:**
```bash
curl -X GET "localhost:9200/mass/_search?q=church_id:1254"
```

**4. Angular UI tesztelés:**
- Nyiss meg egy tempomot external calendar-ral
- Ellenőrizd, hogy az edit/delete gombok nincsenek megjelenítve
- Ellenőrizd, hogy az importált események meg vannak jelenítve az Angular naptárban

---

## 📝 Implementációs Sorrend

1. **Adatbázis séma**
   - `docker/mysql/initdb.d/03-external-calendars.sql` létrehozása
   - `docker/mysql/initdb.d/data/external-calendars-sample.sql` létrehozása
   - `docker/mysql/initdb.d/data/crons.sql` módosítása (id=40 bejegyzés)

2. **Composer dependency**
   - `composer require sabre/vobject` futtatása

3. **PHP Backend**
   - `webapp/classes/eloquent/externalcalendar.php` - ExternalCalendar model
   - `webapp/classes/externalcalendarimporter.php` - Importer osztály
   - `webapp/classes/eloquent/church.php` módosítása:
     - `externalCalendars()` relationship
     - `getHasExternalCalendarAttribute()` property
     - `getWriteAccessAttribute()` módosítása

4. **Cron Job Regisztrálás**
   - `webapp/classes/eloquent/cron.php` módosítása (initialize metódus)

5. **API Végpontok**
   - `webapp/classes/html/ajax/calendar/church.php` módosítása (GET response)
   - `webapp/classes/html/ajax/calendar/masses.php` módosítása (POST védelmi check)
   - `webapp/classes/html/ajax/calendar/suggestions.php` módosítása (GET/POST védelmi check)

6. **Church/Edit Form Integráció**
   - `webapp/classes/html/church/edit.php` módosítása (form elem + modify logika)
   - `webapp/templates/church/edit.twig` módosítása (form render)

7. **Angular Frontend (ha szükséges)**
   - `calendar/src/app/components/church-calendar/church-calendar.component.ts` tesztelése
   - (Az `editable` property már kezelni fogja a `hasExternalCalendar` flag-et az API-ból)

8. **Tesztelés & Validáció**
   - MySQL tábla & mintaadatok
   - Composer dependency telepítés
   - ExternalCalendarImporter manuális futtatása
   - Cron job napi futtatása validálása
   - Keresés integráció tesztelése
   - Angular UI read-only mód tesztelése
   - Backend védelem API hívásokon (masses, suggestions)

---

## 🔗 Referenciák

- **iCalendar RFC 5545:** https://tools.ietf.org/html/rfc5545
- **sabre/vobject dokumentáció:** https://sabre.io/vobject/
- **Google Calendar iCalendar formátum:** https://support.google.com/calendar/answer/37648
- **Eloquent dokumentáció:** https://laravel.com/docs/eloquent

---

## 🐛 Hibakezelés

### Lehetséges hibák és megoldások

**1. iCalendar parse hiba**
```php
try {
    $vCal = Reader::read($icsContent);
} catch (ParseException $e) {
    \Log::error("iCalendar parse failed: " . $e->getMessage());
    throw new \Exception("Invalid iCalendar format: " . $url);
}
```

**2. HTTP letöltés sikertelen**
```php
$api = new \ExternalApi\ExternalApi();
$api->query = $url;
$api->run();
if (!$api->rawData) {
    throw new \Exception("Failed to download iCalendar from: " . $url);
}
```

**3. CalMass duplikáció**
Megoldás: Templomhoz tartozó jelenlegi CalMass-okat törlés előtt.

---

## 📌 Megjegyzések

- Az external calendar-ból importált misék a keresésben azonnal meg fognak jelenni az `updateMasses()` után
- RRULE-t szó szerint megtartjuk, az Angular UI megfelelően kezelheti
- Az external calendar napi frissítés a cron rendszeren keresztül működik
- Temple holder ezt a naptárat nem szerkesztheti, csak olvashatja

---

**Készítette:** Architect Mode  
**Státusz:** Ready for Code Mode Implementation ✅
