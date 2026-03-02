<?php
/**
 * config.php - Database configuration for Hostel Mess Management System
 */

$db_host = 'localhost';
$db_user = 'root';   // ← your MySQL username
$db_pass = '';       // ← your MySQL password
$db_name = 'hostel_mess';

// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset('utf8mb4');

// No closing PHP tag to prevent accidental whitespace