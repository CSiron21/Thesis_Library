<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id'])) {
                getThesis($pdo, intval($_GET['id']));
            } elseif ($action === 'filters') {
                getFilterOptions($pdo);
            } else {
                listTheses($pdo);
            }
            break;
        case 'POST':
            if ($action === 'update') {
                updateThesis($pdo);
            } else {
                createThesis($pdo);
            }
            break;
        case 'DELETE':
            if (isset($_GET['id'])) {
                deleteThesis($pdo, intval($_GET['id']));
            } else {
                respond(400, ['error' => 'ID is required']);
            }
            break;
        default:
            respond(405, ['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    respond(500, ['error' => 'Database error']);
} catch (Exception $e) {
    respond(500, ['error' => $e->getMessage()]);
}

/* ===== Helpers ===== */

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function handleUpload() {
    if (!isset($_FILES['front_page']) || $_FILES['front_page']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES['front_page'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed)) {
        throw new Exception('Invalid file type. Allowed: JPEG, PNG, WebP, GIF');
    }

    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception('File too large. Max: 10 MB');
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg'
    };
    $filename = uniqid('thesis_', true) . '.' . $ext;
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        throw new Exception('Failed to save file');
    }
    return 'uploads/' . $filename;
}

function deleteFile($path) {
    if ($path) {
        $full = __DIR__ . '/../' . $path;
        if (file_exists($full)) {
            unlink($full);
        }
    }
}

function validateRequired($fields) {
    $missing = [];
    foreach ($fields as $label => $value) {
        if (trim($value ?? '') === '') {
            $missing[] = $label;
        }
    }
    if ($missing) {
        respond(400, ['error' => implode(', ', $missing) . ' required']);
    }
}

/* ===== CRUD ===== */

