<?php
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
$uid = $user['id_nguoi_dung'] ?? ($user['id'] ?? null);
if (!$uid) { echo json_encode(['count'=>0]); exit; }

$stmt = $conn->prepare("SELECT COUNT(*) FROM san_pham_yeu_thich WHERE id_nguoi_dung=?");
$stmt->execute([(int)$uid]);
echo json_encode(['count'=>(int)$stmt->fetchColumn()]);
