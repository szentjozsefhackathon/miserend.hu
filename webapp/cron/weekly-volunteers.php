<?php
/**
 * #315: heti hét templom önkéntesség — CLI cron-entry pont.
 *
 * Ütemezésre NINCS rá szükség: a két munka (\Campaign::assignUpdates és
 * ::clearoutVolunteers) be van jegyezve a webapp/fajlok/crons.php registrybe, tehát a
 * rendes cron-futtató elindítja őket. Ez a fájl a kézi futtatásra való — akkor hasznos,
 * ha egyben, bőbeszédűen és értelmes kilépési kóddal akarjuk látni az eredményt:
 *
 *     docker compose exec miserend php cron/weekly-volunteers.php
 *     docker compose exec miserend php cron/weekly-volunteers.php --cleanup
 *
 * Exit code:
 *   0 — sikeres futás (az `errors` is üres)
 *   1 — futás közben volt hiba (de a sikeresen kiosztott templomok már be vannak commitelve)
 *   2 — fatal exception (initializáció bukott)
 */

// Bootloader: a webapp/index.php loadolja a Composer-autoload-ot + a globális $config-ot
$webroot = dirname(__DIR__);
chdir($webroot);

try {
    require_once $webroot . '/load.php';
} catch (\Throwable $e) {
    fwrite(STDERR, "[fatal] load.php failed: " . $e->getMessage() . "\n");
    exit(2);
}

$cleanup = in_array('--cleanup', $argv ?? [], true);
$exitCode = 0;

echo "[" . date('Y-m-d H:i:s') . "] #315 weekly volunteers — start\n";

try {
    $stats = Campaign::assignUpdates();
    echo "  assignUpdates: " .
         "users_processed={$stats['users_processed']}, " .
         "churches_assigned={$stats['churches_assigned']}, " .
         "emails_sent={$stats['emails_sent']}, " .
         "errors=" . count($stats['errors']) . "\n";
    foreach ($stats['errors'] as $err) {
        echo "    ERR: $err\n";
        $exitCode = 1;
    }

    if ($cleanup) {
        $clearStats = Campaign::clearoutVolunteers();
        echo "  clearoutVolunteers: cleared={$clearStats['cleared']}, errors=" . count($clearStats['errors']) . "\n";
        foreach ($clearStats['errors'] as $err) {
            echo "    ERR: $err\n";
            $exitCode = 1;
        }
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "[fatal] " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(2);
}

echo "[" . date('Y-m-d H:i:s') . "] #315 weekly volunteers — done (exit=$exitCode)\n";
exit($exitCode);
