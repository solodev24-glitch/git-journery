<?php
/**
 * api.php — Hostel Mess Management System
 * PHP 7.2+ compatible | Always returns JSON | Never silent empty response
 *
 * ACTIONS:
 *   GET  ?action=search&account_number=X         → search active student + meals
 *   GET  ?action=get_student&account_number=X    → fetch student for edit form
 *   POST ?action=register_student  (multipart)   → register / upsert student
 *   POST ?action=update_student    (multipart)   → update existing student
 *   POST JSON { action:record_meal, ... }        → log a meal
 */

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        ob_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'data'    => null,
            'errors'  => ['PHP Fatal: ' . $err['message'] . ' (line ' . $err['line'] . ')'],
        ]);
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

set_exception_handler(function ($e) {
    ob_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'data'    => null,
        'errors'  => ['PHP Exception: ' . $e->getMessage()],
    ]);
    exit;
});

// ─── DATABASE — edit these four values ───────────────────────────────────────
$db_host = 'localhost';
$db_user = 'root';   // ← your MySQL username
$db_pass = '';       // ← your MySQL password
$db_name = 'hostel_mess';
// ─────────────────────────────────────────────────────────────────────────────

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'data'    => null,
        'errors'  => [
            'Cannot connect to database "' . $db_name . '".',
            'MySQL says: ' . $conn->connect_error,
            'Fix: edit $db_user / $db_pass at the top of api.php',
        ],
    ]);
    exit;
}
$conn->set_charset('utf8mb4');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_flush();
    exit;
}

// ─── AUTO-CREATE TABLES ───────────────────────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS students (
    id                INT PRIMARY KEY AUTO_INCREMENT,
    account_number    VARCHAR(50)  UNIQUE NOT NULL,
    full_name         VARCHAR(100) NOT NULL,
    email             VARCHAR(100) DEFAULT '',
    phone             VARCHAR(20)  DEFAULT '',
    photo_path        VARCHAR(255) DEFAULT '',
    hostel_name       VARCHAR(100) NOT NULL,
    room_number       VARCHAR(20)  NOT NULL,
    food_preference   ENUM('veg','non-veg') NOT NULL DEFAULT 'veg',
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status            ENUM('active','inactive') DEFAULT 'active'
)");

$conn->query("CREATE TABLE IF NOT EXISTS meal_records (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    student_id     INT         NOT NULL,
    account_number VARCHAR(50) NOT NULL,
    meal_type      VARCHAR(20) NOT NULL,
    food_type      VARCHAR(20) DEFAULT NULL,
    meal_date      DATE        DEFAULT NULL,
    meal_time      DATETIME    NOT NULL,
    created_at     TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
)");

// Add food_type column to existing meal_records table if it doesn't exist
$conn->query("ALTER TABLE meal_records ADD COLUMN IF NOT EXISTS food_type VARCHAR(20) DEFAULT NULL AFTER meal_type");

// Add meal_date column to existing meal_records table if it doesn't exist
$conn->query("ALTER TABLE meal_records ADD COLUMN IF NOT EXISTS meal_date DATE DEFAULT NULL AFTER food_type");

// ─── ROUTER ───────────────────────────────────────────────────────────────────
$method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');

if ($method === 'GET') {

    $action  = isset($_GET['action'])         ? trim($_GET['action'])         : '';
    $account = isset($_GET['account_number']) ? trim($_GET['account_number']) : '';

    if ($action === 'search') {
        doSearch($conn, $account);
    } elseif ($action === 'get_student') {
        doGetStudent($conn, $account);
    } elseif ($action === 'get_meals') {
        doGetMeals($conn, $account);
    } elseif ($action === 'get_all_meals') {
        doGetAllMeals($conn);
    } elseif ($action === 'delete_meal') {
        $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        doDeleteMeal($conn, $id);
    } else {
        sendJson(false, null, ['Unknown GET action: ' . $action], 400);
    }

} elseif ($method === 'POST') {

    $ct     = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    $isJson = strpos($ct, 'application/json') !== false;

    if ($isJson) {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) sendJson(false, null, ['Invalid JSON body.'], 400);

        $action = isset($body['action']) ? trim($body['action']) : '';

        if ($action === 'record_meal') {
            doRecordMeal($conn, $body);
        } else {
            sendJson(false, null, ['Unknown JSON action: ' . $action], 400);
        }

    } else {
        $action = '';
        if (!empty($_GET['action']))      $action = trim($_GET['action']);
        elseif (!empty($_POST['action'])) $action = trim($_POST['action']);

        if ($action === 'register_student') {
            doRegister($conn);
        } elseif ($action === 'update_student') {
            doUpdateStudent($conn);
        } else {
            sendJson(false, null, ['Unknown form action: ' . $action], 400);
        }
    }

} else {
    sendJson(false, null, ['Method not allowed.'], 405);
}

