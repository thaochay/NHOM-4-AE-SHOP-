<?php
// contact.php - Trang liên hệ (giao diện & header giống index.php)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

/* fallback helpers nếu inc/helpers.php thiếu */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/* CSRF simple */
if (!isset($_SESSION['csrf_contact'])) $_SESSION['csrf_contact'] = bin2hex(random_bytes(16));

$errors = [];
$success = false;
$values = [
    'ten' => '',
    'email' => '',
    'dien_thoai' => '',
    'tieu_de' => '',
    'noi_dung' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf_contact'] ?? '', $token)) {
        $errors[] = 'Yêu cầu không hợp lệ (CSRF). Vui lòng thử lại.';
    } else {
        $values['ten'] = trim($_POST['ten'] ?? '');
        $values['email'] = trim($_POST['email'] ?? '');
        $values['dien_thoai'] = trim($_POST['dien_thoai'] ?? '');
        $values['tieu_de'] = trim($_POST['tieu_de'] ?? '');
        $values['noi_dung'] = trim($_POST['noi_dung'] ?? '');

        if ($values['ten'] === '') $errors[] = 'Vui lòng nhập tên.';
        if ($values['email'] === '' || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Vui lòng nhập email hợp lệ.';
        if ($values['noi_dung'] === '') $errors[] = 'Vui lòng nhập nội dung liên hệ.';

        if (empty($errors)) {
            try {
                $sql = "INSERT INTO lien_he (ten, email, dien_thoai, tieu_de, noi_dung, created_at, trang_thai)
                        VALUES (:ten, :email, :dien_thoai, :tieu_de, :noi_dung, NOW(), 1)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':ten' => $values['ten'],
                    ':email' => $values['email'],
                    ':dien_thoai' => $values['dien_thoai'],
                    ':tieu_de' => $values['tieu_de'],
                    ':noi_dung' => $values['noi_dung']
                ]);
                $success = true;
                $values = ['ten'=>'','email'=>'','dien_thoai'=>'','tieu_de'=>'','noi_dung'=>''];
            } catch (Throwable $e) {
                @file_put_contents(__DIR__ . '/logs/contact_error.log', date('c') . " - contact save error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
                $success = true;
                $values = ['ten'=>'','email'=>'','dien_thoai'=>'','tieu_de'=>'','noi_dung'=>''];
            }
        }
    }
}

/* cart + user info for header */
$cart = $_SESSION['cart'] ?? [];
$cart_count = 0;
foreach ($cart as $it) {
    $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['so_luong']) ? (int)$it['so_luong'] : 1);
}
$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;

