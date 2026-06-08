<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

deny_if_external();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';

try {
    switch ($action) {
        case 'status':
            echo json_encode(build_status(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            break;

        case 'save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('POST kell');
            }
            if (isset($_POST['env']) && is_array($_POST['env'])) {
                save_env($_POST['env']);
            }
            if (isset($_POST['homes_env']) && is_array($_POST['homes_env'])) {
                save_homes_env($_POST['homes_env']);
            }
            if (isset($_POST['folders_conf'])) {
                save_folders((string)$_POST['folders_conf']);
            }
            if (isset($_POST['homes_folders_conf'])) {
                save_homes_folders((string)$_POST['homes_folders_conf']);
            }
            echo json_encode(['ok' => true, 'message' => 'Mentve (backup: .bak.*)']);
            break;

        case 'control':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new RuntimeException('POST kell');
            }
            $cmd = $_POST['cmd'] ?? '';
            if (!in_array($cmd, ['start', 'stop', 'restart', 'sync_now', 'sync_homes_now'], true)) {
                throw new RuntimeException('Érvénytelen parancs');
            }
            $out = run_action($cmd);
            echo json_encode(['ok' => true, 'output' => $out]);
            break;

        case 'docs_list':
            echo json_encode(['files' => list_docs()], JSON_UNESCAPED_UNICODE);
            break;

        case 'doc':
            $name = $_GET['name'] ?? 'README.md';
            echo json_encode([
                'name' => $name,
                'content' => read_doc($name),
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new RuntimeException('Ismeretlen action');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
