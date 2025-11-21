<?php
// cart.php - Quản lý giỏ hàng (add/remove/update/clear + giao diện)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

/* ---------- fallback helpers (nếu inc/helpers.php thiếu) ---------- */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/* ---------- hàm lấy ảnh sản phẩm (ưu tiên la_anh_chinh) ---------- */
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
    // fallback
    if (file_exists(__DIR__ . '/' . $path)) return $path;
    if (file_exists(__DIR__ . '/' . $placeholder)) return $placeholder;
    return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
}

/* ---------- migrate helper (tương thích dữ liệu cũ) ---------- */
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

/* ---------- recalc totals helper ---------- */
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

/* ---------- detect AJAX request ---------- */
function is_ajax() {
    if (!empty($_POST['ajax']) && ($_POST['ajax'] == '1' || strtolower($_POST['ajax']) === 'true')) return true;
    if (!empty($_GET['ajax']) && ($_GET['ajax'] == '1' || strtolower($_GET['ajax']) === 'true')) return true;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    return false;
}

/* ---------- ensure session cart exists ---------- */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];

/* ---------- handle actions ---------- */
$action = $_REQUEST['action'] ?? null;

/* ----- ADD ----- */
if ($action === 'add') {
    $id = (int)($_POST['id'] ?? $_REQUEST['id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? $_REQUEST['qty'] ?? 1));
    $back = $_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? 'sanpham.php';

    if ($id <= 0) {
        if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>'ID sản phẩm không hợp lệ']); exit; }
        header('Location: ' . $back); exit;
    }

    // load product basic info
    try {
        $pstmt = $conn->prepare("SELECT id_san_pham, ten, gia FROM san_pham WHERE id_san_pham = :id LIMIT 1");
        $pstmt->execute([':id'=>$id]);
        $prod = $pstmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $prod = false;
    }

    if ($prod) {
        $pid = (int)$prod['id_san_pham'];
        $name = $prod['ten'];
        $price = (float)$prod['gia'];
        $img = getProductImage($conn, $pid);
    } else {
        $pid = $id;
        $name = 'Sản phẩm #' . $id;
        $price = 0.0;
        $img = 'images/placeholder.jpg';
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
    if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>true,'message'=>'Đã thêm vào giỏ','cart'=>$data]);
        exit;
    }
    header('Location: ' . $back); exit;
}

/* ----- REMOVE ----- */
if ($action === 'remove') {
    $id = (int)($_POST['id'] ?? $_REQUEST['id'] ?? 0);
    if ($id > 0 && isset($_SESSION['cart'][$id])) unset($_SESSION['cart'][$id]);
    $data = recalc_cart_data();
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>true,'cart'=>$data]); exit; }
    header('Location: cart.php'); exit;
}

/* ----- UPDATE ----- */
if ($action === 'update') {
    // expect qty array like qty[<id>] => value OR POST 'qty' as associative
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
                    // try load product
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

/* ----- CLEAR ----- */
if ($action === 'clear') {
    unset($_SESSION['cart']);
    $data = recalc_cart_data();
    if (is_ajax()) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>true,'cart'=>$data]); exit; }
    header('Location: cart.php'); exit;
}

/* ---------------- render cart page ---------------- */
$cart = $_SESSION['cart'] ?? [];
$tot = recalc_cart_data();
$logged = !empty($_SESSION['user']);

