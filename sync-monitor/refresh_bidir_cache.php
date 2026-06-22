<?php
declare(strict_types=1);
// Háttér: DSM2 bidir cache frissítése (watchdog hívja, a panel ne SSH-zzen kérésenként).
require __DIR__ . '/lib.php';
if (!video_sync_on_dsm2()) {
    exit(0);
}
dsm2_bidir_status(true);
