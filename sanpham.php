<?php
// sanpham.php - danh sách sản phẩm + hỗ trợ category slug + quickview
// (Sửa: QuickView hoạt động khi bấm Thêm; AJAX add-to-cart; mini-cart tap; price slider)

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

/* fallback helpers nếu inc/helpers.php thiếu */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/* getProductImage - ưu tiên ảnh chính hoặc first */
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
    if (preg_match('#^https?://#i', $path)) return $path;
    $candidates = [
        ltrim($path, '/'),
        'images/' . ltrim($path, '/'),
        'images/products/' . ltrim($path, '/'),
        'uploads/' . ltrim($path, '/'),
        'public/' . ltrim($path, '/'),
        'images/' . basename($path),
    ];
    foreach ($candidates as $c) {
        if (file_exists(__DIR__ . '/' . $c) && @filesize(__DIR__ . '/' . $c) > 0) return $c;
    }
    return ltrim($path, '/') ?: $placeholder;
}

/* --- load categories for sidebar/menu --- */
try {
    $cats = $conn->query("SELECT * FROM danh_muc WHERE trang_thai=1 ORDER BY thu_tu ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cats = [];
}

/* get max price from products (for slider max) */
try {
    $maxPriceRow = $conn->query("SELECT MAX(gia) AS mx FROM san_pham WHERE trang_thai=1")->fetch(PDO::FETCH_ASSOC);
    $maxPrice = $maxPriceRow && !empty($maxPriceRow['mx']) ? (int)$maxPriceRow['mx'] : 3000000;
    // round up to nearest 100k
    $maxPrice = ceil($maxPrice / 100000) * 100000;
} catch (Exception $e) {
    $maxPrice = 3000000;
}

/* --- slug support: nếu có slug thì lấy id danh mục tương ứng --- */
$slug = trim((string)($_GET['slug'] ?? ''));
$slugCategory = null;
if ($slug !== '') {
    try {
        $sstmt = $conn->prepare("SELECT id_danh_muc, ten FROM danh_muc WHERE slug = :s LIMIT 1");
        $sstmt->execute([':s' => $slug]);
        $row = $sstmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $slugCategory = (int)$row['id_danh_muc'];
            $slugCategoryName = $row['ten'];
        } else {
            header('Location: sanpham.php');
            exit;
        }
    } catch (Exception $e) {
        $slugCategory = null;
    }
}

/* --- params: q, cat (id), page --- */
$q = trim((string)($_GET['q'] ?? ''));
$cat = (int)($_GET['cat'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page - 1) * $per_page;

/* filter params from form (GET) */
$price_min = (int)($_GET['price_min'] ?? 0);
$price_max = (int)($_GET['price_max'] ?? 0);
if ($price_max <= 0) $price_max = $maxPrice;

/* Nếu có slugCategory, ưu tiên filter theo slug (dùng id từ slug) */
if ($slugCategory !== null) {
    $cat = $slugCategory;
}

/* build where clause */
$where = "WHERE sp.trang_thai = 1";
$params = [];
if ($q !== '') {
    $where .= " AND (sp.ten LIKE :q OR sp.mo_ta LIKE :q OR sp.ma_san_pham LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($cat > 0) {
    $where .= " AND sp.id_danh_muc = :cat";
    $params[':cat'] = $cat;
}
if ($price_min > 0) {
    $where .= " AND sp.gia >= :pmin";
    $params[':pmin'] = $price_min;
}
if ($price_max > 0) {
    $where .= " AND sp.gia <= :pmax";
    $params[':pmax'] = $price_max;
}

/* total count filtered */
$countSql = "SELECT COUNT(*) FROM san_pham sp $where";
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$total_items = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_items / $per_page));

/* total count ALL products in shop (trang_thai=1) */
try {
    $total_all_row = $conn->query("SELECT COUNT(*) as cnt FROM san_pham WHERE trang_thai=1")->fetch(PDO::FETCH_ASSOC);
    $total_all = $total_all_row ? (int)$total_all_row['cnt'] : 0;
} catch (Exception $e) {
    $total_all = 0;
}

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

/* user */
$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;

/* helper build url (preserve current filters when paginating) */
function buildUrl($overrides = []) {
    $qs = array_merge($_GET, $overrides);
    return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($qs);
}

$site_name = function_exists('site_name') ? site_name($conn) : 'AE SHOP';
$accent = '#0b7bdc';
$red = '#dc2626';