ob_end_flush();
exit;


// =============================================================================
// GET: search  — active student + today's meals
// =============================================================================
function doSearch($conn, $account)
{
    if ($account === '') sendJson(false, null, ['Account number is required.'], 400);

    $stmt = $conn->prepare(
        "SELECT id, account_number, full_name, email, phone, photo_path,
                hostel_name, room_number, food_preference, registration_date
         FROM students
         WHERE account_number = ? AND status = 'active'
         LIMIT 1"
    );
    if (!$stmt) sendJson(false, null, ['DB error: ' . $conn->error], 500);

    $stmt->bind_param('s', $account);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        sendJson(false, null, ['No active student found with that account number.'], 404);
    }

    $student = $result->fetch_assoc();
    $stmt->close();

    if (empty($student['photo_path']) || !file_exists($student['photo_path'])) {
        $student['photo_path'] = 'assets/images/default-profile.png';
    }
    if (!empty($student['registration_date'])) {
        $student['registration_date'] = date('F j, Y', strtotime($student['registration_date']));
    }

    sendJson(true, [
        'student'     => $student,
        'meals_today' => getTodayMeals($conn, (int)$student['id']),
    ]);
}


// =============================================================================
// GET: get_student  — full record for edit form
// =============================================================================
function doGetStudent($conn, $account)
{
    if ($account === '') sendJson(false, null, ['account_number is required.'], 400);

    $stmt = $conn->prepare(
        "SELECT id, account_number, full_name, email, phone,
                photo_path, hostel_name, room_number,
                food_preference, registration_date, status
         FROM students WHERE account_number = ? LIMIT 1"
    );
    if (!$stmt) sendJson(false, null, ['DB error: ' . $conn->error], 500);

    $stmt->bind_param('s', $account);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) sendJson(false, null, ['Student not found.'], 404);

    if (empty($student['photo_path']) || !file_exists($student['photo_path'])) {
        $student['photo_path'] = 'assets/images/default-profile.png';
    }

    sendJson(true, $student);
}


// =============================================================================
// POST: register_student  — insert/upsert
// =============================================================================
function doRegister($conn)
{
    $account = isset($_POST['account_number'])  ? trim($_POST['account_number'])  : '';
    $name    = isset($_POST['full_name'])        ? trim($_POST['full_name'])        : '';
    $email   = isset($_POST['email'])            ? trim($_POST['email'])            : '';
    $phone   = isset($_POST['phone'])            ? trim($_POST['phone'])            : '';
    $hostel  = isset($_POST['hostel_name'])      ? trim($_POST['hostel_name'])      : '';
    $room    = isset($_POST['room_number'])      ? trim($_POST['room_number'])      : '';
    $food    = isset($_POST['food_preference'])  ? trim($_POST['food_preference'])  : 'veg';

    $errors = [];
    if ($account === '') $errors[] = 'Account number is required.';
    if ($name    === '') $errors[] = 'Full name is required.';
    if ($hostel  === '') $errors[] = 'Hostel name is required.';
    if ($room    === '') $errors[] = 'Room number is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) $errors[] = 'Enter a valid phone.';
    if (!in_array($food, ['veg', 'non-veg'], true)) $food = 'veg';

    $photo = uploadPhoto($account, $errors);
    if (!empty($errors)) sendJson(false, null, $errors, 422);

    $sql = "INSERT INTO students
              (account_number, full_name, email, phone, photo_path, hostel_name, room_number, food_preference)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              full_name       = VALUES(full_name),
              email           = VALUES(email),
              phone           = VALUES(phone),
              photo_path      = IF(VALUES(photo_path) != '', VALUES(photo_path), photo_path),
              hostel_name     = VALUES(hostel_name),
              room_number     = VALUES(room_number),
              food_preference = VALUES(food_preference),
              status          = 'active'";

    $stmt = $conn->prepare($sql);
    if (!$stmt) sendJson(false, null, ['DB error: ' . $conn->error], 500);
    $stmt->bind_param('ssssssss', $account, $name, $email, $phone, $photo, $hostel, $room, $food);
    if (!$stmt->execute()) sendJson(false, null, ['DB error: ' . $stmt->error], 500);
    $newId = $stmt->insert_id ? $stmt->insert_id : null;
    $stmt->close();

    sendJson(true, [
        'message'        => 'Student registered successfully.',
        'account_number' => $account,
        'id'             => $newId,
    ]);
}


