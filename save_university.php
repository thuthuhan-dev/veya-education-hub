<?php
// save_university.php — Veya Education Hub
// Called via fetch() from universities.php, university_detail.php, dashboard.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'error'=>'not_logged_in']);
    exit;
}

require_once "db_connect.php";
$user_id = (int)$_SESSION['user_id'];
$uni_id  = (int)($_GET['id'] ?? 0);
$action  = $_GET['action'] ?? 'add'; // 'add' or 'remove'

if ($uni_id <= 0) {
    echo json_encode(['success'=>false,'error'=>'invalid_id']);
    exit;
}

if ($action === 'add') {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO saved_universities (user_id, university_id)
        VALUES (?, ?)
    ");
    $stmt->bind_param("ii", $user_id, $uni_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>$ok, 'action'=>'saved']);

} elseif ($action === 'remove') {
    $stmt = $conn->prepare("
        DELETE FROM saved_universities
        WHERE user_id=? AND university_id=?
    ");
    $stmt->bind_param("ii", $user_id, $uni_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>$ok, 'action'=>'removed']);

} else {
    echo json_encode(['success'=>false,'error'=>'invalid_action']);
}