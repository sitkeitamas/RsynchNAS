<?php
declare(strict_types=1);

const SCRIPTS_DIR = '/volume1/homes/sitkeitamas/scripts';
const ENV_FILE = SCRIPTS_DIR . '/sync_video.env';
const FOLDERS_FILE = SCRIPTS_DIR . '/sync_folders.conf';
const HOMES_ENV_FILE = SCRIPTS_DIR . '/sync_homes.env';
const HOMES_FOLDERS_FILE = SCRIPTS_DIR . '/sync_homes_folders.conf';
const VIDEO_LOG = SCRIPTS_DIR . '/video_sync.log';
const HOMES_LOG = SCRIPTS_DIR . '/homes_sync.log';
const WEBCAM_LOG = SCRIPTS_DIR . '/sync_log.txt';
const HOMES_PENDING_FILE = '/tmp/sync_homes_pending';
const BIND_HOST = '192.168.5.9';
const BIND_PORT = 8765;

function client_allowed(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }
    if (preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) {
        return true;
    }
    return false;
}

function deny_if_external(): void
{
    if (!client_allowed()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Csak belső hálózatról (LAN/VPN).']);
        exit;
    }
}

function run(string $cmd, int $timeoutSec = 0): string
{
    $out = [];
    $wrapped = $timeoutSec > 0 ? 'timeout ' . $timeoutSec . ' ' . $cmd : $cmd;
    exec($wrapped . ' 2>&1', $out, $code);
    if ($timeoutSec > 0 && $code === 124) {
        return '';
    }
    return implode("\n", $out);
}

function tail_file(string $path, int $lines = 40): string
{
    if (!is_readable($path)) {
        return '(log nem olvasható)';
    }
    return run('tail -n ' . (int)$lines . ' ' . escapeshellarg($path));
}

function parse_env_file(string $path): array
{
    $cfg = [];
    if (!is_readable($path)) {
        return $cfg;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
            $cfg[$m[1]] = trim($m[2], "\"'");
        }
    }
    return $cfg;
}

function read_folders_conf_file(string $path): string
{
    return is_readable($path) ? file_get_contents($path) : '';
}

function folder_pairs_from(string $text): array
{
    $pairs = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line, 2));
        if (count($parts) === 2 && $parts[0] !== '') {
            $pairs[] = ['src' => $parts[0], 'dest' => $parts[1]];
        }
    }
    return $pairs;
}

const CMD_TIMEOUT_SEC = 6;
const DU_TIMEOUT_SEC = 8;

function dir_size(string $path): string
{
    if (!is_dir($path)) {
        return '—';
    }
    $out = trim(run('du -sh ' . escapeshellarg($path) . " | awk '{print $1}'", DU_TIMEOUT_SEC));
    return $out !== '' ? $out : '⏳';
}

function remote_ssh(string $host, string $remoteCmd, string $user = 'sitkeitamas', int $port = 22, int $timeoutSec = CMD_TIMEOUT_SEC): string
{
    $cmd = sprintf(
        'ssh -o ConnectTimeout=5 -o ServerAliveInterval=3 -o ServerAliveCountMax=1 -o BatchMode=yes -p %d %s@%s %s 2>/dev/null',
        $port,
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg($remoteCmd)
    );
    return trim(run($cmd, $timeoutSec));
}

function remote_dir_size(string $host, string $path, string $user = 'sitkeitamas', int $port = 22): string
{
    // Naszika du lassú — rövid timeout, ne blokkolja a panelt
    $limit = $host === '192.168.9.29' ? 5 : DU_TIMEOUT_SEC;
    $out = remote_ssh(
        $host,
        'du -sh ' . escapeshellarg($path) . " 2>/dev/null | awk '{print \$1}'",
        $user,
        $port,
        $limit
    );
    return $out !== '' ? $out : '⏳';
}

function remote_volume_free(string $host, string $user = 'sitkeitamas', int $port = 22): string
{
    $out = remote_ssh(
        $host,
        "df -h /volume1 2>/dev/null | tail -1 | awk '{print \$4\" szabad (\"\$5\" haszn.)\"}'",
        $user,
        $port,
        CMD_TIMEOUT_SEC
    );
    return $out !== '' ? $out : '—';
}

function process_status(): array
{
    $ps = run('ps aux');
    $videoRsync = [];
    $homesRsync = [];
    foreach (explode("\n", $ps) as $line) {
        if (!str_contains($line, 'rsync')) {
            continue;
        }
        if (str_contains($line, 'dsm2') || str_contains($line, '192.168.9.19')) {
            $videoRsync[] = preg_replace('/\s+/', ' ', trim($line));
        }
        if (str_contains($line, '192.168.9.29')) {
            $homesRsync[] = preg_replace('/\s+/', ' ', trim($line));
        }
    }
    return [
        'video_trigger' => str_contains($ps, 'sync_video_trigger'),
        'homes_trigger' => str_contains($ps, 'sync_homes_trigger'),
        'video_rsync' => $videoRsync,
        'homes_rsync' => $homesRsync,
        'homes_pending' => is_file(HOMES_PENDING_FILE),
    ];
}

function build_folder_sizes(array $pairs, string $host, int $port): array
{
    $sizes = [];
    foreach ($pairs as $p) {
        $label = basename(dirname($p['src'])) . '/' . basename($p['src']);
        $sizes[] = [
            'label' => $label,
            'src' => $p['src'],
            'dest' => $p['dest'],
            'local' => dir_size($p['src']),
            'remote' => remote_dir_size($host, $p['dest'], 'sitkeitamas', $port),
        ];
    }
    return $sizes;
}