if ($slugCategory !== null) {
    $pageTitle = esc($slugCategoryName) . ' — ' . esc($site_name);
    $pageHeading = esc($slugCategoryName);
} else if ($cat > 0) {
    $catName = '';
    foreach ($cats as $c) if ((int)$c['id_danh_muc'] === $cat) { $catName = $c['ten']; break; }
    $pageTitle = ($catName ? esc($catName) . ' — ' : '') . 'Sản phẩm — ' . esc($site_name);
    $pageHeading = $catName ?: 'Sản phẩm';
} else if ($q !== '') {
    $pageTitle = 'Tìm: ' . esc($q) . ' — ' . esc($site_name);
    $pageHeading = 'Kết quả tìm kiếm';
} else {
    $pageTitle = 'Sản phẩm — ' . esc($site_name);
    $pageHeading = 'Sản phẩm';
}
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
    :root{ --accent: <?= $accent ?>; --muted:#6c757d; --card-radius:10px; --card-shadow: 0 10px 30px rgba(11,37,74,0.06); --danger: <?= $red ?>; }
    body{ font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial; background:#f6fbff; color:#071428; margin:0; font-size:14px; }
    .container-main{ max-width:1200px; margin:0 auto; padding:0 12px; }

    /* header (1-line menu) */
    .ae-header{ background:#fff; border-bottom:1px solid #eef3f8; position:sticky; top:0; z-index:1100; padding:10px 0; }
    .nav-center{ display:flex; gap:6px; align-items:center; justify-content:center; flex:1; white-space:nowrap; }
    .nav-link{ color:#1f2937; padding:6px 10px; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; }
    .nav-link:hover, .nav-link.active{ color:var(--accent); background:rgba(11,123,220,0.06); }

    /* BRAND / LOGO (AE style) */
    .brand {
      display:flex;
      align-items:center;
      gap:12px;
      text-decoration:none;
    }

    .brand-logo {
      width:56px;
      height:56px;
      border-radius:12px;
      background: var(--accent);
      display:flex;
      align-items:center;
      justify-content:center;
      box-shadow: 0 8px 22px rgba(11,37,74,0.12);
      flex-shrink:0;
      overflow:hidden;
    }
    .brand-logo img { width:100%; height:100%; object-fit:cover; display:block; }
    .brand-initials {
      font-weight:900;
      font-size:18px;
      color:#fff;
      letter-spacing:1px;
    }
    .brand-text {
      display:flex;
      flex-direction:column;
      line-height:1;
    }
    .brand-title {
      font-weight:900;
      font-size:16px;
      color:#07203a;
      margin:0;
      letter-spacing:0.2px;
    }
    .brand-sub {
      font-size:12px;
      color:var(--muted);
      margin-top:2px;
    }

    /* compact layout */
    .page-wrap{ padding:18px 0; }
    .page-head{ display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
    .page-head h3{ margin:0; font-size:1.05rem; font-weight:800; color:#07203a; }

    aside .filter-card{ background:#fff; padding:14px; border-radius:12px; box-shadow:var(--card-shadow); border:1px solid rgba(11,37,74,0.03); font-size:13px; }
    @media(min-width:992px){ aside .filter-card{ position:sticky; top:80px; } }

    .filter-title{ font-weight:800; font-size:18px; margin:0 0 12px 0; }
    .filter-section{ border-top:1px dashed #eef3f8; padding-top:12px; margin-top:12px; }
    .filter-section h6{ margin:0; display:flex; justify-content:space-between; align-items:center; font-size:14px; font-weight:700; }

    /* ===== CENTERED KNOB PRICE SLIDER ===== */
    .price-box {
      background: #ffffff;
      padding: 12px;
      border-radius: 12px;
      border: 1px solid rgba(11, 37, 74, 0.05);
      margin-top: 8px;
    }
    .price-title { font-weight:800; color:#07203a; margin-bottom:6px; font-size:14px; }

    .slider-container {
      position: relative;
      height: 64px;
      margin-top: 6px;
    }
    /* main track centered vertically */
    .slider-track {
      position: absolute;
      height: 8px;
      background: #333; /* fallback */
      background: #e9eef8;
      left: 0;
      right: 0;
      top: 50%;
      transform: translateY(-50%);
      border-radius: 8px;
    }
    .slider-range {
      position: absolute;
      height: 8px;
      top: 50%;
      transform: translateY(-50%);
      background: linear-gradient(90deg,var(--accent), #0b6ff0);
      border-radius: 8px;
    }

    /* range inputs: put thumbs in the center of the track */
    input[type="range"] {
      position: absolute;
      left: 0;
      right: 0;
      top: 50%;
      transform: translateY(-50%); /* <-- centers thumb vertically */
      width: 100%;
      -webkit-appearance: none;
      background: transparent;
      pointer-events: none; /* allow only thumb interaction - thumb overrides */
    }
    /* thumb centered and slightly larger */
    input[type="range"]::-webkit-slider-thumb {
      -webkit-appearance: none;
      height: 22px;
      width: 22px;
      border-radius: 50%;
      background: #fff;
      border: 4px solid var(--accent);
      box-shadow: 0 6px 18px rgba(9,30,66,0.14);
      cursor: pointer;
      pointer-events: auto;
    }
    input[type="range"]::-moz-range-thumb {
      height: 22px;
      width: 22px;
      border-radius: 50%;
      background: #fff;
      border: 4px solid var(--accent);
      box-shadow: 0 6px 18px rgba(9,30,66,0.14);
      cursor: pointer;
      pointer-events: auto;
    }

    /* small floating value badges above knobs */
    .slider-value {
      position: absolute;
      top: 8px; /* keep above the centered track */
      background: #fff;
      border: 1px solid #eef6ff;
      padding: 4px 8px;
      border-radius: 8px;
      font-weight:700;
      font-size:12px;
      color:#07203a;
      box-shadow: 0 6px 18px rgba(9,30,66,0.06);
      transform: translateX(-50%);
      white-space: nowrap;
      pointer-events: none;
    }

    .price-labels {
      display:flex;
      justify-content:space-between;
      margin-top:14px;
      font-size:13px;
      color:#6c7a92;
    }

    /* product card styles */
    .products-grid{ display:grid; grid-template-columns: repeat(2,1fr); gap:16px; align-items:stretch; }
    @media(min-width:576px){ .products-grid{ grid-template-columns: repeat(3,1fr); } }
    @media(min-width:992px){ .products-grid{ grid-template-columns: repeat(4,1fr); } }
    .card-pro { border-radius:12px; background:#fff; padding:0; overflow:hidden; box-shadow:0 10px 30px rgba(9,30,66,0.04); display:flex; flex-direction:column; position:relative; }
    .card-media{ padding:12px; display:flex; align-items:center; justify-content:center; background:#fbfdff; }
    .card-body-pro{ padding:12px; display:flex; flex-direction:column; gap:8px; flex:1; }

    /* mini cart slide-in panel (tap) */
    .mini-cart-tap {
      position: fixed;
      right: 18px;
      bottom: 18px;
      z-index: 2100;
      width: 300px;
      max-width: calc(100% - 36px);
      border-radius: 12px;
      box-shadow: 0 12px 40px rgba(9,30,66,0.12);
      background: #fff;
      overflow: hidden;
      transform: translateY(12px);
      opacity: 0;
      transition: transform .28s ease, opacity .28s ease;
    }
    .mini-cart-tap.show { transform: translateY(0); opacity: 1; }
    .mini-cart-tap .mc-body { padding:12px; }
    .mini-cart-tap .mc-footer { padding:10px; border-top:1px solid #eef3f8; display:flex; gap:8px; }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/inc/header.php'; ?>

<!-- PAGE -->
<main class="page-wrap">
  <div class="container container-main">
    <div class="page-head">
      <div>
        <h3><?= $pageHeading ?></h3>
        <?php if ($q): ?><div class="small-muted">Kết quả tìm kiếm: "<?= esc($q) ?>"</div><?php endif; ?>
        <?php if ($slugCategory !== null): ?><div class="small-muted">Danh mục: <?= esc($slugCategoryName) ?></div><?php endif; ?>

        <!-- show filtered count / total products -->
        <div class="small-muted mt-1">Hiển thị <strong><?= number_format($total_items,0,',','.') ?></strong> / <?= number_format($total_all,0,',','.') ?> sản phẩm</div>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <a href="index.php" class="btn btn-link p-0">&larr; Trang chủ</a>
        <button class="btn btn-outline-secondary d-lg-none btn-sm" data-bs-toggle="offcanvas" data-bs-target="#mobileFilters">Bộ lọc</button>
      </div>
    </div>

    <div class="row g-2">
      <!-- SIDEBAR FILTER -->
      <aside class="col-lg-3 d-none d-lg-block">
        <div class="filter-card">
          <form id="filterForm" method="get" action="sanpham.php">
            <!-- preserve q and slug when filtering -->
            <input type="hidden" name="q" value="<?= esc($q) ?>">
            <?php if ($slug !== ''): ?><input type="hidden" name="slug" value="<?= esc($slug) ?>"><?php endif; ?>

            <div class="filter-title">Bộ lọc</div>

            <div class="filter-section">
              <h6>Danh mục sản phẩm</h6>
              <ul class="cat-list">
                <li>
                  <label style="cursor:pointer">
                    <input type="radio" name="cat" value="0" <?= $cat===0 ? 'checked' : '' ?>> <strong>Tất cả</strong>
                  </label>
                </li>
                <?php foreach ($cats as $c): ?>
                  <li>
                    <label style="cursor:pointer">
                      <input type="radio" name="cat" value="<?= (int)$c['id_danh_muc'] ?>" <?= $cat === (int)$c['id_danh_muc'] ? 'checked' : '' ?>>
                      <?= esc($c['ten']) ?>
                    </label>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>

            <!-- === CENTERED KNOB PRICE SLIDER === -->
            <div class="price-box">
              <div class="price-title">Khoảng giá</div>

              <div class="slider-container" aria-hidden="false">
                <div class="slider-track"></div>
                <div id="slider-range" class="slider-range" style="left:0;right:0;"></div>

                <div class="slider-value" id="sliderValueMin"><?= number_format($price_min,0,',','.') ?>đ</div>
                <div class="slider-value" id="sliderValueMax"><?= number_format($price_max,0,',','.') ?>đ</div>

                <input id="rangeMin" type="range" min="0" max="<?= $maxPrice ?>" value="<?= $price_min ?>" step="10000">
                <input id="rangeMax" type="range" min="0" max="<?= $maxPrice ?>" value="<?= $price_max ?>" step="10000">
              </div>

              <div class="price-labels">
                <span id="labelMin"><?= number_format($price_min,0,',','.') ?> ₫</span>
                <span id="labelMax"><?= number_format($price_max,0,',','.') ?> ₫</span>
              </div>

              <input type="hidden" id="inputPriceMin" name="price_min" value="<?= (int)$price_min ?>">
              <input type="hidden" id="inputPriceMax" name="price_max" value="<?= (int)$price_max ?>">
            </div>
            <!-- === END PRICE BOX === -->

            <div class="mt-3 d-flex gap-2">
              <button type="submit" class="btn btn-primary w-100">Áp dụng</button>
              <a href="sanpham.php" class="btn btn-outline-secondary w-100">Xóa</a>
            </div>
          </form>

          <!-- visible info under filters about counts -->
          <div class="mt-3 small-muted" style="font-size:13px">
            Kết quả: <strong><?= number_format($total_items,0,',','.') ?></strong> sản phẩm được lọc<br>
            Tổng sản phẩm cửa hàng: <strong><?= number_format($total_all,0,',','.') ?></strong>
          </div>
        </div>
      </aside>

      <!-- mobile filters offcanvas -->
      <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileFilters" aria-labelledby="mobileFiltersLabel">
        <div class="offcanvas-header">
          <h5 id="mobileFiltersLabel">Bộ lọc</h5>
          <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
          <!-- simplified mobile form -->
          <form method="get" action="sanpham.php">
            <div class="mb-3">
              <label class="form-label small">Tìm kiếm</label>
              <input name="q" value="<?= esc($q) ?>" class="form-control form-control-sm mb-2" placeholder="Tên, mã...">
            </div>
            <div class="mb-3">
              <label class="form-label small">Danh mục</label>
              <select name="cat" class="form-select form-select-sm mb-2">
                <option value="0">Tất cả</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= (int)$c['id_danh_muc'] ?>" <?= $cat === (int)$c['id_danh_muc'] ? 'selected' : '' ?>><?= esc($c['ten']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label small">Khoảng giá</label>
              <div class="d-flex gap-2">
                <input type="number" name="price_min" class="form-control form-control-sm" placeholder="Từ" value="<?= (int)$price_min ?>">
                <input type="number" name="price_max" class="form-control form-control-sm" placeholder="Đến" value="<?= (int)$price_max ?>">
              </div>
            </div>

            <div class="d-grid">
              <button class="btn btn-primary">Áp dụng</button>
            </div>
          </form>
        </div>
      </div>

      <!-- PRODUCTS -->
      <section class="col-12 col-lg-9">
        <div class="products-grid">
          <?php if (empty($products)): ?>
            <div class="col-12"><div class="alert alert-info py-2">Không tìm thấy sản phẩm.</div></div>
          <?php endif; ?>

          <?php foreach ($products as $p):
            $pid = (int)$p['id_san_pham'];
            $img = getProductImage($conn, $pid);
            $name = $p['ten'];
            $priceVal = (float)$p['gia'];
            $old = !empty($p['gia_cu']) ? (float)$p['gia_cu'] : 0;
            $detailUrl = 'sanpham_chitiet.php?id=' . $pid;
            $discount = ($old && $old > $priceVal) ? (int)round((($old - $priceVal)/$old)*100) : 0;

            // payload cho quickview
            $payload = [
              'id' => $pid,
              'name' => $p['ten'],
              'gia_raw' => $p['gia'],
              'price' => $p['gia'],
              'mo_ta' => mb_substr(strip_tags($p['mo_ta'] ?? ''),0,400),
              'img' => $img,
              'stock' => (int)$p['so_luong'],
              'thumbs' => [$img],
            ];
            $payloadJson = htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
          ?>
          <article class="card-pro" aria-label="<?= esc($name) ?>">
            <?php if ($discount > 0): ?>
              <div style="position:absolute;left:12px;top:12px;background:#ef4444;color:#fff;padding:6px 8px;border-radius:8px;font-weight:800;font-size:12px;">-<?= $discount ?>%</div>
            <?php endif; ?>

            <!-- CLICK ẢNH -> CHI TIẾT (không mở QuickView) -->
            <a href="<?= esc($detailUrl) ?>" class="card-media" style="text-decoration:none;">
              <img src="<?= esc($img) ?>" alt="<?= esc($name) ?>" style="max-height:160px;object-fit:contain">
            </a>

            <div class="card-body-pro">
              <div style="display:flex;justify-content:space-between;align-items:flex-start">
                <div style="flex:1">
                  <div style="font-weight:800;font-size:14px"><?= esc($name) ?></div>
                  <div style="color:var(--muted);font-size:12px; margin-top:6px; min-height:36px"><?= esc(mb_substr(strip_tags($p['mo_ta'] ?? ''),0,80)) ?></div>
                </div>
                <div style="text-align:right;margin-left:12px">
                  <div style="color:#ef4444;font-weight:900"><?= price($priceVal) ?></div>
                  <?php if ($old && $old > $priceVal): ?><div style="text-decoration:line-through;color:#9aa6b2;font-size:12px"><?= number_format($old,0,',','.') ?> ₫</div><?php endif; ?>
                </div>
              </div>

              <div style="margin-top:8px;display:flex;gap:8px">
                <!-- Thêm => mở QuickView (giống index) -->
                <button type="button"
                  class="btn btn-sm btn-primary"
                  onclick='openQuickViewFromPayload(<?= $payloadJson ?>)'>
                  <i class="bi bi-cart-plus"></i> Thêm
                </button>

                <!-- Xem -> trang chi tiết -->
                <a href="<?= esc($detailUrl) ?>" class="btn btn-sm btn-outline-secondary">Xem</a>

                <!-- Xem nhanh (mở QuickView modal) -->
                <button type="button" class="btn btn-sm btn-light" onclick='openQuickViewFromPayload(<?= $payloadJson ?>)' title="Xem nhanh">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>

        <!-- pagination -->
        <nav class="mt-3" aria-label="Trang sản phẩm">
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
</main>

<!-- QUICKVIEW modal -->
<style>
.qv-price-box{
  border:1px solid #eee;
  border-radius:12px;
  padding:16px 20px;
  background:#fafafa;
  display:flex;
  justify-content:space-between;
  align-items:center;
}
.qv-size button{
  border-radius:10px;
  min-width:52px;
  font-weight:700;
}
.qv-qty-group input{
  width:60px;
  text-align:center;
}
.qv-qty-group button{
  width:40px;
}
.qv-add{
  background:linear-gradient(90deg,#ef4444,#f97316);
  border:none;
  color:#fff;
  font-weight:800;
  border-radius:12px;
}
.qv-buy{
  border-radius:12px;
  border:2px solid #e5e7eb;
  font-weight:700;
}
.qv-share i{
  font-size:20px;
  color:#2563eb;
  margin-right:12px;
  cursor:pointer;
}
</style>


<!-- QUICKVIEW MODAL ĐẸP Y HÌNH -->
<div class="modal fade" id="quickViewModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content p-3 rounded-4">
      <div class="modal-body">
        <div class="row g-4">

          <!-- ẢNH TRÁI -->
          <div class="col-md-6 text-center">
            <img id="qv-main-img" class="img-fluid rounded-4 mb-3" style="max-height:520px;object-fit:contain">
            <div class="d-flex justify-content-center gap-2" id="qv-thumbs"></div>
          </div>

          <!-- THÔNG TIN PHẢI -->
          <div class="col-md-6">
            <h3 id="qv-title" class="fw-bold mb-2"></h3>

            <div class="mb-2 text-muted">
              Mã sản phẩm: <strong id="qv-code"></strong>
              <span id="qv-stock" class="text-success fw-bold ms-2"></span>
            </div>

            <!-- GIÁ -->
            <div class="qv-price-box mb-3">
              <div class="text-muted">Giá:</div>
              <div id="qv-price" class="fs-4 fw-bold text-danger"></div>
            </div>

            <div id="qv-short-desc" class="text-muted mb-3"></div>

            <hr>

            <!-- SIZE -->
            <div class="fw-bold mb-2">Kích thước:</div>
            <div id="qv-sizes" class="qv-size mb-3"></div>

            <!-- SỐ LƯỢNG -->
            <div class="d-flex align-items-center gap-3 mb-3">
              <div class="qv-qty-group d-flex align-items-center gap-2">
                <button type="button" id="qv-dec" class="btn btn-outline-secondary">−</button>
                <input id="qv-qty" type="number" value="1" class="form-control">
                <button type="button" id="qv-inc" class="btn btn-outline-secondary">+</button>
              </div>
              <span class="text-muted">Giao hàng 1–3 ngày • Đổi trả 7 ngày</span>
            </div>

            <!-- FORM ADD -->
            <form id="qv-addform" method="post" action="cart.php">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="id" id="qv-id">
              <input type="hidden" name="qty" id="qv-id-qty" value="1">
              <input type="hidden" name="size" id="qv-size">

              <button id="qv-addbtn" class="qv-add w-100 p-3 mb-3">
                <i class="bi bi-cart-plus"></i> THÊM VÀO GIỎ
              </button>
            </form>

            <button id="qv-buy" class="qv-buy w-100 p-3 mb-3">
              MUA NGAY
            </button>

            <!-- SHARE -->
            <div class="qv-share mt-3">
              Chia sẻ:
              <i class="bi bi-facebook"></i>
              <i class="bi bi-messenger"></i>
              <i class="bi bi-twitter"></i>
              <i class="bi bi-pinterest"></i>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>


<script>
const qv_title = document.getElementById('qv-title');
const qv_price = document.getElementById('qv-price');
const qv_main_img = document.getElementById('qv-main-img');
const qv_short_desc = document.getElementById('qv-short-desc');
const qv_id = document.getElementById('qv-id');
const qv_qty = document.getElementById('qv-qty');
const qv_id_qty = document.getElementById('qv-id-qty');
const qv_size = document.getElementById('qv-size');
const qv_thumbs = document.getElementById('qv-thumbs');
const qv_sizes = document.getElementById('qv-sizes');
const qv_addform = document.getElementById('qv-addform');
const qv_addbtn = document.getElementById('qv-addbtn');
const qv_buy = document.getElementById('qv-buy');
const qv_code = document.getElementById('qv-code');
const qv_stock = document.getElementById('qv-stock');
const quickViewModal = document.getElementById('quickViewModal');

function openQuickViewFromPayload(data){
  qv_title.textContent = data.name;
  qv_price.textContent = new Intl.NumberFormat('vi-VN').format(data.price) + ' ₫';
  qv_main_img.src = data.img;
  qv_short_desc.textContent = data.mo_ta;
  qv_id.value = data.id;
  qv_qty.value = 1;
  qv_id_qty.value = 1;
  qv_size.value = '';

  qv_code.textContent = '#' + data.id;
  qv_stock.textContent = data.stock > 0 ? 'Còn hàng' : 'Hết hàng';

  qv_thumbs.innerHTML = '';
  (data.thumbs || [data.img]).forEach(img=>{
    const im = document.createElement('img');
    im.src = img;
    im.style.width='70px';
    im.style.height='70px';
    im.style.objectFit='cover';
    im.className='border rounded';
    im.onclick = ()=> qv_main_img.src = img;
    qv_thumbs.appendChild(im);
  });

  qv_sizes.innerHTML = '';
  ['S','M','L','XL'].forEach(s=>{
    const b = document.createElement('button');
    b.className = 'btn btn-outline-secondary me-2';
    b.textContent = s;
    b.onclick = ()=>{
      document.querySelectorAll('#qv-sizes button').forEach(x=>x.classList.remove('btn-danger'));
      b.classList.add('btn-danger');
      qv_size.value = s;
    };
    qv_sizes.appendChild(b);
  });

  new bootstrap.Modal(quickViewModal).show();
}

document.getElementById('qv-inc').onclick = ()=>{ qv_qty.value++; qv_id_qty.value = qv_qty.value; };
document.getElementById('qv-dec').onclick = ()=>{ qv_qty.value = Math.max(1, qv_qty.value-1); qv_id_qty.value = qv_qty.value; };

qv_addform.onsubmit = async e=>{
  e.preventDefault();
  const fd = new FormData(qv_addform);
  fd.append('ajax','1');
  const res = await fetch('cart.php?action=add',{method:'POST',body:fd});
  const json = await res.json();

  if(json.success){
    bootstrap.Modal.getInstance(quickViewModal).hide();
    alert('✅ Đã thêm vào giỏ');
  }
};

qv_buy.onclick = ()=>{
  qv_addform.requestSubmit();
  setTimeout(()=>location.href='checkout.php',400);
};
</script>





<!-- mini-cart tap (slide in) -->
<div id="miniCartTap" class="mini-cart-tap" aria-hidden="true" role="status" aria-live="polite">
  <div class="mc-body">
    <div class="d-flex align-items-start gap-2">
      <img id="mc-thumb" src="images/placeholder.jpg" style="width:64px;height:64px;object-fit:cover;border-radius:8px">
      <div style="flex:1">
        <div id="mc-title" style="font-weight:800"></div>
        <div id="mc-sub" class="small text-muted"></div>
      </div>
    </div>
  </div>
  <div class="mc-footer">
    <a href="cart.php" class="btn btn-primary btn-sm w-100">Xem giỏ hàng</a>
    <button id="mc-close" class="btn btn-outline-secondary btn-sm w-100">Đóng</button>
  </div>
</div>

<footer class="bg-white py-3 mt-4 border-top">
  <div class="container container-main text-center small text-muted"><?= esc($site_name) ?> — © <?= date('Y') ?></div>
</footer>

<!-- scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Improved centered-thumb dual-range slider */
(function(){
  const minR = document.getElementById("rangeMin");
  const maxR = document.getElementById("rangeMax");
  const sliderRange = document.getElementById("slider-range");
  const vMin = document.getElementById("sliderValueMin");
  const vMax = document.getElementById("sliderValueMax");
  const labelMin = document.getElementById("labelMin");
  const labelMax = document.getElementById("labelMax");
  const inputMin = document.getElementById("inputPriceMin");
  const inputMax = document.getElementById("inputPriceMax");

  if (!minR || !maxR || !sliderRange) return;
  const maxValue = parseInt(minR.max || '3000000', 10);

  function format(v){ return v.toLocaleString('vi-VN') + 'đ'; }
  function formatLabel(v){ return v.toLocaleString('vi-VN') + ' ₫'; }

  function update(){
    let a = parseInt(minR.value || 0, 10);
    let b = parseInt(maxR.value || maxValue, 10);

    if (isNaN(a)) a = 0;
    if (isNaN(b)) b = maxValue;

    // if thumbs cross, swap visually but keep the input values (we set inputs when submitting)
    if (a > b) {
      const t = a; a = b; b = t;
    }

    const leftPct = (a / maxValue) * 100;
    const rightPct = 100 - (b / maxValue) * 100;

    sliderRange.style.left = leftPct + "%";
    sliderRange.style.right = rightPct + "%";

    vMin.textContent = format(a);
    vMax.textContent = format(b);

    // position float labels above thumb centers (clamped)
    vMin.style.left = Math.max(6, Math.min(94, leftPct)) + "%";
    vMax.style.left = Math.max(6, Math.min(94, (100 - rightPct))) + "%";

    if (labelMin) labelMin.textContent = formatLabel(a);
    if (labelMax) labelMax.textContent = formatLabel(b);

    if (inputMin) inputMin.value = a;
    if (inputMax) inputMax.value = b;
  }

  // enable pointer events on range inputs via event listeners
  minR.addEventListener('input', update);
  maxR.addEventListener('input', update);

  // init
  update();
})();

// AJAX add-to-cart helper (keeps badge update + shows toast + mini-cart tap)
async function ajaxAddToCart(evt, form){
  try {
    if (evt) evt.preventDefault();
    const fd = new FormData(form);
    fd.append('ajax','1');
    const actionUrl = form.getAttribute('action') || 'cart.php';
    const res = await fetch(actionUrl, { method:'POST', body:fd, credentials:'same-origin', headers: {'X-Requested-With':'XMLHttpRequest'} });
    if (!res.ok) {
      if (evt) form.submit();
      return false;
    }
    const data = await res.json();
    if (!data || !data.success) {
      if (evt) form.submit();
      return false;
    }

    /* BUTTON FEEDBACK */
    const btn = form.querySelector('button[type="submit"],button');
    if (btn) {
      const old = btn.innerHTML;
      btn.innerHTML = '<i class="bi bi-check-lg"></i> Đã thêm';
      btn.disabled = true;
      setTimeout(()=>{ btn.innerHTML = old; btn.disabled = false; }, 900);
    }

    /* UPDATE BADGE */
    if (data.cart && typeof data.cart.items_count !== 'undefined') {
      document.querySelectorAll('#cartBadge').forEach(b => b.textContent = data.cart.items_count);
    }

    /* MINI CART TAP */
    try {
      const tap = document.getElementById('miniCartTap');
      const thumb = document.getElementById('mc-thumb');
      const title = document.getElementById('mc-title');
      const sub = document.getElementById('mc-sub');

      if (tap) {
        let img = 'images/placeholder.jpg';
        let name = 'Sản phẩm vừa thêm';
        let priceText = '';

        if (data.last_added) {
          img = data.last_added.img || img;
          name = data.last_added.name || name;
          if (data.last_added.price) priceText = new Intl.NumberFormat('vi-VN').format(data.last_added.price) + ' ₫';
        } else {
          const card = form.closest('.card-pro');
          if (card) {
            img = card.querySelector('img')?.src || img;
            name = card.querySelector('.card-body-pro div[style*="font-weight:800"]')?.textContent || name;
            priceText = card.querySelector('div[style*="text-align:right"]')?.textContent || '';
          }
        }

        if (thumb) thumb.src = img;
        if (title) title.textContent = name;
        if (sub) sub.textContent = priceText;

        tap.classList.add('show');
        setTimeout(()=>{ tap.classList.remove('show'); }, 3200);
      }
    } catch(e) { console.error(e); }

    /* small toast */
    try {
      const tmp = document.createElement('div');
      tmp.style.position = 'fixed';
      tmp.style.right = '18px';
      tmp.style.bottom = '18px';
      tmp.style.zIndex = 2050;
      tmp.innerHTML = '<div class="toast align-items-center text-bg-success border-0 show" role="status" aria-live="polite" aria-atomic="true"><div class="d-flex"><div class="toast-body">Đã thêm vào giỏ hàng</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>';
      document.body.appendChild(tmp);
      setTimeout(()=> { try{ document.body.removeChild(tmp); }catch(e){} }, 2000);
    } catch(e){}

    return false;
  } catch (err) {
    console.error(err);
    if (evt) form.submit();
    return true;
  }
}

/* ====== QUICKVIEW: KHỞI TẠO BIẾN DOM VÀ HÀM MỞ ====== */
const qv_title = document.getElementById('qv-title');
const qv_price = document.getElementById('qv-price');
const qv_main_img = document.getElementById('qv-main-img');
const qv_short_desc = document.getElementById('qv-short-desc');
const qv_id = document.getElementById('qv-id');
const qv_qty = document.getElementById('qv-qty');
const qv_id_qty = document.getElementById('qv-id-qty');
const qv_size = document.getElementById('qv-size');
const qv_thumbs = document.getElementById('qv-thumbs');
const qv_sizes = document.getElementById('qv-sizes');
const qv_addform = document.getElementById('qv-addform');
const qv_addbtn = document.getElementById('qv-addbtn');
const qv_buy = document.getElementById('qv-buy');
const quickViewModal = document.getElementById('quickViewModal');

function openQuickViewFromPayload(data){
  try {
    if (!data) return;
    qv_title.textContent = data.name || '';
    qv_price.textContent = new Intl.NumberFormat('vi-VN').format(data.price || data.gia_raw || 0) + ' ₫';
    qv_main_img.src = data.img || 'images/placeholder.jpg';
    qv_short_desc.textContent = data.mo_ta || '';
    qv_id.value = data.id || '';
    qv_qty.value = 1;
    qv_id_qty.value = 1;
    qv_size.value = '';

    // thumbs
    qv_thumbs.innerHTML = '';
    (data.thumbs || [data.img]).forEach((t,i)=>{
      const im = document.createElement('img');
      im.src = t;
      im.style.width='64px';
      im.style.height='64px';
      im.style.objectFit='cover';
      im.style.cursor='pointer';
      im.className = 'me-2';
      if(i===0) im.classList.add('border','border-danger');
      im.onclick = ()=>{ qv_main_img.src = t; };
      qv_thumbs.appendChild(im);
    });

    // sizes (demo)
    qv_sizes.innerHTML = '';
    (data.sizes || ['S','M','L','XL']).forEach(s=>{
      const b = document.createElement('button');
      b.type='button';
      b.className='btn btn-outline-secondary me-2 mb-2';
      b.textContent=s;
      b.onclick = ()=>{
        document.querySelectorAll('#qv-sizes button').forEach(x=>x.classList.remove('btn-danger'));
        b.classList.add('btn-danger');
        qv_size.value = s;
      };
      qv_sizes.appendChild(b);
    });

    new bootstrap.Modal(quickViewModal).show();
  } catch(e){
    console.error('openQuickView error', e);
  }
}

/* qty buttons */
document.getElementById('qv-inc').onclick = ()=>{ qv_qty.value = +qv_qty.value +1; qv_id_qty.value = qv_qty.value; };
document.getElementById('qv-dec').onclick = ()=>{ qv_qty.value = Math.max(1,+qv_qty.value -1); qv_id_qty.value = qv_qty.value; };

/* AJAX add to cart (QuickView) */
qv_addform.onsubmit = async e=>{
  e.preventDefault();
  const fd = new FormData(qv_addform);
  fd.append('ajax','1');

  qv_addbtn.disabled = true;
  try {
    const res = await fetch('cart.php?action=add',{method:'POST',body:fd});
    const json = await res.json();
    qv_addbtn.disabled = false;
    if (json && json.success) {
      document.querySelectorAll('#cartBadge').forEach(b=>b.textContent=json.cart.items_count);
      bootstrap.Modal.getInstance(quickViewModal)?.hide();
      // show small toast (or use alert as fallback)
      try {
        const t = document.createElement('div');
        t.style.position='fixed'; t.style.right='18px'; t.style.bottom='18px'; t.style.zIndex=2050;
        t.innerHTML = '<div class="toast align-items-center text-bg-success border-0 show" role="status" aria-live="polite" aria-atomic="true"><div class="d-flex"><div class="toast-body">Đã thêm vào giỏ hàng</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>';
        document.body.appendChild(t);
        setTimeout(()=>{ try{ document.body.removeChild(t); }catch(e){} },1600);
      } catch(e){ alert('✅ Đã thêm vào giỏ'); }
    } else {
      if (!json) alert('Lỗi server');
    }
  } catch(err){
    console.error(err);
    qv_addbtn.disabled = false;
    alert('Lỗi mạng, thử lại');
  }
};

/* buy now */
qv_buy.onclick = ()=>{
  qv_addform.requestSubmit();
  setTimeout(()=>location.href='checkout.php',400);
};

/* allow closing mini cart manually */
document.getElementById('mc-close')?.addEventListener('click', function(){ document.getElementById('miniCartTap').classList.remove('show'); });
</script>
</body>
</html>
