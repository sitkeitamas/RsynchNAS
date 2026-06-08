<?php
declare(strict_types=1);

const SCRIPTS_DIR = '/volume1/homes/sitkeitamas/scripts';
const ENV_FILE = SCRIPTS_DIR . '/sync_video.env';
const FOLDERS_FILE = SCRIPTS_DIR . '/sync_folders.conf';
const VIDEO_LOG = SCRIPTS_DIR . '/video_sync.log';
const WEBCAM_LOG = SCRIPTS_DIR . '/sync_log.txt';
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

function run(string $cmd): string
{
    $out = [];
    exec($cmd . ' 2>&1', $out, $code);
    return implode("\n", $out);
}

function tail_file(string $path, int $lines = 40): string
{
    if (!is_readable($path)) {
        return '(log nem olvasható)';
    }
    return run('tail -n ' . (int)$lines . ' ' . escapeshellarg($path));
}

function parse_env_file(): array
{
    $cfg = [];
    if (!is_readable(ENV_FILE)) {
        return $cfg;
    }
    foreach (file(ENV_FILE, FILE_IGNORE_NEW_LINES) as $line) {
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

function read_folders_conf(): string
{
    return is_readable(FOLDERS_FILE) ? file_get_contents(FOLDERS_FILE) : '';
}

function folder_pairs(): array
{
    $pairs = [];
    foreach (explode("\n", read_folders_conf()) as $line) {
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

function dir_size(string $path): string
{
    if (!is_dir($path)) {
        return '—';
    }
    return trim(run('du -sh ' . escapeshellarg($path) . " | awk '{print $1}'"));
}

function remote_dir_size(string $host, string $path, string $user = 'sitkeitamas'): string
{
    $cmd = sprintf(
        'ssh -o ConnectTimeout=8 -o BatchMode=yes %s@%s %s 2>/dev/null | awk \'{print $1}\'',
        escapeshellarg($user),
        escapeshellarg($host),
        escapeshellarg('du -sh ' . $path . ' 2>/dev/null')
    );
    $out = trim(run($cmd));
    return $out !== '' ? $out : '—';
}

function process_status(): array
{
    $ps = run('ps aux');
    $trigger = str_contains($ps, 'sync_video_trigger');
    $homes = str_contains($ps, 'sync_homes_trigger');
    $rsync = [];
    foreach (explode("\n", $ps) as $line) {
        if (str_contains($line, 'rsync') && str_contains($line, 'dsm2')) {
            $rsync[] = preg_replace('/\s+/', ' ', trim($line));
        }
    }
    return [
        'video_trigger' => $trigger,
        'homes_trigger' => $homes,
        'rsync' => $rsync,
    ];
}

function build_status(): array
{
    $env = parse_env_file();
    $host = $env['REMOTE_HOST'] ?? 'dsm2.sitkeitamas.hu';
    $pairs = folder_pairs();
    $sizes = [];
    foreach ($pairs as $p) {
        $sizes[] = [
            'src' => $p['src'],
            'dest' => $p['dest'],
            'local' => dir_size($p['src']),
            'remote' => remote_dir_size($host, $p['dest']),
        ];
    }
    return [
        'time' => date('Y-m-d H:i:s'),
        'processes' => process_status(),
        'folders' => $sizes,
        'env' => $env,
        'folders_conf' => read_folders_conf(),
        'video_log' => tail_file(VIDEO_LOG, 35),
        'webcam_log' => tail_file(WEBCAM_LOG, 15),
    ];
}

function backup_file(string $path): void
{
    if (is_file($path)) {
        copy($path, $path . '.bak.' . date('Ymd-His'));
    }
}

function save_env(array $in): void
{
    $allowed = ['REMOTE_HOST', 'REMOTE_PORT', 'RSYNC_BWLIMIT', 'POLL_INTERVAL_SEC', 'REMOTE_USER'];
    $current = file_get_contents(ENV_FILE);
    foreach ($allowed as $key) {
        if (!isset($in[$key])) {
            continue;
        }
        $val = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$in[$key]);
        if ($key === 'RSYNC_BWLIMIT' || $key === 'POLL_INTERVAL_SEC' || $key === 'REMOTE_PORT') {
            $val = (string)max(0, (int)$in[$key]);
        }
        $current = preg_replace('/^' . preg_quote($key, '/') . '=.*/m', $key . '="' . $val . '"', $current);
    }
    backup_file(ENV_FILE);
    file_put_contents(ENV_FILE, $current);
}

function save_folders(string $text): void
{
    backup_file(FOLDERS_FILE);
    file_put_contents(FOLDERS_FILE, rtrim($text) . "\n");
}

function run_action(string $action): string
{
    $base = SCRIPTS_DIR;
    return match ($action) {
        'start' => run('bash ' . escapeshellarg($base . '/sync_control.sh') . ' start'),
        'stop' => run('bash ' . escapeshellarg($base . '/sync_control.sh') . ' stop'),
        'restart' => run('bash ' . escapeshellarg($base . '/sync_control.sh') . ' restart'),
        'sync_now' => run('bash ' . escapeshellarg($base . '/sync_now.sh') . ' &'),
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
