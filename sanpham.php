<?php
// sanpham.php - danh sách sản phẩm (giao diện giống index.php) + AJAX add-to-cart & QuickView
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
 * getProductImage - lấy ảnh sản phẩm (ưu tiên la_anh_chinh, nếu không -> first)
 * trả về path relative (không in debug).
 */
function getProductImage($conn, $product_id) {
    $placeholder = 'images/placeholder.jpg';
    try {
        $stmt = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
        $stmt->execute([':id' => $product_id]);
        $path = $stmt->fetchColumn();
        if (!$path) {
            $stmt2 = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id ORDER BY thu_tu ASC, id_anh ASC LIMIT 1");
            $stmt2->execute([':id' => $product_id]);
            $path = $stmt2->fetchColumn();
        }
    } catch (Exception $e) {
        $path = null;
    }

    if (!$path) return $placeholder;
    $path = trim($path);

    // absolute URL?
    if (preg_match('#^https?://#i', $path)) return $path;

    $candidates = [
        ltrim($path, '/'),
        'images/' . ltrim($path, '/'),
        'images/products/' . ltrim($path, '/'),
        'uploads/' . ltrim($path, '/'),
        'public/' . ltrim($path, '/'),
        'images/' . basename($path),
        'images/products/' . basename($path),
        'uploads/' . basename($path),
    ];
    $candidates = array_values(array_unique($candidates));
    foreach ($candidates as $c) {
        if (file_exists(__DIR__ . '/' . $c) && filesize(__DIR__ . '/' . $c) > 0) {
            return $c;
        }
    }
    // fallback
    return $placeholder;
}

/**
 * getBannerImage - trả về banner path (thử nhiều candidate)
 * (Hàm giữ nguyên ở đây vì có thể dùng chung trong project, nhưng phần hiển thị banner đã được xóa)
 */
function getBannerImage($filename) {
    $placeholder = 'images/placeholder-banner.jpg';
    $candidates = [
        ltrim($filename, '/'),
        'images/' . ltrim($filename, '/'),
        'assets/images/' . ltrim($filename, '/'),
        'uploads/' . ltrim($filename, '/'),
        '../images/' . ltrim($filename, '/'),
    ];
    foreach (array_values(array_unique($candidates)) as $c) {
        if (file_exists(__DIR__ . '/' . $c) && filesize(__DIR__ . '/' . $c) > 0) {
            return $c;
        }
    }
    return $placeholder;
}

