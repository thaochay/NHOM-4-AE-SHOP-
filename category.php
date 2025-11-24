<?php
// category.php - hiển thị danh mục và sản phẩm, menu & quickview giống index.php
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

/**
 * getProductMainImageFromDB
 */
function getProductMainImageFromDB($conn, $product_id, $placeholder = 'images/placeholder.jpg') {
    try {
        $stmt = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
        $stmt->execute(['id'=>$product_id]);
        $path = $stmt->fetchColumn();
        if (!$path) {
            $stmt2 = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id ORDER BY thu_tu ASC, id_anh ASC LIMIT 1");
            $stmt2->execute(['id'=>$product_id]);
            $path = $stmt2->fetchColumn();
        }
    } catch (Exception $e) {
        $path = null;
    }

    if (!$path) {
        return $placeholder;
    }

    $path = trim($path);
    if (preg_match('#^https?://#i', $path)) return $path;

    $candidates = [
        ltrim($path, '/'),
        'images/' . ltrim($path, '/'),
        'images/products/' . ltrim($path, '/'),
        'uploads/' . ltrim($path, '/'),
        'public/' . ltrim($path, '/'),
        'images/' . basename($path),
        'images/products/' . basename($path),
    ];
    $candidates = array_values(array_unique($candidates));
    foreach ($candidates as $c) {
        if (file_exists(__DIR__ . '/' . $c) && filesize(__DIR__ . '/' . $c) > 0) {
            return $c;
        }
    }

    if (file_exists(__DIR__ . '/' . $placeholder) && filesize(__DIR__ . '/' . $placeholder) > 0) return $placeholder;
    return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
}

/* --- read slug param --- */
$slug = trim($_GET['slug'] ?? '');
if ($slug === '') { header('Location: index.php'); exit; }

/* fetch category */
$catStmt = $conn->prepare("SELECT * FROM danh_muc WHERE slug = :s LIMIT 1");
$catStmt->execute(['s'=>$slug]);
$cat = $catStmt->fetch(PDO::FETCH_ASSOC);
if (!$cat) { header('Location: index.php'); exit; }

/* fetch products in category (only active) */
$prodStmt = $conn->prepare("SELECT id_san_pham, ten, gia, gia_cu, so_luong, trang_thai, mo_ta FROM san_pham WHERE id_danh_muc = :id AND trang_thai = 1 ORDER BY id_san_pham DESC");
$prodStmt->execute(['id'=>$cat['id_danh_muc']]);
$products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

/* fetch categories for menu */
$catsMenu = [];
try {
    $cstmt = $conn->query("SELECT id_danh_muc, ten, slug FROM danh_muc WHERE trang_thai = 1 ORDER BY thu_tu ASC");
    $catsMenu = $cstmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $catsMenu = []; }

/* cart count for header */
$cart = $_SESSION['cart'] ?? [];
$cart_count = 0;
foreach ($cart as $it) $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1);

