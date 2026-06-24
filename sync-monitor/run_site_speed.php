#!/usr/bin/env php
<?php
declare(strict_types=1);
// Háttér sebességteszt — CLI worker (nem blokkolja a 8765-ös PHP szervert).
require __DIR__ . '/lib.php';

$mb = max(5, min(SITE_SPEED_MAX_MB, (int)($argv[1] ?? 20)));
$label = trim((string)($argv[2] ?? ''));

$lock = [
    'pid' => getmypid(),
    'mb' => $mb,
    'label' => $label,
    'started' => date('c'),
    'ts' => time(),
];
file_put_contents(SITE_SPEED_LOCK_FILE, json_encode($lock, JSON_UNESCAPED_UNICODE));

try {
    $result = measure_site_speed($mb);
    $result['label'] = $label;
    $result['status'] = 'done';
    file_put_contents(SITE_SPEED_RESULT_FILE, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    site_speed_append_history($result);
} catch (Throwable $e) {
    $err = [
        'status' => 'error',
        'error' => $e->getMessage(),
        'label' => $label,
        'mb_per_test' => $mb,
        'at' => date('c'),
    ];
    file_put_contents(SITE_SPEED_RESULT_FILE, json_encode($err, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
} finally {
    @unlink(SITE_SPEED_LOCK_FILE);
}
