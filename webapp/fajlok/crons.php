<?php

/*
 * #638: A rendszer által IGÉNYELT cron-munkák egyetlen listája.
 *
 * Eddig két helyen élt ez a tudás: a MySQL seed-ben (docker/mysql/initdb.d/data/crons.sql)
 * és — régebben — egy kódbeli cron::init()-ben. Emiatt új cron-függvénynél kézzel kellett
 * INSERT INTO-t nyomni az éles adatbázisba, különben soha nem futott le.
 *
 * Mostantól EZ a lista a forrás. A \Eloquent\Cron::init() (lásd /index.php?q=cron&cron_init=1)
 * a hiányzó sorokat felveszi, a MEGLÉVŐKET NEM BÁNTJA — így a lastsuccess_at / attempts
 * előzmény és a kézzel hangolt frequency megmarad. A seed-dump ezek után csak a fejlesztői
 * kezdőállapot (időbélyegekkel), nem definíció.
 *
 * Egy bejegyzés:
 *   class     — a futtatandó osztály teljes neve, vezető backslash-sel (ahogy a DB-ben)
 *   function  — a példányon hívandó metódus
 *   frequency — strtotime-kompatibilis gyakoriság ('1 day', '15 min', ...)
 *   from/until— opcionális napi időablak (strtotime, pl. '1am' / '6am'); null = bármikor
 */

