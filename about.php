<?php
session_start();
require_once __DIR__ . "/inc/helpers.php"; // esc(), price(), site_name()
require_once __DIR__ . "/db.php";

// cart count
$cart = $_SESSION['cart'] ?? [];
$cart_count = 0;
foreach ($cart as $it) {
    $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['so_luong']) ? (int)$it['so_luong'] : 1);
}

// helper active
function is_active($file) {
    return basename($_SERVER['PHP_SELF']) === $file ? 'active' : '';
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Giới thiệu — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    /* ====== Compact header styles ====== */
    :root { --primary:#0d6efd; --muted:#6c757d; --brand-bg:#0b1220; --border:#eef3fb; }
    body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial; color:#222; }

    .header { border-bottom:1px solid var(--border); background:#fff; }
    .hdr-inner { max-width:1200px; margin:0 auto; padding:10px 16px; display:flex; gap:12px; align-items:center; justify-content:space-between; }
    .brand { display:flex; gap:10px; align-items:center; text-decoration:none; color:inherit; }
    .brand-circle { width:52px; height:52px; border-radius:50%; background:var(--brand-bg); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:18px; }
    .brand-title { font-weight:700; margin:0; font-size:16px; }
    .brand-sub { margin:0; font-size:12px; color:var(--muted); }

    .nav-short { display:flex; gap:8px; align-items:center; }
    .nav-short a { color:#444; padding:6px 8px; border-radius:6px; text-decoration:none; font-size:15px;}
    .nav-short a.active, .nav-short a:hover { color:var(--primary); font-weight:600; }

    .right-controls { display:flex; gap:10px; align-items:center; }

    .search-xs { display:flex; gap:6px; align-items:center; }
    .search-xs input { width:260px; max-width:36vw; height:36px; border-radius:8px; border:1px solid #e6ecf8; padding:6px 10px; }

    .cart-badge { position:relative; top:-8px; left:-6px; font-size:.72rem; }

    @media (max-width: 992px) {
      .nav-short { display:none; }
      .search-xs { display:none; }
      .brand-sub { display:none; }
    }

    /* Value cards */
    .value-card { border-radius:12px; padding:20px; background:#fff; border:1px solid #eef5ff; transition:transform .18s, box-shadow .18s; }
    .value-card:hover { transform:translateY(-6px); box-shadow:0 10px 30px rgba(13,110,253,0.06); }
  </style>
</head>
<body>

<!-- ===== Compact Header ===== -->
<header class="header">
  <div class="hdr-inner">
    <!-- left: brand -->
    <a href="index.php" class="brand" aria-label="Trang chủ">
      <div class="brand-circle" aria-hidden="true">AE</div>
      <div class="d-none d-md-block">
        <p class="brand-title mb-0"><?= esc(site_name($conn)) ?></p>
        <p class="brand-sub mb-0">Thời trang nam cao cấp</p>
      </div>
    </a>

    <!-- center: nav (desktop) -->
    <nav class="nav-short" role="navigation" aria-label="Menu chính">
      <a class="<?= is_active('index.php') ?>" href="index.php">Trang chủ</a>
      <a class="<?= is_active('sanpham.php') ?>" href="sanpham.php">Sản phẩm</a>
      <!-- changed: now points to sale.php -->
      <a class="<?= is_active('sale.php') ?>" href="sale.php">Danh mục sale</a>
      <a href="about.php">Giới Thiệu</a>
    </nav>

    <!-- right -->
    <div class="right-controls">
      <form class="search-xs d-none d-lg-flex" method="get" action="sanpham.php" role="search" aria-label="Tìm sản phẩm">
        <input type="search" name="q" placeholder="Tìm sản phẩm, mã..." aria-label="Tìm sản phẩm">
        <button type="submit" class="btn btn-dark btn-sm"><i class="bi bi-search"></i></button>
      </form>

      <a href="account.php" class="btn btn-link text-decoration-none">
        <i class="bi bi-person" style="color:var(--primary); font-size:18px;"></i>
      </a>

      <div class="dropdown d-none d-md-block">
        <a class="btn btn-link text-decoration-none" href="#" data-bs-toggle="dropdown">việt</a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="?lang=vi">Tiếng Việt</a></li>
          <li><a class="dropdown-item" href="?lang=en">English</a></li>
        </ul>
      </div>

      <a href="cart.php" class="btn btn-outline-primary position-relative" aria-label="Giỏ hàng">
        <i class="bi bi-bag" style="font-size:18px"></i>
        <span class="d-none d-md-inline ms-2">Giỏ hàng</span>
        <span id="cart-count-badge" class="badge bg-danger rounded-pill cart-badge"><?= (int)$cart_count ?></span>
      </a>

      <!-- mobile menu button -->
      <button class="btn btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-controls="mobileMenu">
        <i class="bi bi-list" style="font-size:20px"></i>
      </button>
    </div>
  </div>
</header>

<!-- Mobile offcanvas -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
  <div class="offcanvas-header">
    <h5 id="mobileMenuLabel"><?= esc(site_name($conn)) ?></h5>
    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Đóng"></button>
  </div>
  <div class="offcanvas-body">
    <form class="d-flex mb-3" role="search" method="get" action="sanpham.php">
      <input class="form-control form-control-sm me-2" type="search" name="q" placeholder="Tìm sản phẩm...">
      <button class="btn btn-sm btn-dark" type="submit"><i class="bi bi-search"></i></button>
    </form>
    <ul class="list-unstyled">
      <li class="mb-2"><a href="index.php" class="text-decoration-none">Trang chủ</a></li>
      <li class="mb-2"><a href="sanpham.php" class="text-decoration-none">Sản phẩm</a></li>
      <!-- changed here too: mobile menu -> sale.php -->
      <li class="mb-2"><a href="sale.php" class="text-decoration-none">Danh mục sale</a></li>
      <li class="mb-2"><a href="stores.php" class="text-decoration-none">Giới Thiệu</a></li>
      <li class="mb-2"><a href="about.php" class="text-decoration-none">Giới thiệu</a></li>
      <li class="mb-2"><a href="contact.php" class="text-decoration-none">Liên hệ</a></li>
    </ul>
  </div>
</div>

<!-- ===== Hero ===== -->
<section class="py-5 text-center" style="background:linear-gradient(135deg,#f6f9ff,#ffffff);">
  <div class="container">
    <h1 class="fw-bold">Về <?= esc(site_name($conn)) ?></h1>
    <p class="lead text-muted">Chúng tôi mang đến những sản phẩm thời trang chất lượng, xu hướng mới và dịch vụ tận tâm.</p>
  </div>
</section>

<!-- ===== Main content ===== -->
<main class="container my-5">
  <div class="row g-4 align-items-center">
    <div class="col-lg-6">
      <img src="images/about-banner.jpg" alt="About banner" class="img-fluid rounded shadow-sm" loading="lazy">
    </div>
    <div class="col-lg-6">
      <h3 class="fw-bold">Câu chuyện của chúng tôi</h3>
      <p class="text-muted" style="line-height:1.7;">
        <?= esc(site_name($conn)) ?> ra đời từ đam mê thời trang và mong muốn đem đến trải nghiệm mua sắm tối ưu cho khách hàng.
        Chúng tôi chọn lựa sản phẩm kỹ càng, chú trọng chất liệu và thiết kế để mỗi sản phẩm đến tay bạn là một lựa chọn đáng giá.
      </p>
      <p class="text-muted mb-0">Thời trang không chỉ là vẻ ngoài — đó là phong cách, là sự tự tin.</p>
    </div>
  </div>

  <section class="py-5 mt-4" style="background:#f8fbff; border-radius:12px;">
    <div class="container py-4">
      <h2 class="text-center fw-bold mb-4">Giá trị cốt lõi</h2>
      <div class="row g-4">
        <div class="col-md-4">
          <div class="value-card text-center">
            <div style="font-size:32px">💎</div>
            <h5 class="fw-bold mt-2">Chất lượng hàng đầu</h5>
            <p class="text-muted">Sản phẩm được chọn lọc kỹ lưỡng, đảm bảo chất lượng và độ bền.</p>
          </div>
        </div>

        <div class="col-md-4">
          <div class="value-card text-center">
            <div style="font-size:32px">⚡</div>
            <h5 class="fw-bold mt-2">Dịch vụ nhanh chóng</h5>
            <p class="text-muted">Giao hàng toàn quốc, đóng gói cẩn thận, xử lý đơn hàng nhanh.</p>
          </div>
        </div>

        <div class="col-md-4">
          <div class="value-card text-center">
            <div style="font-size:32px">❤️</div>
            <h5 class="fw-bold mt-2">Khách hàng là ưu tiên</h5>
            <p class="text-muted">Tư vấn tận tâm, hỗ trợ đổi trả dễ dàng trong 7 ngày.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div class="row g-4 mt-4 align-items-center">
    <div class="col-md-6">
      <h3 class="fw-bold">Tầm nhìn & Sứ mệnh</h3>
      <p class="text-muted" style="line-height:1.7;">
        - Trở thành thương hiệu thời trang được yêu thích nhất bởi giới trẻ Việt Nam.<br>
        - Mang đến sản phẩm giá tốt với chất lượng vượt mong đợi.<br>
        - Xây dựng cộng đồng thời trang năng động và sáng tạo.
      </p>
    </div>
    <div class="col-md-6">
      <img src="images/about-vision.jpg" alt="Vision" class="img-fluid rounded shadow-sm" loading="lazy">
    </div>
  </div>

  <div class="text-center mt-5">
    <a href="sanpham.php" class="btn btn-primary btn-lg">Khám phá bộ sưu tập</a>
  </div>
</main>

<!-- ===== Footer ===== -->
<footer style="background:#0b1220; color:#dfefff; padding:28px 0;">
  <div class="container text-center">
    <div class="row align-items-center">
      <div class="col-md-6 text-md-start mb-3 mb-md-0">
        <strong><?= esc(site_name($conn)) ?></strong><br>
        <small class="text-muted" style="color:#cbdaf7;">Địa chỉ: 123 Đường ABC, Quận XYZ — Điện thoại: 0123 456 789</small>
      </div>
      <div class="col-md-6 text-md-end">
        <a href="#" style="color:#dfefff; text-decoration:none; margin-right:12px;">Chính sách</a>
        <a href="contact.php" style="color:#dfefff; text-decoration:none;">Liên hệ</a>
      </div>
    </div>
    <div class="mt-3">
      <small class="text-muted" style="color:#cbdaf7;">© <?= date('Y') ?> <?= esc(site_name($conn)) ?> — Bảo lưu mọi quyền.</small>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- helper: update cart badge -->
<script>
  function setCartCount(n){
    const el = document.getElementById('cart-count-badge');
    if(el) el.textContent = n;
  }
</script>
</body>
</html>