/* load categories for menu */
try {
    $cats = $conn->query("SELECT id_danh_muc, ten, slug FROM danh_muc WHERE trang_thai=1 ORDER BY thu_tu ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cats = [];
}

/* helper active */
function is_active($file) { return basename($_SERVER['PHP_SELF']) === $file ? 'active' : ''; }
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Liên hệ — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --accent:#0b7bdc;
      --muted:#6c757d;
      --nav-bg:#ffffff;
    }
    body{background:#f8fbff;color:#102a43;font-family:system-ui, -apple-system, "Segoe UI", Roboto, Arial;}
    .ae-navbar{background:var(--nav-bg);box-shadow:0 6px 18px rgba(11,38,80,0.04);backdrop-filter:blur(12px)}
    .ae-logo-mark{width:46px;height:46px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}
    .nav-link.custom{position:relative;padding:.6rem .9rem;border-radius:8px;color:#2b3a42;font-weight:600}
    .nav-link.custom:hover{color:var(--accent)}
    .nav-orders{ padding-inline:.8rem;border-radius:999px;background:rgba(11,123,220,.06);display:flex;align-items:center;gap:.4rem;text-decoration:none;color:inherit }
    .nav-orders:hover{background:rgba(11,123,220,.12); color:var(--accent)}
    .hero { background:linear-gradient(135deg,#f6f9ff,#ffffff); padding:48px 0; text-align:center; }
    .card-contact{ border-radius:12px; box-shadow: 0 8px 30px rgba(13,38,59,0.04); overflow:hidden; background:#fff; }
    .contact-left{ padding:28px; }
    .contact-right{ background:#f7fbff; padding:24px; min-width:320px; border-left:1px solid rgba(11,38,80,0.02); }
    .map-box{ border-radius:8px; overflow:hidden; border:1px solid #e9eef8; }
    .btn-primary{ background:var(--accent); border-color:var(--accent); }
    @media (max-width:991px){ .nav-center{ display:none } .search-input{ display:none } .contact-right{ order:2 } .contact-left{ order:1 } .contact-right{ min-width:auto } }
    /* QuickView modal styles (reused) */
    #quickViewModal .modal-content{ border-radius:20px; border:none; box-shadow:0 22px 80px rgba(15,23,42,0.35); }
    #quickViewModal .modal-body{ background:radial-gradient(circle at top,#f5f9ff,#ffffff); }
    #quickViewModal .qv-main{ border-radius:16px; background:#fff; box-shadow:0 14px 40px rgba(15,23,42,0.08); }
    .qv-thumb{ width:70px;height:70px;object-fit:cover;border-radius:8px;cursor:pointer;border:2px solid transparent }
    .qv-thumb.active{border-color:var(--accent);box-shadow:0 8px 20px rgba(11,38,80,0.06)}
  </style>
</head>
<body>

<!-- NAV -->
<nav class="navbar navbar-expand-lg ae-navbar sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <div class="ae-logo-mark">AE</div>
      <div class="d-none d-md-block">
        <div style="font-weight:800"><?= esc(site_name($conn)) ?></div>
        <div style="font-size:12px;color:var(--muted)">Thời trang nam cao cấp</div>
      </div>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain"><span class="navbar-toggler-icon"></span></button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav mx-auto mb-2 mb-lg-0 align-items-center">
        <li class="nav-item"><a class="nav-link custom <?= is_active('index.php') ?>" href="index.php">Trang chủ</a></li>

        <li class="nav-item dropdown">
          <a class="nav-link custom dropdown-toggle" href="#" data-bs-toggle="dropdown">Sản phẩm</a>
          <ul class="dropdown-menu">
            <?php foreach($cats as $c): ?>
              <li><a class="dropdown-item" href="category.php?slug=<?= urlencode($c['slug']) ?>"><?= esc($c['ten']) ?></a></li>
            <?php endforeach; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-muted" href="sanpham.php">Xem tất cả sản phẩm</a></li>
          </ul>
        </li>

        <li class="nav-item"><a class="nav-link custom <?= is_active('about.php') ?>" href="about.php">Giới Thiệu</a></li>
        <li class="nav-item"><a class="nav-link custom <?= is_active('contact.php') ?>" href="contact.php">Liên hệ</a></li>

        <li class="nav-item">
          <a class="nav-link nav-orders ms-2" href="orders.php"><i class="bi bi-receipt-cutoff"></i><span class="ms-1 d-none d-lg-inline">Đơn hàng của tôi</span></a>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-2">
        <form class="d-none d-lg-flex me-2" method="get" action="sanpham.php"><div class="input-group input-group-sm"><input name="q" class="form-control search-input" placeholder="Tìm sản phẩm, mã..." value="<?= esc($_GET['q'] ?? '') ?>"><button class="btn btn-dark" type="submit"><i class="bi bi-search"></i></button></div></form>

        <div class="dropdown">
          <a class="d-flex align-items-center text-decoration-none" href="#" data-bs-toggle="dropdown">
            <div style="width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:var(--accent)"><i class="bi bi-person-fill"></i></div>
            <span class="ms-2 d-none d-md-inline small"><?= $user_name ? esc($user_name) : 'Tài khoản' ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end p-2">
            <?php if(empty($_SESSION['user'])): ?>
              <li><a class="dropdown-item" href="login.php">Đăng nhập</a></li>
              <li><a class="dropdown-item" href="register.php">Tạo tài khoản</a></li>
            <?php else: ?>
              <li><a class="dropdown-item" href="account.php">Tài khoản của tôi</a></li>
              <li><a class="dropdown-item" href="orders.php">Đơn hàng</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="logout.php">Đăng xuất</a></li>
            <?php endif; ?>
          </ul>
        </div>

        <div class="dropdown">
          <a class="d-flex align-items-center text-decoration-none position-relative" href="#" data-bs-toggle="dropdown">
            <div style="width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:var(--accent)"><i class="bi bi-bag-fill"></i></div>
            <span class="ms-2 d-none d-md-inline small">Giỏ hàng</span>
            <span class="badge bg-danger rounded-pill" style="position:relative;top:-10px;left:-8px"><?= (int)$cart_count ?></span>
          </a>
          <div class="dropdown-menu dropdown-menu-end p-3" style="min-width:320px">
            <?php if (empty($_SESSION['cart'])): ?>
              <div class="text-muted small">Bạn chưa có sản phẩm nào trong giỏ.</div>
              <div class="mt-3"><a href="sanpham.php" class="btn btn-primary btn-sm w-100">Mua ngay</a></div>
            <?php else: ?>
              <div style="max-height:240px;overflow:auto">
                <?php $total=0; foreach($_SESSION['cart'] as $id=>$item):
                  $qty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['sl']) ? (int)$item['sl'] : 1);
                  $price = isset($item['price']) ? (float)$item['price'] : (isset($item['gia']) ? (float)$item['gia'] : 0);
                  $name = $item['name'] ?? $item['ten'] ?? '';
                  $img = $item['img'] ?? $item['hinh'] ?? 'images/placeholder.jpg';
                  $img = preg_match('#^https?://#i', $img) ? $img : ltrim($img, '/');
                  $subtotal = $qty * $price; $total += $subtotal;
                ?>
                  <div class="d-flex gap-2 align-items-center py-2">
                    <img src="<?= esc($img) ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px" alt="<?= esc($name) ?>">
                    <div class="flex-grow-1"><div class="small fw-semibold mb-1"><?= esc($name) ?></div><div class="small text-muted"><?= $qty ?> x <?= number_format($price,0,',','.') ?> ₫</div></div>
                    <div class="small"><?= number_format($subtotal,0,',','.') ?> ₫</div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="d-flex justify-content-between align-items-center mt-3"><div class="text-muted small">Tạm tính</div><div class="fw-semibold"><?= number_format($total,0,',','.') ?> ₫</div></div>
              <div class="mt-3 d-grid gap-2"><a href="cart.php" class="btn btn-outline-secondary btn-sm">Giỏ hàng</a><a href="checkout.php" class="btn btn-primary btn-sm">Thanh toán</a></div>
            <?php endif; ?>
          </div>
        </div>

        <button class="btn btn-light d-lg-none ms-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-controls="mobileMenu">
          <i class="bi bi-list"></i>
        </button>
      </div>
    </div>
  </div>
</nav>

<!-- Mobile offcanvas -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
  <div class="offcanvas-header">
    <h5 id="mobileMenuLabel"><?= esc(site_name($conn)) ?></h5>
    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Đóng"></button>
  </div>
  <div class="offcanvas-body">
    <form action="sanpham.php" method="get" class="mb-3 d-flex">
      <input class="form-control me-2" name="q" placeholder="Tìm sản phẩm..." value="<?= esc($_GET['q'] ?? '') ?>">
      <button class="btn btn-dark">Tìm</button>
    </form>

    <ul class="list-unstyled">
      <li class="mb-2"><a href="index.php" class="text-decoration-none">Trang chủ</a></li>
      <li class="mb-2"><a href="sanpham.php" class="text-decoration-none">Sản phẩm</a></li>
      <?php foreach($cats as $c): ?>
        <li class="mb-2 ps-2"><a href="category.php?slug=<?= urlencode($c['slug']) ?>" class="text-decoration-none"><?= esc($c['ten']) ?></a></li>
      <?php endforeach; ?>
      <li class="mb-2"><a href="sale.php" class="text-decoration-none">Khuyến mãi</a></li>
      <li class="mb-2"><a href="about.php" class="text-decoration-none">Giới thiệu</a></li>
      <li class="mb-2"><a href="contact.php" class="text-decoration-none">Liên hệ</a></li>
      <li class="mb-2"><a href="orders.php" class="text-decoration-none">Đơn hàng của tôi</a></li>
    </ul>
  </div>
</div>

<!-- HERO -->
<section class="hero">
  <div class="container">
    <h1 class="fw-bold">Liên hệ với <?= esc(site_name($conn)) ?></h1>
    <p class="text-muted">Gửi yêu cầu, góp ý hoặc thắc mắc — chúng tôi sẽ trả lời sớm nhất.</p>
  </div>
</section>

<!-- MAIN: contact form & info -->
<main class="container my-4">
  <div class="card card-contact d-flex flex-row">
    <div class="contact-left" style="flex:1;">
      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
          <?php foreach ($errors as $e) echo '<div>'.esc($e).'</div>'; ?>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success">
          Cám ơn bạn — chúng tôi đã nhận được yêu cầu. Chúng tôi sẽ phản hồi sớm.
        </div>
      <?php endif; ?>

      <form method="post" action="contact.php" class="row g-3">
        <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_contact']) ?>">
        <div class="col-md-6">
          <label class="form-label small">Họ & Tên</label>
          <input type="text" name="ten" class="form-control" value="<?= esc($values['ten']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label small">Email</label>
          <input type="email" name="email" class="form-control" value="<?= esc($values['email']) ?>" required>
        </div>

        <div class="col-md-6">
          <label class="form-label small">Số điện thoại</label>
          <input type="text" name="dien_thoai" class="form-control" value="<?= esc($values['dien_thoai']) ?>" placeholder="Không bắt buộc">
        </div>
        <div class="col-md-6">
          <label class="form-label small">Tiêu đề</label>
          <input type="text" name="tieu_de" class="form-control" value="<?= esc($values['tieu_de']) ?>" placeholder="Ví dụ: Hỏi về đơn hàng">
        </div>

        <div class="col-12">
          <label class="form-label small">Nội dung</label>
          <textarea name="noi_dung" rows="6" class="form-control" required><?= esc($values['noi_dung']) ?></textarea>
        </div>

        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Gửi liên hệ</button>
          <a href="index.php" class="btn btn-outline-secondary">Hủy</a>
        </div>
      </form>
    </div>

    <aside class="contact-right">
      <h5 class="mb-3">Thông tin liên hệ</h5>
      <div class="mb-3">
        <div class="fw-semibold"><?= esc(site_name($conn)) ?></div>
        <div class="text-muted">Địa chỉ: 123 Đường ABC, Quận XYZ</div>
        <div class="text-muted">Hotline: <a href="tel:0123456789">0123 456 789</a></div>
        <div class="text-muted">Email: <a href="mailto:info@example.com">info@example.com</a></div>
      </div>

      <hr>

      <h6 class="mb-2">Giờ làm việc</h6>
      <div class="text-muted mb-3">
        Thứ 2 - Thứ 7: 08:30 — 18:00<br>
        Chủ nhật: Nghỉ
      </div>

      <h6 class="mb-2">Bản đồ</h6>
      <div class="map-box mb-3">
        <iframe src="https://www.google.com/maps?q=Hanoi&output=embed" style="width:100%;height:200px;border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
      </div>

      <div class="text-muted small">Hoặc gửi yêu cầu qua form — chúng tôi sẽ liên hệ lại trong 24 giờ làm việc.</div>
    </aside>
  </div>
</main>

<!-- QUICKVIEW modal (sẵn sàng dùng giống index) -->
<div class="modal fade" id="quickViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-4">
        <div class="row gx-4">
          <div class="col-md-6">
            <div class="qv-main text-center p-3"><img id="qv-main-img" src="images/placeholder.jpg" class="img-fluid" style="max-height:420px;object-fit:contain"></div>
            <div class="d-flex gap-2 mt-3" id="qv-thumbs"></div>
          </div>
          <div class="col-md-6">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <h4 id="qv-title">Tên sản phẩm</h4>
                <div class="small text-muted" id="qv-sku">Mã: -</div>
                <div class="mt-2" id="qv-rate">★★★★★ <span class="small text-muted">(0 đánh giá)</span></div>
              </div>
              <div class="text-end">
                <div class="h4 text-danger" id="qv-price">0 ₫</div>
                <div class="small text-muted" id="qv-stock">Còn: -</div>
              </div>
            </div>

            <div class="mt-3" id="qv-short-desc">Mô tả ngắn...</div>

            <div class="mt-3">
              <div class="mb-2"><strong>Màu sắc</strong></div>
              <div id="qv-swatches" class="mb-3"></div>

              <div class="mb-2"><strong>Kích thước</strong></div>
              <div id="qv-sizes" class="mb-3"></div>

              <div class="mb-3 d-flex align-items-center gap-3">
                <div>
                  <label class="form-label small mb-1">Số lượng</label>
                  <input id="qv-qty" type="number" class="form-control form-control-sm" value="1" min="1" style="width:100px">
                </div>
                <div class="flex-grow-1 text-muted small">Giao hàng nhanh trong 1-3 ngày, đổi trả 7 ngày.</div>
              </div>

              <div class="d-flex gap-2 mb-2">
                <form id="qv-addform" method="post" action="cart.php" class="d-flex w-100">
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="id" id="qv-id" value="">
                  <input type="hidden" name="qty" id="qv-id-qty" value="1">
                  <button type="submit" class="btn btn-success w-100 add-anim"><i class="bi bi-cart-plus"></i> Thêm vào giỏ</button>
                </form>
                <a id="qv-buy" href="#" class="btn btn-outline-primary">Mua ngay</a>
              </div>

              <ul class="nav nav-tabs mt-3" id="qvTab" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" id="desc-tab" data-bs-toggle="tab" data-bs-target="#desc" type="button">Mô tả</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="spec-tab" data-bs-toggle="tab" data-bs-target="#spec" type="button">Thông số</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="rev-tab" data-bs-toggle="tab" data-bs-target="#rev" type="button">Đánh giá</button></li>
              </ul>
              <div class="tab-content p-3 border rounded-bottom" id="qvTabContent">
                <div class="tab-pane fade show active" id="desc" role="tabpanel"></div>
                <div class="tab-pane fade" id="spec" role="tabpanel"><div class="small text-muted">Chưa có thông số chi tiết.</div></div>
                <div class="tab-pane fade" id="rev" role="tabpanel"><div class="small text-muted">Chưa có đánh giá.</div></div>
              </div>

            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button></div>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer class="bg-dark text-white py-4 mt-4">
  <div class="container text-center"><small><?= esc(site_name($conn)) ?> — © <?= date('Y') ?> — Hotline: 0123 456 789</small></div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openQuickView(btn){
  try {
    const data = JSON.parse(btn.getAttribute('data-product') || '{}');
    document.getElementById('qv-title').textContent = data.name || '';
    document.getElementById('qv-short-desc').textContent = data.mo_ta || '';
    document.getElementById('qv-price').textContent = new Intl.NumberFormat('vi-VN').format(data.price || data.gia_raw || 0) + ' ₫';
    document.getElementById('qv-stock').textContent = 'Còn: ' + (data.stock !== undefined ? data.stock : '-');
    document.getElementById('qv-id').value = data.id || '';
    document.getElementById('qv-main-img').src = data.img || 'images/placeholder.jpg';

    const thumbsBox = document.getElementById('qv-thumbs'); thumbsBox.innerHTML = '';
    let thumbs = Array.isArray(data.thumbs) && data.thumbs.length ? data.thumbs : [data.img || 'images/placeholder.jpg'];
    thumbs.forEach((t, idx) => {
      const im = document.createElement('img');
      im.src = t;
      im.className = 'qv-thumb' + (idx===0 ? ' active' : '');
      im.onclick = function(){ document.getElementById('qv-main-img').src = t; document.querySelectorAll('.qv-thumb').forEach(x=>x.classList.remove('active')); this.classList.add('active'); };
      thumbsBox.appendChild(im);
    });

    document.getElementById('desc').innerHTML = data.mo_ta ? data.mo_ta : '<div class="small text-muted">Không có mô tả chi tiết.</div>';
    document.getElementById('spec').innerHTML = data.specs ? data.specs : '<div class="small text-muted">Không có thông số.</div>';
    document.getElementById('rev').innerHTML = '<div class="small text-muted">Chưa có đánh giá.</div>';

    document.getElementById('qv-buy').href = 'sanpham_chitiet.php?id=' + encodeURIComponent(data.id || '');
    var myModal = new bootstrap.Modal(document.getElementById('quickViewModal'));
    myModal.show();
  } catch(e) {
    console.error('openQuickView error', e);
  }
}

document.addEventListener('click', function(e){
  if (e.target.closest('.add-anim')) {
    const btn = e.target.closest('.add-anim');
    btn.style.transform = 'scale(0.97)';
    setTimeout(()=> btn.style.transform = '', 160);
  }
});
</script>
</body>
</html>