// =============================================================================
// POST: update_student  — UPDATE existing record
// =============================================================================
function doUpdateStudent($conn)
{
    $account = isset($_POST['account_number'])  ? trim($_POST['account_number'])  : '';
    $name    = isset($_POST['full_name'])        ? trim($_POST['full_name'])        : '';
    $email   = isset($_POST['email'])            ? trim($_POST['email'])            : '';
    $phone   = isset($_POST['phone'])            ? trim($_POST['phone'])            : '';
    $hostel  = isset($_POST['hostel_name'])      ? trim($_POST['hostel_name'])      : '';
    $room    = isset($_POST['room_number'])      ? trim($_POST['room_number'])      : '';
    $food    = isset($_POST['food_preference'])  ? trim($_POST['food_preference'])  : 'veg';

    $errors = [];
    if ($account === '') $errors[] = 'Account number is required.';
    if ($name    === '') $errors[] = 'Full name is required.';
    if ($hostel  === '') $errors[] = 'Hostel name is required.';
    if ($room    === '') $errors[] = 'Room number is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $phone)) $errors[] = 'Enter a valid phone.';
    if (!in_array($food, ['veg', 'non-veg'], true)) $food = 'veg';

    // Verify student exists and grab current photo
    $check = $conn->prepare("SELECT id, photo_path FROM students WHERE account_number = ? LIMIT 1");
    if (!$check) sendJson(false, null, ['DB error: ' . $conn->error], 500);
    $check->bind_param('s', $account);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$existing) sendJson(false, null, ['Student not found. Cannot update.'], 404);

    // New photo if uploaded, else keep the old one
    $newPhoto = uploadPhoto($account, $errors);
    $photo    = ($newPhoto !== '') ? $newPhoto : $existing['photo_path'];

    if (!empty($errors)) sendJson(false, null, $errors, 422);

    $stmt = $conn->prepare(
        "UPDATE students
         SET full_name = ?, email = ?, phone = ?, photo_path = ?,
             hostel_name = ?, room_number = ?, food_preference = ?
         WHERE account_number = ?"
    );
    if (!$stmt) sendJson(false, null, ['DB error: ' . $conn->error], 500);

    $stmt->bind_param('ssssssss', $name, $email, $phone, $photo, $hostel, $room, $food, $account);
    if (!$stmt->execute()) sendJson(false, null, ['DB error: ' . $stmt->error], 500);
    $stmt->close();

    sendJson(true, [
        'message'        => 'Student updated successfully.',
        'account_number' => $account,
    ]);
}


// =============================================================================
// POST JSON: record_meal
// =============================================================================
function doRecordMeal($conn, $body)
{
    $account    = isset($body['account_number']) ? trim($body['account_number']) : '';
    $meal_type  = isset($body['meal_type'])      ? trim($body['meal_type'])      : '';
    $click_time = isset($body['click_time'])     ? trim($body['click_time'])     : date('Y-m-d H:i:s');

    $errors = [];
    if ($account === '') $errors[] = 'account_number is required.';
    if (!in_array($meal_type, ['breakfast', 'lunch', 'dinner'], true)) {
        $errors[] = 'meal_type must be breakfast, lunch, or dinner.';
    }
    if (!empty($errors)) sendJson(false, null, $errors, 422);
    if (!strtotime($click_time)) $click_time = date('Y-m-d H:i:s');

    $stmt = $conn->prepare(
        "SELECT id, food_preference FROM students WHERE account_number = ? AND status = 'active' LIMIT 1"
    );
    if (!$stmt) sendJson(false, null, ['DB error: ' . $conn->error], 500);
    $stmt->bind_param('s', $account);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) sendJson(false, null, ['Student not found.'], 404);

    $sid   = (int)$row['id'];
    $food  = $row['food_preference'];
    $today = date('Y-m-d');

    // Check for duplicate - use the date from click_time to ensure consistency
    $recordDate = date('Y-m-d', strtotime($click_time));
    $dup = $conn->prepare(
        "SELECT id FROM meal_records WHERE student_id = ? AND meal_type = ? AND DATE(meal_time) = ? LIMIT 1"
    );
    if (!$dup) sendJson(false, null, ['DB error: ' . $conn->error], 500);
    $dup->bind_param('iss', $sid, $meal_type, $recordDate);
    $dup->execute();
    $found = $dup->get_result()->num_rows;
    $dup->close();
    if ($found > 0) {
        sendJson(false, null, [ucfirst($meal_type) . ' already recorded for ' . $recordDate . '.'], 409);
    }

    $ins = $conn->prepare(
        "INSERT INTO meal_records (student_id, account_number, meal_type, food_type, meal_time, meal_date)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if (!$ins) sendJson(false, null, ['DB error: ' . $conn->error], 500);
    $ins->bind_param('isssss', $sid, $account, $meal_type, $food, $click_time, $recordDate);
    if (!$ins->execute()) {
        if ($conn->errno == 1062) {
            sendJson(false, null, [ucfirst($meal_type) . ' already recorded for today.'], 409);
        }
        sendJson(false, null, ['Insert failed: ' . $ins->error], 500);
    }
    $ins->close();

    $mealId = $conn->insert_id;
    sendJson(true, [
        'id'        => $mealId,
        'message'   => ucfirst($meal_type) . ' recorded at ' . date('h:i A', strtotime($click_time)),
        'meal_type' => $meal_type,
        'food_type' => $food,
        'time'      => $click_time,
    ]);
}


