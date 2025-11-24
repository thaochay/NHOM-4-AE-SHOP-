<?php
// login.php - xử lý + giao diện đăng nhập (self-contained)
// Đã chỉnh: nếu user.is_admin = 1 -> set $_SESSION['is_admin'] = 1 và redirect sang admin/index.php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// fallback helpers
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('site_name')) {
    function site_name($conn = null){ return 'AE Shop'; }
}

// flash helpers
if (!isset($_SESSION['flash'])) $_SESSION['flash'] = [];
function flash_set($k, $msg){ $_SESSION['flash'][$k] = $msg; }
function flash_get($k){ $v = $_SESSION['flash'][$k] ?? null; if ($v) unset($_SESSION['flash'][$k]); return $v; }

// determine back url
$back = $_REQUEST['back'] ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php');

// if user already logged in, redirect back
if (!empty($_SESSION['user'])) {
    // if admin already logged in, prefer admin dashboard when available
    if (!empty($_SESSION['is_admin'])) {
        header('Location: admin/index.php');
        exit;
    }
    header('Location: ' . $back);
    exit;
}

$err = null;
$success = null;

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = trim($_POST['mat_khau'] ?? '');
    $remember = !empty($_POST['remember']);

    if ($email === '' || $pass === '') {
        $err = 'Vui lòng nhập email và mật khẩu.';
    } else {
        try {
            // lấy cả trường is_admin để kiểm tra
            $stmt = $conn->prepare("SELECT * FROM nguoi_dung WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $err = 'Email hoặc mật khẩu không đúng.';
            } else {
                $hash = $user['mat_khau'] ?? '';

                // Preferred: password_verify with hashed password
                if ($hash !== '' && (password_verify($pass, $hash) || (password_needs_rehash($hash, PASSWORD_DEFAULT) && password_verify($pass, $hash)))) {
                    // OK - if needs rehash, update
                    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                        $newHash = password_hash($pass, PASSWORD_DEFAULT);
                        $u = $conn->prepare("UPDATE nguoi_dung SET mat_khau = :h WHERE id_nguoi_dung = :id");
                        $u->execute([':h'=>$newHash, ':id'=>$user['id_nguoi_dung']]);
                    }

                    // login success: set session user
                    $_SESSION['user'] = [
                        'id_nguoi_dung' => (int)$user['id_nguoi_dung'],
                        'ten'           => $user['ten'] ?? '',
                        'email'         => $user['email'] ?? ''
                    ];

                    // update last_login
                    try {
                        $upd = $conn->prepare("UPDATE nguoi_dung SET last_login = NOW() WHERE id_nguoi_dung = :id");
                        $upd->execute([':id'=>$user['id_nguoi_dung']]);
                    } catch (Exception $e) { /* ignore */ }

                    // remember email (optional convenience only)
                    if ($remember) setcookie('remember_email', $email, time()+60*60*24*30, "/");

                    // If user is admin -> mark session and redirect to admin dashboard
                    $isAdmin = !empty($user['is_admin']) && (int)$user['is_admin'] === 1;
                    if ($isAdmin) {
                        $_SESSION['is_admin'] = 1;
                        // flash and redirect to admin dashboard
                        flash_set('success', 'Đăng nhập quản trị thành công. Chào ' . ($_SESSION['user']['ten'] ?: 'Admin') . '!');
                        header('Location: admin/index.php');
                        exit;
                    }

                    // Non-admin normal user
                    $success = "Đăng nhập thành công. Chào " . ($_SESSION['user']['ten'] ?: 'bạn') . "!";
                    flash_set('success', $success);

                    // redirect to back (avoid open-redirect: allow only relative or same-host paths)
                    $redirect = 'index.php';
                    if ($back && (strpos($back, '/') === 0 || parse_url($back, PHP_URL_HOST) === null)) {
                        $redirect = $back;
                    }
                    header('Location: ' . $redirect);
                    exit;

                } else {
                    // fallback: if DB stored plain-text (NOT RECOMMENDED) - allow and migrate to hash
                    if ($hash !== '' && $pass === $hash) {
                        // migrate: hash the plain password and update DB
                        $newHash = password_hash($pass, PASSWORD_DEFAULT);
                        $u = $conn->prepare("UPDATE nguoi_dung SET mat_khau = :h WHERE id_nguoi_dung = :id");
                        $u->execute([':h'=>$newHash, ':id'=>$user['id_nguoi_dung']]);

                        // set session and update last_login
                        $_SESSION['user'] = [
                            'id_nguoi_dung' => $user['id_nguoi_dung'],
                            'ten'           => $user['ten'] ?? '',
                            'email'         => $user['email'] ?? ''
                        ];
                        $upd = $conn->prepare("UPDATE nguoi_dung SET last_login = NOW() WHERE id_nguoi_dung = :id");
                        $upd->execute([':id'=>$user['id_nguoi_dung']]);

                        if ($remember) setcookie('remember_email', $email, time()+60*60*24*30, "/");

                        // admin check for plain-text migration case as well
                        $isAdmin = !empty($user['is_admin']) && (int)$user['is_admin'] === 1;
                        if ($isAdmin) {
                            $_SESSION['is_admin'] = 1;
                            flash_set('success', 'Đăng nhập quản trị thành công (migrate mật khẩu). Chào ' . ($_SESSION['user']['ten'] ?: 'Admin') . '!');
                            header('Location: admin/index.php');
                            exit;
                        }

                        $success = "Đăng nhập thành công (migrate mật khẩu). Chào " . ($_SESSION['user']['ten'] ?: 'bạn') . "!";
                        flash_set('success', $success);
                        header('Location: ' . ($back ?: 'index.php'));
                        exit;
                    }

                    $err = 'Email hoặc mật khẩu không đúng.';
                }
            }
        } catch (Exception $e) {
            // don't leak DB details to user in production
            $err = 'Lỗi hệ thống: ' . $e->getMessage();
        }
    }
}

