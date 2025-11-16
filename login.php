<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $mat_khau = $_POST['mat_khau'] ?? '';
    if ($email === '' || $mat_khau === '') $error = 'Vui lòng nhập email và mật khẩu.';
    else {
        try {
            $stmt = $conn->prepare("SELECT id, ho_ten, mat_khau FROM nguoi_dung WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $u = $stmt->fetch();
            if (!$u || !password_verify($mat_khau, $u['mat_khau'])) $error = 'Email hoặc mật khẩu không đúng.';
            else {
                $_SESSION['user_id'] = $u['id'];
                $_SESSION['user_name'] = $u['ho_ten'];
                header('Location: index.php'); exit;
            }
        } catch (PDOException $e) {
            $error = 'Lỗi hệ thống khi đăng nhập.';
        }
    }
}
include __DIR__ . '/header.php';
?>
<div class="container-lg my-4">
  <div class="row justify-content-center">
    <div class="col-md-8">
      <div class="card p-4">
        <h3>Đăng nhập</h3>
        <?php if($error): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>
        <form method="post">
          <div class="mb-3"><label>Email</label><input name="email" type="email" class="form-control" required value="<?= esc($_POST['email'] ?? '') ?>"></div>
          <div class="mb-3"><label>Mật khẩu</label><input name="mat_khau" type="password" class="form-control" required></div>
          <div class="d-flex gap-2"><button class="btn btn-primary">Đăng nhập</button><a href="register.php" class="btn btn-outline-secondary">Tạo tài khoản</a></div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
