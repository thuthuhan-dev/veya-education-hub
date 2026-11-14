<?php
// save_scholarship.php — Veya Education Hub
// Called via fetch() — saves or removes a scholarship for the logged-in user
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    exit;
}

require_once "db_connect.php";

$user_id = (int)$_SESSION['user_id'];
$sch_id  = (int)($_GET['id']     ?? 0);
$action  = trim($_GET['action']  ?? 'add');

if ($sch_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'invalid_id']);
    exit;
}

if ($action === 'add') {
    $stmt = $conn->prepare("INSERT IGNORE INTO saved_scholarships (user_id, scholarship_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $user_id, $sch_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok, 'action' => 'saved']);

} elseif ($action === 'remove') {
    $stmt = $conn->prepare("DELETE FROM saved_scholarships WHERE user_id=? AND scholarship_id=?");
    $stmt->bind_param("ii", $user_id, $sch_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok, 'action' => 'removed']);

} else {
    echo json_encode(['success' => false, 'error' => 'invalid_action']);
}