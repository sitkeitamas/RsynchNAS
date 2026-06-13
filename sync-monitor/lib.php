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
const DSM2_HOST = '192.168.9.19';
const DSM2_PORT = 22;
const VIDEO_BIDIR_LOG = '/volume1/homes/sitkeitamas/scripts/video_bidir.log';
const BIDIR_STATUS_CACHE = '/tmp/sync_monitor_bidir_cache.json';
const BIDIR_CACHE_TTL_SEC = 25;
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

const CMD_TIMEOUT_SEC = 5;
const DU_TIMEOUT_SEC = 6;
const STATUS_CACHE_FILE = '/tmp/sync_monitor_disk_cache.json';
const STATUS_CACHE_TTL_SEC = 120;

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
    $target = escapeshellarg($user . '@' . $host);
    $cmd = sprintf(
        'ssh -o ConnectTimeout=4 -o ServerAliveInterval=2 -o ServerAliveCountMax=1 -o BatchMode=yes -p %d %s %s 2>/dev/null',
        $port,
        $target,
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

function video_sync_on_dsm2(): bool
{
    $env = parse_env_file(ENV_FILE);
    return ($env['VIDEO_SYNC_DISABLED'] ?? '0') === '1';
}

function remote_log_last_match(string $host, string $path, string $pattern, int $port = 22, int $timeoutSec = 12): ?string
{
    $line = remote_ssh(
        $host,
        'grep -e ' . escapeshellarg($pattern) . ' ' . escapeshellarg($path) . ' 2>/dev/null | tail -1',
        'sitkeitamas',
        $port,
        $timeoutSec
    );
    return $line !== '' ? $line : null;
}

function remote_log_tail(string $host, string $path, int $lines = 35, int $port = 22): string
{
    $out = remote_ssh(
        $host,
        'tail -n ' . max(1, $lines) . ' ' . escapeshellarg($path) . ' 2>/dev/null',
        'sitkeitamas',
        $port,
        10
    );
    return $out !== '' ? $out : '(DSM2 log nem elérhető — SSH/VPN?)';
}

/** @return array{ok:bool,trigger:bool,rsync:list<string>,log_tail:string} */
function dsm2_bidir_status(bool $refresh = false): array
{
    $empty = ['ok' => false, 'trigger' => false, 'rsync' => [], 'log_tail' => ''];
    if (!$refresh && is_readable(BIDIR_STATUS_CACHE)) {
        $cache = json_decode((string)file_get_contents(BIDIR_STATUS_CACHE), true);
        if (is_array($cache) && (time() - (int)($cache['ts'] ?? 0)) < BIDIR_CACHE_TTL_SEC) {
            return $cache + ['ok' => (bool)($cache['ok'] ?? false)];
        }
    }
    if (remote_ssh(DSM2_HOST, 'echo ok', 'sitkeitamas', DSM2_PORT, 4) !== 'ok') {
        return $empty + ['log_tail' => '(DSM2 SSH timeout)'];
    }
    $ps = remote_ssh(
        DSM2_HOST,
        'ps aux 2>/dev/null | grep -E "sync_video_bidir|[r]sync.*volume1/video" | grep -v grep',
        'sitkeitamas',
        DSM2_PORT,
        6
    );
    $rsync = [];
    foreach (explode("\n", $ps) as $line) {
        if ($line === '' || !str_contains($line, 'rsync')) {
            continue;
        }
        if (str_contains($line, 'rsync --server')) {
            continue;
        }
        $rsync[] = preg_replace('/\s+/', ' ', trim($line));
    }
    $data = [
        'ok' => true,
        'trigger' => str_contains($ps, 'sync_video_bidir_trigger'),
        'rsync' => $rsync,
        'log_tail' => remote_log_tail(DSM2_HOST, VIDEO_BIDIR_LOG, 35, DSM2_PORT),
        'ts' => time(),
    ];
    @file_put_contents(BIDIR_STATUS_CACHE, json_encode($data, JSON_UNESCAPED_UNICODE));
    return $data;
}

function process_status(): array
{
    $ps = run('ps aux 2>/dev/null | grep -E "sync_(video|homes)|rsync" | grep -v grep', 3);
    $videoRsync = [];
    $homesRsync = [];
    foreach (explode("\n", $ps) as $line) {
        if ($line === '' || !str_contains($line, 'rsync')) {
            continue;
        }
        // ssh gyerek: „rsync --server …” — nem külön sync példány
        if (str_contains($line, 'rsync --server')) {
            continue;
        }
        if (str_contains($line, 'dsm2') || str_contains($line, '192.168.9.19')) {
            $videoRsync[] = preg_replace('/\s+/', ' ', trim($line));
        }
        if (str_contains($line, '192.168.9.29') || str_contains($line, 'NetBackup/homes')) {
            $homesRsync[] = preg_replace('/\s+/', ' ', trim($line));
        }
    }
    $bidir = video_sync_on_dsm2() ? dsm2_bidir_status(false) : ['ok' => false, 'trigger' => false, 'rsync' => []];
    if ($bidir['ok'] && !empty($bidir['rsync'])) {
        $videoRsync = array_merge($videoRsync, $bidir['rsync']);
    }
    return [
        'video_trigger' => str_contains($ps, 'sync_video_trigger'),
        'video_bidir' => video_sync_on_dsm2(),
        'video_bidir_trigger' => (bool)($bidir['trigger'] ?? false),
        'video_bidir_ok' => (bool)($bidir['ok'] ?? false),
        'homes_trigger' => str_contains($ps, 'sync_homes_trigger'),
        'homes_sync' => str_contains($ps, 'sync_homes_to_dsm3'),
        'video_rsync' => $videoRsync,
        'homes_rsync' => $homesRsync,
        'homes_pending' => is_file(HOMES_PENDING_FILE),
    ];
}

function cached_remote_disk(string $key, string $host, int $port, bool $refresh = false): string
{
    $cache = [];
    if (is_readable(STATUS_CACHE_FILE)) {
        $cache = json_decode((string)file_get_contents(STATUS_CACHE_FILE), true) ?: [];
    }
    if (!$refresh) {
        return (string)($cache[$key] ?? '—');
    }
    $value = remote_volume_free($host, 'sitkeitamas', $port);
    if ($value === '') {
        return (string)($cache[$key] ?? '—');
    }
    $cache[$key] = $value;
    $cache[$key . '_ts'] = time();
    @file_put_contents(STATUS_CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE));
    return $value;
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

function format_duration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . ' mp';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' perc ' . ($seconds % 60) . ' mp';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h . ' óra ' . $m . ' perc';
}

