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

$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;

/* helper buildUrl */
function buildUrl($overrides = []) {
    $qs = array_merge($_GET, $overrides);
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($qs);
}

/* Prepare menu source like index.php */
$catsMenu = $cats;
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
    :root{
      --accent:#0b7bdc;
      --muted:#6c757d;
      --muted-2:#94a3b8;
      --card-radius:12px;
      --card-shadow: 0 10px 30px rgba(15,23,42,0.06);
    }
    body { background:#f7fbff; color:#0f1724; font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial; }

    /* NAV (use index.php header style) */
    .ae-header { background:#fff; border-bottom:1px solid #eef3f8; position:sticky; top:0; z-index:1050; }
    .ae-logo-mark{ width:46px;height:46px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800; }
    .nav-center { display:flex; gap:8px; align-items:center; justify-content:center; flex:1; }
    .nav-center .nav-link { color:#333; padding:8px 12px; border-radius:8px; font-weight:600; text-decoration:none; transition:all .15s; }
    .nav-center .nav-link.active, .nav-center .nav-link:hover { color:var(--accent); background:rgba(11,123,220,0.06); }

    /* rest of styles from original sanpham.php */
    .container-main { max-width:1200px; margin:32px auto; padding:0 16px; }
    .page-head { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:18px; }
    .page-head h3 { margin:0; font-size:1.25rem; font-weight:700; }

    aside .filter-card { background:#fff; padding:16px; border-radius:var(--card-radius); box-shadow:var(--card-shadow); }
    @media(min-width:992px){ aside .filter-card { position:sticky; top:90px; } }

    .products-grid { display:grid; grid-template-columns: repeat(2,1fr); gap:18px; }
    @media(min-width:576px){ .products-grid { grid-template-columns: repeat(3,1fr); } }
    @media(min-width:992px){ .products-grid { grid-template-columns: repeat(4,1fr); } }

    .card-product { background:#fff; border-radius:var(--card-radius); overflow:hidden; display:flex; flex-direction:column; height:100%; transition:transform .12s ease, box-shadow .12s ease; border:1px solid rgba(14,30,50,0.04); }
    .card-product:hover { transform:translateY(-6px); box-shadow:var(--card-shadow); }

    .card-media { background:#fff; padding:14px; display:flex; align-items:center; justify-content:center; min-height:200px; }
    .card-media img { width:100%; max-height:220px; object-fit:contain; display:block; }

    .card-body { padding:12px 14px 16px; display:flex; flex-direction:column; gap:10px; flex-grow:1; }
    .product-title { font-size:0.98rem; font-weight:700; color:#0b1724; line-height:1.2; min-height:2.4em; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
    .product-meta { display:flex; align-items:center; gap:8px; justify-content:space-between; margin-top:4px; }
    .price-current { font-weight:800; color:var(--accent); font-size:1.02rem; }
    .price-old { color:var(--muted-2); text-decoration:line-through; font-size:0.9rem; }

    .product-ctas { display:flex; gap:8px; margin-top:auto; }
    .btn-ghost { border:1px solid rgba(11,38,80,0.06); background:#fff; color:var(--accent); padding:9px 10px; border-radius:10px; width:50%; }
    .btn-buy { background:#059669; border:0; color:#fff; padding:9px 10px; border-radius:10px; width:50%; }

    .badge-discount { position:absolute; left:12px; top:12px; padding:6px 8px; border-radius:10px; font-weight:700; color:#fff; background:#ef4444; box-shadow:0 8px 18px rgba(14,20,30,0.12); }

    /* QUICKVIEW tweaks */
    #quickViewModal .qv-main { min-height:320px; display:flex; align-items:center; justify-content:center; background:#fff; border-radius:8px; }
    #qv-thumbs { display:flex; gap:8px; overflow-x:auto; padding-top:8px; }
    .qv-thumb { width:64px; height:64px; object-fit:cover; border-radius:8px; border:2px solid transparent; cursor:pointer; flex:0 0 auto; }
    .qv-thumb.active { border-color:var(--accent); box-shadow:0 8px 18px rgba(11,38,80,0.06); }

    .small-muted { color:var(--muted); font-size:0.88rem; }
    .search-inline { max-width:380px; }
    @media (max-width:991px){ .search-inline { display:none; } }

  </style>
</head>
<body>

<!-- NAV / HEADER (copied style from index.php) -->
<header class="ae-header">
  <div class="container d-flex align-items-center gap-3 py-2">
    <a class="brand d-flex align-items-center gap-2 text-decoration-none" href="index.php" aria-label="<?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?>">
      <div class="ae-logo-mark">AE</div>
      <div class="d-none d-md-block">
        <div style="font-weight:800"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></div>
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
      <a class="nav-link active" href="sanpham.php">Sản phẩm</a>
      <a class="nav-link" href="about.php">Giới thiệu</a>
      <a class="nav-link" href="contact.php">Liên hệ</a>
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
    <h5 id="mobileMenuLabel"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></h5>
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

<div class="container-main">
  <div class="page-head">
    <div>
      <h3>Sản phẩm</h3>
      <?php if ($q): ?><div class="small-muted">Kết quả tìm kiếm: "<?= esc($q) ?>"</div><?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <a href="index.php" class="btn btn-link">&larr; Trang chủ</a>
    </div>
  </div>

  <div class="row g-3">
    <!-- SIDEBAR -->
    <aside class="col-lg-3 d-none d-lg-block">
      <div class="filter-card">
        <form method="get" action="sanpham.php" class="mb-3">
          <label class="form-label small">Tìm kiếm</label>
          <input name="q" value="<?= esc($q) ?>" class="form-control form-control-sm mb-3" placeholder="Tên, mã...">

          <label class="form-label small">Danh mục</label>
          <select name="cat" class="form-select form-select-sm mb-3">
            <option value="0">Tất cả</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= (int)$c['id_danh_muc'] ?>" <?= $cat === (int)$c['id_danh_muc'] ? 'selected' : '' ?>><?= esc($c['ten']) ?></option>
            <?php endforeach; ?>
          </select>

          <div class="d-grid">
            <button class="btn btn-primary btn-sm">Lọc</button>
          </div>
        </form>

        <hr>
        <div class="small-muted">Hiển thị <strong><?= count($products) ?></strong> / <?= $total_items ?> sản phẩm</div>
      </div>
    </aside>

    <!-- MOBILE OFFCANVAS FILTERS -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileFilters" aria-labelledby="mobileFiltersLabel">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="mobileFiltersLabel">Bộ lọc</h5>
        <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body">
        <div class="mb-3">
          <form method="get" action="sanpham.php">
            <div class="mb-2">
              <label class="form-label small">Tìm kiếm</label>
              <input name="q" value="<?= esc($q) ?>" class="form-control form-control-sm mb-2" placeholder="Tên, mã...">
            </div>
            <div class="mb-2">
              <label class="form-label small">Danh mục</label>
              <select name="cat" class="form-select form-select-sm mb-2">
                <option value="0">Tất cả</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= (int)$c['id_danh_muc'] ?>" <?= $cat === (int)$c['id_danh_muc'] ? 'selected' : '' ?>><?= esc($c['ten']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="d-grid">
              <button class="btn btn-primary btn-sm">Lọc</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- PRODUCTS -->
    <section class="col-12 col-lg-9">
      <div class="products-grid">
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
          $short = mb_substr(strip_tags($p['mo_ta'] ?? ''),0,120);
        ?>
        <article class="card-product position-relative" aria-label="<?= esc($name) ?>">
          <?php if ($discount>0): ?><div class="badge-discount">-<?= $discount ?>%</div><?php endif; ?>
          <div class="card-media">
            <a href="<?= esc($detailUrl) ?>" class="d-block" style="width:100%;height:100%;">
              <img src="<?= esc($img) ?>" alt="<?= esc($name) ?>">
            </a>
          </div>

          <div class="card-body">
            <?php if (!empty($p['danh_muc_ten'])): ?><div class="small-muted"><?= esc($p['danh_muc_ten']) ?></div><?php endif; ?>
            <a href="<?= esc($detailUrl) ?>" class="text-decoration-none"><div class="product-title"><?= esc($name) ?></div></a>

            <div class="product-meta">
              <div>
                <div class="price-current"><?= price($price) ?></div>
                <?php if ($old && $old > $price): ?><div class="price-old"><?= number_format($old,0,',','.') ?> ₫</div><?php endif; ?>
              </div>
              <div class="small-muted">Còn <?= $stock ?> sp</div>
            </div>

            <div class="small-muted"><?= esc($short) ?></div>

            <div class="product-ctas">
              <!-- quickview uses data-product JSON -->
              <button type="button" class="btn-ghost open-qv" data-product='<?= htmlspecialchars(json_encode([
                  'id'=>$pid,
                  'name'=>$name,
                  'price'=>$price,
                  'gia_raw'=>$price,
                  'mo_ta'=>mb_substr(strip_tags($p['mo_ta'] ?? ''),0,200),
                  'img'=>$img,
                  'thumbs'=>[$img],
                  'stock'=>$stock
              ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' onclick="openQuickView(this)">
                <i class="bi bi-eye"></i> Xem
              </button>

              <form method="post" action="cart.php?action=add" class="m-0 w-100" onsubmit="return ajaxAddToCart(event, this)">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <input type="hidden" name="qty" value="1">
                <button type="submit" class="btn-buy"><i class="bi bi-cart-plus"></i> Thêm</button>
              </form>
            </div>
          </div>
        </article>
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

<!-- QUICKVIEW modal -->
<div class="modal fade" id="quickViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-4">
        <div class="row gx-4">
          <div class="col-md-6">
            <div class="qv-main"><img id="qv-main-img" src="images/placeholder.jpg" class="img-fluid" style="max-height:420px;object-fit:contain"></div>
            <div id="qv-thumbs" class="mt-3"></div>
          </div>
          <div class="col-md-6">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <h4 id="qv-title" class="mb-1">Tên sản phẩm</h4>
                <div class="small-muted" id="qv-sku">Mã: -</div>
                <div id="qv-rate" class="mt-2 small-muted"></div>
              </div>
              <div class="text-end">
                <div class="h4 text-danger" id="qv-price">0 ₫</div>
                <div class="small-muted" id="qv-stock">Còn: -</div>
              </div>
            </div>

            <div class="mt-3" id="qv-short-desc">Mô tả ngắn...</div>

            <div class="mt-3">
              <div class="mb-2"><strong>Màu sắc</strong></div>
              <div id="qv-swatches" class="mb-3 d-flex gap-2"></div>

              <div class="mb-2"><strong>Kích thước</strong></div>
              <div id="qv-sizes" class="mb-3 d-flex gap-2"></div>

              <div class="mb-3 d-flex align-items-center gap-3">
                <div>
                  <label class="form-label small mb-1">Số lượng</label>
                  <input id="qv-qty" type="number" class="form-control form-control-sm" value="1" min="1" style="width:100px">
                </div>
                <div class="flex-grow-1 small-muted">Giao hàng nhanh trong 1-3 ngày, đổi trả 7 ngày.</div>
              </div>

              <div class="d-flex gap-2 mb-2">
                <form id="qv-addform" method="post" action="cart.php" class="d-flex w-100" onsubmit="return ajaxAddToCart(event, this)">
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="id" id="qv-id" value="">
                  <input type="hidden" name="qty" id="qv-id-qty" value="1">
                  <button type="submit" class="btn btn-success w-100 add-anim"><i class="bi bi-cart-plus"></i> Thêm vào giỏ</button>
                </form>
                <a id="qv-buy" href="#" class="btn btn-outline-primary d-flex align-items-center justify-content-center" style="min-width:140px">Mua ngay</a>
              </div>

              <ul class="nav nav-tabs mt-3" id="qvTab" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" id="desc-tab" data-bs-toggle="tab" data-bs-target="#desc" type="button">Mô tả</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="spec-tab" data-bs-toggle="tab" data-bs-target="#spec" type="button">Thông số</button></li>
                <li class="nav-item" role="presentation"><button class="nav-link" id="rev-tab" data-bs-toggle="tab" data-bs-target="#rev" type="button">Đánh giá</button></li>
              </ul>
              <div class="tab-content p-3 border rounded-bottom" id="qvTabContent">
                <div class="tab-pane fade show active" id="desc" role="tabpanel"></div>
                <div class="tab-pane fade" id="spec" role="tabpanel"><div class="small-muted">Chưa có thông số chi tiết.</div></div>
                <div class="tab-pane fade" id="rev" role="tabpanel"><div class="small-muted">Chưa có đánh giá.</div></div>
              </div>

            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button></div>
    </div>
  </div>
</div>

<footer class="bg-dark text-white py-4 mt-5">
  <div class="container text-center small"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?> — © <?= date('Y') ?></div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Open QuickView */
function openQuickView(btn){
  try {
    const raw = btn.getAttribute('data-product') || btn.dataset.product || null;
    if (!raw) return;
    const data = JSON.parse(raw);

    document.getElementById('qv-title').textContent = data.name || '';
    document.getElementById('qv-short-desc').innerHTML = data.mo_ta ? (data.mo_ta.replace(/\n/g,'<br>')) : '<div class="small-muted">Không có mô tả chi tiết.</div>';
    document.getElementById('qv-price').textContent = new Intl.NumberFormat('vi-VN').format(data.price || data.gia_raw || 0) + ' ₫';
    document.getElementById('qv-stock').textContent = 'Còn: ' + (data.stock !== undefined ? data.stock : '-');
    document.getElementById('qv-id').value = data.id || '';
    document.getElementById('qv-main-img').src = data.img || 'images/placeholder.jpg';

    // thumbs
    const thumbsBox = document.getElementById('qv-thumbs'); thumbsBox.innerHTML = '';
    let thumbs = Array.isArray(data.thumbs) && data.thumbs.length ? data.thumbs : [data.img || 'images/placeholder.jpg'];
    thumbs.forEach((t, idx) => {
      const im = document.createElement('img');
      im.src = t;
      im.className = 'qv-thumb' + (idx===0 ? ' active' : '');
      im.onclick = function(){ document.getElementById('qv-main-img').src = t; document.querySelectorAll('.qv-thumb').forEach(x=>x.classList.remove('active')); this.classList.add('active'); };
      thumbsBox.appendChild(im);
    });

    // swatches (simple)
    const swBox = document.getElementById('qv-swatches'); swBox.innerHTML = '';
    ['#111','#0b7bdc','#777'].forEach(c => { const el=document.createElement('span'); el.style.width='28px'; el.style.height='28px'; el.style.borderRadius='6px'; el.style.display='inline-block'; el.style.background=c; el.style.border='1px solid #e6eefb'; swBox.appendChild(el); });

    // sizes
    const sizeBox = document.getElementById('qv-sizes'); sizeBox.innerHTML = '';
    ['S','M','L','XL'].forEach(sz => {
      const b = document.createElement('button');
      b.className = 'btn btn-outline-secondary btn-sm';
      b.type = 'button';
      b.style.borderRadius = '8px';
      b.style.padding = '6px 10px';
      b.innerText = sz;
      b.onclick = function(){ document.querySelectorAll('#qv-sizes button').forEach(x=>x.classList.remove('active')); this.classList.add('active'); };
      sizeBox.appendChild(b);
    });

    // qty
    const q = document.getElementById('qv-qty'); q.value = 1;
    document.getElementById('qv-id-qty').value = 1;
    q.oninput = function(){ document.getElementById('qv-id-qty').value = Math.max(1, parseInt(this.value || 1)); };

    // simple rating display placeholder
    document.getElementById('qv-rate').textContent = (data.rating ? (parseFloat(data.rating).toFixed(1) + '*') : '');

    // buy link
    document.getElementById('qv-buy').href = 'sanpham_chitiet.php?id=' + encodeURIComponent(data.id || '');

    var myModal = new bootstrap.Modal(document.getElementById('quickViewModal'));
    myModal.show();
  } catch (e) {
    console.error('openQuickView error', e);
  }
}

/* AJAX add-to-cart helper: used by forms (onsubmit) */
async function ajaxAddToCart(evt, form){
  try {
    evt.preventDefault();
    const fd = new FormData(form);
    fd.append('ajax','1');
    const actionUrl = form.getAttribute('action') || 'cart.php';
    const url = actionUrl + (actionUrl.indexOf('?') === -1 ? '' : '');
    const res = await fetch(url, { method:'POST', body:fd, credentials:'same-origin', headers: {'X-Requested-With':'XMLHttpRequest'} });
    if (!res.ok) { form.submit(); return false; }
    const data = await res.json();
    if (data && data.success) {
      // visual feedback on button
      const btn = form.querySelector('button[type="submit"]');
      if (btn) {
        const old = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check-lg"></i> Đã thêm';
        btn.disabled = true;
        setTimeout(()=>{ btn.innerHTML = old; btn.disabled = false; }, 1200);
      }

      // update cart badge
      if (document.getElementById('cart-count-badge')) {
        const cnt = (data.cart && typeof data.cart.items_count !== 'undefined') ? data.cart.items_count : (data.items_count ?? null);
        if (cnt !== null) document.getElementById('cart-count-badge').textContent = cnt;
      }

      // update dropdown items if returned
      if (data.cart && document.querySelector('#cartDropdownItems')) {
        const itemsWrap = document.querySelector('#cartDropdownItems');
        const items = data.cart.items || [];
        if (items.length === 0) {
          itemsWrap.innerHTML = '<div class="small-muted">Chưa có sản phẩm.</div>';
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
                <div class="small-muted">${qty} x ${price.toLocaleString('vi-VN')} ₫</div>
              </div>
              <div class="ms-2 small">${(qty * price).toLocaleString('vi-VN')} ₫</div>
            </div>`;
          });
          itemsWrap.innerHTML = html;
          if (document.querySelector('#cartDropdownSubtotal') && typeof data.cart.subtotal !== 'undefined') {
            document.querySelector('#cartDropdownSubtotal').textContent = Number(data.cart.subtotal).toLocaleString('vi-VN') + ' ₫';
          }
        }
      }
      return false;
    } else {
      // fallback
      form.submit();
      return true;
    }
  } catch (err) {
    console.error(err);
    form.submit();
    return true;
  }
}

/* Enhance keyboard accessibility: open quickview on Enter on product card */
document.addEventListener('keydown', function(e){
  if ((e.key === 'Enter' || e.key === ' ') && document.activeElement && document.activeElement.closest) {
    const card = document.activeElement.closest('.card-product');
    if (card) {
      const btn = card.querySelector('.open-qv');
      if (btn) { openQuickView(btn); e.preventDefault(); }
    }
  }
});
</script>
</body>
</html>
