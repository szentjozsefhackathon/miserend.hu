<?php
/**
 * #315: heti hét templom önkéntesség — CLI cron-entry pont.
 *
 * Telepítés:
 *   - Cron-bejegyzés: minden hétfő reggel 7-kor:
 *     0 7 * * 1 cd /path/to/miserend/webapp && /usr/bin/php cron/weekly-volunteers.php >> /var/log/miserend/volunteers.log 2>&1
 *
 *   - Havonta egyszer inaktívak takarítása is ajánlott (külön cron, vagy ide
 *     bekapcsolható `--cleanup` flaggel a hónap első hétfőjén):
 *     0 7 1-7 * 1 cd /path/to/miserend/webapp && /usr/bin/php cron/weekly-volunteers.php --cleanup >> ...
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
