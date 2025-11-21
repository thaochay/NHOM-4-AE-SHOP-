<?php
// forgot.php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// Config
define('EXPIRY_SECONDS', 60 * 60); // token hợp lệ trong 1 giờ
define('SITE_FROM_EMAIL', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'example.com'));
define('DEV_SHOW_LINK', true); // set false trên production để không hiển thị token trên trang

// ensure CSRF
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$flash = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $error = 'Yêu cầu không hợp lệ (CSRF). Vui lòng thử lại.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email không hợp lệ.';
        } else {
            try {
                // look up user
                $stmt = $conn->prepare("SELECT id_nguoi_dung, ten, email FROM nguoi_dung WHERE email = :email LIMIT 1");
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Always respond with generic success to avoid user enumeration
                $flash = 'Nếu email tồn tại trong hệ thống, chúng tôi đã gửi hướng dẫn đặt lại mật khẩu tới email đó.';

                if ($user) {
                    // ensure password_resets table exists (safe to run many times)
                    $conn->exec("CREATE TABLE IF NOT EXISTS password_resets (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        email VARCHAR(255) NOT NULL,
                        token_hash VARCHAR(128) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        created_at DATETIME NOT NULL,
                        INDEX (email),
                        INDEX (token_hash)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

                    // generate token (plaintext to be emailed), store hash
                    $token = bin2hex(random_bytes(24)); // 48 hex chars = 24 bytes
                    $token_hash = hash('sha256', $token);
                    $expires_at = date('Y-m-d H:i:s', time() + EXPIRY_SECONDS);

                    // optionally remove previous tokens for this email (or keep them)
                    $del = $conn->prepare("DELETE FROM password_resets WHERE email = :email");
                    $del->execute(['email' => $email]);

                    // insert reset
                    $ins = $conn->prepare("INSERT INTO password_resets (email, token_hash, expires_at, created_at) VALUES (:email, :token_hash, :expires_at, NOW())");
                    $ins->execute([
                        'email' => $email,
                        'token_hash' => $token_hash,
                        'expires_at' => $expires_at
                    ]);

                    // build reset link
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                    $reset_link = $scheme . '://' . $host . $path . '/reset.php?token=' . urlencode($token);

                    // email content
                    $subject = '[' . esc(site_name($conn)) . '] Hướng dẫn đặt lại mật khẩu';
                    $message = "Xin chào " . ($user['ten'] ?? '') . ",\n\n";
                    $message .= "Bạn (hoặc ai đó) đã yêu cầu đặt lại mật khẩu cho tài khoản liên kết với email này.\n\n";
                    $message .= "Để đặt lại mật khẩu, vui lòng click vào liên kết bên dưới (hoặc dán vào trình duyệt):\n\n";
                    $message .= $reset_link . "\n\n";
                    $message .= "Liên kết này sẽ hết hạn sau " . (EXPIRY_SECONDS / 60) . " phút.\n\n";
                    $message .= "Nếu bạn không yêu cầu việc này, hãy bỏ qua email này.\n\n";
                    $message .= esc(site_name($conn)) . "\n";

                    // try to send mail (simple)
                    $headers = "From: " . SITE_FROM_EMAIL . "\r\n" .
                               "Reply-To: " . SITE_FROM_EMAIL . "\r\n" .
                               "Content-Type: text/plain; charset=UTF-8\r\n";

                    $mail_sent = false;
                    // suppress warnings if mail isn't configured
                    try {
                        $mail_sent = @mail($email, $subject, $message, $headers);
                    } catch (Exception $e) {
                        $mail_sent = false;
                    }

                    // For dev/testing, optionally show link on page if mail not sent
                    if (!$mail_sent && DEV_SHOW_LINK) {
                        // append debug link to flash so developer can click
                        $flash .= ' (DEBUG: liên kết đặt lại: <a href="' . esc($reset_link) . '">' . esc($reset_link) . '</a>)';
                    }
                }
            } catch (Exception $e) {
                // log error server-side if you have logger; but show generic message to user
                error_log("Forgot password error: " . $e->getMessage());
                $error = 'Có lỗi hệ thống, vui lòng thử lại sau.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Quên mật khẩu - <?= esc(site_name($conn)) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    :root { --brand:#0d6efd; --muted:#6c757d; --bg:#f6f8fb; --card-radius:12px; }
    body { background: linear-gradient(180deg,#fff,#f6f8fb); font-family:Inter,system-ui, -apple-system, 'Segoe UI', Roboto, Arial; padding:28px; }
    .wrap { max-width:820px; margin:40px auto; }
    .card { border-radius:var(--card-radius); box-shadow:0 18px 50px rgba(12,38,63,0.06); overflow:hidden; }
    .card-body { padding:28px; }
    .brand { display:flex; gap:12px; align-items:center; margin-bottom:12px; }
    .logo { width:56px; height:56px; border-radius:10px; background:var(--brand); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; }
    .small-muted { color:var(--muted); font-size:14px; }
    .help-box { background:#f8faff; border:1px solid #e7f0ff; padding:12px; border-radius:8px; color:var(--muted); }
    .btn-primary { background:var(--brand); border-color:var(--brand); }
    @media (max-width:576px){ .card-body{ padding:18px; } }
  </style>
</head>
<body>

<div class="wrap">
  <div class="card">
    <div class="card-body">
      <div class="brand">
        <div class="logo"><?= htmlspecialchars(substr(site_name($conn),0,2)) ?></div>
        <div>
          <h4 class="mb-0">Quên mật khẩu</h4>
          <div class="small-muted">Nhập email để nhận liên kết đặt lại mật khẩu.</div>
        </div>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= esc($error) ?></div>
      <?php endif; ?>

      <?php if ($flash): ?>
        <div class="alert alert-success"><?= $flash ?></div>
      <?php endif; ?>

      <div class="row g-3">
        <div class="col-md-6">
          <form method="post" action="forgot.php" novalidate>
            <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
            <label class="form-label small">Email</label>
            <input name="email" type="email" required class="form-control mb-2" placeholder="you@example.com">
            <div class="d-flex gap-2">
              <button class="btn btn-primary" type="submit"><i class="bi bi-envelope me-2"></i>Gửi liên kết đặt lại</button>
              <a href="login.php" class="btn btn-outline-secondary">Quay lại đăng nhập</a>
            </div>
          </form>
        </div>

        <div class="col-md-6">
          <div class="help-box">
            <strong>Gợi ý</strong>
            <ul class="mb-0" style="margin-top:8px;">
              <li>Nếu bạn không nhận được email, kiểm tra hòm thư rác (spam) hoặc chờ vài phút.</li>
              <li>Liên kết đặt lại chỉ hợp lệ trong <?= EXPIRY_SECONDS / 60 ?> phút.</li>
              <li>Nếu bạn không yêu cầu đặt lại mật khẩu, có thể bỏ qua email.</li>
            </ul>
          </div>
        </div>
      </div>

      <div class="mt-3 small-muted">
        <a href="index.php">Quay lại trang chủ</a> · <a href="contact.php">Liên hệ hỗ trợ</a>
      </div>
    </div>
  </div>
</div>

</body>
</html>
