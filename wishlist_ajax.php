<?php
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (empty($_SESSION['user']['id_nguoi_dung'])) {
    echo json_encode(['ok' => false, 'msg' => 'login']);
    exit;
}

$uid = (int)$_SESSION['user']['id_nguoi_dung'];
$pid = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($pid <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'invalid']);
    exit;
}

if ($action === 'add') {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO wishlist (id_nguoi_dung, id_san_pham, created_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$uid, $pid]);

    echo json_encode(['ok' => true, 'state' => 'added']);
    exit;
}

if ($action === 'remove') {
    $stmt = $conn->prepare("
        DELETE FROM wishlist
        WHERE id_nguoi_dung = ? AND id_san_pham = ?
    ");
    $stmt->execute([$uid, $pid]);

    echo json_encode(['ok' => true, 'state' => 'removed']);
    exit;
}

echo json_encode(['ok' => false]);
