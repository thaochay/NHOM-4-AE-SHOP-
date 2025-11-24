<?php
// cart.php - Menu (ae-navbar) + improved UI + full cart logic (unchanged)
// Đã bổ sung menu "Đơn hàng của tôi"
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

/* ---------- fallback helpers ---------- */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/* ---------- getProductImage (prioritizes la_anh_chinh) ---------- */
function getProductImage($conn, $product_id, $placeholder = 'images/placeholder.jpg') {
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
    if (!$path || trim($path) === '') return $placeholder;
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
    foreach (array_unique($candidates) as $c) {
        $fs = __DIR__ . '/' . $c;
        if (file_exists($fs) && is_readable($fs) && filesize($fs) > 0) return $c;
    }
    if (file_exists(__DIR__ . '/' . $path)) return $path;
    if (file_exists(__DIR__ . '/' . $placeholder)) return $placeholder;
    return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
}

/* ---------- migrate (compat) ---------- */
function cart_migrate_if_needed(){
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) return;
    foreach ($_SESSION['cart'] as $key => $item) {
        if ((isset($item['ten']) || isset($item['gia'])) && (!isset($item['name']) || !isset($item['price']))) {
            $name = $item['ten'] ?? ($item['name'] ?? 'Sản phẩm');
            $price = $item['gia'] ?? ($item['price'] ?? 0);
            $img = $item['img'] ?? ($item['hinh'] ?? null);
            $qty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['so_luong']) ? (int)$item['so_luong'] : 1);
            $_SESSION['cart'][$key] = [
                'id' => $item['id'] ?? $key,
                'name' => $name,
                'price' => (float)$price,
                'qty' => max(1,$qty),
                'img' => $img,
                'ten' => $name,
                'gia' => (float)$price
            ];
        }
    }
}
cart_migrate_if_needed();

/* ---------- recalc ---------- */
function recalc_cart_data() {
    $cart = $_SESSION['cart'] ?? [];
    $subtotal = 0.0;
    $items_count = 0;
    foreach ($cart as $it) {
        $price = isset($it['price']) ? (float)$it['price'] : (isset($it['gia']) ? (float)$it['gia'] : 0.0);
        $qty = isset($it['qty']) ? (int)$it['qty'] : 1;
        $subtotal += $price * $qty;
        $items_count += $qty;
    }
    $shipping = ($subtotal >= 1000000 || $subtotal == 0) ? 0 : 30000;
    $discount = 0.0;
    $total = max(0, $subtotal - $discount + $shipping);
    return [
        'subtotal' => $subtotal,
        'subtotal_fmt' => price($subtotal),
        'shipping' => $shipping,
        'shipping_fmt' => $shipping == 0 ? 'Miễn phí' : price($shipping),
        'discount' => $discount,
        'discount_fmt' => $discount > 0 ? price($discount) : '-',
        'total' => $total,
        'total_fmt' => price($total),
        'items_count' => $items_count,
        'items' => array_values($_SESSION['cart'] ?? [])
    ];
}

/* ---------- is_ajax ---------- */
function is_ajax() {
    if (!empty($_POST['ajax']) && ($_POST['ajax'] == '1' || strtolower($_POST['ajax']) === 'true')) return true;
    if (!empty($_GET['ajax']) && ($_GET['ajax'] == '1' || strtolower($_GET['ajax']) === 'true')) return true;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    return false;
}

/* ---------- ensure session cart ---------- */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];

