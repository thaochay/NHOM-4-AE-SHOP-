
<?php

session_start();
require_once __DIR__ . '/db.php'; // expects $conn (PDO)
require_once __DIR__ . '/inc/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php');
  exit;
}


if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  $_SESSION['flash_error'] = 'Yêu cầu không hợp lệ.';
  header('Location: index.php');
  exit;
}


$ten = trim($_POST['ten'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$pw = $_POST['password'] ?? '';
$pw2 = $_POST['password_confirm'] ?? '';

if (!$ten || !$email || !$phone || !$pw || !$pw2) {
  $_SESSION['flash_error'] = 'Vui lòng điền đủ thông tin.';
  header('Location: index.php');
  exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  $_SESSION['flash_error'] = 'Email không đúng định dạng.';
  header('Location: index.php');
  exit;
}
if ($pw !== $pw2) {
  $_SESSION['flash_error'] = 'Mật khẩu không khớp.';
  header('Location: index.php');
  exit;
}
if (strlen($pw) < 6) {
  $_SESSION['flash_error'] = 'Mật khẩu quá ngắn.';
  header('Location: index.php');
  exit;
}


$stmt = $conn->prepare("SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1");
$stmt->execute(['email'=>$email, 'phone'=>$phone]);
if ($stmt->fetch()) {
  $_SESSION['flash_error'] = 'Email hoặc số điện thoại đã được sử dụng.';
  header('Location: index.php');
  exit;
}

// insert
$pw_hash = password_hash($pw, PASSWORD_DEFAULT);
$now = date('Y-m-d H:i:s');
$ins = $conn->prepare("INSERT INTO users (ten, email, phone, password_hash, created_at, trang_thai) VALUES (:ten, :email, :phone, :ph, :now, 1)");
$ok = $ins->execute([
  'ten'=>$ten,
  'email'=>$email,
  'phone'=>$phone,
  'ph'=>$pw_hash,
  'now'=>$now
]);

if ($ok) {
  $id = $conn->lastInsertId();
  $user = ['id'=>$id, 'ten'=>$ten, 'email'=>$email, 'phone'=>$phone];
  $_SESSION['user'] = $user;
  $_SESSION['flash_success'] = 'Đăng ký thành công. Chào mừng ' . $ten;
  header('Location: account.php');
  exit;
} else {
  $_SESSION['flash_error'] = 'Có lỗi khi tạo tài khoản. Vui lòng thử lại.';
  header('Location: index.php');
  exit;
}
