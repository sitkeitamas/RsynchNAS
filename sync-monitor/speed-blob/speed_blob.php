<?php
declare(strict_types=1);
// Külön port (8766) — HTTP blob letöltés sebességteszthez, ne blokkolja a fő monitort.

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$lan = $ip === '127.0.0.1' || $ip === '::1'
    || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip);
if (!$lan) {
    http_response_code(403);
    exit('Forbidden');
}

$mb = max(1, min(50, (int)($_GET['mb'] ?? 20)));
$bytes = $mb * 1024 * 1024;
header('Content-Type: application/octet-stream');
header('Content-Length: ' . $bytes);
header('Cache-Control: no-store');
$chunk = str_repeat("\0", 65536);
$remaining = $bytes;
while ($remaining > 0) {
    $n = min(strlen($chunk), $remaining);
    echo substr($chunk, 0, $n);
    $remaining -= $n;
}