/* ---------- load categories ---------- */
try {
    $cats = $conn->query("SELECT id_danh_muc, ten, slug, thu_tu FROM danh_muc WHERE trang_thai = 1 ORDER BY thu_tu ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cats = [];
}

/* ---------- actions (unchanged) ---------- */
$action = $_REQUEST['action'] ?? null;

/* ADD */
if ($action === 'add') {
    $id = (int)($_POST['id'] ?? $_REQUEST['id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? $_REQUEST['qty'] ?? 1));
    $back = $_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? 'sanpham.php';
    if ($id <= 0) {
        if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>'ID sản phẩm không hợp lệ']); exit; }
        header('Location: ' . $back); exit;
    }
    try {
        $pstmt = $conn->prepare("SELECT id_san_pham, ten, gia FROM san_pham WHERE id_san_pham = :id LIMIT 1");
        $pstmt->execute([':id'=>$id]);
        $prod = $pstmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $prod = false; }
    if ($prod) {
        $pid = (int)$prod['id_san_pham'];
        $name = $prod['ten'];
        $price = (float)$prod['gia'];
        $img = getProductImage($conn, $pid);
    } else {
        $pid = $id; $name = 'Sản phẩm #' . $id; $price = 0.0; $img = 'images/placeholder.jpg';
    }
    if (isset($_SESSION['cart'][$pid])) {
        $_SESSION['cart'][$pid]['qty'] += $qty;
    } else {
        $_SESSION['cart'][$pid] = [
            'id' => $pid,
            'name' => $name,
            'price' => $price,
            'qty' => $qty,
            'img' => $img,
            'ten' => $name,
            'gia' => $price
        ];
    }
    $data = recalc_cart_data();
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>true,'message'=>'Đã thêm vào giỏ','cart'=>$data]); exit; }
    header('Location: ' . $back); exit;
}

/* REMOVE */
if ($action === 'remove') {
    $id = (int)($_POST['id'] ?? $_REQUEST['id'] ?? 0);
    if ($id > 0 && isset($_SESSION['cart'][$id])) unset($_SESSION['cart'][$id]);
    $data = recalc_cart_data();
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>true,'cart'=>$data]); exit; }
    header('Location: cart.php'); exit;
}

/* UPDATE */
if ($action === 'update') {
    $updated = false;
    if (!empty($_POST['qty']) && is_array($_POST['qty'])) {
        foreach ($_POST['qty'] as $k => $v) {
            $id = (int)$k;
            $q = max(0, (int)$v);
            if ($id <= 0) continue;
            if ($q <= 0) {
                if (isset($_SESSION['cart'][$id])) { unset($_SESSION['cart'][$id]); $updated = true; }
            } else {
                if (isset($_SESSION['cart'][$id])) {
                    $_SESSION['cart'][$id]['qty'] = $q; $updated = true;
                } else {
                    try {
                        $pstmt = $conn->prepare("SELECT ten, gia FROM san_pham WHERE id_san_pham=:id LIMIT 1");
                        $pstmt->execute([':id'=>$id]);
                        $r = $pstmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) { $r = false; }
                    if ($r) {
                        $_SESSION['cart'][$id] = ['id'=>$id,'name'=>$r['ten'],'price'=>(float)$r['gia'],'qty'=>$q,'img'=>getProductImage($conn,$id)];
                        $updated = true;
                    }
                }
            }
        }
    }
    $data = recalc_cart_data();
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>true,'cart'=>$data]); exit; }
    header('Location: cart.php'); exit;
}

/* CLEAR */
if ($action === 'clear') {
    unset($_SESSION['cart']);
    $data = recalc_cart_data();
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>true,'cart'=>$data]); exit; }
    header('Location: cart.php'); exit;
}