function build_status(bool $includeSizes = false): array
{
    $videoEnv = parse_env_file(ENV_FILE);
    $homesEnv = parse_env_file(HOMES_ENV_FILE);
    $videoHost = $videoEnv['REMOTE_HOST'] ?? 'dsm2.sitkeitamas.hu';
    $videoPort = (int)($videoEnv['REMOTE_PORT'] ?? 22);
    $homesHost = $homesEnv['REMOTE_HOST'] ?? '192.168.9.29';
    $homesPort = (int)($homesEnv['REMOTE_PORT'] ?? 22);

    $videoPairs = folder_pairs_from(read_folders_conf_file(FOLDERS_FILE));
    $homesPairs = folder_pairs_from(read_folders_conf_file(HOMES_FOLDERS_FILE));
    $videoFolders = $includeSizes
        ? build_folder_sizes($videoPairs, $videoHost, $videoPort)
        : array_map(static fn(array $p) => [
            'label' => basename(dirname($p['src'])) . '/' . basename($p['src']),
            'src' => $p['src'],
            'dest' => $p['dest'],
            'local' => '—',
            'remote' => '—',
        ], $videoPairs);
    $homesFolders = $includeSizes
        ? build_folder_sizes($homesPairs, $homesHost, $homesPort)
        : array_map(static fn(array $p) => [
            'label' => basename(dirname($p['src'])) . '/' . basename($p['src']),
            'src' => $p['src'],
            'dest' => $p['dest'],
            'local' => '—',
            'remote' => '—',
        ], $homesPairs);

    return [
        'time' => date('Y-m-d H:i:s'),
        'sizes_included' => $includeSizes,
        'processes' => process_status(),
        'video' => [
            'folders' => $videoFolders,
            'env' => $videoEnv,
            'folders_conf' => read_folders_conf_file(FOLDERS_FILE),
            'remote_disk' => remote_volume_free($videoHost, 'sitkeitamas', $videoPort),
            'log' => tail_file(VIDEO_LOG, 35),
        ],
        'homes' => [
            'folders' => $homesFolders,
            'env' => $homesEnv,
            'folders_conf' => read_folders_conf_file(HOMES_FOLDERS_FILE),
            'remote_disk' => remote_volume_free($homesHost, 'sitkeitamas', $homesPort),
            'log' => tail_file(HOMES_LOG, 35),
        ],
        'webcam_log' => tail_file(WEBCAM_LOG, 15),
        'folders' => $videoFolders,
        'env' => $videoEnv,
        'folders_conf' => read_folders_conf_file(FOLDERS_FILE),
        'video_log' => tail_file(VIDEO_LOG, 35),
    ];
}

function backup_file(string $path): void
{
    if (is_file($path)) {
        copy($path, $path . '.bak.' . date('Ymd-His'));
    }
}

function save_env_file(string $path, array $in, array $allowed): void
{
    $current = file_get_contents($path);
    foreach ($allowed as $key) {
        if (!isset($in[$key])) {
            continue;
        }
        $val = (string)$in[$key];
        if (in_array($key, ['RSYNC_BWLIMIT', 'POLL_INTERVAL_SEC', 'REMOTE_PORT', 'SYNC_HOUR_START', 'SYNC_HOUR_END'], true)) {
            $val = (string)max(0, (int)$in[$key]);
        } else {
            $val = preg_replace('/[^a-zA-Z0-9._-]/', '', $val);
        }
        $current = preg_replace('/^' . preg_quote($key, '/') . '=.*/m', $key . '="' . $val . '"', $current);
    }
    backup_file($path);
    file_put_contents($path, $current);
}

function save_env(array $in): void
{
    save_env_file(ENV_FILE, $in, ['REMOTE_HOST', 'REMOTE_PORT', 'RSYNC_BWLIMIT', 'POLL_INTERVAL_SEC', 'REMOTE_USER']);
}

function save_homes_env(array $in): void
{
    save_env_file(HOMES_ENV_FILE, $in, [
        'REMOTE_HOST', 'REMOTE_PORT', 'RSYNC_BWLIMIT', 'POLL_INTERVAL_SEC',
        'SYNC_HOUR_START', 'SYNC_HOUR_END', 'REMOTE_USER',
    ]);
}

function save_folders(string $text): void
{
    backup_file(FOLDERS_FILE);
    file_put_contents(FOLDERS_FILE, rtrim($text) . "\n");
}

function save_homes_folders(string $text): void
{
    backup_file(HOMES_FOLDERS_FILE);
    file_put_contents(HOMES_FOLDERS_FILE, rtrim($text) . "\n");
}

function run_action(string $action): string
{
    $base = SCRIPTS_DIR;
    return match ($action) {
        'start' => run('bash ' . escapeshellarg($base . '/sync_control.sh') . ' start'),
        'stop' => run('bash ' . escapeshellarg($base . '/sync_control.sh') . ' stop'),
        'restart' => run('bash ' . escapeshellarg($base . '/sync_control.sh') . ' restart'),
        'sync_now' => run('bash ' . escapeshellarg($base . '/sync_now.sh') . ' &'),
        'sync_homes_now' => run('bash ' . escapeshellarg($base . '/sync_homes_now.sh') . ' &'),
        default => 'Ismeretlen művelet',
    };
}

/** @return list<string> */
function list_docs(): array
{
    $files = glob(SCRIPTS_DIR . '/README*.md') ?: [];
    sort($files);
    return array_values(array_map('basename', $files));
}

function read_doc(string $name): string
{
    if (!preg_match('/^README[-a-zA-Z0-9]*\.md$/', $name)) {
        throw new InvalidArgumentException('Érvénytelen dokumentum név');
    }
    $path = SCRIPTS_DIR . '/' . $name;
    if (!is_readable($path)) {
        throw new RuntimeException('A fájl nem olvasható: ' . $name);
    }
    return file_get_contents($path);
}