// =============================================================================
// HELPER: handle photo upload — returns saved path or ''
// =============================================================================
function uploadPhoto($account, &$errors)
{
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Photo upload error (code ' . $f['error'] . ').';
        return '';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        $errors[] = 'Photo must be PNG, JPG, or WEBP.';
        return '';
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        $errors[] = 'Photo must be under 5 MB.';
        return '';
    }
    $dir = __DIR__ . '/assets/images';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $account);
    $dest = $dir . '/' . $safe . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        $errors[] = 'Could not save photo. Check folder permissions on assets/images/';
        return '';
    }
    return 'assets/images/' . basename($dest);
}


// =============================================================================
// HELPER: today's meal status for a student
// =============================================================================
function getTodayMeals($conn, $student_id)
{
    $meals = ['breakfast' => false, 'lunch' => false, 'dinner' => false];
    $today = date('Y-m-d');
    $stmt  = $conn->prepare(
        "SELECT meal_type FROM meal_records WHERE student_id = ? AND DATE(meal_time) = ?"
    );
    if (!$stmt) return $meals;
    $stmt->bind_param('is', $student_id, $today);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        if (isset($meals[$r['meal_type']])) $meals[$r['meal_type']] = true;
    }
    $stmt->close();
    return $meals;
}


// =============================================================================
// GET: get_meals — get all meal records for a student
// =============================================================================
function doGetMeals($conn, $account)
{
    if ($account === '') sendJson(false, null, ['Account number is required.'], 400);

    $stmt = $conn->prepare(
        "SELECT mr.id, mr.meal_type, mr.food_type, mr.meal_time, mr.created_at
         FROM meal_records mr
         JOIN students s ON mr.student_id = s.id
         WHERE mr.account_number = ?
         ORDER BY mr.meal_time DESC"
    );
    if (!$stmt) sendJson(false, null, ['DB error: ' . $conn->error], 500);

    $stmt->bind_param('s', $account);
    $stmt->execute();
    $result = $stmt->get_result();

    $meals = [];
    while ($row = $result->fetch_assoc()) {
        $meals[] = $row;
    }
    $stmt->close();

    sendJson(true, ['meals' => $meals, 'count' => count($meals)]);
}

// =============================================================================
// GET: get_all_meals — get all meal records (for reports)
// =============================================================================
function doGetAllMeals($conn)
{
    $stmt = $conn->prepare(
        "SELECT mr.id, mr.account_number, mr.meal_type, mr.food_type, mr.meal_time, mr.created_at,
                s.full_name as student_name
         FROM meal_records mr
         JOIN students s ON mr.student_id = s.id
         ORDER BY mr.meal_time DESC"
    );
    if (!$stmt) sendJson(false, null, ['DB error: ' . $conn->error], 500);

    $stmt->execute();
    $result = $stmt->get_result();

    $meals = [];
    while ($row = $result->fetch_assoc()) {
        $meals[] = $row;
    }
    $stmt->close();

    sendJson(true, ['meals' => $meals, 'count' => count($meals)]);
}

// =============================================================================
// GET: delete_meal — delete a meal record
// =============================================================================
function doDeleteMeal($conn, $id)
{
    if ($id <= 0) sendJson(false, null, ['Meal ID is required.'], 400);

    $stmt = $conn->prepare("DELETE FROM meal_records WHERE id = ?");
    if (!$stmt) sendJson(false, null, ['DB error: ' . $conn->error], 500);

    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            sendJson(true, ['message' => 'Meal record deleted successfully.']);
        } else {
            sendJson(false, null, ['Meal record not found.'], 404);
        }
    } else {
        sendJson(false, null, ['Error deleting meal: ' . $stmt->error], 500);
    }
    $stmt->close();
}

// =============================================================================
// HELPER: send JSON response and exit
// =============================================================================
function sendJson($success, $data, $errors = [], $code = 200)
{
    ob_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code($code);
    }
    echo json_encode([
        'success' => (bool)$success,
        'data'    => $data,
        'errors'  => (array)$errors,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?>