function next_daily_at(int $hour, int $minute = 0): string
{
    $now = time();
    $target = mktime($hour, $minute, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
    if ($target <= $now) {
        $target = mktime($hour, $minute, 0, (int)date('n'), (int)date('j') + 1, (int)date('Y'));
    }
    return date('Y-m-d H:i', $target);
}

function next_homes_run_label(int $start, int $end, bool $pending): string
{
    $h = (int)date('G');
    if ($h >= $start && $h < $end) {
        return $pending ? 'várakozás a trigger pollra (max ' . intdiv((int)(parse_env_file(HOMES_ENV_FILE)['POLL_INTERVAL_SEC'] ?? 1800), 60) . ' perc)' : 'éjszakai ablakban — változáskor';
    }
    if ($pending) {
        return next_daily_at($start) . ' (pending változás vár)';
    }
    return next_daily_at($start) . ' (éjszakai ablak, ha van változás)';
}

/** @return list<string> */
function read_log_lines(string $path, int $maxLines = 400): array
{
    if (!is_readable($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    return array_slice($lines, -$maxLines);
}

function log_last_match(string $path, string $pattern, ?string $mustAlsoContain = null): ?string
{
    if (!is_readable($path)) {
        return null;
    }
    $line = trim(run(
        'grep -e ' . escapeshellarg($pattern) . ' ' . escapeshellarg($path) . ' 2>/dev/null | tail -1',
        20
    ));
    if ($line === '') {
        return null;
    }
    if ($mustAlsoContain !== null && !str_contains($line, $mustAlsoContain)) {
        return null;
    }
    return $line;
}

/** @return list<string> */
function log_grep_tail(string $path, string $fixedPattern, int $count = 5): array
{
    if (!is_readable($path)) {
        return [];
    }
    $out = run(
        'grep -e ' . escapeshellarg($fixedPattern) . ' ' . escapeshellarg($path) . ' 2>/dev/null | tail -' . max(1, $count),
        20
    );
    if ($out === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode("\n", $out))));
}

/** @param list<string> $lines */
function last_line_matching(array $lines, string $pattern): ?string
{
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (preg_match($pattern, $lines[$i])) {
            return $lines[$i];
        }
    }
    return null;
}

