<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

/* ===== CSRF validation on write operations ===== */
if (in_array($method, ['POST', 'DELETE'])) {
    validateCsrf();
}

try {
    switch ($method) {
        case 'GET':
            if ($action === 'image' && isset($_GET['id'])) {
                serveImage($pdo, intval($_GET['id']));
            } elseif ($action === 'csrf') {
                respond(200, ['success' => true, 'data' => ['token' => csrfToken()]]);
            } elseif (isset($_GET['id'])) {
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
                respond(400, ['success' => false, 'error' => 'ID is required']);
            }
            break;
        default:
            respond(405, ['success' => false, 'error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    error_log('DB Error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => 'A database error occurred']);
} catch (Exception $e) {
    error_log('App Error: ' . $e->getMessage());
    respond(500, ['success' => false, 'error' => $e->getMessage()]);
}

/* ===== Helpers ===== */

function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function handleBlobUpload() {
    if (!isset($_FILES['front_page']) || $_FILES['front_page']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES['front_page'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    /* SEC-5: Enforce 5 MB max file size */
    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File too large. Maximum size is 5 MB');
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed)) {
        throw new Exception('Invalid file type. Allowed: JPEG, PNG, WebP, GIF');
    }

    $data = file_get_contents($file['tmp_name']);
    if ($data === false) {
        throw new Exception('Failed to read uploaded file');
    }

    return ['data' => $data, 'mime' => $mime];
}

function validateRequired($fields) {
    $missing = [];
    foreach ($fields as $label => $value) {
        if (trim($value ?? '') === '') {
            $missing[] = $label;
        }
    }
    if ($missing) {
        respond(400, ['success' => false, 'error' => implode(', ', $missing) . ' required']);
    }
}

/**
 * BUG-3/BUG-4: Validate field lengths and year range.
 */
function validateFields($title, $adviser, $year) {
    $errors = [];
    if (mb_strlen($title) > 500) {
        $errors[] = 'Title must be 500 characters or fewer';
    }
    if (mb_strlen($adviser) > 255) {
        $errors[] = 'Thesis Adviser must be 255 characters or fewer';
    }
    $yearInt = intval($year);
    if ($yearInt < 1900 || $yearInt > 2099) {
        $errors[] = 'Year must be between 1900 and 2099';
    }
    if ($errors) {
        respond(400, ['success' => false, 'error' => implode('; ', $errors)]);
    }
}

/* ===== Image Serving ===== */

function serveImage($pdo, $id) {
    /* PERF-1: Use streaming to avoid loading full BLOB into PHP memory */
    $stmt = $pdo->prepare('SELECT front_page_mime, OCTET_LENGTH(front_page_data) AS img_size FROM theses WHERE id = ? AND front_page_data IS NOT NULL');
    $stmt->execute([$id]);
    $meta = $stmt->fetch();

    if (!$meta) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'Image not found';
        exit;
    }

    header('Content-Type: ' . $meta['front_page_mime']);
    header('Content-Length: ' . $meta['img_size']);
    header('Cache-Control: public, max-age=86400');

    /* Stream in chunks via PDO::PARAM_LOB */
    $stmt2 = $pdo->prepare('SELECT front_page_data FROM theses WHERE id = ?');
    $stmt2->execute([$id]);
    $stmt2->bindColumn(1, $lob, PDO::PARAM_LOB);
    $stmt2->fetch(PDO::FETCH_BOUND);
    if (is_resource($lob)) {
        fpassthru($lob);
    } else {
        echo $lob;
    }
    exit;
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

    /* Select — exclude BLOB, include has_front_page flag */
    $cols = "id, title, (front_page_data IS NOT NULL) AS has_front_page, year, proponents, panelists, thesis_adviser, created_at $extraSelect";
    $allParams = array_merge($extraSelectParams, $whereParams);
    $stmt = $pdo->prepare("SELECT $cols FROM theses $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
    $stmt->execute($allParams);

    respond(200, [
        'success' => true,
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
    $stmt = $pdo->prepare('SELECT id, title, abstract, (front_page_data IS NOT NULL) AS has_front_page, year, proponents, panelists, thesis_adviser, created_at, updated_at FROM theses WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        respond(404, ['success' => false, 'error' => 'Thesis not found']);
    }
    respond(200, ['success' => true, 'data' => $row]);
}

function getFilterOptions($pdo) {
    $years    = $pdo->query('SELECT DISTINCT year FROM theses ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN);
    $advisers = $pdo->query('SELECT DISTINCT thesis_adviser FROM theses ORDER BY thesis_adviser ASC')->fetchAll(PDO::FETCH_COLUMN);
    respond(200, ['success' => true, 'years' => $years, 'advisers' => $advisers]);
}

function createThesis($pdo) {
    $title   = trim($_POST['title'] ?? '');
    $abstract = trim($_POST['abstract'] ?? '');
    $year    = $_POST['year'] ?? '';
    $proponents = trim($_POST['proponents'] ?? '');
    $panelists  = trim($_POST['panelists'] ?? '');
    $adviser = trim($_POST['thesis_adviser'] ?? '');

    validateRequired([
        'Title'          => $title,
        'Abstract'       => $abstract,
        'Year'           => $year,
        'Proponents'     => $proponents,
        'Panelists'      => $panelists,
        'Thesis Adviser' => $adviser
    ]);
    validateFields($title, $adviser, $year);

    $blob = handleBlobUpload();
    $stmt = $pdo->prepare(
        'INSERT INTO theses (title, abstract, front_page_data, front_page_mime, year, proponents, panelists, thesis_adviser) VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $title,
        $abstract,
        $blob ? $blob['data'] : null,
        $blob ? $blob['mime'] : null,
        intval($year),
        $proponents,
        $panelists,
        $adviser
    ]);
    getThesis($pdo, intval($pdo->lastInsertId()));
}

function updateThesis($pdo) {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
        respond(400, ['success' => false, 'error' => 'Valid ID is required']);
    }

    $stmt = $pdo->prepare('SELECT id, (front_page_data IS NOT NULL) AS has_front_page FROM theses WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        respond(404, ['success' => false, 'error' => 'Thesis not found']);
    }

    $title   = trim($_POST['title'] ?? '');
    $abstract = trim($_POST['abstract'] ?? '');
    $year    = $_POST['year'] ?? '';
    $proponents = trim($_POST['proponents'] ?? '');
    $panelists  = trim($_POST['panelists'] ?? '');
    $adviser = trim($_POST['thesis_adviser'] ?? '');

    validateRequired([
        'Title'          => $title,
        'Abstract'       => $abstract,
        'Year'           => $year,
        'Proponents'     => $proponents,
        'Panelists'      => $panelists,
        'Thesis Adviser' => $adviser
    ]);
    validateFields($title, $adviser, $year);

    $blob = handleBlobUpload();
    $removeImage = isset($_POST['remove_image']) && $_POST['remove_image'] === '1';

    if ($blob) {
        /* New image uploaded — replace */
        $stmt = $pdo->prepare(
            'UPDATE theses SET title=?, abstract=?, front_page_data=?, front_page_mime=?, year=?, proponents=?, panelists=?, thesis_adviser=? WHERE id=?'
        );
        $stmt->execute([
            $title,
            $abstract,
            $blob['data'],
            $blob['mime'],
            intval($year),
            $proponents,
            $panelists,
            $adviser,
            $id
        ]);
    } elseif ($removeImage) {
        /* Remove existing image */
        $stmt = $pdo->prepare(
            'UPDATE theses SET title=?, abstract=?, front_page_data=NULL, front_page_mime=NULL, year=?, proponents=?, panelists=?, thesis_adviser=? WHERE id=?'
        );
        $stmt->execute([
            $title,
            $abstract,
            intval($year),
            $proponents,
            $panelists,
            $adviser,
            $id
        ]);
    } else {
        /* Keep existing image — don't touch BLOB columns */
        $stmt = $pdo->prepare(
            'UPDATE theses SET title=?, abstract=?, year=?, proponents=?, panelists=?, thesis_adviser=? WHERE id=?'
        );
        $stmt->execute([
            $title,
            $abstract,
            intval($year),
            $proponents,
            $panelists,
            $adviser,
            $id
        ]);
    }
    getThesis($pdo, $id);
}

function deleteThesis($pdo, $id) {
    $stmt = $pdo->prepare('SELECT id FROM theses WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        respond(404, ['success' => false, 'error' => 'Thesis not found']);
    }

    $pdo->prepare('DELETE FROM theses WHERE id = ?')->execute([$id]);
    respond(200, ['success' => true, 'message' => 'Thesis deleted']);
}