/* --- load data --- */
try {
    $cats = $conn->query("SELECT * FROM danh_muc WHERE trang_thai=1 ORDER BY thu_tu ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cats = []; }

/* sản phẩm: hỗ trợ tìm kiếm / lọc / phân trang */
$q = trim((string)($_GET['q'] ?? ''));
$cat = (int)($_GET['cat'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

$where = "WHERE sp.trang_thai = 1";
$params = [];
if ($q !== '') { $where .= " AND (sp.ten LIKE :q OR sp.mo_ta LIKE :q OR sp.ma_san_pham LIKE :q)"; $params[':q'] = '%'.$q.'%'; }
if ($cat > 0) { $where .= " AND sp.id_danh_muc = :cat"; $params[':cat'] = $cat; }

/* total count */
$countSql = "SELECT COUNT(*) FROM san_pham sp $where";
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$total_items = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_items / $per_page));

/* fetch products */
$sql = "
  SELECT sp.id_san_pham, sp.ten, sp.gia, sp.gia_cu, sp.so_luong, sp.id_danh_muc, dm.ten AS danh_muc_ten, sp.mo_ta
  FROM san_pham sp
  LEFT JOIN danh_muc dm ON sp.id_danh_muc = dm.id_danh_muc
  $where
  ORDER BY sp.id_san_pham DESC
  LIMIT :limit OFFSET :offset
";
$stmt = $conn->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
$stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* cart count */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart_count = 0;
foreach ($_SESSION['cart'] as $it) {
    $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1);
}

/* banners (hàm còn nhưng ta sẽ không hiển thị phần banner) */
$banner1 = getBannerImage('banner1.jpg');
$banner2 = getBannerImage('banner2.jpg');
$banner3 = getBannerImage('banner3.jpg');

$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;

/* helper buildUrl */
function buildUrl($overrides = []) {
    $qs = array_merge($_GET, $overrides);
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($qs);
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Sản phẩm — <?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <style>
  /* copy styles from index so header/menu match exactly */
  :root{
    --accent:#0b7bdc;
    --muted:#6c757d;
    --nav-bg:#ffffff;
  }

  body{background:#f8fbff}

  .ae-navbar{
    background:var(--nav-bg);
    box-shadow:0 6px 18px rgba(11,38,80,0.04);
    backdrop-filter:blur(12px);
  }
  .ae-logo-mark{
    width:46px;height:46px;border-radius:10px;
    background:var(--accent);color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-weight:800;
  }

  .navbar-nav .nav-item + .nav-item{ margin-left:.25rem; }
  .ae-navbar .nav-link{ position:relative; padding:0.75rem 1rem; font-weight:500; font-size:.95rem; color:#1f2933; transition:color .18s ease-out; }
  .ae-navbar .nav-link::after{ content:''; position:absolute; left:1rem; right:1rem; bottom:0.35rem; height:2px; border-radius:99px; background:linear-gradient(90deg,#0b7bdc,#38bdf8); transform:scaleX(0); transform-origin:center; transition:transform .18s ease-out,opacity .18s ease-out; opacity:0; }
  .ae-navbar .nav-link:hover, .ae-navbar .nav-link:focus{ color:#0b7bdc; }
  .ae-navbar .nav-link:hover::after, .ae-navbar .nav-link:focus::after, .ae-navbar .nav-link.active::after{ transform:scaleX(1); opacity:1; }

  .nav-orders{ padding-inline:0.9rem; margin-left:.25rem; border-radius:999px; background:rgba(11,123,220,.06); display:flex; align-items:center; gap:.35rem; }
  .product-card{ border-radius:12px; background:linear-gradient(180deg,#fff,#f6fbff); border:1px solid rgba(11,38,80,0.04); transition:transform .14s, box-shadow .14s; }
  .product-card:hover{ transform:translateY(-8px); box-shadow:0 24px 60px rgba(11,38,80,0.06); }
  .qv-clickable{ cursor:pointer; border-radius:14px; background:#ffffff; transition:transform .15s, box-shadow .15s; }
  .qv-clickable:hover{ transform:translateY(-3px); box-shadow:0 14px 40px rgba(15,23,42,0.12); }
  .sale-badge{ padding:6px 8px; border-radius:10px; }
  .price-new{ font-weight:800; color:var(--accent); font-size:1.05rem; }
  .price-old{ color:#9aa8c2; text-decoration:line-through; }
  .prod-img{ height:220px; object-fit:contain; background:#fff; padding:12px; border-radius:8px; }
  .filter-card{ border-radius:12px; background:#fff; padding:14px; box-shadow:0 8px 30px rgba(11,38,80,0.03); }
  .qv-thumb{ width:70px; height:70px; object-fit:cover; border-radius:8px; cursor:pointer; border:2px solid transparent; }
  .qv-thumb.active{ border-color:var(--accent); box-shadow:0 8px 20px rgba(11,38,80,0.06); }
  @media (max-width:991px){ .nav-center{display:none;} .search-input{display:none;} }
  </style>
</head>
<body>

<!-- NAV (identical to index) -->
<nav class="navbar navbar-expand-lg ae-navbar sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <div class="ae-logo-mark">AE</div>
      <div class="d-none d-md-block">
        <div style="font-weight:800"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></div>
        <div style="font-size:12px;color:var(--muted)">Thời trang nam cao cấp</div>
      </div>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain"><span class="navbar-toggler-icon"></span></button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="index.php">Trang chủ</a></li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Sản phẩm</a>
          <ul class="dropdown-menu">
            <?php foreach($cats as $c): ?>
              <li><a class="dropdown-item" href="sanpham.php?cat=<?= (int)$c['id_danh_muc'] ?>"><?= esc($c['ten']) ?></a></li>
            <?php endforeach; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-muted" href="sanpham.php">Xem tất cả sản phẩm</a></li>
          </ul>
        </li>

        <li class="nav-item"><a class="nav-link" href="about.php">Giới Thiệu</a></li>
        <li class="nav-item"><a class="nav-link" href="contact.php">Liên hệ</a></li>
        <li class="nav-item">
          <a class="nav-link nav-orders" href="orders.php">
            <i class="bi bi-receipt-cutoff"></i>
            <span class="d-none d-lg-inline ms-1">Đơn hàng của tôi</span>
          </a>
        </li>
      </ul>

      <div class="d-flex align-items-center gap-2">
        <form class="d-none d-lg-flex" method="get" action="sanpham.php"><div class="input-group input-group-sm"><input name="q" class="form-control" placeholder="Tìm sản phẩm, mã..." value="<?= esc($q) ?>"><button class="btn btn-dark" type="submit"><i class="bi bi-search"></i></button></div></form>

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
            <div class="d-flex justify-content-between align-items-center mb-2"><strong>Giỏ hàng (<?= (int)$cart_count ?>)</strong><a href="cart.php" class="small">Xem đầy đủ</a></div>
            <?php if (empty($_SESSION['cart'])): ?>
              <div class="text-muted small">Bạn chưa có sản phẩm nào trong giỏ.</div>
              <div class="mt-3"><a href="sanpham.php" class="btn btn-primary btn-sm w-100">Mua ngay</a></div>
            <?php else: ?>
              <div id="cartDropdownItems" style="max-height:240px;overflow:auto">
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
              <div class="d-flex justify-content-between align-items-center mt-3"><div class="text-muted small">Tạm tính</div><div id="cartDropdownSubtotal" class="fw-semibold"><?= number_format($total,0,',','.') ?> ₫</div></div>
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

<!-- NOTE: Banner/hero removed as requested -->

<!-- PAGE: product listing uses index layout -->
<div class="container my-5">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <h3 class="mb-0">Sản phẩm</h3>
      <?php if ($q): ?><div class="small text-muted">Kết quả tìm kiếm: "<?= esc($q) ?>"</div><?php endif; ?>
    </div>
    <a href="index.php" class="btn btn-link">&larr; Trang chủ</a>
  </div>

  <div class="row g-3">
    <!-- SIDEBAR -->
    <aside class="col-md-3">
      <div class="filter-card mb-3">
        <form method="get" action="sanpham.php">
          <div class="mb-2"><label class="form-label small">Tìm kiếm</label>
            <input name="q" value="<?= esc($q) ?>" class="form-control form-control-sm" placeholder="Tên, mã..."></div>

          <div class="mb-2"><label class="form-label small">Danh mục</label>
            <select name="cat" class="form-select form-select-sm">
              <option value="0">Tất cả</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int)$c['id_danh_muc'] ?>" <?= $cat === (int)$c['id_danh_muc'] ? 'selected' : '' ?>><?= esc($c['ten']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="d-grid"><button class="btn btn-primary btn-sm">Lọc</button></div>
        </form>

        <hr>
        <div class="small text-muted">Hiển thị <?= count($products) ?> / <?= $total_items ?> sản phẩm</div>
      </div>
    </aside>

    <!-- PRODUCTS -->
    <section class="col-md-9">
      <div class="row g-3">
        <?php if (empty($products)): ?>
          <div class="col-12"><div class="alert alert-info">Không tìm thấy sản phẩm.</div></div>
        <?php endif; ?>

        <?php foreach ($products as $p):
          $pid = (int)$p['id_san_pham'];
          $img = getProductImage($conn, $pid);
          $name = $p['ten'];
          $price = (float)$p['gia'];
          $old = !empty($p['gia_cu']) ? (float)$p['gia_cu'] : 0;
          $detailUrl = 'sanpham_chitiet.php?id=' . $pid;
          $stock = (int)($p['so_luong'] ?? 0);
          $discount = ($old && $old > $price) ? (int)round((($old - $price)/$old)*100) : 0;
        ?>
        <div class="col-6 col-sm-4 col-md-3">
          <div class="product-card h-100 d-flex flex-column">
            <div class="position-relative text-center p-3 qv-clickable"
                 data-product='<?= htmlspecialchars(json_encode([
                   'id'=>$pid,
                   'name'=>$name,
                   'price'=>$price,
                   'gia_raw'=>$price,
                   'mo_ta'=>mb_substr(strip_tags($p['mo_ta'] ?? ''),0,300),
                   'img'=>$img,
                   'thumbs'=>[$img],
                   'stock'=>$stock
                 ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
                 onclick="openQuickView(this)">
              <?php if($discount>0): ?><span class="badge bg-danger sale-badge position-absolute" style="left:12px;top:12px">-<?= $discount ?>%</span><?php endif; ?>
              <img src="<?= esc($img) ?>" alt="<?= esc($name) ?>" class="prod-img mx-auto">
            </div>

            <div class="p-3 mt-auto d-flex flex-column">
              <?php if (!empty($p['danh_muc_ten'])): ?><div class="small text-muted"><?= esc($p['danh_muc_ten']) ?></div><?php endif; ?>
              <a href="<?= esc($detailUrl) ?>" class="text-decoration-none text-dark"><h6 class="mb-2"><?= esc($name) ?></h6></a>

              <div class="d-flex align-items-center mb-3">
                <div>
                  <div class="price-new"><?= price($price) ?></div>
                  <?php if ($old && $old > $price): ?><div class="price-old"><?= number_format($old,0,',','.') ?> ₫</div><?php endif; ?>
                </div>
                <div class="ms-auto small text-muted">Còn <?= $stock ?> sp</div>
              </div>

              <div class="d-flex gap-2 mt-auto">
                <button type="button"
                        class="btn btn-outline-primary w-50 open-qv add-anim"
                        data-product='<?= htmlspecialchars(json_encode([
                          'id'=>$pid,
                          'name'=>$name,
                          'price'=>$price,
                          'gia_raw'=>$price,
                          'mo_ta'=>mb_substr(strip_tags($p['mo_ta'] ?? ''),0,300),
                          'img'=>$img,
                          'thumbs'=>[$img],
                          'stock'=>$stock
                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
                        onclick="openQuickView(this)">
                  <i class="bi bi-eye"></i> Xem
                </button>

                <form method="post" action="cart.php?action=add" class="w-50 add-to-cart-form">
                  <input type="hidden" name="id" value="<?= $pid ?>">
                  <input type="hidden" name="qty" value="1">
                  <input type="hidden" name="back" value="<?= esc($_SERVER['REQUEST_URI']) ?>">
                  <button type="submit" class="btn btn-success w-100 add-to-cart-btn" data-id="<?= $pid ?>"><i class="bi bi-cart-plus"></i> Thêm</button>
                </form>
              </div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- pagination -->
      <nav class="mt-4">
        <ul class="pagination justify-content-center">
          <?php
            $start = max(1, $page - 3);
            $end = min($total_pages, $page + 3);
            if ($page > 1) echo '<li class="page-item"><a class="page-link" href="'. buildUrl(['page'=>$page-1]) .'">&laquo;</a></li>';
            else echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
            for ($i=$start;$i<=$end;$i++){
              if ($i==$page) echo '<li class="page-item active"><span class="page-link">'.$i.'</span></li>';
              else echo '<li class="page-item"><a class="page-link" href="'. buildUrl(['page'=>$i]) .'">'.$i.'</a></li>';
            }
            if ($page < $total_pages) echo '<li class="page-item"><a class="page-link" href="'. buildUrl(['page'=>$page+1]) .'">&raquo;</a></li>';
            else echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
          ?>
        </ul>
      </nav>
    </section>
  </div>
</div>

<!-- QUICKVIEW modal (same design as index) -->
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

<!-- footer same as index -->
<footer class="bg-dark text-white py-4 mt-4">
  <div class="container text-center"><small><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?> — © <?= date('Y') ?></small></div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
function openQuickView(btn){
  try {
    const raw = btn.getAttribute('data-product') || btn.dataset.product || null;
    const data = JSON.parse(raw);
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

    const swBox = document.getElementById('qv-swatches'); swBox.innerHTML = '';
    ['#111','#0b7bdc','#777'].forEach(c => { const el = document.createElement('span'); el.className='swatch'; el.style.background=c; swBox.appendChild(el); });

    const sizeBox = document.getElementById('qv-sizes'); sizeBox.innerHTML = '';
    ['S','M','L','XL'].forEach(sz => { const b=document.createElement('button'); b.className='size-btn'; b.innerText=sz; b.onclick=function(){document.querySelectorAll('.size-btn').forEach(x=>x.classList.remove('active')); this.classList.add('active');}; sizeBox.appendChild(b); });

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

/* AJAX add-to-cart behavior (graceful fallback to form POST) */
document.querySelectorAll('.add-to-cart-btn').forEach(function(btn){
  btn.addEventListener('click', async function(e){
    e.preventDefault();
    const form = this.closest('form');
    if (!form) return;
    const formData = new FormData(form);
    formData.set('ajax','1');

    try {
      const res = await fetch('cart.php?action=add', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (data && data.success) {
        const old = btn.innerHTML;
        btn.classList.remove('btn-outline-primary'); btn.classList.remove('btn-success');
        btn.classList.add('btn-success');
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Đã thêm';
        setTimeout(()=>{ btn.classList.remove('btn-success'); btn.innerHTML = old; }, 1400);

        const badge = document.getElementById('cart-count-badge');
        if (badge) {
          const cnt = data.cart && typeof data.cart.items_count !== 'undefined' ? data.cart.items_count : (data.items_count ?? null);
          if (cnt !== null) badge.textContent = cnt;
        }

        if (data.cart) {
          const items = data.cart.items || [];
          const subtotal = data.cart.subtotal ?? null;
          const itemsWrap = document.querySelector('#cartDropdownItems');
          if (itemsWrap) {
            if (items.length === 0) {
              itemsWrap.innerHTML = '<div class="small text-muted">Chưa có sản phẩm.</div>';
            } else {
              let html = '';
              items.forEach(it=>{
                const img = it.img ? it.img : 'images/placeholder.jpg';
                const name = it.name ? it.name : ('Sản phẩm #' + (it.id ?? ''));
                const qty = it.qty ?? 1;
                const price = Number(it.price ?? 0);
                html += `<div class="d-flex align-items-center py-2">
                  <img src="${img}" style="width:56px;height:56px;object-fit:cover;border-radius:8px" alt="">
                  <div class="ms-2 flex-grow-1">
                    <div class="small fw-semibold">${name}</div>
                    <div class="small text-muted">${qty} x ${price.toLocaleString('vi-VN')} ₫</div>
                  </div>
                  <div class="ms-2 small">${(qty * price).toLocaleString('vi-VN')} ₫</div>
                </div>`;
              });
              itemsWrap.innerHTML = html;
              if (document.querySelector('#cartDropdownSubtotal') && subtotal !== null) {
                document.querySelector('#cartDropdownSubtotal').textContent = Number(subtotal).toLocaleString('vi-VN') + ' ₫';
              }
            }
          }
        }
      } else {
        form.submit();
      }
    } catch (err) {
      console.error(err);
      form.submit();
    }
  });
});
</script>

</body>
</html>
