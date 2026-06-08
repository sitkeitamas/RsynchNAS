<?php
declare(strict_types=1);
// Minimális health check — nem hív lib.php-t, nem SSH-zik.
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'time' => date('c')], JSON_UNESCAPED_UNICODE);
