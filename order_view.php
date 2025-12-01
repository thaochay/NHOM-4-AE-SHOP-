<?php
// order_view.php - Chi tiết đơn hàng (giao diện đẹp & responsive)
// Phiên bản: bỏ khung Hỗ trợ ở dưới, nút Hỗ trợ sẽ chuyển sang support_request.php?order_id=...

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// tạo CSRF nếu chưa có
if (!isset($_SESSION['csrf'])) {
    try { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    catch (Exception $e) { $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16)); }
}

// kiểm tra login
if (!isset($_SESSION['user'])) {
    header("Location: login.php?back=" . urlencode(basename(__FILE__) . '?' . $_SERVER['QUERY_STRING']));
    exit;
}
$user = $_SESSION['user'];
$user_id = $user['id_nguoi_dung'] ?? $user['id'] ?? $user['user_id'] ?? 0;

// lấy id đơn từ query
$order_id = (int)($_GET['id'] ?? 0);
if ($order_id <= 0) { http_response_code(400); die("ID đơn không hợp lệ"); }

// fetch đơn
try {
    $stmt = $conn->prepare("SELECT * FROM don_hang WHERE id_don_hang = :id LIMIT 1");
    $stmt->execute([':id' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500); die("Lỗi truy vấn CSDL.");
}
if (!$order) { http_response_code(404); die("Không tìm thấy đơn hàng."); }
if (!empty($order['id_nguoi_dung']) && $order['id_nguoi_dung'] != $user_id) {
    http_response_code(403); die("Bạn không có quyền xem đơn này.");
}

// fetch items
try {
    $q = $conn->prepare("
        SELECT ctdh.*, sp.ten as ten_san_pham
        FROM chi_tiet_don_hang ctdh
        LEFT JOIN san_pham sp ON ctdh.id_san_pham = sp.id_san_pham
        WHERE ctdh.id_don_hang = :id
    ");
    $q->execute([':id' => $order_id]);
    $items = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $items = []; }

// helper
function e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function status_badge($s){
    $s = strtolower((string)$s);
    $map = [
        'moi'=>'Chờ xử lý','new'=>'Chờ xử lý','processing'=>'Đang xử lý','dang_xu_ly'=>'Đang xử lý',
        'shipped'=>'Đã giao','delivered'=>'Đã giao','completed'=>'Hoàn tất',
        'cancel'=>'Đã huỷ','huy'=>'Đã huỷ'
    ];
    $label = $map[$s] ?? ucfirst($s);
    $cls = 'secondary';
    if (in_array($s, ['moi','new','processing','dang_xu_ly'])) $cls = 'warning';
    if (in_array($s, ['shipped','delivered','completed'])) $cls = 'success';
    if (in_array($s, ['cancel','huy'])) $cls = 'danger';
    return "<span class=\"badge bg-{$cls}\">" . e($label) . "</span>";
}

$total = (float)($order['tong_tien'] ?? 0);
$shipping = (float)($order['phi_van_chuyen'] ?? 0);
$discount = (float)($order['giam_gia'] ?? 0);
$created_at = $order['ngay_dat'] ?? $order['created_at'] ?? '';

/* image helpers - đơn giản */
function normalize_image_path($p) {
    $p = trim((string)$p);
    if ($p === '') return 'https://via.placeholder.com/200x200?text=No+Image';
    $p = html_entity_decode($p, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('#^https?://#i', $p)) return $p;
    return $p;
}
function get_product_image($conn, $product_id) {
    $fallback = 'https://via.placeholder.com/200x200?text=No+Image';
    if (empty($product_id)) return $fallback;
    try {
        $q = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
        $q->execute([':id' => $product_id]); $path = $q->fetchColumn();
        if ($path) return normalize_image_path($path);
        $q2 = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id ORDER BY thu_tu ASC, id_anh ASC LIMIT 1");
        $q2->execute([':id' => $product_id]); $path2 = $q2->fetchColumn();
        if ($path2) return normalize_image_path($path2);
    } catch (Exception $e) {}
    return $fallback;
}
function get_product_images($conn, $product_id, $limit = 4) {
    $res = []; if (empty($product_id)) return $res;
    try {
        $q = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id ORDER BY la_anh_chinh DESC, thu_tu ASC, id_anh ASC LIMIT :lim");
        $q->bindValue(':id', $product_id, PDO::PARAM_INT);
        $q->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        $q->execute(); $rows = $q->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $p) if ($p) $res[] = normalize_image_path($p);
    } catch (Exception $e) {}
    return $res;
}

/* decode ghi_chu / dia_chi */
function try_json_decode($str) {
    if (!is_string($str)) return null;
    $s = trim($str); if ($s === '') return null;
    $decoded = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($decoded !== '' && ($decoded[0] === '{' || $decoded[0] === '[')) {
        $j = json_decode($decoded, true);
        if (json_last_error() === JSON_ERROR_NONE) return $j;
    }
    return null;
}

$raw_ghi_chu = $order['ghi_chu'] ?? '';
$maybe_note = try_json_decode($raw_ghi_chu);
if ($maybe_note && is_array($maybe_note)) {
    $pieces = [];
    if (!empty($maybe_note['ten'])) $pieces[] = 'Tên: ' . e($maybe_note['ten']);
    if (!empty($maybe_note['dien_thoai'])) $pieces[] = 'ĐT: ' . e($maybe_note['dien_thoai']);
    if (!empty($maybe_note['email'])) $pieces[] = 'Email: ' . e($maybe_note['email']);
    if (!empty($maybe_note['dia_chi'])) $pieces[] = 'Địa chỉ: ' . e($maybe_note['dia_chi']);
    if (!empty($maybe_note['phuong_thuc'])) $pieces[] = 'Thanh toán: ' . e($maybe_note['phuong_thuc']);
    $ghi_chu_display = count($pieces) ? implode(' — ', $pieces) : e(json_encode($maybe_note, JSON_UNESCAPED_UNICODE));
} else {
    $ghi_chu_display = e(html_entity_decode((string)$raw_ghi_chu, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

$raw_dia_chi = $order['dia_chi'] ?? ($order['diachi'] ?? '');
$maybe_addr = try_json_decode($raw_dia_chi);
if ($maybe_addr && is_array($maybe_addr)) {
    if (!empty($maybe_addr['dia_chi'])) $dia_chi_display = e($maybe_addr['dia_chi']);
    else {
        $addr_parts = [];
        foreach (['ten','dia_chi','phuong_xa','quan_huyen','tinh_tp','so_dien_thoai'] as $k) {
            if (!empty($maybe_addr[$k])) $addr_parts[] = e($maybe_addr[$k]);
        }
        $dia_chi_display = count($addr_parts) ? implode(', ', $addr_parts) : e(json_encode($maybe_addr, JSON_UNESCAPED_UNICODE));
    }
} else {
    $dia_chi_display = e(html_entity_decode((string)$raw_dia_chi, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

$display_name = e($order['ten_khach'] ?? $order['ten'] ?? $_SESSION['user']['ten'] ?? '');
$display_email = e($order['email'] ?? $_SESSION['user']['email'] ?? '');
$display_phone = e($order['so_dien_thoai'] ?? $order['dien_thoai'] ?? '');

?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Chi tiết đơn hàng #<?= e($order['ma_don'] ?? $order_id) ?> — <?= e(function_exists('site_name') ? site_name($conn) : 'Cửa hàng') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    :root{--accent:#0d6efd;--muted:#6c757d;--card-radius:14px;}
    body{background:#f5f8fb;color:#222;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;padding:24px 12px;}
    .page-wrap{max-width:1100px;margin:0 auto;}
    .hero{border-radius:var(--card-radius);overflow:hidden;display:flex;gap:16px;align-items:center;padding:18px;background:linear-gradient(90deg, rgba(13,110,253,0.03), rgba(13,110,253,0.02));border:1px solid rgba(13,110,253,0.04);}
    .hero img{width:120px;height:120px;object-fit:cover;border-radius:10px;background:#fff;}
    .card-panel{border-radius:14px;background:#fff;padding:18px;box-shadow:0 16px 40px rgba(11,38,80,0.04);}
    .table-products img{width:64px;height:64px;object-fit:cover;border-radius:8px;}
    .timeline-step{padding:8px 12px;border-radius:999px;background:#f8fbff;border:1px solid #eaf2ff;font-size:14px;color:var(--muted);}
    .print-hide{display:inline-block;}
    .support-fab {
      position:fixed; right:18px; bottom:20px; z-index:9999; border-radius:50%; width:56px; height:56px;
      display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#0d6efd,#0069d9);
      color:#fff; box-shadow:0 8px 20px rgba(13,110,253,0.18); cursor:pointer; border:none;
    }
    .support-fab:active{transform:translateY(1px)}
    @media print{body{background:#fff;padding:0}.print-hide{display:none !important}.support-fab{display:none}}
  </style>
</head>
<body>
<div class="page-wrap">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div class="d-flex gap-2 align-items-center">
      <a href="orders.php" class="btn btn-link">&larr; Quay lại</a>
      <h4 class="mb-0">Chi tiết đơn hàng</h4>
    </div>
    <div class="print-hide d-flex gap-2 align-items-center">
      <button onclick="window.print()" class="btn btn-outline-secondary me-2"><i class="bi bi-printer"></i> In đơn</button>
      <a href="orders.php" class="btn btn-outline-primary">Danh sách đơn hàng</a>
      <!-- nút Hỗ trợ: chuyển trang tới support_request.php -->
      <button id="jump-to-support" class="btn btn-outline-success ms-2" title="Yêu cầu hỗ trợ cho đơn này"><i class="bi bi-life-preserver"></i> Hỗ trợ</button>
    </div>
  </div>

  <div class="hero mb-4">
    <img src="<?= e($order['hero_image'] ?? '') ?: 'https://picsum.photos/seed/orderhero/240/240' ?>" alt="Order hero" onerror="this.style.display='none'">
    <div style="flex:1">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <h5 class="mb-1">Mã đơn: <strong>#<?= e($order['ma_don'] ?? $order_id) ?></strong></h5>
          <div class="text-muted small">Ngày đặt: <?= e($created_at) ?></div>
        </div>
        <div class="text-end">
          <div style="font-size:18px"><?= status_badge($order['trang_thai'] ?? '') ?></div>
          <div class="text-muted small mt-2">Tổng: <strong style="font-size:18px"><?= number_format($total,0,',','.') ?> ₫</strong></div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <div class="timeline-step">Đã đặt</div>
        <div class="timeline-step">Đang xử lý</div>
        <div class="timeline-step">Đang giao</div>
        <div class="timeline-step">Hoàn tất</div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-lg-8">
      <!-- products -->
      <div class="card-panel mb-3">
        <h5 class="mb-3">Sản phẩm (<?= count($items) ?>)</h5>
        <div class="table-responsive">
          <table class="table table-borderless align-middle table-products">
            <thead>
              <tr class="text-muted small">
                <th style="width:80px"></th>
                <th>Sản phẩm</th>
                <th class="text-end">Giá</th>
                <th class="text-center">Số lượng</th>
                <th class="text-end">Thành tiền</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($items as $it):
                $pname = $it['ten_san_pham'] ?? $it['ten'] ?? 'Sản phẩm';
                $price = (float)($it['gia'] ?? 0);
                $qty   = (int)($it['so_luong'] ?? $it['so'] ?? 1);
                $subtotal = (float)($it['thanh_tien'] ?? $price * $qty);
                $main_img = get_product_image($conn, $it['id_san_pham'] ?? null);
                $thumbs = get_product_images($conn, $it['id_san_pham'] ?? null, 6);
                if ($main_img && !in_array($main_img, $thumbs)) array_unshift($thumbs, $main_img);
            ?>
              <tr>
                <td style="width:80px">
                  <img src="<?= e($main_img) ?>" alt="<?= e($pname) ?>" onerror="this.src='https://via.placeholder.com/64'" style="width:64px;height:64px;object-fit:cover;border-radius:8px">
                </td>
                <td>
                  <div class="fw-semibold"><?= e($pname) ?></div>
                  <?php if (!empty($thumbs)): ?>
                    <div class="mt-1" style="display:flex;gap:6px;align-items:center">
                      <?php $count=0; foreach($thumbs as $t){ if($count++>=3) break; ?>
                        <img src="<?= e($t) ?>" alt="<?= e($pname) ?> thumb" style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #eee" onerror="this.src='https://via.placeholder.com/40'">
                      <?php } if(count($thumbs)>3): ?><div class="small text-muted" style="margin-left:6px">+<?= count($thumbs)-3 ?></div><?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($it['ten_mau']) || !empty($it['size'])): ?><div class="text-muted small mt-1">Chi tiết: <?= e(implode(' - ', array_filter([$it['ten_mau'] ?? '', $it['size'] ?? '']))) ?></div><?php elseif (!empty($it['id_chi_tiet'])): ?><div class="text-muted small mt-1">Chi tiết: <?= e($it['id_chi_tiet']) ?></div><?php endif; ?>
                </td>
                <td class="text-end"><?= number_format($price,0,',','.') ?> ₫</td>
                <td class="text-center"><?= $qty ?></td>
                <td class="text-end"><?= number_format($subtotal,0,',','.') ?> ₫</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- payment & shipping info -->
      <div class="card-panel">
        <h5 class="mb-3">Thông tin thanh toán & giao hàng</h5>
        <div class="row">
          <div class="col-md-6 mb-3">
            <div class="small text-muted">Người đặt</div>
            <div class="fw-semibold"><?= $display_name ?></div>
            <div class="small text-muted"><?= $display_email ?></div>
            <div class="small text-muted"><?= $display_phone ?></div>
          </div>
          <div class="col-md-6 mb-3">
            <div class="small text-muted">Địa chỉ giao hàng</div>
            <div class="fw-semibold"><?= nl2br(e($dia_chi_display ?: '-')) ?></div>
          </div>
          <div class="col-md-6 mb-3">
            <div class="small text-muted">Phương thức thanh toán</div>
            <div class="fw-semibold"><?= e($order['phuong_thuc_thanh_toan'] ?? $order['phuong_thuc'] ?? 'COD') ?></div>
          </div>
          <div class="col-md-6 mb-3">
            <div class="small text-muted">Trạng thái đơn</div>
            <div class="fw-semibold"><?= status_badge($order['trang_thai'] ?? '') ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card-panel">
        <h5 class="mb-3">Tóm tắt thanh toán</h5>
        <div class="d-flex justify-content-between mb-2"><div class="text-muted">Tạm tính</div><div><?= number_format(array_reduce($items, function($s,$it){ return $s + (float)($it['thanh_tien'] ?? ($it['gia'] * ($it['so_luong'] ?? 1))); }, 0),0,',','.') ?> ₫</div></div>
        <div class="d-flex justify-content-between mb-2"><div class="text-muted">Phí vận chuyển</div><div><?= $shipping ? number_format($shipping,0,',','.') . ' ₫' : '<strong>Miễn phí</strong>' ?></div></div>
        <div class="d-flex justify-content-between mb-2"><div class="text-muted">Giảm giá</div><div><?= $discount ? '-' . number_format($discount,0,',','.') . ' ₫' : '-' ?></div></div>
        <hr>
        <div class="d-flex justify-content-between align-items-center"><div class="fw-bold">Tổng thanh toán</div><div class="h5 text-primary fw-bold"><?= number_format($total,0,',','.') ?> ₫</div></div>

        <div class="mt-3">
          <form method="post" action="checkout.php" class="d-grid mb-2 print-hide"><input type="hidden" name="action" value="reorder"><input type="hidden" name="order_id" value="<?= $order_id ?>"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf']) ?>"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-arrow-clockwise"></i> Mua lại</button></form>

          <form method="post" action="checkout.php" class="d-grid print-hide"><input type="hidden" name="action" value="pay_order"><input type="hidden" name="order_id" value="<?= $order_id ?>"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf']) ?>"><button class="btn btn-primary" type="submit"><i class="bi bi-credit-card"></i> Thanh toán đơn này</button></form>

          <a href="invoice.php?id=<?= $order_id ?>" class="btn btn-outline-secondary w-100 mt-2 print-hide">Xem hoá đơn</a>
          <a href="orders.php" class="btn btn-link w-100 mt-1 print-hide">Quay về danh sách đơn</a>
        </div>
      </div>

      <!-- NOTE: khung Hỗ trợ đã được gỡ theo yêu cầu -->
    </div>
  </div>
</div>

<!-- Floating support button -->
<button id="support-fab" class="support-fab" aria-label="Yêu cầu hỗ trợ" title="Yêu cầu hỗ trợ">
  <i class="bi bi-headset"></i>
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const orderId = <?= json_encode((int)$order_id) ?>;
  const jumpBtn = document.getElementById('jump-to-support');
  const fab = document.getElementById('support-fab');

  function goToSupportPage() {
    // chuyển đến trang support_request.php kèm order_id
    const url = 'support_request.php?order_id=' + encodeURIComponent(orderId);
    window.location.href = url;
  }

  if (jumpBtn) jumpBtn.addEventListener('click', function(e){ e.preventDefault(); goToSupportPage(); });
  if (fab) fab.addEventListener('click', function(e){ e.preventDefault(); goToSupportPage(); });
});
</script>

</body>
</html>