return [
    ['class' => '\Api\Sqlite',                    'function' => 'cron',                       'frequency' => '1 day'],
    /*
     * #791: az EGY-API-s takarítás helyett MINDEN külső API cache-ét takarítjuk.
     * borazslo: "a ExternalApi::clearAllOldCache() tényleg jó ötlet bekötni [...]
     * És akkor nem is kell a másik clearOldCache". A régi Overpass-sort a
     * Cron::pruneRemoved() takarítja ki, mert kikerült ebből a listából.
     */
    ['class' => '\ExternalApi\ExternalApi',       'function' => 'clearAllOldCache',           'frequency' => '1 day'],
    ['class' => '\Distance',                      'function' => 'updateSome',                 'frequency' => '15 min'],
    ['class' => '\OSM',                           'function' => 'syncUrlMiserendFromOSM',     'frequency' => '1 day',      'from' => '1am', 'until' => '6am'],
    /*
     * #842: HÁROM ÓRA, nem öt perc.
     *
     * A registry '5 min'-t mondott, az éles adatbázisban viszont 3 óra áll — az
     * `ensureRegistered()` ugyanis a meglévő sort nem írja felül, tehát a kettő évek óta
     * szétcsúszva élt. Egy friss telepítés viszont az itteni értéket kapná: 288 futás
     * naponta, kötegenként 50 templommal, ami napi 14 400 Overpass-hívás lenne.
     * A valósághoz igazítom, hogy ne legyen benne meglepetés.
     */
    ['class' => '\OSM',                           'function' => 'checkBoundaries',            'frequency' => '3 hours'],
    /*
     * #842: egyszeri, de idempotens visszatöltés — annak ad bélyeget, aminek a határa
     * már megvan. Havonta fut, mert a `requeueChurchesWithoutBoundary()` NULL-ozhat
     * sorokat, és mert egy friss telepítésen is le kell futnia.
     */
    ['class' => '\Crons',                         'function' => 'backfillBoundaryCheckedAt',  'frequency' => '1 month'],
    ['class' => '\Token',                         'function' => 'cleanOut',                   'frequency' => '2 hours'],
    ['class' => '\Message',                       'function' => 'clean',                      'frequency' => '1 hour'],
    ['class' => '\Photos',                        'function' => 'cron',                       'frequency' => '1 week'],
    ['class' => '\User',                          'function' => 'sendActivationNotification', 'frequency' => '20 minutes', 'from' => '1am', 'until' => '6am'],
    ['class' => '\User',                          'function' => 'sendInactivityNotification', 'frequency' => '20 minutes', 'from' => '1am', 'until' => '6am'],
    ['class' => '\User',                          'function' => 'sendUpdateNotification',     'frequency' => '20 minutes', 'from' => '1am', 'until' => '6am'],
    ['class' => '\User',                          'function' => 'deleteNonActivatedUsers',    'frequency' => '20 minutes', 'from' => '1am', 'until' => '6am'],
    ['class' => '\User',                          'function' => 'sendHolidayReminder',        'frequency' => '1 day',      'from' => '1am', 'until' => '6am'],
    /*
     * #568: búcsú-emlékeztető a gondnokoknak, 21 nappal a várható dátum előtt.
     * NAPI futás: a cron azt nézi, mely templom búcsúja esik pontosan 21 nap múlvára.
     */
    ['class' => '\Eloquent\Church',               'function' => 'sendBucsuReminders',         'frequency' => '1 day',      'from' => '1am', 'until' => '6am'],
    /*
     * #497: a koordináta nélküli templomok kivétele a megjelenésből.
     * FIGYELEM: publikus tartalmat rejt el, élesben 47 templomot.
     * Napi futás: az újonnan felvett, koordináta nélküli templomokat is elkapja.
     */
    ['class' => '\Crons',                         'function' => 'hideChurchesWithoutCoordinates', 'frequency' => '1 day'],
    /*
     * #496: a határ nélkül maradt templomok újra sorba állítása. HAVI futás — a
     * 30 napos korlát miatt gyakoribbnak nincs értelme, lásd a metódus doksiját.
     */
    ['class' => '\Crons',                         'function' => 'requeueChurchesWithoutBoundary', 'frequency' => '1 month'],
    /*
     * #496 / #497 / #498: itt állt az archiveLocationOfChurchesWithoutCoordinates
     * cron. Kikerült, mert a metódus megszűnt: az archiválás átkerült magába a
     * migrációs SQL-be, közvetlenül a DROP elé — így a két lépés atomi.
     * A meglévő DB-sort a Cron::pruneRemoved() takarítja ki.
     */
    ['class' => '\ExternalApi\ElasticsearchApi',  'function' => 'updateChurches',             'frequency' => '6 hours'],
    ['class' => '\ExternalApi\ElasticsearchApi',  'function' => 'updateMasses',               'frequency' => '6 hours'],
    // A teljes indexépítés akkor is hagyhat lyukat, ha közben elhasal valami; ez varrja
    // össze. Élesben 631 misézőhely maradt ki így a keresésből.
    ['class' => '\ExternalApi\ElasticsearchApi',  'function' => 'reindexMissingMasses',       'frequency' => '6 hours'],
    /*
     * #826: a `location` mező nélkül indexelt templomok pótlása.
     *
     * Ezeket a „X km-en belül" keresés NÉMÁN nem találja meg — nem hiba, csak nulla
     * találat. Eddig a health oldal jelezte a számot (élesben 22), a javítás pedig
     * kézi, teljes újraindexelés volt. Egy kézi deploy-lépés előbb-utóbb elmarad;
     * célzott pótlással a hiány magától elfogy.
     */
    ['class' => '\ExternalApi\ElasticsearchApi',  'function' => 'reindexChurchesWithoutLocation', 'frequency' => '6 hours'],
    ['class' => '\ExternalCalendarImporter',      'function' => 'importAllExternalCalendars', 'frequency' => '1 day'],
    // #239: éles adatbázisban régóta fut (id 39), de a registryből kimaradt — egy
    // újrahúzott adatbázisban tehát soha nem jött volna létre.
    ['class' => '\ExternalApi\szentsegimadasApi', 'function' => 'cron',                       'frequency' => '1 day',      'from' => '2am', 'until' => '5am'],
    ['class' => '\Crons',                         'function' => 'cleanExternalApiStats',      'frequency' => '1 day'],
    ['class' => '\Crons',                         'function' => 'cleanUsageStats',            'frequency' => '1 day'],
    ['class' => '\Crons',                         'function' => 'cleanNotificationEmails',    'frequency' => '1 day'],
    ['class' => '\Crons',                         'function' => 'rollPeriodYears',            'frequency' => '1 month'],
    ['class' => '\Eloquent\Email',                'function' => 'sendQueued',                 'frequency' => '15 minutes', 'from' => '1am', 'until' => '6am'],
    // #872: a napi/heti összefoglaló. A sendQueued ELŐTT kell futnia (az teszi sorba a
    // levelet), ezért az ablak eleje felé — a `from` az 1 óra, a drainer utána ér rá.
    ['class' => '\DigestQueue',                   'function' => 'sendDue',                    'frequency' => '1 day',      'from' => '1am', 'until' => '6am'],
    // #315: heti hét templom önkéntesség. Korábban a seed-dumpba (data/crons.sql) írtam
    // volna őket, de az a fájl azóta megszűnt — a #638 óta ez a lista az egyetlen forrás.
    // Mindkettő levelet küld, ezért — a többi levelező cronhoz igazodva — hajnalban fut.
    ['class' => '\Campaign',                      'function' => 'assignUpdates',              'frequency' => '1 week',     'from' => '1am', 'until' => '6am'],
    ['class' => '\Campaign',                      'function' => 'clearoutVolunteers',         'frequency' => '1 month',    'from' => '1am', 'until' => '6am'],
];
