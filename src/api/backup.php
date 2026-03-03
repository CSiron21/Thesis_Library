<?php
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

/* CSRF validation on restore (POST) */
if ($method === 'POST') {
    validateCsrf();
}

try {
    if ($method === 'GET' && $action === 'backup') {
        backupDatabase($dbConfig);
    } elseif ($method === 'POST' && $action === 'restore') {
        restoreDatabase($dbConfig);
    } else {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid action. Use ?action=backup or ?action=restore']);
    }
} catch (Exception $e) {
    error_log('Backup Error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Backup operation failed']);
}

function backupDatabase($config) {
    $timestamp = date('Y-m-d_His');
    $filename  = "thesis_library_backup_{$timestamp}.sql";

    $cmd = sprintf(
        'mysqldump --host=%s --user=%s --password=%s --skip-ssl --hex-blob --single-transaction --routines --triggers %s',
        escapeshellarg($config['host']),
        escapeshellarg($config['user']),
        escapeshellarg($config['pass']),
        escapeshellarg($config['name'])
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new Exception('Failed to run mysqldump');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new Exception('mysqldump failed: ' . trim($errors));
    }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($output));
    echo $output;
    exit;
}

function restoreDatabase($config) {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No backup file uploaded or upload error');
    }

    $file = $_FILES['backup_file'];

    /* Accept .sql files only */
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'sql') {
        throw new Exception('Only .sql files are accepted');
    }

    $sql = file_get_contents($file['tmp_name']);
    if ($sql === false || trim($sql) === '') {
        throw new Exception('Backup file is empty or unreadable');
    }

    $cmd = sprintf(
        'mysql --host=%s --user=%s --password=%s --skip-ssl %s',
        escapeshellarg($config['host']),
        escapeshellarg($config['user']),
        escapeshellarg($config['pass']),
        escapeshellarg($config['name'])
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new Exception('Failed to run mysql restore');
    }

    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new Exception('Restore failed: ' . trim($errors));
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Database restored successfully']);
    exit;
}