function listTheses($pdo) {
    $page    = max(1, intval($_GET['page'] ?? 1));
    $perPage = max(1, min(50, intval($_GET['per_page'] ?? 12)));
    $search  = trim($_GET['search'] ?? '');

    $yearFrom  = $_GET['year_from'] ?? '';
    $yearTo    = $_GET['year_to'] ?? '';
    $adviser   = trim($_GET['adviser'] ?? '');
    $proponent = trim($_GET['proponent'] ?? '');
    $panelist  = trim($_GET['panelist'] ?? '');

    $whereParts  = [];
    $whereParams = [];
    $extraSelect = '';
    $extraSelectParams = [];
    $orderBy = 'year DESC, title ASC';

    /* Full-text / LIKE search */
    if ($search !== '') {
        $words = preg_split('/\s+/', $search);
        $hasLong = false;
        foreach ($words as $w) {
            if (mb_strlen(preg_replace('/[+\-><()~*\"@]/', '', $w)) >= 3) {
                $hasLong = true;
                break;
            }
        }
        if ($hasLong) {
            $boolTerms = [];
            foreach ($words as $w) {
                $clean = preg_replace('/[+\-><()~*\"@]/', '', $w);
                if ($clean !== '') {
                    $boolTerms[] = '+' . $clean . '*';
                }
            }
            $term = implode(' ', $boolTerms);
            $extraSelect = ', MATCH(title, abstract) AGAINST(? IN BOOLEAN MODE) AS relevance';
            $extraSelectParams[] = $term;
            $whereParts[]  = 'MATCH(title, abstract) AGAINST(? IN BOOLEAN MODE)';
            $whereParams[] = $term;
            $orderBy = 'relevance DESC, year DESC';
        } else {
            $like = '%' . $search . '%';
            $whereParts[]  = '(title LIKE ? OR abstract LIKE ?)';
            $whereParams[] = $like;
            $whereParams[] = $like;
        }
    }

    /* Filters */
    if ($yearFrom !== '') {
        $whereParts[]  = 'year >= ?';
        $whereParams[] = intval($yearFrom);
    }
    if ($yearTo !== '') {
        $whereParts[]  = 'year <= ?';
        $whereParams[] = intval($yearTo);
    }
    if ($adviser !== '') {
        $whereParts[]  = 'thesis_adviser = ?';
        $whereParams[] = $adviser;
    }
    if ($proponent !== '') {
        $whereParts[]  = 'proponents LIKE ?';
        $whereParams[] = '%' . $proponent . '%';
    }
    if ($panelist !== '') {
        $whereParts[]  = 'panelists LIKE ?';
        $whereParams[] = '%' . $panelist . '%';
    }

    $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

    /* Count */
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM theses $where");
    $countStmt->execute($whereParams);
    $total = intval($countStmt->fetchColumn());

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page   = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    /* Select */
    $cols = "id, title, front_page, year, proponents, panelists, thesis_adviser, created_at $extraSelect";
    $allParams = array_merge($extraSelectParams, $whereParams);
    $stmt = $pdo->prepare("SELECT $cols FROM theses $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
    $stmt->execute($allParams);

    respond(200, [
        'data' => $stmt->fetchAll(),
        'pagination' => [
            'current_page' => $page,
            'total_pages'  => $totalPages,
            'per_page'     => $perPage,
            'total'        => $total
        ]
    ]);
}

function getThesis($pdo, $id) {
    $stmt = $pdo->prepare('SELECT * FROM theses WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(404, ['error' => 'Thesis not found']);
    }
    respond(200, ['data' => $row]);
}

function getFilterOptions($pdo) {
    $years    = $pdo->query('SELECT DISTINCT year FROM theses ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN);
    $advisers = $pdo->query('SELECT DISTINCT thesis_adviser FROM theses ORDER BY thesis_adviser ASC')->fetchAll(PDO::FETCH_COLUMN);
    respond(200, ['years' => $years, 'advisers' => $advisers]);
}

function createThesis($pdo) {
    validateRequired([
        'Title'          => $_POST['title'] ?? '',
        'Abstract'       => $_POST['abstract'] ?? '',
        'Year'           => $_POST['year'] ?? '',
        'Proponents'     => $_POST['proponents'] ?? '',
        'Panelists'      => $_POST['panelists'] ?? '',
        'Thesis Adviser' => $_POST['thesis_adviser'] ?? ''
    ]);

    $frontPage = handleUpload();
    $stmt = $pdo->prepare(
        'INSERT INTO theses (title, abstract, front_page, year, proponents, panelists, thesis_adviser) VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        trim($_POST['title']),
        trim($_POST['abstract']),
        $frontPage,
        intval($_POST['year']),
        trim($_POST['proponents']),
        trim($_POST['panelists']),
        trim($_POST['thesis_adviser'])
    ]);
    getThesis($pdo, intval($pdo->lastInsertId()));
}

function updateThesis($pdo) {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond(400, ['error' => 'Valid ID is required']);
    }

    $stmt = $pdo->prepare('SELECT * FROM theses WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        respond(404, ['error' => 'Thesis not found']);
    }

    validateRequired([
        'Title'          => $_POST['title'] ?? '',
        'Abstract'       => $_POST['abstract'] ?? '',
        'Year'           => $_POST['year'] ?? '',
        'Proponents'     => $_POST['proponents'] ?? '',
        'Panelists'      => $_POST['panelists'] ?? '',
        'Thesis Adviser' => $_POST['thesis_adviser'] ?? ''
    ]);

    $frontPage = handleUpload();

    if ($frontPage !== null) {
        deleteFile($existing['front_page']);
    } else {
        $frontPage = $existing['front_page'];
    }

    if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
        deleteFile($existing['front_page']);
        $frontPage = null;
    }

    $stmt = $pdo->prepare(
        'UPDATE theses SET title=?, abstract=?, front_page=?, year=?, proponents=?, panelists=?, thesis_adviser=? WHERE id=?'
    );
    $stmt->execute([
        trim($_POST['title']),
        trim($_POST['abstract']),
        $frontPage,
        intval($_POST['year']),
        trim($_POST['proponents']),
        trim($_POST['panelists']),
        trim($_POST['thesis_adviser']),
        $id
    ]);
    getThesis($pdo, $id);
}

function deleteThesis($pdo, $id) {
    $stmt = $pdo->prepare('SELECT front_page FROM theses WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(404, ['error' => 'Thesis not found']);
    }

    deleteFile($row['front_page']);
    $pdo->prepare('DELETE FROM theses WHERE id = ?')->execute([$id]);
    respond(200, ['message' => 'Thesis deleted']);
}