$pageTitle = esc($cat['ten']) . ' — ' . esc(site_name($conn));
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title><?= $pageTitle ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
  :root{
    --accent:#0b7bdc;
    --muted:#6c757d;
    --nav-bg:#ffffff;
  }
  body{background:#f8fbff; color:#102a43;}
  .ae-navbar{background:var(--nav-bg);box-shadow:0 6px 18px rgba(11,38,80,0.04);backdrop-filter:blur(12px)}
  .ae-logo-mark{width:46px;height:46px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800}

  /* header */
  .ae-header { background:#fff; border-bottom:1px solid #eef3f8; position:sticky; top:0; z-index:1050; }
  .brand { display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; }

  /* centered nav */
  .nav-center { display:flex; gap:8px; align-items:center; justify-content:center; flex:1; }
  .nav-center .nav-link { color:#333; padding:8px 12px; border-radius:8px; font-weight:600; text-decoration:none; transition:all .15s; }
  .nav-center .nav-link.active, .nav-center .nav-link:hover { color:var(--accent); background:rgba(11,123,220,0.06); }

  /* orders button */
  .nav-orders{ padding-inline:0.9rem; margin-left:.25rem; border-radius:999px; background:rgba(11,123,220,.06); display:flex; align-items:center; gap:.35rem; text-decoration:none; color:inherit; }
  .nav-orders:hover{ background:rgba(11,123,220,.12); color:var(--accent); }

  /* product grid */
  .product-card{ border-radius:12px; overflow:hidden; border:1px solid #eef3f7; transition:transform .14s, box-shadow .14s; background:#fff; }
  .product-card:hover{ transform:translateY(-6px); box-shadow:0 18px 40px rgba(11,38,80,0.06); }
  .prod-img{ width:100%; height:200px; object-fit:contain; background:#fff; padding:12px; }
  .price{ color:var(--accent); font-weight:800; }
  .old-price{ text-decoration:line-through; color:#9fb0c8; margin-left:8px; font-weight:700; }
  .badge-sale{ position:absolute; left:10px; top:10px; z-index:2; border-radius:10px; padding:6px 8px; font-weight:700; }

  /* quickview clickable */
  .qv-clickable{ cursor:pointer; border-radius:10px; transition:transform .12s, box-shadow .12s; }
  .qv-clickable:hover{ transform:translateY(-4px); box-shadow:0 14px 34px rgba(15,23,42,0.08); }

  /* no products */
  .no-products { padding:40px; text-align:center; background:#fff; border-radius:12px; border:1px solid #eef6ff; box-shadow:0 6px 18px rgba(11,38,80,0.02); }

  /* quickview modal styles */
  #quickViewModal .modal-content{ border-radius:20px; border:none; box-shadow:0 22px 80px rgba(15,23,42,0.35); }
  #quickViewModal .modal-body{ background:radial-gradient(circle at top,#f5f9ff,#ffffff); }
  #quickViewModal .qv-main{ border-radius:16px; background:#fff; box-shadow:0 14px 40px rgba(15,23,42,0.08); }
  #quickViewModal .nav-tabs .nav-link.active{ color:#111827; border-color:var(--accent); }

  @media (max-width:991px){ .nav-center{ display:none; } .search-input{ display:none; } }
  </style>
</head>
<body>

<!-- NAV / HEADER (giống index) -->
<header class="ae-header">
  <div class="container d-flex align-items-center gap-3 py-2">
    <a class="brand" href="index.php" aria-label="<?= esc(site_name($conn)) ?>">
      <div class="ae-logo-mark">AE</div>
      <div class="d-none d-md-block">
        <div style="font-weight:800"><?= esc(site_name($conn)) ?></div>
        <div style="font-size:12px;color:var(--muted)">Thời trang nam cao cấp</div>
      </div>
    </a>

    <nav class="nav-center d-none d-lg-flex" role="navigation" aria-label="Main menu">
      <a class="nav-link" href="index.php">Trang chủ</a>

      <div class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">Sản Phẩm</a>
        <ul class="dropdown-menu p-2">
          <?php if (!empty($catsMenu)): foreach($catsMenu as $c): ?>
            <li><a class="dropdown-item" href="category.php?slug=<?= urlencode($c['slug']) ?>"><?= esc($c['ten']) ?></a></li>
          <?php endforeach; else: ?>
            <li><span class="dropdown-item text-muted">Chưa có danh mục</span></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item text-muted" href="sanpham.php">Xem tất cả sản phẩm</a></li>
        </ul>
      </div>
      <a class="nav-link" href="about.php">Giới thiệu</a>
      <a class="nav-link" href="contact.php">Liên hệ</a>

      <a class="nav-orders" href="orders.php" title="Đơn hàng của tôi">
        <i class="bi bi-receipt-cutoff"></i>
        <span class="d-none d-lg-inline ms-1">Đơn hàng của tôi</span>
      </a>
    </nav>

    <div class="ms-auto d-flex align-items-center gap-2">
      <form class="d-none d-lg-flex" action="sanpham.php" method="get" role="search">
        <div class="input-group input-group-sm shadow-sm" style="border-radius:10px; overflow:hidden;">
          <input name="q" class="form-control form-control-sm search-input" placeholder="Tìm sản phẩm, mã..." value="<?= esc($_GET['q'] ?? '') ?>">
          <button class="btn btn-dark btn-sm" type="submit"><i class="bi bi-search"></i></button>
        </div>
      </form>

      <div class="dropdown">
        <a class="text-decoration-none d-flex align-items-center" href="#" data-bs-toggle="dropdown" aria-expanded="false">
          <div style="width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:#0b1220"><i class="bi bi-person-fill"></i></div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end p-2">
          <?php if(empty($_SESSION['user'])): ?>
            <li><a class="dropdown-item" href="login.php">Đăng nhập</a></li>
            <li><a class="dropdown-item" href="register.php">Tạo tài khoản</a></li>
          <?php else: ?>
            <li><a class="dropdown-item" href="account.php">Tài khoản</a></li>
            <li><a class="dropdown-item" href="orders.php">Đơn hàng</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php">Đăng xuất</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="dropdown">
        <a class="text-decoration-none position-relative d-flex align-items-center" href="#" id="miniCartBtn" data-bs-toggle="dropdown" aria-expanded="false">
          <div style="width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:#0b1220"><i class="bi bi-bag-fill"></i></div>
          <span class="ms-2 d-none d-md-inline small">Giỏ hàng</span>
          <span class="badge bg-danger rounded-pill" style="position:relative;top:-10px;left:-8px"><?= (int)$cart_count ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-end p-3" aria-labelledby="miniCartBtn" style="min-width:320px;">
          <?php if (empty($_SESSION['cart'])): ?>
            <div class="small text-muted">Bạn chưa có sản phẩm nào trong giỏ.</div>
            <div class="mt-3 d-grid gap-2">
              <a href="sanpham.php" class="btn btn-primary btn-sm">Mua ngay</a>
            </div>
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
</header>

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
      <?php foreach($catsMenu as $c): ?>
        <li class="mb-2 ps-2"><a href="category.php?slug=<?= urlencode($c['slug']) ?>" class="text-decoration-none"><?= esc($c['ten']) ?></a></li>
      <?php endforeach; ?>
      <li class="mb-2"><a href="about.php" class="text-decoration-none">Giới thiệu</a></li>
      <li class="mb-2"><a href="contact.php" class="text-decoration-none">Liên hệ</a></li>
    </ul>
  </div>
</div>

<!-- PAGE -->
<main class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <a href="index.php" class="text-decoration-none small">&larr; Trang chủ</a>
      <h3 class="mt-1 mb-0"><?= esc($cat['ten']) ?></h3>
      <?php if (!empty($cat['mo_ta'])): ?><div class="small text-muted"><?= esc(mb_strimwidth($cat['mo_ta'],0,180,'...')) ?></div><?php endif; ?>
    </div>
    <div class="text-muted small">Tổng: <strong><?= count($products) ?></strong> sản phẩm</div>
  </div>

  <div class="row g-3">
    <?php if (empty($products)): ?>
      <div class="col-12">
        <div class="no-products">
          <h5>Chưa có sản phẩm trong danh mục này</h5>
          <p class="text-muted">Bạn có thể thử xem các danh mục khác hoặc quay về <a href="index.php">trang chủ</a>.</p>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($products as $p):
      $pid = (int)$p['id_san_pham'];
      $imgp = getProductMainImageFromDB($conn, $pid);
      $discount = 0;
      if (!empty($p['gia_cu']) && $p['gia_cu'] > $p['gia']) $discount = (int) round((($p['gia_cu'] - $p['gia']) / $p['gia_cu']) * 100);

      // payload for quickview
      $payload = [
        'id' => $pid,
        'name' => $p['ten'],
        'gia_raw' => $p['gia'],
        'price' => $p['gia'],
        'mo_ta' => mb_substr(strip_tags($p['mo_ta'] ?? ''),0,400),
        'img' => $imgp,
        'stock' => (int)$p['so_luong'],
        'thumbs' => [$imgp],
      ];
      $payloadJson = htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    ?>
    <div class="col-6 col-sm-4 col-md-3">
      <div class="product-card position-relative h-100">
        <?php if ($discount>0): ?><div class="badge bg-danger text-white badge-sale">-<?= $discount ?>%</div><?php endif; ?>

        <!-- image clickable opens QuickView -->
        <div class="qv-clickable" data-product="<?= $payloadJson ?>" onclick="openQuickView(this)">
          <img src="<?= esc($imgp) ?>" alt="<?= esc($p['ten']) ?>" class="prod-img d-block mx-auto">
        </div>

        <div class="card-body">
          <a href="sanpham_chitiet.php?id=<?= $pid ?>" class="text-decoration-none text-dark"><h6 class="mb-2" style="font-size:0.95rem"><?= esc($p['ten']) ?></h6></a>
          <div class="d-flex align-items-center">
            <div class="price"><?= price($p['gia']) ?></div>
            <?php if (!empty($p['gia_cu']) && $p['gia_cu'] > $p['gia']): ?>
              <div class="old-price"><?= number_format($p['gia_cu'],0,',','.') ?> ₫</div>
            <?php endif; ?>
            <div class="ms-auto small text-muted">Còn <?= (int)$p['so_luong'] ?> sp</div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <!-- xem nhanh mở quickview -->
            <button type="button" class="btn btn-sm btn-outline-primary w-50 add-anim"
              data-product="<?= $payloadJson ?>" onclick="openQuickView(this)">Xem</button>

            <form method="post" action="cart.php?action=add" class="w-50">
              <input type="hidden" name="id" value="<?= $pid ?>">
              <input type="hidden" name="qty" value="1">
              <button class="btn btn-sm btn-success w-100"><i class="bi bi-cart-plus"></i> Thêm</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</main>

<!-- QUICKVIEW modal (same as index) -->
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

<footer class="text-center py-4 text-muted">
  <small><?= esc(site_name($conn)) ?> © <?= date('Y') ?></small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openQuickView(btn){
  try {
    const data = JSON.parse(btn.getAttribute('data-product'));
    document.getElementById('qv-title').textContent = data.name || '';
    document.getElementById('qv-short-desc').textContent = data.mo_ta || '';
    document.getElementById('qv-price').textContent = new Intl.NumberFormat('vi-VN').format(data.price || data.gia_raw || 0) + ' ₫';
    document.getElementById('qv-stock').textContent = 'Còn: ' + (data.stock !== undefined ? data.stock : '-');
    document.getElementById('qv-id').value = data.id || '';
    document.getElementById('qv-main-img').src = data.img || 'images/placeholder.jpg';

    const thumbsBox = document.getElementById('qv-thumbs');
    thumbsBox.innerHTML = '';
    let thumbs = Array.isArray(data.thumbs) && data.thumbs.length ? data.thumbs : [data.img || 'images/placeholder.jpg'];
    thumbs.forEach((t, idx) => {
      const im = document.createElement('img');
      im.src = t;
      im.className = 'qv-thumb' + (idx===0 ? ' active' : '');
      im.style.width = '70px';
      im.style.height = '70px';
      im.style.objectFit = 'cover';
      im.style.borderRadius = '8px';
      im.style.cursor = 'pointer';
      im.style.border = '2px solid transparent';
      im.onclick = function(){ document.getElementById('qv-main-img').src = t; document.querySelectorAll('.qv-thumb').forEach(x=>x.classList.remove('active')); this.classList.add('active'); };
      thumbsBox.appendChild(im);
    });

    const swBox = document.getElementById('qv-swatches'); swBox.innerHTML = '';
    if (Array.isArray(data.colors) && data.colors.length) {
      data.colors.forEach((c) => {
        const el = document.createElement('span'); el.className='swatch'; el.style.background = c; el.onclick = function(){ document.querySelectorAll('.swatch').forEach(s=>s.style.outline=''); this.style.outline='2px solid rgba(0,0,0,0.06)'; }; swBox.appendChild(el);
      });
    } else {
      ['#111','#0b7bdc','#777'].forEach(c => { const el = document.createElement('span'); el.className='swatch'; el.style.background=c; el.style.width='28px'; el.style.height='28px'; el.style.display='inline-block'; el.style.borderRadius='50%'; el.style.marginRight='6px'; swBox.appendChild(el); });
    }

    const sizeBox = document.getElementById('qv-sizes'); sizeBox.innerHTML = '';
    if (Array.isArray(data.sizes) && data.sizes.length) {
      data.sizes.forEach(sz => { const b=document.createElement('button'); b.className='size-btn'; b.innerText=sz; b.onclick=function(){document.querySelectorAll('.size-btn').forEach(x=>x.classList.remove('active')); this.classList.add('active');}; sizeBox.appendChild(b); });
    } else {
      ['S','M','L','XL'].forEach(sz => { const b=document.createElement('button'); b.className='size-btn'; b.innerText=sz; b.onclick=function(){document.querySelectorAll('.size-btn').forEach(x=>x.classList.remove('active')); this.classList.add('active');}; sizeBox.appendChild(b); });
    }

    const q = document.getElementById('qv-qty'); q.value = 1; document.getElementById('qv-id-qty').value = 1;
    q.oninput = function(){ document.getElementById('qv-id-qty').value = Math.max(1, parseInt(this.value||1)); };

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
