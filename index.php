<?php
// index.php (full - header cải tiến, megamenu danh mục đẹp hơn, mini-cart dropdown)
// Yêu cầu: db.php, inc/helpers.php, assets/css/style.css, assets/js/main.js
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// load danh mục + featured + promos + latest
$cats = $conn->query("SELECT * FROM danh_muc WHERE trang_thai=1 ORDER BY thu_tu ASC")->fetchAll();
$featuredCats = array_slice($cats, 0, 6);

$promos = $conn->query("SELECT * FROM san_pham WHERE trang_thai=1 AND gia_cu IS NOT NULL AND gia_cu>gia ORDER BY created_at DESC LIMIT 12")->fetchAll();
$latest = $conn->query("SELECT * FROM san_pham WHERE trang_thai=1 ORDER BY created_at DESC LIMIT 12")->fetchAll();

// helper: ensure cart session keys normalized when adding products elsewhere in site
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// cart total count (for badge) — safe count
$cart_count = 0;
foreach ($_SESSION['cart'] as $it) {
    $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1);
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= esc(site_name($conn)) ?></title>

  <!-- Bootstrap + icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">

  <style>
    /* ---------- Header / Megamenu styling ---------- */
    :root { --ae-primary: #0d6efd; --muted: #6c757d; --card-bg:#ffffff; --accent:#0b1220; }
    .ae-header { background:#fff; border-bottom:1px solid #eef3f8; position:sticky; top:0; z-index:1050; }
    .ae-header .container { max-width:1200px; }
    .ae-brand { display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; }
    .ae-logo-mark { width:50px;height:50px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:18px; }
    .ae-logo-title { line-height:1; }
    .ae-logo-title .title { font-weight:800; font-size:18px; }
    .ae-logo-title .subtitle { font-size:12px; color:var(--muted); margin-top:2px; }

    .ae-mainnav { display:flex; gap:12px; align-items:center; justify-content:center; flex:1; }
    .ae-mainnav .nav-link { color:#333; padding:10px 14px; border-radius:10px; font-weight:600; text-decoration:none; }
    .ae-mainnav .nav-link:hover, .ae-mainnav .nav-link.active { background:rgba(13,110,253,0.06); color:var(--ae-primary); }

    /* mega simple vertical menu */
    .simple-cat-list .cat-name-small {
      font-size: 16px;
      font-weight: 500;
      line-height: 1.2;
      padding-left: 2px;
    }
    .simple-cat-list li a:hover .cat-name-small { color: var(--ae-primary); }

    /* Mini cart & header small improvements */
    .icon-circle { width:44px; height:44px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; background:#f6f8fb; color:var(--accent); }
    .cart-badge { position:relative; top:-10px; left:-8px; font-size:.72rem; }
    .mini-cart { min-width:340px; max-width:420px; }
    .mini-cart .mini-item img { width:56px; height:56px; object-fit:cover; border-radius:8px; }

    /* hero */
    .hero { padding:28px 0; }

    /* categories / cards */
    .category-card { border-radius:12px; transition:transform .12s, box-shadow .12s; border:1px solid #eef2f7; padding:18px; }
    .category-card:hover { transform:translateY(-6px); box-shadow:0 14px 40px rgba(13,38,59,0.04); }

    /* product card tweaks */
    .product-card { border-radius:12px; overflow:hidden; border:1px solid #eef2f7; transition:transform .14s, box-shadow .14s; }
    .product-card:hover { transform:translateY(-6px); box-shadow:0 14px 40px rgba(13,38,59,0.06); }

    /* ===== ABOUT + CONTACT sections styling ===== */
    .site-about { background: linear-gradient(135deg,#f7fbff,#ffffff); padding:48px 0; }
    .site-about .about-card { border-radius:12px; padding:24px; background:#fff; box-shadow:0 8px 30px rgba(13,38,59,0.03); }
    .site-contact { background: linear-gradient(180deg,#fff,#fbfdff); padding:48px 0; }
    .contact-card { border-radius:12px; overflow:hidden; display:flex; gap:0; box-shadow:0 10px 30px rgba(13,38,59,0.03); }
    .contact-left { padding:22px; flex:1; background:#fff; }
    .contact-right { width:360px; background:linear-gradient(180deg,#fbfdff,#f6f9ff); padding:18px; }
    .contact-right .info-item { margin-bottom:12px; }
    .map-box { border-radius:8px; overflow:hidden; border:1px solid #e9eef8; height:180px; }
    @media (max-width:991px){ .contact-card { flex-direction:column; } .contact-right{ width:100%; } .ae-mainnav{ display:none; } }
  </style>
</head>
<body>

<!-- HEADER -->
<header class="ae-header shadow-sm">
  <div class="container d-flex align-items-center gap-3 py-2">
    <a class="ae-brand" href="index.php" aria-label="<?= esc(site_name($conn)) ?>">
      <div class="ae-logo-mark" aria-hidden="true">AE</div>
      <div class="ae-logo-title d-none d-md-block">
        <div class="title"><?= esc(site_name($conn)) ?></div>
        <div class="subtitle">Thời trang nam cao cấp</div>
      </div>
    </a>

    <!-- center menu -->
    <nav class="ae-mainnav d-none d-lg-flex" role="navigation" aria-label="Main menu">
      <a class="nav-link" href="index.php">Trang chủ</a>

      <!-- improved megamenu: simple vertical list for clarity -->
      <div class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">Sản phẩm</a>
        <div class="dropdown-menu shadow-sm p-0" style="border-radius:8px; min-width:220px; overflow:hidden;">
          <div class="p-3" style="width:220px;">
            <ul class="simple-cat-list list-unstyled mb-2">
              <?php foreach ($cats as $c): ?>
                <li class="py-2">
                  <a href="category.php?slug=<?= urlencode($c['slug'] ?? '') ?>" class="text-decoration-none text-dark d-block">
                    <span class="cat-name-small"><?= esc($c['ten']) ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>

            <hr style="margin:8px 0 10px;border-color:#eee">

            <div>
              <a href="sanpham.php" class="text-decoration-none d-block small text-muted">Xem tất cả sản phẩm</a>
            </div>
          </div>
        </div>
      </div>

      <a class="nav-link" href="sale.php" style="font-weight:700; font-size:1.02rem;">Danh Mục Sale</a>
      <a class="nav-link" href="about.php">Giới Thiệu</a>
    </nav>

    <!-- right controls -->
    <div class="ms-auto d-flex align-items-center gap-2">
      <!-- search -->
      <form class="d-none d-lg-flex" method="get" action="sanpham.php" role="search" style="margin-right:8px;">
        <div class="input-group input-group-sm shadow-sm" style="border-radius:10px; overflow:hidden;">
          <input name="q" class="form-control form-control-sm" placeholder="Tìm sản phẩm, mã..." value="<?= esc($_GET['q'] ?? '') ?>" aria-label="Tìm sản phẩm">
          <button class="btn btn-dark btn-sm" type="submit"><i class="bi bi-search"></i></button>
        </div>
      </form>

      <!-- account -->
      <div class="dropdown">
        <a class="text-decoration-none d-flex align-items-center" href="#" data-bs-toggle="dropdown" aria-expanded="false">
          <div class="icon-circle" title="Tài khoản"><i class="bi bi-person-fill"></i></div>
          <span class="ms-2 d-none d-md-inline small"><?= !empty($_SESSION['user']) ? esc($_SESSION['user']['ten']) : 'Tài khoản' ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:220px;">
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

      <!-- mini cart -->
      <div class="dropdown">
        <a class="text-decoration-none position-relative d-flex align-items-center ms-2" href="#" id="miniCartBtn" data-bs-toggle="dropdown" aria-expanded="false">
          <div class="icon-circle"><i class="bi bi-bag-fill"></i></div>
          <span class="ms-2 d-none d-md-inline small">Giỏ hàng</span>
          <span class="badge bg-danger rounded-pill cart-badge"><?= (int)$cart_count ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-end mini-cart p-3 shadow-sm" aria-labelledby="miniCartBtn">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <strong>Giỏ hàng (<?= (int)$cart_count ?>)</strong>
            <a href="cart.php" class="small">Xem đầy đủ</a>
          </div>

          <?php if (empty($_SESSION['cart'])): ?>
            <div class="text-muted small">Bạn chưa có sản phẩm nào trong giỏ.</div>
            <div class="mt-3"><a href="sanpham.php" class="btn btn-sm btn-primary w-100">Mua ngay</a></div>
          <?php else: ?>
            <div style="max-height:260px; overflow:auto;">
              <?php $total=0; foreach($_SESSION['cart'] as $id=>$item):
                $qty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['sl']) ? (int)$item['sl'] : 1);
                $price = isset($item['price']) ? (float)$item['price'] : (isset($item['gia']) ? (float)$item['gia'] : 0);
                $name = $item['name'] ?? $item['ten'] ?? '';
                $img = $item['img'] ?? $item['hinh'] ?? 'images/placeholder.jpg';
                $subtotal = $qty * $price;
                $total += $subtotal;
              ?>
                <div class="d-flex gap-2 align-items-center mini-item py-2">
                  <img src="<?= esc($img) ?>" alt="<?= esc($name) ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px">
                  <div class="flex-grow-1">
                    <div class="small fw-semibold mb-1"><?= esc($name) ?></div>
                    <div class="small text-muted"><?= $qty ?> x <?= number_format($price,0,',','.') ?> ₫</div>
                  </div>
                  <div class="small"><?= number_format($subtotal,0,',','.') ?> ₫</div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
              <div class="text-muted small">Tạm tính</div>
              <div class="fw-semibold"><?= number_format($total,0,',','.') ?> ₫</div>
            </div>

            <div class="mt-3 d-grid gap-2">
              <a href="cart.php" class="btn btn-outline-secondary btn-sm">Giỏ hàng</a>
              <a href="checkout.php" class="btn btn-primary btn-sm">Thanh toán</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- mobile menu toggle -->
      <button class="btn btn-light d-lg-none ms-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-controls="mobileMenu" aria-label="Mở menu">
        <i class="bi bi-list"></i>
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
    <form action="sanpham.php" method="get" class="mb-3 d-flex">
      <input class="form-control me-2" name="q" placeholder="Tìm sản phẩm...">
      <button class="btn btn-dark">Tìm</button>
    </form>

    <ul class="list-unstyled">
      <li class="mb-2"><a href="index.php" class="text-decoration-none">Trang chủ</a></li>
      <li class="mb-2"><a href="sanpham.php" class="text-decoration-none">Sản phẩm</a></li>
      <?php foreach($cats as $c): ?>
        <li class="mb-2 ps-2"><a href="category.php?slug=<?= urlencode($c['slug']) ?>" class="text-decoration-none"><?= esc($c['ten']) ?></a></li>
      <?php endforeach; ?>
      <li class="mb-2"><a href="sale.php" class="text-decoration-none">Danh Mục Sale</a></li>
      <li class="mb-2"><a href="about.php" class="text-decoration-none">Giới Thiệu</a></li>
    </ul>
  </div>
</div>

<!-- HERO -->
<section class="hero py-3">
  <div class="container">
    <div id="heroCarousel" class="carousel slide" data-bs-ride="carousel">
      <div class="carousel-inner">
        <div class="carousel-item active">
          <img src="images/banner1.jpg" class="d-block w-100 rounded" alt="banner1" style="height:420px; object-fit:cover;">
        </div>
        <div class="carousel-item">
          <img src="images/banner2.jpg" class="d-block w-100 rounded" alt="banner2" style="height:420px; object-fit:cover;">
        </div>
        <div class="carousel-item">
          <img src="images/banner3.jpg" class="d-block w-100 rounded" alt="banner3" style="height:420px; object-fit:cover;">
        </div>
      </div>
      <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
        <span class="carousel-control-prev-icon"></span>
      </button>
      <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
        <span class="carousel-control-next-icon"></span>
      </button>
    </div>
  </div>
</section>

<!-- CATEGORIES (featured) -->
<section class="py-4">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">Danh mục</h5>
      <a href="sanpham.php" class="text-muted small">Xem tất cả</a>
    </div>
    <div class="row g-3">
      <?php foreach($featuredCats as $c): ?>
        <div class="col-6 col-sm-4 col-md-2">
          <a href="category.php?slug=<?= urlencode($c['slug']) ?>" class="text-decoration-none">
            <div class="category-card text-center p-3">
              <div class="fw-semibold"><?= esc($c['ten']) ?></div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- PROMO -->
<section class="py-4 bg-light">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">Sản phẩm khuyến mãi</h5>
      <a href="#" class="text-muted small">Xem thêm</a>
    </div>
    <div class="row g-3">
      <?php foreach($promos as $p):
        $imgStmt = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
        $imgStmt->execute(['id'=>$p['id_san_pham']]);
        $imgp = $imgStmt->fetchColumn() ?: 'images/placeholder.jpg';
        $discount = ($p['gia_cu'] && $p['gia_cu']>$p['gia']) ? round((($p['gia_cu']-$p['gia'])/$p['gia_cu'])*100) : 0;
      ?>
      <div class="col-6 col-sm-4 col-md-3">
        <div class="card product-card h-100 position-relative">
          <div class="position-relative">
            <img src="<?= esc($imgp) ?>" class="card-img-top p-3" style="height:220px;object-fit:contain;">
            <?php if($discount>0): ?><div class="badge bg-danger sale-badge position-absolute" style="right:12px; top:12px">-<?= $discount ?>%</div><?php endif; ?>
            <div class="product-actions" style="right:8px; top:8px;">
              <button class="btn btn-sm btn-light" data-product='<?= json_encode([
                'id'=>$p['id_san_pham'],
                'name'=>$p['ten'],
                'gia_raw'=>$p['gia'],
                'price'=>$p['gia'],
                'mo_ta'=>mb_substr(strip_tags($p['mo_ta']),0,220),
                'img'=>$imgp
              ], JSON_UNESCAPED_UNICODE) ?>' onclick="openQuickView(this)"><i class="bi bi-eye"></i></button>

              <form method="post" action="cart.php" class="d-inline">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="id" value="<?= $p['id_san_pham'] ?>">
                <button class="btn btn-sm btn-success"><i class="bi bi-cart-plus"></i></button>
              </form>
            </div>
          </div>

          <div class="card-body d-flex flex-column">
            <h6 class="card-title"><?= esc($p['ten']) ?></h6>
            <div class="mt-auto">
              <div class="d-flex align-items-center">
                <div class="product-price me-2"><?= price($p['gia']) ?></div>
                <?php if($p['gia_cu'] && $p['gia_cu']>$p['gia']): ?><div class="text-muted text-decoration-line-through small"><?= number_format($p['gia_cu'],0,',','.') ?> ₫</div><?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- LATEST -->
<section class="py-4">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0">Sản phẩm mới</h5>
      <a href="#" class="text-muted small">Xem thêm</a>
    </div>
    <div class="row g-3">
      <?php foreach($latest as $p):
        $imgStmt = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
        $imgStmt->execute(['id'=>$p['id_san_pham']]);
        $imgp = $imgStmt->fetchColumn() ?: 'images/placeholder.jpg';
      ?>
      <div class="col-6 col-sm-4 col-md-3">
        <div class="card h-100 product-card">
          <img src="<?= esc($imgp) ?>" class="card-img-top p-3" style="height:220px;object-fit:contain;">
          <div class="card-body d-flex flex-column">
            <h6 class="card-title"><?= esc($p['ten']) ?></h6>
            <div class="mt-auto d-flex justify-content-between align-items-center">
              <div class="product-price"><?= price($p['gia']) ?></div>
              <a href="product.php?id=<?= $p['id_san_pham'] ?>" class="btn btn-sm btn-outline-primary">Xem</a>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ===== ABOUT (mô tả ngắn, đẹp) ===== -->
<section class="site-about">
  <div class="container">
    <div class="row align-items-center g-4">
      <div class="col-lg-6">
        <div class="about-card">
          <h3 class="fw-bold mb-2">Về <?= esc(site_name($conn)) ?></h3>
          <p class="text-muted mb-3">
            <?= esc(site_name($conn)) ?> chuyên cung cấp trang phục nam theo phong cách hiện đại — chú trọng chất liệu,
            kiểu dáng và trải nghiệm mua sắm. Chúng tôi lựa chọn từng sản phẩm kỹ lưỡng để mang đến giá trị thực sự cho khách hàng.
          </p>

          <div class="row g-3">
            <div class="col-6">
              <div class="p-3 border rounded">
                <div class="fw-semibold">Chất lượng</div>
                <div class="text-muted small">Sản phẩm bền & hợp xu hướng.</div>
              </div>
            </div>
            <div class="col-6">
              <div class="p-3 border rounded">
                <div class="fw-semibold">Dịch vụ</div>
                <div class="text-muted small">Giao hàng nhanh & đổi trả thuận tiện.</div>
              </div>
            </div>
            <div class="col-6">
              <div class="p-3 border rounded">
                <div class="fw-semibold">Uy tín</div>
                <div class="text-muted small">Hỗ trợ khách hàng tận tâm.</div>
              </div>
            </div>
            <div class="col-6">
              <div class="p-3 border rounded">
                <div class="fw-semibold">Giá cả</div>
                <div class="text-muted small">Cân bằng giữa giá & chất lượng.</div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <div class="col-lg-6 text-center">
        <img src="images/about-banner.jpg" alt="About" class="img-fluid rounded shadow-sm" style="max-height:360px; object-fit:cover;">
      </div>
    </div>
  </div>
</section>

<!-- ===== CONTACT (gọn, đẹp) ===== -->
<section class="site-contact">
  <div class="container">
    <div class="mb-4 text-center">
      <h3 class="fw-bold">Liên hệ</h3>
      <p class="text-muted">Cần hỗ trợ? Gửi yêu cầu hoặc gọi cho chúng tôi — luôn sẵn sàng phục vụ.</p>
    </div>

    <div class="contact-card">
      <div class="contact-left">
        <form action="contact.php" method="get" class="row g-3">
          <div class="col-md-6">
            <input name="ten" class="form-control form-control-lg" placeholder="Họ & tên" required>
          </div>
          <div class="col-md-6">
            <input name="email" type="email" class="form-control form-control-lg" placeholder="Email" required>
          </div>
          <div class="col-md-6">
            <input name="dien_thoai" class="form-control" placeholder="Số điện thoại (tuỳ chọn)">
          </div>
          <div class="col-md-6">
            <input name="tieu_de" class="form-control" placeholder="Tiêu đề">
          </div>
          <div class="col-12">
            <textarea name="noi_dung" rows="5" class="form-control" placeholder="Nội dung liên hệ..." required></textarea>
          </div>
          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary">Gửi liên hệ</button>
            <a href="contact.php" class="btn btn-outline-secondary">Hoặc đến trang Liên hệ chi tiết</a>
          </div>
        </form>
      </div>

      <div class="contact-right">
        <div class="mb-3">
          <div class="fw-semibold"><?= esc(site_name($conn)) ?></div>
          <div class="text-muted small">123 Đường ABC, Quận XYZ</div>
          <div class="text-muted small">Hotline: <a href="tel:0123456789">0123 456 789</a></div>
          <div class="text-muted small">Email: <a href="mailto:info@example.com">info@example.com</a></div>
        </div>

        <hr>

        <div class="mb-3">
          <div class="fw-semibold mb-2">Giờ mở cửa</div>
          <div class="text-muted small">T2 - T7: 08:30 — 18:00<br>CN: Nghỉ</div>
        </div>

        <div class="mb-3">
          <div class="fw-semibold mb-2">Bản đồ</div>
          <div class="map-box">
            <iframe src="https://www.google.com/maps?q=Hanoi&output=embed" style="width:100%;height:100%;border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
          </div>
        </div>

      </div>
    </div>
  </div>
</section>

<!-- QUICK VIEW MODAL -->
<div class="modal fade" id="quickViewModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-4">
        <div class="row">
          <div class="col-md-6" id="qv-image"></div>
          <div class="col-md-6">
            <h5 id="qv-title"></h5>
            <p id="qv-desc" class="text-muted small"></p>
            <div class="h4 text-danger mb-3" id="qv-price"></div>
            <form method="post" action="cart.php">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="id" id="qv-id" value="">
              <div class="mb-3">
                <label>Số lượng</label>
                <input type="number" name="qty" value="1" class="form-control" min="1">
              </div>
              <button class="btn btn-success">Thêm vào giỏ</button>
            </form>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button></div>
    </div>
  </div>
</div>

<footer class="footer bg-dark text-white py-4 mt-4">
  <div class="container text-center">
    <small><?= esc(site_name($conn)) ?> — © <?= date('Y') ?> — Hotline: 0123 456 789</small>
  </div>
</footer>

<!-- SCRIPTS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
// QuickView handler (uses data-product JSON in product buttons)
function openQuickView(btn){
  try {
    const data = JSON.parse(btn.getAttribute('data-product'));
    document.getElementById('qv-title').textContent = data.name || '';
    document.getElementById('qv-desc').textContent = data.mo_ta || data.desc || '';
    document.getElementById('qv-price').textContent = new Intl.NumberFormat('vi-VN').format(data.price || data.gia_raw || 0) + ' ₫';
    document.getElementById('qv-id').value = data.id || '';
    document.getElementById('qv-image').innerHTML = '<img src="'+(data.img||'images/placeholder.jpg')+'" class="img-fluid rounded">';
    var myModal = new bootstrap.Modal(document.getElementById('quickViewModal'));
    myModal.show();
  } catch(e) {
    console.error('QV error', e);
  }
}
</script>

<script src="assets/js/main.js"></script>
</body>
</html>