/* ---------- render ---------- */
$cart = $_SESSION['cart'] ?? [];
$tot = recalc_cart_data();
$logged = !empty($_SESSION['user']);
$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;
function is_active($file) { return basename($_SERVER['PHP_SELF']) === $file ? 'active' : ''; }
$cart_count_badge = isset($tot['items_count']) ? (int)$tot['items_count'] : (isset($_SESSION['cart']) ? array_sum(array_map(function($it){ return isset($it['qty'])?(int)$it['qty']:1; }, $_SESSION['cart'])) : 0);
$site_name = function_exists('site_name') ? site_name($conn) : 'AE Shop';
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Giỏ hàng — <?= esc($site_name) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
  /* === MENU + THEME (from your index.php, polished) === */
  :root{
    --accent:#0b7bdc;
    --muted:#6c757d;
    --nav-bg:#ffffff;
    --card-bg: #ffffff;
  }
  body{ background:#f8fbff; font-family:Inter,system-ui,Arial,sans-serif; color:#0b1a2b; }

  .ae-navbar{
    background:var(--nav-bg);
    box-shadow:0 10px 30px rgba(11,38,80,0.04);
    backdrop-filter:blur(10px);
    border-radius:12px;
    padding:10px 14px;
    margin:18px;
  }
  .ae-logo-mark{
    width:46px;height:46px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;
  }
  .nav-subtitle{ font-size:12px;color:var(--muted) }

  .navbar-nav .nav-item + .nav-item{ margin-left:.25rem; }
  .ae-navbar .nav-link{
    position:relative;padding:0.6rem 0.9rem;font-weight:600;font-size:.95rem;color:#1f2933;transition:color .18s ease-out;
  }
  .ae-navbar .nav-link::after{
    content:'';position:absolute;left:0.9rem;right:0.9rem;bottom:0.35rem;height:2px;border-radius:99px;background:linear-gradient(90deg,#0b7bdc,#38bdf8);transform:scaleX(0);opacity:0;transition:transform .18s ease-out,opacity .18s ease-out;
  }
  .ae-navbar .nav-link:hover,.ae-navbar .nav-link:focus{ color:var(--accent); }
  .ae-navbar .nav-link:hover::after,.ae-navbar .nav-link.active::after{ transform:scaleX(1);opacity:1; }

  /* ORDERS menu highlight */
  .nav-orders{
    padding-inline:0.9rem;
    margin-left:.25rem;
    border-radius:999px;
    background:rgba(11,123,220,0.06);
    display:flex;
    align-items:center;
    gap:.35rem;
  }

  .acc-icon{ width:40px;height:40px;border-radius:8px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:var(--accent); }
  .cart-badge{ position:relative; top:-10px; left:-8px; display:inline-block; margin-left:6px; }

  /* === PAGE LAYOUT === */
  .container-main{ max-width:1160px; margin:12px auto 48px; }
  .grid{ display:grid; grid-template-columns: 1fr 380px; gap:24px; align-items:start; }
  @media (max-width:991px){ .grid{ grid-template-columns:1fr; } .ae-navbar{ margin:10px 8px; } }

  /* cart list */
  .cart-panel{ background:var(--card-bg); border-radius:12px; padding:18px; box-shadow:0 10px 28px rgba(11,38,80,0.04); }
  .cart-item{ display:flex; gap:16px; align-items:center; padding:14px; border-radius:12px; transition:transform .12s; background:linear-gradient(180deg,#fff,#fbfdff); border:1px solid rgba(11,38,80,0.03); }
  .cart-item + .cart-item{ margin-top:12px; }
  .cart-item:hover{ transform:translateY(-6px); box-shadow:0 18px 50px rgba(11,38,80,0.06); }
  .thumb{ width:120px; height:100px; object-fit:contain; border-radius:8px; background:#f6f8fb; padding:6px; }
  .item-title{ font-weight:700; font-size:1rem; color:#0b1a2b; }
  .item-meta small{ color:var(--muted); }

  .qty-box{ display:flex; align-items:center; gap:8px; border-radius:10px; padding:6px; background:#f3f8ff; border:1px solid rgba(11,38,80,0.04); }
  .qty-box button{ width:34px; height:34px; border-radius:8px; border:none; background:#fff; box-shadow:0 4px 10px rgba(11,38,80,0.04); }
  .qty-box input{ width:72px; text-align:center; border:none; background:transparent; font-weight:700; }

  .remove-btn{ color:#ef4444; border:none; background:transparent; font-weight:700; cursor:pointer; }

  /* summary */
  .summary{ position:sticky; top:22px; background:linear-gradient(180deg,#fff,#f6fbff); padding:18px; border-radius:12px; box-shadow:0 12px 36px rgba(11,38,80,0.06); border:1px solid rgba(11,38,80,0.03); }
  .summary .row-item{ display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; color:#334155; }
  .summary .total{ font-size:1.35rem; font-weight:800; color:var(--accent); }
  .btn-checkout{ width:100%; padding:12px 14px; border-radius:12px; background:linear-gradient(90deg,var(--accent),#38bdf8); color:#fff; border:none; font-weight:800; box-shadow:0 12px 36px rgba(11,123,220,0.12); }

  .back-cart { display:inline-flex; align-items:center; gap:.5rem; padding:.5rem .85rem; border-radius:999px; border:1px solid rgba(11,123,220,0.12); background:#fff; color:var(--accent); font-weight:700; text-decoration:none; }

  footer.site-foot { margin-top:36px; padding:22px 0; text-align:center; color:#64748b; font-size:14px; }
  </style>
</head>
<body>

<!-- NAV -->
<nav class="navbar ae-navbar navbar-expand-lg">
  <div class="container-fluid">
    <div class="container d-flex align-items-center justify-content-between">
      <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
        <div class="ae-logo-mark">AE</div>
        <div class="d-none d-md-block">
          <div style="font-weight:800; font-size:1rem;"><?= esc($site_name) ?></div>
          <div class="nav-subtitle">Thời trang nam cao cấp</div>
        </div>
      </a>

      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain"><span class="navbar-toggler-icon"></span></button>

      <div class="collapse navbar-collapse" id="navMain">
        <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link <?= is_active('index.php') ?>" href="index.php">Trang chủ</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Sản phẩm</a>
            <ul class="dropdown-menu">
              <?php foreach($cats as $c): ?>
                <li><a class="dropdown-item" href="sanpham.php?cat=<?= (int)($c['id_danh_muc'] ?? $c['id'] ?? 0) ?>"><?= esc($c['ten']) ?></a></li>
              <?php endforeach; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-muted" href="sanpham.php">Xem tất cả sản phẩm</a></li>
            </ul>
          </li>

          <li class="nav-item"><a class="nav-link <?= is_active('about.php') ?>" href="about.php">Giới Thiệu</a></li>
          <li class="nav-item"><a class="nav-link <?= is_active('contact.php') ?>" href="contact.php">Liên hệ</a></li>

          <!-- HERE: ĐƠN HÀNG CỦA TÔI (đã thêm trở lại) -->
          <li class="nav-item">
            <a class="nav-link nav-orders <?= is_active('orders.php') ?>" href="orders.php">
              <i class="bi bi-receipt-cutoff"></i>
              <span class="d-none d-lg-inline ms-1">Đơn hàng của tôi</span>
            </a>
          </li>
        </ul>

        <div class="d-flex align-items-center gap-2">
          <form class="d-none d-lg-flex me-3" method="get" action="sanpham.php">
            <div class="input-group input-group-sm">
              <input name="q" class="form-control" placeholder="Tìm sản phẩm, mã..." value="<?= esc($_GET['q'] ?? '') ?>">
              <button class="btn btn-dark" type="submit"><i class="bi bi-search"></i></button>
            </div>
          </form>

          <!-- account -->
          <div class="dropdown me-2">
            <a class="d-flex align-items-center text-decoration-none" href="#" data-bs-toggle="dropdown">
              <div class="acc-icon"><i class="bi bi-person-fill"></i></div>
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

          <!-- mini-cart -->
          <div class="dropdown">
            <a class="d-flex align-items-center text-decoration-none position-relative" href="#" data-bs-toggle="dropdown">
              <div style="width:44px;height:44px;border-radius:10px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;color:var(--accent)"><i class="bi bi-bag-fill"></i></div>
              <span class="ms-2 d-none d-md-inline small">Giỏ hàng</span>
              <?php if($cart_count_badge>0): ?><span class="badge bg-danger rounded-pill cart-badge"><?= $cart_count_badge ?></span><?php endif; ?>
            </a>
            <div class="dropdown-menu dropdown-menu-end p-3" style="min-width:320px">
              <div class="d-flex justify-content-between align-items-center mb-2"><strong>Giỏ hàng (<?= $cart_count_badge ?>)</strong><a href="cart.php" class="small">Xem đầy đủ</a></div>
              <?php if (empty($_SESSION['cart'])): ?>
                <div class="text-muted small">Bạn chưa có sản phẩm nào trong giỏ.</div>
                <div class="mt-3"><a href="sanpham.php" class="btn btn-primary btn-sm w-100">Mua ngay</a></div>
              <?php else: ?>
                <div style="max-height:240px;overflow:auto">
                  <?php $total_preview=0; foreach($_SESSION['cart'] as $id=>$item):
                    $qty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['sl']) ? (int)$item['sl'] : 1);
                    $price = isset($item['price']) ? (float)$item['price'] : (isset($item['gia']) ? (float)$item['gia'] : 0);
                    $name = $item['name'] ?? $item['ten'] ?? '';
                    $img = $item['img'] ?? $item['hinh'] ?? 'images/placeholder.jpg';
                    $subtotal = $qty * $price; $total_preview += $subtotal;
                  ?>
                    <div class="d-flex gap-2 align-items-center py-2">
                      <img src="<?= esc($img) ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px" alt="<?= esc($name) ?>">
                      <div class="flex-grow-1"><div class="small fw-semibold mb-1"><?= esc($name) ?></div><div class="small text-muted"><?= $qty ?> x <?= number_format($price,0,',','.') ?> ₫</div></div>
                      <div class="small"><?= number_format($subtotal,0,',','.') ?> ₫</div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3"><div class="text-muted small">Tạm tính</div><div class="fw-semibold"><?= number_format($total_preview,0,',','.') ?> ₫</div></div>
                <div class="mt-3 d-grid gap-2"><a href="cart.php" class="btn btn-outline-secondary btn-sm">Giỏ hàng</a><a href="checkout.php" class="btn btn-primary btn-sm">Thanh toán</a></div>
              <?php endif; ?>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</nav>

<!-- MAIN -->
<div class="container-main">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <a href="sanpham.php" class="back-cart"><i class="bi bi-arrow-left-circle"></i> Tiếp tục mua sắm</a>
    <div class="text-center">
      <h4 class="mb-0">Giỏ hàng của bạn</h4>
      <small class="text-muted">Bạn có <strong><?= (int)$tot['items_count'] ?></strong> sản phẩm</small>
    </div>
    <div></div>
  </div>

  <div class="grid">
    <!-- left: items -->
    <div class="cart-panel">
      <?php if (empty($cart)): ?>
        <div class="text-center py-5">
          <h5 class="mb-2">Giỏ hàng trống</h5>
          <p class="text-muted">Bạn chưa thêm sản phẩm nào. Bắt đầu mua sắm thôi!</p>
          <div class="mt-3 d-flex justify-content-center gap-2">
            <a href="sanpham.php" class="btn btn-primary">Mua ngay</a>
            <a href="index.php" class="btn btn-outline-secondary">Về trang chủ</a>
          </div>
        </div>
      <?php else: ?>
        <form id="cartForm" method="post" action="cart.php?action=update">
          <?php foreach ($cart as $id => $it):
            $price = isset($it['price']) ? (float)$it['price'] : (isset($it['gia']) ? (float)$it['gia'] : 0);
            $qty = isset($it['qty']) ? (int)$it['qty'] : 1;
            $img = $it['img'] ?? getProductImage($conn, $it['id'] ?? $id);
            $name = $it['name'] ?? $it['ten'] ?? 'Sản phẩm';
          ?>
            <div class="cart-item" data-id="<?= (int)$id ?>">
              <img src="<?= esc($img) ?>" alt="<?= esc($name) ?>" class="thumb" onerror="this.src='images/placeholder.jpg'">
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between">
                  <div>
                    <div class="item-title"><?= esc($name) ?></div>
                    <div class="text-muted small">Mã: <?= (int)($it['id'] ?? $id) ?></div>
                  </div>
                  <div class="text-end">
                    <div class="fw-bold"><?= price($price) ?></div>
                    <div class="text-muted small"><?= price($price * $qty) ?></div>
                  </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                  <div class="qty-box">
                    <button type="button" class="btn-decr btn btn-sm" data-id="<?= (int)$id ?>"><i class="bi bi-dash"></i></button>
                    <input type="number" min="0" name="qty[<?= (int)$id ?>]" value="<?= (int)$qty ?>" class="form-control form-control-sm" style="width:84px;text-align:center;font-weight:700;">
                    <button type="button" class="btn-incr btn btn-sm" data-id="<?= (int)$id ?>"><i class="bi bi-plus"></i></button>
                  </div>

                  <div>
                    <button type="button" class="remove-btn" data-id="<?= (int)$id ?>" title="Xóa"><i class="bi bi-trash"></i></button>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary">Cập nhật giỏ hàng</button>
            <button type="button" id="clearCartBtn" class="btn btn-outline-danger">Xóa toàn bộ</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <!-- right: summary -->
    <aside>
      <div class="summary">
        <div class="row-item"><div class="text-muted">Tạm tính</div><div class="fw-semibold"><?= $tot['subtotal_fmt'] ?></div></div>
        <div class="row-item"><div class="text-muted">Phí vận chuyển</div><div class="fw-semibold"><?= $tot['shipping_fmt'] ?></div></div>
        <hr>
        <div class="row-item mb-3"><div class="text-muted">Tổng</div><div class="total"><?= $tot['total_fmt'] ?></div></div>

        <?php if ($logged): ?>
          <a href="checkout.php" class="btn btn-checkout mb-2">Tiến hành thanh toán</a>
        <?php else: ?>
          <a href="login.php?back=checkout.php" class="btn btn-checkout mb-2">Đăng nhập & Thanh toán</a>
          <a href="checkout.php?guest=1" class="btn btn-outline-secondary w-100 mb-2">Thanh toán như khách</a>
        <?php endif; ?>

        <a href="sanpham.php" class="btn btn-outline-primary w-100 mb-2">Tiếp tục mua sắm</a>
        <div class="text-muted small">Giao hàng 1-3 ngày • Đổi trả 7 ngày</div>
      </div>
    </aside>
  </div>
</div>

<footer class="site-foot"><?= esc($site_name) ?> — © <?= date('Y') ?></footer>

<!-- modal confirm clear -->
<div class="modal fade" id="confirmClearModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body text-center p-4">
        <h5 class="mb-3">Xóa toàn bộ giỏ hàng?</h5>
        <p class="text-muted">Hành động này sẽ xóa tất cả sản phẩm trong giỏ.</p>
        <div class="d-flex gap-2 justify-content-center mt-3">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
          <button id="confirmClearBtn" type="button" class="btn btn-danger">Xóa</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // qty controls
  document.querySelectorAll('.btn-decr').forEach(b => b.addEventListener('click', function(){
    const id = this.dataset.id;
    const f = document.querySelector('input[name="qty['+id+']"]');
    if(!f) return;
    f.value = Math.max(0, parseInt(f.value||0) - 1);
  }));
  document.querySelectorAll('.btn-incr').forEach(b => b.addEventListener('click', function(){
    const id = this.dataset.id;
    const f = document.querySelector('input[name="qty['+id+']"]');
    if(!f) return;
    f.value = Math.max(1, parseInt(f.value||0) + 1);
  }));

  // remove item via AJAX
  document.querySelectorAll('.remove-btn').forEach(b => b.addEventListener('click', function(){
    if (!confirm('Bạn có chắc muốn xóa sản phẩm này khỏi giỏ?')) return;
    const id = this.dataset.id;
    fetch('cart.php?action=remove', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: 'id=' + encodeURIComponent(id) + '&ajax=1'
    }).then(r=>r.json()).then(res=>{
      if (res.success) location.reload();
      else alert(res.message || 'Lỗi');
    }).catch(()=>alert('Lỗi kết nối'));
  }));

  // clear cart (modal)
  document.getElementById('clearCartBtn')?.addEventListener('click', function(){
    var m = new bootstrap.Modal(document.getElementById('confirmClearModal'));
    m.show();
  });
  document.getElementById('confirmClearBtn')?.addEventListener('click', function(){
    fetch('cart.php?action=clear', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'ajax=1'
    }).then(r=>r.json()).then(res=>{
      if(res.success) location.reload(); else alert('Lỗi');
    }).catch(()=>alert('Lỗi kết nối'));
  });

  // default submit for update form
  document.getElementById('cartForm')?.addEventListener('submit', function(){ /* use native submit */ });
</script>
</body>
</html>
