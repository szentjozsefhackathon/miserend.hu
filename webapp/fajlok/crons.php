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
    ['class' => '\OSM',                           'function' => 'checkBoundaries',            'frequency' => '5 min'],
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
    ['class' => '\User',                          'function' => 'sendBucsuReminder',          'frequency' => '1 day',      'from' => '1am', 'until' => '6am'],
    /*
     * #496: a határ nélkül maradt templomok újra sorba állítása. HAVI futás — a
     * 30 napos korlát miatt gyakoribbnak nincs értelme, lásd a metódus doksiját.
     */
    ['class' => '\Crons',                         'function' => 'requeueChurchesWithoutBoundary', 'frequency' => '1 month'],
    /*
     * #496: a koordináta nélküli templomok helyadatának átmentése a megjegyzés
     * mezőbe. EGYSZERI feladat a kivezetés előtt; idempotens, tehát ártalmatlan,
     * ha a napi futás többször is meghívja.
     */
    ['class' => '\Crons',                         'function' => 'archiveLocationOfChurchesWithoutCoordinates', 'frequency' => '1 day'],
    ['class' => '\ExternalApi\ElasticsearchApi',  'function' => 'updateChurches',             'frequency' => '6 hours'],
    ['class' => '\ExternalApi\ElasticsearchApi',  'function' => 'updateMasses',               'frequency' => '6 hours'],
    // A teljes indexépítés akkor is hagyhat lyukat, ha közben elhasal valami; ez varrja
    // össze. Élesben 631 misézőhely maradt ki így a keresésből.
    ['class' => '\ExternalApi\ElasticsearchApi',  'function' => 'reindexMissingMasses',       'frequency' => '6 hours'],
    ['class' => '\ExternalCalendarImporter',      'function' => 'importAllExternalCalendars', 'frequency' => '1 day'],
    // #239: éles adatbázisban régóta fut (id 39), de a registryből kimaradt — egy
    // újrahúzott adatbázisban tehát soha nem jött volna létre.
    ['class' => '\ExternalApi\szentsegimadasApi', 'function' => 'cron',                       'frequency' => '1 day',      'from' => '2am', 'until' => '5am'],
    ['class' => '\Crons',                         'function' => 'cleanExternalApiStats',      'frequency' => '1 day'],
    ['class' => '\Crons',                         'function' => 'cleanUsageStats',            'frequency' => '1 day'],
    ['class' => '\Crons',                         'function' => 'cleanNotificationEmails',    'frequency' => '1 day'],
    ['class' => '\Crons',                         'function' => 'rollPeriodYears',            'frequency' => '1 month'],
    ['class' => '\Eloquent\Email',                'function' => 'sendQueued',                 'frequency' => '15 minutes', 'from' => '1am', 'until' => '6am'],
    // #315: heti hét templom önkéntesség. Korábban a seed-dumpba (data/crons.sql) írtam
    // volna őket, de az a fájl azóta megszűnt — a #638 óta ez a lista az egyetlen forrás.
    // Mindkettő levelet küld, ezért — a többi levelező cronhoz igazodva — hajnalban fut.
    ['class' => '\Campaign',                      'function' => 'assignUpdates',              'frequency' => '1 week',     'from' => '1am', 'until' => '6am'],
    ['class' => '\Campaign',                      'function' => 'clearoutVolunteers',         'frequency' => '1 month',    'from' => '1am', 'until' => '6am'],
];