// prefill remembered email if cookie exists
$remembered = $_COOKIE['remember_email'] ?? '';

// fetch flash messages
$flash_success = flash_get('success');
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Đăng nhập — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{--accent:#0d6efd;--muted:#6c757d;--radius:12px}
    html,body{height:100%}
    body{display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#fff,#f1f6fb);font-family:Inter,system-ui,Roboto,Arial;margin:0;padding:28px;color:#212529}
    .login-wrap{width:100%;max-width:1080px;border-radius:14px;overflow:hidden;display:grid;grid-template-columns:1fr 420px;box-shadow:0 18px 60px rgba(12,38,63,0.08);background:linear-gradient(180deg,#fff,#fbfdff)}
    @media (max-width:992px){ .login-wrap{grid-template-columns:1fr} .visual{padding:28px} .form-panel{padding:28px} }
    .visual{padding:40px;background:linear-gradient(180deg,rgba(13,110,253,0.04),rgba(13,110,253,0.02));display:flex;flex-direction:column;gap:18px;justify-content:space-between}
    .brand-mark{width:64px;height:64px;border-radius:12px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:22px}
    .hero-visual{display:flex;gap:12px;align-items:center;padding:18px;border-radius:12px;background:#fff}
    .form-panel{padding:42px;display:flex;flex-direction:column;gap:14px;background:linear-gradient(180deg,#fff,#fbfdff)}
    .btn-primary{background:var(--accent);border-color:var(--accent)}
  </style>
</head>
<body>

<main class="login-wrap" id="loginWrap" role="main" aria-labelledby="loginTitle">
  <section class="visual" aria-hidden="true">
    <div>
      <div style="display:flex;gap:14px;align-items:center">
        <div class="brand-mark"><?= htmlspecialchars(substr(site_name($conn),0,2)) ?></div>
        <div>
          <div style="font-weight:800"><?= esc(site_name($conn)) ?></div>
          <div style="color:var(--muted);font-size:13px">Thời trang nam cao cấp</div>
        </div>
      </div>

      <div class="hero-visual mt-3">
        <img src="images/login-hero.jpg" alt="Hero" style="width:160px;border-radius:8px" onerror="this.style.display='none'">
        <div>
          <div style="font-weight:700">Mua sắm thông minh</div>
          <div style="color:var(--muted);margin-top:6px">Ưu đãi & cập nhật bộ sưu tập mới mỗi tuần.</div>
          <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
            <div style="background:#fff;padding:8px 12px;border-radius:8px;border:1px solid #eef4ff;color:var(--muted);font-size:13px">Giao nhanh</div>
            <div style="background:#fff;padding:8px 12px;border-radius:8px;border:1px solid #eef4ff;color:var(--muted);font-size:13px">Đổi trả dễ</div>
            <div style="background:#fff;padding:8px 12px;border-radius:8px;border:1px solid #eef4ff;color:var(--muted);font-size:13px">Bảo mật</div>
          </div>
        </div>
      </div>
    </div>

    <div style="color:var(--muted);font-size:13px">
      <div style="font-weight:600;margin-bottom:6px">Hỗ trợ khách hàng</div>
      Hotline: <a href="tel:0123456789">0123 456 789</a> — <a href="mailto:info@example.com">info@example.com</a>
    </div>
  </section>

  <section class="form-panel" aria-labelledby="loginTitle">
    <div>
      <h2 id="loginTitle">Đăng nhập</h2>
      <div style="color:var(--muted);font-size:14px">Đăng nhập để quản lý đơn hàng, xem lịch sử và nhận khuyến mãi.</div>
    </div>

    <?php if ($flash_success): ?>
      <div class="alert alert-success"><?= esc($flash_success) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger"><?= esc($err) ?></div>
    <?php endif; ?>

    <form method="post" action="" autocomplete="on" novalidate>
      <input type="hidden" name="back" value="<?= esc($back) ?>">

      <div class="mb-3">
        <label class="form-label small">Email</label>
        <input name="email" type="email" class="form-control form-control-lg" placeholder="you@example.com" required value="<?= esc($remembered ?: ($_POST['email'] ?? '')) ?>" autofocus>
      </div>

      <div class="mb-3">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <label class="form-label small mb-0">Mật khẩu</label>
          <a href="forgot.php" class="small">Quên mật khẩu?</a>
        </div>
        <div class="input-group">
          <input name="mat_khau" type="password" class="form-control form-control-lg" placeholder="Nhập mật khẩu" required>
          <button type="button" id="togglePass" class="btn btn-light" aria-label="Hiện mật khẩu"><i class="bi bi-eye"></i></button>
        </div>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="1" id="rememberMe" name="remember">
          <label class="form-check-label small" for="rememberMe">Ghi nhớ email</label>
        </div>
        <div><a href="register.php" class="small">Tạo tài khoản</a></div>
      </div>

      <div class="d-grid gap-2 mb-2">
        <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-box-arrow-in-right me-2"></i> Đăng nhập</button>
        <a href="index.php" class="btn btn-outline-secondary btn-lg"><i class="bi bi-house-door me-2"></i> Quay lại trang chủ</a>
      </div>

      <div style="display:flex;align-items:center;gap:12px;color:var(--muted);margin-bottom:10px">
        <span style="flex:1;height:1px;background:#eef4ff;display:block"></span>
        <small>hoặc</small>
        <span style="flex:1;height:1px;background:#eef4ff;display:block"></span>
      </div>

      <div style="display:flex;gap:10px" role="group">
        <a href="#" class="btn shadow-sm" style="flex:1;padding:10px;border-radius:10px;border:1px solid #e9eef8"><i class="bi bi-google"></i> Google</a>
        <a href="#" class="btn shadow-sm" style="flex:1;padding:10px;border-radius:10px;border:1px solid #e9eef8"><i class="bi bi-facebook"></i> Facebook</a>
      </div>

      <div style="color:var(--muted);text-align:center;margin-top:12px;font-size:13px">Bằng việc tiếp tục, bạn đồng ý với <a href="terms.php">Điều khoản & Điều kiện</a>.</div>
    </form>
  </section>
</main>

<script>
  // show animation
  window.addEventListener('load', function(){ document.getElementById('loginWrap')?.classList?.add('visible'); });

  // toggle password visibility
  (function(){
    const toggle = document.getElementById('togglePass');
    const pwd = document.querySelector('input[name="mat_khau"]');
    toggle?.addEventListener('click', function(){
      if(!pwd) return;
      if (pwd.type === 'password') { pwd.type = 'text'; this.innerHTML = '<i class="bi bi-eye-slash"></i>'; }
      else { pwd.type = 'password'; this.innerHTML = '<i class="bi bi-eye"></i>'; }
      pwd.focus();
    });
  })();

  // minimal client validation
  (function(){
    const form = document.querySelector('form');
    form?.addEventListener('submit', function(e){
      const email = this.querySelector('[name=email]');
      const pass = this.querySelector('[name=mat_khau]');
      if (!email.value.trim() || !pass.value.trim()) {
        e.preventDefault();
        alert('Vui lòng nhập email và mật khẩu.');
        email.focus();
      }
    });
  })();
</script>

</body>
</html>
