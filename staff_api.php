<?php
/**
 * staff_api.php - Staff Management and Feedback API
 * Handles: staff CRUD, feedback submission, feedback listing
 */

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    // Staff Management
    case 'get_staff':
        getStaff($conn);
        break;
    case 'add_staff':
        addStaff($conn);
        break;
    case 'update_staff':
        updateStaff($conn);
        break;
    case 'delete_staff':
        deleteStaff($conn);
        break;
        
    // Feedback
    case 'submit_feedback':
        submitFeedback($conn);
        break;
    case 'get_feedback':
        getFeedback($conn);
        break;
    case 'delete_feedback':
        deleteFeedback($conn);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

// ========== STAFF FUNCTIONS ==========

function getStaff($conn) {
    $sql = "SELECT id, full_name, role, phone, email, food_preference, joined_at, active 
            FROM staff 
            WHERE active = 'yes' 
            ORDER BY full_name";
    $result = $conn->query($sql);
    
    $staff = [];
    while ($row = $result->fetch_assoc()) {
        $staff[] = [
            'id' => $row['id'],
            'name' => $row['full_name'],
            'role' => $row['role'],
            'phone' => $row['phone'] ?? 'N/A',
            'email' => $row['email'] ?? 'N/A',
            'foodPreference' => $row['food_preference'],
            'joined' => $row['joined_at'],
            'status' => $row['active'] === 'yes' ? 'active' : 'inactive'
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $staff]);
}

function addStaff($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = $conn->real_escape_string($data['name'] ?? '');
    $role = $conn->real_escape_string($data['role'] ?? '');
    $phone = $conn->real_escape_string($data['phone'] ?? '');
    $email = $conn->real_escape_string($data['email'] ?? '');
    $foodPreference = $conn->real_escape_string($data['foodPreference'] ?? 'veg');
    $joined = $conn->real_escape_string($data['joined'] ?? date('Y-m-d'));
    
    if (empty($name) || empty($role)) {
        echo json_encode(['success' => false, 'message' => 'Name and role are required']);
        return;
    }
    
    $sql = "INSERT INTO staff (full_name, role, phone, email, food_preference, joined_at, active) 
            VALUES (?, ?, ?, ?, ?, ?, 'yes')";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssss', $name, $role, $phone, $email, $foodPreference, $joined);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Staff added successfully', 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error adding staff: ' . $conn->error]);
    }
}

function updateStaff($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($data['id'] ?? 0);
    $name = $conn->real_escape_string($data['name'] ?? '');
    $role = $conn->real_escape_string($data['role'] ?? '');
    $phone = $conn->real_escape_string($data['phone'] ?? '');
    $email = $conn->real_escape_string($data['email'] ?? '');
    $foodPreference = $conn->real_escape_string($data['foodPreference'] ?? 'veg');
    $joined = $conn->real_escape_string($data['joined'] ?? date('Y-m-d'));
    
    $sql = "UPDATE staff SET full_name=?, role=?, phone=?, email=?, food_preference=?, joined_at=? WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssi', $name, $role, $phone, $email, $foodPreference, $joined, $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Staff updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating staff']);
    }
}

function deleteStaff($conn) {
    $id = intval($_GET['id'] ?? 0);
    
    // Soft delete - just mark as inactive
    $sql = "UPDATE staff SET active='no' WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Staff deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting staff']);
    }
}

// ========== FEEDBACK FUNCTIONS ==========

function submitFeedback($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $account = $conn->real_escape_string($data['accountNumber'] ?? '');
    $quality = intval($data['quality'] ?? 0);
    $hygiene = intval($data['hygiene'] ?? 0);
    $service = intval($data['service'] ?? 0);
    $comments = $conn->real_escape_string($data['comments'] ?? '');
    $ratings = $data['ratings'] ?? [];
    
    if (empty($account)) {
        echo json_encode(['success' => false, 'message' => 'Account number is required']);
        return;
    }
    
    $successCount = 0;
    
    foreach ($ratings as $rating) {
        $staffId = intval($rating['staffId'] ?? 0);
        $staffRating = intval($rating['rating'] ?? 0);
        
        if ($staffId > 0 && $staffRating > 0) {
            $sql = "INSERT INTO staff_feedback (staff_id, account_number, quality, hygiene, service, staff_rating, comments) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isiiiis', $staffId, $account, $quality, $hygiene, $service, $staffRating, $comments);
            
            if ($stmt->execute()) {
                $successCount++;
            }
        }
    }
    
    if ($successCount > 0) {
        echo json_encode(['success' => true, 'message' => "Feedback submitted for $successCount staff member(s)"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No feedback was saved']);
    }
}

function getFeedback($conn) {
    $sql = "SELECT sf.id, sf.staff_id, s.full_name as staff_name, s.role as staff_role, 
                   sf.account_number, sf.quality, sf.hygiene, sf.service, sf.staff_rating, 
                   sf.comments, sf.created_at
            FROM staff_feedback sf
            JOIN staff s ON sf.staff_id = s.id
            ORDER BY sf.created_at DESC";
    
    $result = $conn->query($sql);
    
    $feedback = [];
    while ($row = $result->fetch_assoc()) {
        $feedback[] = [
            'id' => $row['id'],
            'staffId' => $row['staff_id'],
            'staffName' => $row['staff_name'],
            'staffRole' => $row['staff_role'],
            'studentAccount' => $row['account_number'],
            'quality' => $row['quality'],
            'hygiene' => $row['hygiene'],
            'service' => $row['service'],
            'rating' => $row['staff_rating'],
            'comments' => $row['comments'],
            'date' => $row['created_at']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $feedback]);
}

function deleteFeedback($conn) {
    $id = intval($_GET['id'] ?? 0);
    
    $sql = "DELETE FROM staff_feedback WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Feedback deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting feedback']);
    }
}
?>
