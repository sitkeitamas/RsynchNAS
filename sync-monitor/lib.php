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
const BIDIR_CACHE_TTL_SEC = 60;
const BIDIR_SSH_TIMEOUT_SEC = 12;
const BIND_HOST = '192.168.5.9';
const BIND_PORT = 8765;
const SPEED_PORT = 8766;

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

function dsm2_bidir_remote_bundle_cmd(): string
{
    // Csak ps + log vége — a log TB méretű lehet (rsync sorok), grep SOHA.
    return sprintf(
        'ps aux 2>/dev/null | grep -E "sync_video_bidir|[r]sync.*volume1/video" | grep -v grep; ' .
        'printf "\n---SPLIT---\n"; ' .
        'tail -n 80 %s 2>/dev/null',
        escapeshellarg(VIDEO_BIDIR_LOG)
    );
}

function dsm2_bidir_status_from_cache_file(): ?array
{
    if (!is_readable(BIDIR_STATUS_CACHE)) {
        return null;
    }
    $cache = json_decode((string)file_get_contents(BIDIR_STATUS_CACHE), true);
    return is_array($cache) ? $cache : null;
}

/** @return array{ok:bool,trigger:bool,sync_count:int,rsync:list<string>,log_tail:string,last_indul:?string,last_vege:?string,poll_sec:int} */
function dsm2_bidir_status(bool $refresh = false): array
{
    $empty = [
        'ok' => false,
        'trigger' => false,
        'sync_count' => 0,
        'rsync' => [],
        'log_tail' => '',
        'last_indul' => null,
        'last_vege' => null,
        'poll_sec' => 120,
    ];
    if (!$refresh) {
        $cache = dsm2_bidir_status_from_cache_file();
        if (is_array($cache) && (time() - (int)($cache['ts'] ?? 0)) < BIDIR_CACHE_TTL_SEC) {
            return $cache + ['ok' => (bool)($cache['ok'] ?? false)];
        }
    }
    $raw = remote_ssh(DSM2_HOST, dsm2_bidir_remote_bundle_cmd(), 'sitkeitamas', DSM2_PORT, BIDIR_SSH_TIMEOUT_SEC);
    if ($raw === '') {
        $stale = dsm2_bidir_status_from_cache_file();
        if (is_array($stale) && ($stale['ok'] ?? false)) {
            return $stale + ['ok' => true, 'stale' => true];
        }
        $fail = $empty + ['log_tail' => '(DSM2 SSH timeout)', 'ts' => time()];
        @file_put_contents(BIDIR_STATUS_CACHE, json_encode($fail, JSON_UNESCAPED_UNICODE));
        return $fail;
    }
    $parts = explode("\n---SPLIT---\n", $raw, 2);
    $ps = $parts[0] ?? '';
    $logTail = trim($parts[1] ?? '');
    $logLines = $logTail !== '' ? explode("\n", $logTail) : [];
    $lastIndul = last_line_matching($logLines, '/Videó bidir INDUL/');
    $lastVege = last_line_matching($logLines, '/Videó bidir VÉGE/');
    $rsync = [];
    $syncCount = 0;
    foreach (explode("\n", $ps) as $line) {
        if (str_contains($line, 'sync_video_bidir.sh') && !str_contains($line, 'sync_video_bidir_trigger')) {
            $syncCount++;
        }
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
        'sync_count' => $syncCount,
        'rsync' => $rsync,
        'log_tail' => $logTail !== '' ? $logTail : '(DSM2 log üres)',
        'last_indul' => $lastIndul,
        'last_vege' => $lastVege,
        'poll_sec' => 120,
        'ts' => time(),
    ];
    @file_put_contents(BIDIR_STATUS_CACHE, json_encode($data, JSON_UNESCAPED_UNICODE));
    return $data;
}