function parse_ts(?string $line): ?string
{
    if ($line === null || !preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
        return null;
    }
    return $m[1];
}

function build_jobs_summary(array $processes): array
{
    $videoEnv = parse_env_file(ENV_FILE);
    $homesEnv = parse_env_file(HOMES_ENV_FILE);
    $homesStart = (int)($homesEnv['SYNC_HOUR_START'] ?? 1);
    $homesEnd = (int)($homesEnv['SYNC_HOUR_END'] ?? 6);
    $videoPoll = (int)($videoEnv['POLL_INTERVAL_SEC'] ?? 120);
    $homesPending = (bool)($processes['homes_pending'] ?? false);

    $jobs = [];

    // --- Videó (DSM2 bidir vagy legacy nasznagy push) ---
    $bidirMode = video_sync_on_dsm2();
    if ($bidirMode) {
        $bidir = dsm2_bidir_status(false);
        $vStart = remote_log_last_match(DSM2_HOST, VIDEO_BIDIR_LOG, 'Videó bidir INDUL', DSM2_PORT);
        $vEnd = remote_log_last_match(DSM2_HOST, VIDEO_BIDIR_LOG, 'Videó bidir VÉGE', DSM2_PORT);
        $vPoll = (int)(remote_ssh(
            DSM2_HOST,
            "grep -E '^POLL_INTERVAL_SEC=' " . escapeshellarg(dirname(VIDEO_BIDIR_LOG) . '/sync_video_bidir.env') . " 2>/dev/null | cut -d= -f2",
            'sitkeitamas',
            DSM2_PORT,
            5
        ) ?: 120);
    } else {
        $vStart = log_last_match(VIDEO_LOG, '--- Szinkron INDUL ---');
        $vEnd = log_last_match(VIDEO_LOG, 'mp ---', 'Szinkron');
        $vPoll = $videoPoll;
        $bidir = ['ok' => true, 'trigger' => str_contains(run('ps aux 2>/dev/null | grep sync_video_trigger | grep -v grep', 2), 'sync_video_trigger')];
    }
    $vStartTs = parse_ts($vStart);
    $vDuration = null;
    if ($vEnd && preg_match('/össz idő: (\d+) mp/', $vEnd, $m)) {
        $vDuration = (int)$m[1];
    }
    $vRunning = count($processes['video_rsync'] ?? []) > 0;
    $vDuplicate = count($processes['video_rsync'] ?? []) > 1;
    $vStatus = 'unknown';
    $vLabel = 'Ismeretlen';
    $vHints = [];
    if ($bidirMode && !($bidir['ok'] ?? false)) {
        $vStatus = 'error';
        $vLabel = 'DSM2 nem elérhető';
        $vHints[] = 'SSH a naszareti (.19) felé sikertelen — VPN vagy DSM2 leállt?';
    } elseif ($vRunning) {
        $vStatus = $vDuplicate ? 'warn' : 'running';
        $vLabel = $vDuplicate ? 'Fut (dupla rsync!)' : 'Fut';
        if ($vDuplicate) {
            $vHints[] = 'Két videó rsync fut egyszerre — sync_video_bidir_control restart a DSM2-n.';
        }
    } elseif ($vEnd && $vStartTs && ($vEndTs = parse_ts($vEnd)) && $vEndTs >= $vStartTs) {
        $vStatus = 'ok';
        $vLabel = 'Sikeres (utolsó kör)';
    } elseif ($vStartTs) {
        $vStatus = 'error';
        $vLabel = 'Megszakadt?';
        $vHints[] = 'INDUL van, de nincs VÉGE — DSM2: ps aux | grep sync_video_bidir';
    } elseif ($bidirMode && ($bidir['trigger'] ?? false)) {
        $vStatus = 'ok';
        $vLabel = 'Figyelő fut';
    }
    if ($bidirMode) {
        $vNext = 'DSM2 poll ' . $vPoll . ' s · Ederics→BP majd BP→Ederics · boot task';
        if (!($bidir['trigger'] ?? false) && ($bidir['ok'] ?? false)) {
            $vHints[] = 'Bidir figyelő nem fut — DSM2: sync_video_bidir_control.sh start';
        }
    } else {
        $vNext = 'Változáskor (poll ' . $vPoll . ' s) · cron: ma/holnap 03:00 és ' . next_daily_at(15);
    }
    $vEndTs = parse_ts($vEnd);
    $vShowEnd = (!$vRunning && $vEndTs && $vStartTs && $vEndTs >= $vStartTs) ? $vEndTs : null;
    $jobs[] = [
        'id' => 'video',
        'name' => $bidirMode ? 'Videó bidir (DSM2)' : 'Videó → DSM2',
        'status' => $vStatus,
        'status_label' => $vLabel,
        'last_start' => $vStartTs,
        'last_end' => $vShowEnd,
        'duration_sec' => $vRunning ? null : $vDuration,
        'duration_human' => $vRunning ? 'folyamatban…' : ($vDuration !== null ? format_duration($vDuration) : '—'),
        'next_run' => $vNext,
        'hints' => $vHints,
        'running' => $vRunning,
    ];

    // --- Homes → DSM3 ---
    $hStart = log_last_match(HOMES_LOG, '--- Homes szinkron INDUL');
    $hEnd = log_last_match(HOMES_LOG, 'mp | rsync ---');
    $hStartTs = parse_ts($hStart);
    $hDuration = null;
    if ($hEnd && preg_match('/össz idő: (\d+) mp/', $hEnd, $m)) {
        $hDuration = (int)$m[1];
    }
    $hErrorLines = log_grep_tail(HOMES_LOG, 'HIBA p', 12);
    if ($hStartTs) {
        $hErrorLines = array_values(array_filter(
            $hErrorLines,
            static function (string $line) use ($hStartTs): bool {
                $ts = parse_ts($line);
                return $ts !== null && $ts >= $hStartTs;
            }
        ));
    }
    $hErrors = array_map(
        static fn(string $line): string => preg_replace('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] /', '', $line),
        array_slice($hErrorLines, -5)
    );
    $hRunning = count($processes['homes_rsync'] ?? []) > 0
        || !empty($processes['homes_sync']);
    $hStatus = 'ok';
    $hLabel = 'Sikeres';
    if ($hRunning) {
        $hStatus = 'running';
        $hLabel = 'Fut';
    } elseif ($homesPending && !$hRunning) {
        $hStatus = 'waiting';
        $hLabel = $hErrors !== [] ? 'Várakozik (pending · utolsó körben hibák)' : 'Várakozik (pending)';
    } elseif ($hErrors !== []) {
        $hStatus = 'partial';
        $hLabel = 'Részleges (' . count($hErrors) . ' hiba)';
    }
    $hHints = [];
    foreach ($hErrors as $err) {
        if (str_contains($err, 'jogosultság') || str_contains($err, 'ACL') || str_contains($err, 'NetBackup')) {
            $hHints[] = 'NetBackup/homes írási jog: touch teszt a naszikán (sitkeitamas tulajdon)';
        } elseif (str_contains($err, 'rsync szolgáltatás')) {
            $hHints[] = 'Naszika: Vezérlőpult → Fájlszolgáltatások → rsync engedélyezése';
        } elseif (str_contains($err, 'tamas.sitkei/Drive')) {
            $hHints[] = 'tamas.sitkei Drive: tulajdonos + sitkeitamas írás (fix script vagy File Station)';
        }
    }
    $hHints = array_values(array_unique($hHints));
    if ($homesPending && !$hRunning) {
        $hHints[] = 'Változás észlelve — sync csak ' . $homesStart . ':00–' . $homesEnd . ':00 között indul.';
    }
    $jobs[] = [
        'id' => 'homes',
        'name' => 'Homes → DSM3',
        'status' => $hStatus,
        'status_label' => $hLabel,
        'last_start' => $hStartTs,
        'last_end' => parse_ts($hEnd),
        'duration_sec' => $hDuration,
        'duration_human' => $hDuration !== null ? format_duration($hDuration) : ($hRunning ? 'folyamatban…' : '—'),
        'next_run' => next_homes_run_label($homesStart, $homesEnd, $homesPending),
        'hints' => $hHints,
        'errors' => array_slice($hErrors, 0, 5),
        'running' => $hRunning,
        'pending' => $homesPending,
    ];

    // --- Webcam ---
    $wLines = read_log_lines(WEBCAM_LOG, 80);
    $wStart = last_line_matching($wLines, '/Válogatás és Web frissítés indul/');
    $wEnd = last_line_matching($wLines, '/--- KÉSZ ---/');
    $wStartTs = parse_ts($wStart);
    $wEndTs = parse_ts($wEnd);
    $wOk = $wStartTs && $wEndTs && $wEndTs >= $wStartTs;
    $jobs[] = [
        'id' => 'webcam',
        'name' => 'Webcam → homepage',
        'status' => $wOk ? 'ok' : ($wStartTs ? 'running' : 'unknown'),
        'status_label' => $wOk ? 'Sikeres' : ($wStartTs ? 'Folyamatban?' : 'Nincs adat'),
        'last_start' => $wStartTs,
        'last_end' => $wOk ? $wEndTs : null,
        'duration_sec' => null,
        'duration_human' => ($wStartTs && $wEndTs) ? format_duration(max(1, strtotime($wEndTs) - strtotime($wStartTs))) : '—',
        'next_run' => 'DSM task: 10 percenként (sync_ederics.sh)',
        'hints' => [],
        'errors' => [],
        'running' => false,
    ];

    // --- Monitor ---
    $monPid = is_readable('/tmp/sync_monitor.pid') ? trim((string)file_get_contents('/tmp/sync_monitor.pid')) : '';
    $monRunning = $monPid !== '' && run('kill -0 ' . escapeshellarg($monPid) . ' 2>/dev/null', 1) === '';
    $wdPid = is_readable('/tmp/sync_monitor_watchdog.pid') ? trim((string)file_get_contents('/tmp/sync_monitor_watchdog.pid')) : '';
    $wdRunning = $wdPid !== '' && run('kill -0 ' . escapeshellarg($wdPid) . ' 2>/dev/null', 1) === '';
    $jobs[] = [
        'id' => 'monitor',
        'name' => 'Sync monitor',
        'status' => $monRunning ? 'ok' : 'error',
        'status_label' => $monRunning ? 'Fut' : 'Leállt',
        'last_start' => null,
        'last_end' => null,
        'duration_sec' => null,
        'duration_human' => '—',
        'next_run' => $wdRunning ? 'Watchdog: 5 percenként ellenőriz' : 'Watchdog nem fut — sync_control restart',
        'hints' => $monRunning ? [] : ['Indítsd: bash ~/scripts/sync-monitor/serve.sh start'],
        'errors' => [],
        'running' => $monRunning,
    ];

    return $jobs;
}