function is_active($file) {
    return basename($_SERVER['PHP_SELF']) === $file ? 'active' : '';
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Giỏ hàng — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{--primary:#0d6efd;--muted:#6c757d;--border:#eef3f8;}
    body{background:#f5f7fb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;color:#222;}
    .header{border-bottom:1px solid var(--border);background:#fff}
    .hdr-inner{max-width:1200px;margin:0 auto;padding:10px 16px;display:flex;gap:12px;align-items:center;justify-content:space-between}
    .brand{display:flex;gap:10px;align-items:center;text-decoration:none;color:inherit}
    .brand-circle{width:52px;height:52px;border-radius:50%;background:#0b1220;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px}
    .cart-wrap{max-width:1100px;margin:36px auto}
    .card-cart{border-radius:14px;box-shadow:0 10px 30px rgba(14,30,60,0.06);overflow:hidden;display:flex}
    .cart-left{padding:20px;background:#fff;flex:1}
    .cart-right{background:linear-gradient(180deg,#fff,#f8fbff);padding:20px;min-width:320px;width:320px}
    .product-thumb{width:84px;height:84px;object-fit:cover;border-radius:8px}
    .muted-small{color:var(--muted);font-size:.95rem}
    .qty-input{width:110px}
    .empty-hero{padding:28px;border-radius:12px;border:1px dashed #e6eef9;background:linear-gradient(90deg,#fbfcff,#ffffff);text-align:center}
  </style>
</head>
<body>
  <header class="header">
    <div class="hdr-inner">
      <a href="index.php" class="brand"><div class="brand-circle">AE</div><div class="d-none d-md-block"><strong><?= esc(site_name($conn)) ?></strong><div class="muted-small">Thời trang nam cao cấp</div></div></a>
      <nav class="d-none d-md-flex gap-3"><a class="<?= is_active('index.php') ?>" href="index.php">Trang chủ</a><a class="<?= is_active('sanpham.php') ?>" href="sanpham.php">Sản phẩm</a><a href="contact.php">Liên hệ</a></nav>
      <div class="d-flex align-items-center gap-2">
        <a href="cart.php" class="btn btn-outline-primary position-relative"><i class="bi bi-bag"></i><span class="d-none d-md-inline ms-2">Giỏ hàng</span><span id="cart-count-badge" class="badge bg-danger rounded-pill cart-badge"><?= (int)$tot['items_count'] ?></span></a>
      </div>
    </div>
  </header>

  <div class="cart-wrap">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <a href="sanpham.php" class="btn btn-link">&larr; Tiếp tục mua sắm</a>
      <h3 class="mb-0">Giỏ hàng của bạn (<?= (int)$tot['items_count'] ?>)</h3>
      <div></div>
    </div>

    <div class="card card-cart">
      <div class="cart-left">
        <?php if (empty($cart)): ?>
          <div class="empty-hero">
            <h5>Giỏ hàng trống</h5>
            <p class="muted-small">Bạn chưa thêm sản phẩm nào. Bắt đầu khám phá và thêm vào giỏ nhé!</p>
            <div class="mt-3 d-flex gap-2 justify-content-center">
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
            <div class="d-flex align-items-center gap-3 mb-3">
              <img src="<?= esc($img) ?>" alt="" class="product-thumb" onerror="this.src='images/placeholder.jpg'">
              <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <div class="fw-semibold"><?= esc($name) ?></div>
                    <div class="muted-small mt-1">Mã: <?= (int)($it['id'] ?? $id) ?></div>
                  </div>
                  <div class="text-end">
                    <div class="fw-bold"><?= price($price) ?></div>
                    <div class="muted-small mt-1"><?= price($price * $qty) ?></div>
                  </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mt-3">
                  <div class="input-group input-group-sm qty-input" style="width:140px;">
                    <button type="button" class="btn btn-outline-secondary btn-decr" data-id="<?= (int)$id ?>">-</button>
                    <input type="number" min="0" name="qty[<?= (int)$id ?>]" value="<?= (int)$qty ?>" class="form-control text-center qty-field" data-id="<?= (int)$id ?>">
                    <button type="button" class="btn btn-outline-secondary btn-incr" data-id="<?= (int)$id ?>">+</button>
                  </div>

                  <div>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove" data-id="<?= (int)$id ?>">Xóa</button>
                  </div>
                </div>
              </div>
            </div>
            <hr>
            <?php endforeach; ?>

            <div class="d-flex gap-2 mt-3">
              <button type="submit" class="btn btn-primary">Cập nhật giỏ hàng</button>
              <button type="button" id="clearCartBtn" class="btn btn-outline-danger">Xóa toàn bộ</button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <div class="cart-right">
        <div class="position-sticky" style="top:22px;">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="muted-small">Tạm tính</div>
            <div class="fw-semibold"><?= $tot['subtotal_fmt'] ?></div>
          </div>

          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="muted-small">Phí vận chuyển</div>
            <div class="fw-semibold"><?= $tot['shipping_fmt'] ?></div>
          </div>

          <hr>

          <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="h5 mb-0">Tổng thanh toán</div>
            <div class="h5 mb-0 text-primary"><?= $tot['total_fmt'] ?></div>
          </div>

          <?php if (!$logged): ?>
            <div class="mb-3" style="border-radius:10px;border:1px solid #eaf0ff;padding:12px;background:#fff;">
              <div class="fw-semibold mb-1">Bạn chưa đăng nhập</div>
              <div class="muted-small mb-2">Đăng nhập để lưu giỏ hàng, theo dõi đơn và thanh toán nhanh hơn.</div>
              <div class="d-grid gap-2">
                <a href="login.php?back=cart.php" class="btn btn-primary">Đăng nhập</a>
                <a href="register.php" class="btn btn-outline-primary">Tạo tài khoản</a>
                <a href="checkout.php?guest=1" class="btn btn-secondary">Thanh toán như khách</a>
              </div>
            </div>
          <?php else: ?>
            <div class="d-grid gap-2">
              <a href="checkout.php" class="btn btn-success btn-lg">Tiến hành thanh toán</a>
              <a href="sanpham.php" class="btn btn-outline-secondary">Tiếp tục mua sắm</a>
            </div>
          <?php endif; ?>

          <div class="mt-3 muted-small">Hỗ trợ: <a href="contact.php">Liên hệ</a></div>
        </div>
      </div>

    </div>
  </div>

<!-- scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // qty controls
  document.querySelectorAll('.btn-decr').forEach(b => b.addEventListener('click', function(){
    const id = this.dataset.id; const f = document.querySelector('input.qty-field[data-id="'+id+'"]'); if(!f) return;
    f.value = Math.max(0, parseInt(f.value||0) - 1);
  }));
  document.querySelectorAll('.btn-incr').forEach(b => b.addEventListener('click', function(){
    const id = this.dataset.id; const f = document.querySelector('input.qty-field[data-id="'+id+'"]'); if(!f) return;
    f.value = Math.max(1, parseInt(f.value||0) + 1);
  }));

  // remove item via AJAX
  document.querySelectorAll('.btn-remove').forEach(b => b.addEventListener('click', function(){
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

  // clear cart
  document.getElementById('clearCartBtn')?.addEventListener('click', function(){
    if (!confirm('Xóa toàn bộ giỏ hàng?')) return;
    fetch('cart.php?action=clear', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: 'ajax=1' })
    .then(r=>r.json()).then(res=>{ if(res.success) location.reload(); else alert('Lỗi'); })
    .catch(()=>alert('Lỗi kết nối'));
  });

  // allow default form submit for update (non-AJAX). If you prefer AJAX update, implement here.
  document.getElementById('cartForm')?.addEventListener('submit', function(e){
    // default submit -> server xử lý update và reload
    return true;
  });
</script>
</body>
</html>