function process_status(?array $bidir = null): array
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
    if ($bidir === null && video_sync_on_dsm2()) {
        $bidir = dsm2_bidir_status(false);
    }
    if (!is_array($bidir)) {
        $bidir = ['ok' => false, 'trigger' => false, 'sync_count' => 0, 'rsync' => []];
    }
    if ($bidir['ok'] && !empty($bidir['rsync'])) {
        $videoRsync = array_merge($videoRsync, $bidir['rsync']);
    }
    return [
        'video_trigger' => str_contains($ps, 'sync_video_trigger'),
        'video_bidir' => video_sync_on_dsm2(),
        'video_bidir_trigger' => (bool)($bidir['trigger'] ?? false),
        'video_bidir_sync_count' => (int)($bidir['sync_count'] ?? 0),
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

function build_jobs_summary(array $processes, ?array $bidir = null): array
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
        if ($bidir === null) {
            $bidir = dsm2_bidir_status(false);
        }
        $vStart = $bidir['last_indul'] ?? null;
        $vEnd = $bidir['last_vege'] ?? null;
        $vPoll = (int)($bidir['poll_sec'] ?? 120);
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
    if ($bidirMode) {
        $syncCount = (int)($processes['video_bidir_sync_count'] ?? 0);
        if ($syncCount > 0) {
            $vRunning = true;
        }
        $vDuplicate = $syncCount > 1;
    }
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

    $bidirMode = video_sync_on_dsm2();
    $bidir = $bidirMode ? dsm2_bidir_status(false) : null;
    $processes = process_status($bidir);
    $videoLog = $bidirMode && is_array($bidir)
        ? (string)($bidir['log_tail'] ?? '(DSM2 log nem elérhető)')
        : tail_file(VIDEO_LOG, 35);

    return [
        'time' => date('Y-m-d H:i:s'),
        'sizes_included' => $includeSizes,
        'jobs' => build_jobs_summary($processes, $bidir),
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

const SITE_SPEED_MAX_MB = 50;
const SITE_SPEED_TIMEOUT_SEC = 120;
const SITE_SPEED_LOCK_FILE = '/tmp/sync_site_speed.lock';
const SITE_SPEED_RESULT_FILE = '/tmp/sync_site_speed_result.json';
const SITE_SPEED_HISTORY_FILE = '/tmp/sync_site_speed_history.json';
const SITE_SPEED_HISTORY_MAX = 12;

function site_speed_job_running(): bool
{
    if (!is_readable(SITE_SPEED_LOCK_FILE)) {
        return false;
    }
    $lock = json_decode((string)file_get_contents(SITE_SPEED_LOCK_FILE), true);
    $pid = (int)($lock['pid'] ?? 0);
    if ($pid > 0 && trim(run('kill -0 ' . $pid . ' 2>/dev/null', 1)) === '') {
        return true;
    }
    @unlink(SITE_SPEED_LOCK_FILE);
    return false;
}

/** @return array<string, mixed> */
function site_speed_read_history(): array
{
    if (!is_readable(SITE_SPEED_HISTORY_FILE)) {
        return [];
    }
    $data = json_decode((string)file_get_contents(SITE_SPEED_HISTORY_FILE), true);
    return is_array($data) ? $data : [];
}

/** @param array<string, mixed> $result */
function site_speed_append_history(array $result): void
{
    $history = site_speed_read_history();
    array_unshift($history, [
        'at' => $result['at'] ?? date('c'),
        'label' => $result['label'] ?? '',
        'mb_per_test' => $result['mb_per_test'] ?? null,
        'ssh_edercis_to_bp_mbps' => $result['ssh_edercis_to_bp_mbps'] ?? null,
        'ssh_bp_to_ederics_mbps' => $result['ssh_bp_to_ederics_mbps'] ?? null,
        'http_edercis_to_bp_mbps' => $result['http_edercis_to_bp_mbps'] ?? null,
        'ping_ms' => $result['ping_ms'] ?? [],
    ]);
    $history = array_slice($history, 0, SITE_SPEED_HISTORY_MAX);
    @file_put_contents(SITE_SPEED_HISTORY_FILE, json_encode($history, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/** @return array<string, mixed> */
function start_site_speed_job(int $mb, string $label): array
{
    if (site_speed_job_running()) {
        $lock = json_decode((string)file_get_contents(SITE_SPEED_LOCK_FILE), true) ?: [];
        return [
            'status' => 'running',
            'message' => 'Mérés már fut',
            'label' => $lock['label'] ?? '',
            'started' => $lock['started'] ?? null,
        ];
    }
    $mb = max(5, min(SITE_SPEED_MAX_MB, $mb));
    $label = trim(preg_replace('/[^\p{L}\p{N}\s._()\/-]/u', '', $label) ?? '');
    $php = is_executable('/usr/local/bin/php82') ? '/usr/local/bin/php82' : 'php';
    $worker = __DIR__ . '/run_site_speed.php';
    @unlink(SITE_SPEED_RESULT_FILE);
    $cmd = sprintf(
        'nohup %s %s %d %s >> /tmp/sync_site_speed_worker.log 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($worker),
        $mb,
        escapeshellarg($label)
    );
    run($cmd, 5);
    usleep(300000);
    if (!site_speed_job_running()) {
        throw new RuntimeException('Háttér mérés nem indult el — lásd /tmp/sync_site_speed_worker.log');
    }
    return [
        'status' => 'started',
        'mb' => $mb,
        'label' => $label,
        'message' => 'Mérés háttérben fut — a monitor továbbra is elérhető',
    ];
}

/** @return array<string, mixed> */
function site_speed_poll(): array
{
    if (site_speed_job_running()) {
        $lock = json_decode((string)file_get_contents(SITE_SPEED_LOCK_FILE), true) ?: [];
        return [
            'status' => 'running',
            'label' => $lock['label'] ?? '',
            'started' => $lock['started'] ?? null,
            'mb' => $lock['mb'] ?? null,
        ];
    }
    if (is_readable(SITE_SPEED_RESULT_FILE)) {
        $result = json_decode((string)file_get_contents(SITE_SPEED_RESULT_FILE), true);
        if (is_array($result)) {
            return [
                'status' => (string)($result['status'] ?? 'done'),
                'result' => $result,
                'history' => site_speed_read_history(),
            ];
        }
    }
    return ['status' => 'idle', 'history' => site_speed_read_history()];
}

function ping_ms(string $host): ?float
{
    $t0 = microtime(true);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, 22, $errno, $errstr, 2.0);
    if ($fp === false) {
        return null;
    }
    fclose($fp);
    return round((microtime(true) - $t0) * 1000, 1);
}

function parse_shell_seconds(string $out): ?float
{
    $out = trim($out);
    if ($out === '') {
        return null;
    }
    $lines = preg_split('/\r?\n/', $out) ?: [];
    $last = trim((string)end($lines));
    if (is_numeric($last)) {
        return (float)$last;
    }
    if (preg_match('/real\s+(\d+)m([\d.]+)s/', $out, $m)) {
        return (int)$m[1] * 60 + (float)$m[2];
    }
    if (preg_match('/real\s+([\d.]+)s/', $out, $m)) {
        return (float)$m[1];
    }
    return null;
}

function measure_ssh_throughput(string $direction, int $mb): ?float
{
    $remote = escapeshellarg('sitkeitamas@' . DSM2_HOST);
    $ssh = sprintf(
        'ssh -o BatchMode=yes -o ConnectTimeout=5 -o ServerAliveInterval=5 -p %d %s',
        DSM2_PORT,
        $remote
    );
    if ($direction === 'e2b') {
        $cmd = sprintf(
            'sh -c %s',
            escapeshellarg(sprintf(
                'TIMEFORMAT=%%R; time ( %s "dd if=/dev/zero bs=1M count=%d 2>/dev/null" | dd of=/dev/null bs=1M 2>/dev/null ) 2>&1',
                $ssh,
                $mb
            ))
        );
    } elseif ($direction === 'b2e') {
        $cmd = sprintf(
            'sh -c %s',
            escapeshellarg(sprintf(
                'TIMEFORMAT=%%R; time ( dd if=/dev/zero bs=1M count=%d 2>/dev/null | %s "dd of=/dev/null bs=1M 2>/dev/null" ) 2>&1',
                $mb,
                $ssh
            ))
        );
    } else {
        return null;
    }
    $out = run($cmd, SITE_SPEED_TIMEOUT_SEC + 10);
    $sec = parse_shell_seconds($out);
    if ($sec === null || $sec <= 0) {
        return null;
    }
    return round($mb * 8 / $sec, 1);
}

/** @return array<string, mixed> */
function measure_site_speed(int $mb = 20): array
{
    $mb = max(5, min(SITE_SPEED_MAX_MB, $mb));
    $blobUrl = sprintf(
        'http://%s:%d/speed_blob.php?mb=%d',
        BIND_HOST,
        SPEED_PORT,
        $mb
    );
    $httpMbps = null;
    $curlRemote = sprintf(
        "curl -sS -m %d -o /dev/null -w '%%{speed_download}' %s",
        SITE_SPEED_TIMEOUT_SEC,
        $blobUrl
    );
    $bps = remote_ssh(DSM2_HOST, $curlRemote, 'sitkeitamas', DSM2_PORT, SITE_SPEED_TIMEOUT_SEC + 5);
    if ($bps !== '' && is_numeric($bps)) {
        $httpMbps = round((float)$bps * 8 / 1_000_000, 1);
    }
    return [
        'at' => date('c'),
        'mb_per_test' => $mb,
        'ping_ms' => [
            'bp_beryl' => ping_ms('192.168.5.1'),
            'ederberyl' => ping_ms('192.168.10.1'),
            'naszareti' => ping_ms(DSM2_HOST),
        ],
        'ssh_edercis_to_bp_mbps' => measure_ssh_throughput('e2b', $mb),
        'ssh_bp_to_ederics_mbps' => measure_ssh_throughput('b2e', $mb),
        'http_edercis_to_bp_mbps' => $httpMbps,
        'notes' => [
            'ssh_* = rsync-szerű út (SSH a két NAS között, S2S VPN-en)',
            'http_* = Ederics NAS letölt BP sync monitor blob-ból (8766)',
            'A mérés háttérben fut — a monitor (8765) nem blokkolódik',
        ],
    ];
}