function build_status(bool $includeSizes = false, bool $refreshDisk = false): array
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

    $processes = process_status();
    $bidirMode = video_sync_on_dsm2();
    $bidir = $bidirMode ? dsm2_bidir_status(false) : null;
    $videoLog = $bidirMode && is_array($bidir)
        ? (string)($bidir['log_tail'] ?? remote_log_tail(DSM2_HOST, VIDEO_BIDIR_LOG))
        : tail_file(VIDEO_LOG, 35);

    return [
        'time' => date('Y-m-d H:i:s'),
        'sizes_included' => $includeSizes,
        'jobs' => build_jobs_summary($processes),
        'processes' => $processes,
        'video' => [
            'bidir' => $bidirMode,
            'folders' => $videoFolders,
            'env' => $videoEnv,
            'folders_conf' => read_folders_conf_file(FOLDERS_FILE),
            'remote_disk' => cached_remote_disk('video_disk', $videoHost, $videoPort, $refreshDisk),
            'log' => $videoLog,
        ],
        'homes' => [
            'folders' => $homesFolders,
            'env' => $homesEnv,
            'folders_conf' => read_folders_conf_file(HOMES_FOLDERS_FILE),
            'remote_disk' => cached_remote_disk('homes_disk', $homesHost, $homesPort, $refreshDisk),
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
        'sync_video_bidir_now' => run(
            'ssh -o ConnectTimeout=10 -o BatchMode=yes -p ' . DSM2_PORT . ' '
            . escapeshellarg('sitkeitamas@' . DSM2_HOST) . ' '
            . escapeshellarg('bash /volume1/homes/sitkeitamas/scripts/sync_video_bidir_now.sh') . ' &'
        ),
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
