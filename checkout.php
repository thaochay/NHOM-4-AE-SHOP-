<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

/* ❌ ĐÃ BỎ header.php Ở ĐÂY ĐỂ TRÁNH LỖI REDIRECT */

if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}
if (!function_exists('safe_post')) {
    function safe_post($k){ return trim($_POST[$k] ?? ''); }
}
if (!function_exists('normalize_phone')) {
    function normalize_phone($p){ return preg_replace('/[^0-9\+]/','', $p); }
}
if (!function_exists('valid_phone')) {
    function valid_phone($p){ return preg_match('/^\+?[0-9]{9,15}$/', $p); }
}

/* ===== LẤY ẢNH TỪ SQL ===== */
function getProductImage($conn, $product_id) {
    $placeholder = 'images/noimg.png';
    try {
        $stmt = $conn->prepare("
            SELECT duong_dan 
            FROM anh_san_pham 
            WHERE id_san_pham = ? 
            ORDER BY la_anh_chinh DESC, thu_tu ASC, id_anh ASC 
            LIMIT 1
        ");
        $stmt->execute([(int)$product_id]);
        $p = $stmt->fetchColumn();
        if ($p && trim($p) !== '') {
            return ltrim($p, '/');
        }
    } catch (Exception $e) {}
    return $placeholder;
}

function build_address_string($street, $ward, $city) {
    $parts = [];
    if ($street) $parts[] = $street;
    if ($ward)   $parts[] = $ward;
    if ($city)   $parts[] = $city;
    return implode(', ', $parts);
}

$cart = $_SESSION['cart'] ?? [];
$subtotal = 0;

foreach ($cart as $it) {
    $priceItem = (float)($it['price'] ?? $it['gia'] ?? 0);
    $qtyItem   = (int)($it['qty'] ?? $it['sl'] ?? 1);
    $subtotal += $priceItem * $qtyItem;
}

$shipping = ($subtotal >= 1000000 || $subtotal == 0) ? 0 : 30000;
$discount = $_SESSION['applied_coupon']['amount'] ?? 0;
$coupon_id = $_SESSION['applied_coupon']['id'] ?? null; // nếu lưu id mã giảm trong session
$total    = max(0, $subtotal + $shipping - $discount);

$errors = [];

/* ===== XỬ LÝ ĐẶT HÀNG (REPLACE PHẦN POST HIỆN TẠI) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
      $errors[] = "Lỗi bảo mật CSRF.";
  } else {

      $name   = safe_post('name');
      $phone  = normalize_phone(safe_post('phone'));
      $email  = safe_post('email');

      $street = safe_post('address_street');
      $ward   = safe_post('address_ward');
      $city   = safe_post('address_city');

      $address_full = build_address_string($street, $ward, $city);
      $payment_method = safe_post('payment_method') ?: 'cod';

      if (!$name) $errors[] = "Chưa nhập họ tên.";
      if (!valid_phone($phone)) $errors[] = "Số điện thoại không hợp lệ.";
      if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email sai định dạng.";
      if (!$address_full) $errors[] = "Chưa nhập địa chỉ.";
      if (empty($cart)) $errors[] = "Giỏ hàng trống.";

      if (empty($errors)) {
          try {
              $conn->beginTransaction();

              $ma_don = 'DH' . date('YmdHis');

              // Bắt buộc login theo code gốc của bạn:
              if (!isset($_SESSION['user']['id_nguoi_dung'])) {
                  throw new Exception("Bạn phải đăng nhập để đặt hàng");
              }
              $user_id = (int) $_SESSION['user']['id_nguoi_dung'];

              // 1) LƯU dia_chi
              $stmtAddr = $conn->prepare("
                  INSERT INTO dia_chi
                  (id_nguoi_dung, ho_ten, so_dien_thoai, dia_chi_chi_tiet, phuong_xa, quan_huyen, tinh_thanh, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
              ");
              $stmtAddr->execute([
                  $user_id,
                  $name,
                  $phone,
                  $street,
                  $ward,
                  '',    // quan_huyen (để trống nếu DB của bạn vậy)
                  $city
              ]);
              $id_dia_chi = (int)$conn->lastInsertId();
              if ($id_dia_chi <= 0) throw new Exception("Không lưu được địa chỉ.");

              // 2) LƯU don_hang (chú ý set id_ma_giam_gia NULL nếu không có)
              $coupon_id = $_SESSION['applied_coupon']['id'] ?? null;
              $discount   = $_SESSION['applied_coupon']['amount'] ?? 0;
              $ghi_chu_parts = [
                "payment={$payment_method}",
                "email={$email}",
                "coupon_id=" . ($coupon_id ? $coupon_id : 'null'),
                "coupon_amount=" . (float)$discount
              ];
              $ghi_chu = implode(';', $ghi_chu_parts);

              $stmt = $conn->prepare("
                  INSERT INTO don_hang
                  (ma_don, id_nguoi_dung, id_dia_chi, id_ma_giam_gia, trang_thai, tong_tien, phi_van_chuyen, ngay_dat, ghi_chu)
                  VALUES (?, ?, ?, ?, 'moi', ?, ?, NOW(), ?)
              ");

              $id_ma_giam_gia_val = $coupon_id ? $coupon_id : null;

              $stmt->execute([
                  $ma_don,
                  $user_id,
                  $id_dia_chi,
                  $id_ma_giam_gia_val, // sẽ truyền NULL nếu không có
                  0.00,                // tong_tien tạm thời 0 -> update sau
                  $shipping,
                  $ghi_chu
              ]);

              $order_id = (int)$conn->lastInsertId();
              if ($order_id <= 0) throw new Exception("Không tạo được đơn hàng.");

              // 3) LƯU chi_tiet_don_hang
              // Trước khi insert, kiểm tra sản phẩm tồn tại để tránh lỗi FK
              $stmtCheckProd = $conn->prepare("SELECT id_san_pham, gia, so_luong FROM san_pham WHERE id_san_pham = ?");
              $stmtInsertItem = $conn->prepare("
                  INSERT INTO chi_tiet_don_hang
                  (id_don_hang, id_san_pham, id_chi_tiet, so_luong, gia, thanh_tien)
                  VALUES (?, ?, ?, ?, ?, ?)
              ");

              $sum_items = 0.0;
              foreach ($cart as $it) {
                  $pid = (int)($it['product_id'] ?? 0);
                  if ($pid <= 0) continue;

                  // kiểm tra product tồn tại
                  $stmtCheckProd->execute([$pid]);
                  $prod = $stmtCheckProd->fetch(PDO::FETCH_ASSOC);
                  if (!$prod) {
                      throw new Exception("Sản phẩm (ID: $pid) không tồn tại hoặc đã bị xóa. Vui lòng kiểm tra giỏ hàng.");
                  }

                  $gia = (float)($it['price'] ?? $it['gia'] ?? $prod['gia'] ?? 0);
                  $sl  = (int)($it['qty'] ?? 1);
                  $thanh_tien = $gia * $sl;

                  $stmtInsertItem->execute([
                      $order_id,
                      $pid,
                      null, // id_chi_tiet (nếu bạn có bảng chi tiết option riêng, để NULL nếu không dùng)
                      $sl,
                      $gia,
                      $thanh_tien
                  ]);

                  $sum_items += $thanh_tien;
              }

              // 4) Cập nhật lại tổng tiền trong don_hang
              $new_total = max(0, $sum_items + $shipping - (float)$discount);
              $stmtUpdate = $conn->prepare("UPDATE don_hang SET tong_tien = ? WHERE id_don_hang = ?");
              $stmtUpdate->execute([$new_total, $order_id]);

              // 5) (tuỳ DB) ghi log trạng thái (nếu bảng don_hang_trang_thai_log có tồn tại)
              $tableCheck = $conn->query("SHOW TABLES LIKE 'don_hang_trang_thai_log'")->fetch();
              if ($tableCheck) {
                  $stmtLog = $conn->prepare("
                      INSERT INTO don_hang_trang_thai_log
                      (id_don_hang, trang_thai_cu, trang_thai_moi, ghi_chu, changed_by, created_at)
                      VALUES (?, ?, ?, ?, ?, NOW())
                  ");
                  $stmtLog->execute([$order_id, null, 'moi', 'Tạo đơn từ checkout', $user_id]);
              }

              $conn->commit();

              // xóa session cart và coupon
              unset($_SESSION['cart']);
              unset($_SESSION['applied_coupon']);

              $_SESSION['flash_order_success'] = "Đặt hàng thành công! Mã đơn: $ma_don";
              header("Location: orders.php");
              exit;

          } catch (Exception $e) {
              // rollback và show lỗi rõ cho dev
              if ($conn->inTransaction()) $conn->rollBack();
              // log server
              error_log("[ORDER-ERROR] " . $e->getMessage());
              $errors[] = "Lỗi hệ thống: " . $e->getMessage();
          }
      }
  }
}
/* ===== END XỬ LÝ POST ===== */




$provinces = [
  '' => 'Chọn tỉnh / thành',
  'Hanoi' => 'Hà Nội',
  'HCM' => 'TP. Hồ Chí Minh',
  'DaNang' => 'Đà Nẵng'
];
$wards = [
  '' => ['' => 'Chọn phường / xã'],
  'Hanoi' => ['H1' => 'Phường A', 'H2' => 'Phường B'],
  'HCM' => ['C1' => 'Phường 1', 'C2' => 'Phường 2'],
  'DaNang' => ['D1' => 'Phường X', 'D2' => 'Phường Y']
];
?>

<?php require_once __DIR__ . '/inc/header.php'; ?>

<!doctype html>
<html lang="vi">
<!-- PHẦN HTML + JS CỦA BẠN GIỮ NGUYÊN NHƯ TRONG FILE BẠN GỬI -->

<meta charset="utf-8">
<title>Thanh toán — AE Shop</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
/* --- Layout --- */
.page-wrapper{max-width:1200px;margin:30px auto;padding:0 16px}
.left-card, .right-card{background:#fff;border-radius:12px;padding:22px;box-shadow:0 6px 20px rgba(15,23,42,.06)}
.left-card { transition:transform .16s ease; }
.left-card:focus-within, .left-card:hover{ transform:translateY(-3px); }
.right-card { position:sticky; top:100px; }

/* --- Titles --- */
.h-title{font-weight:700;font-size:18px;margin-bottom:12px;color:#0b2f5a}

/* --- Form --- */
.form-label{font-weight:600;font-size:13px}
.input-icon{position:relative}
.input-icon i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9aa5b1}
.input-icon input{padding-left:38px}

/* --- Order list --- */
.product-row{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid #f1f3f5;align-items:center}
.product-thumb{width:64px;height:80px;border-radius:8px;overflow:hidden;border:1px solid #eee;flex-shrink:0}
.product-thumb img{width:100%;height:100%;object-fit:cover}
.product-meta{flex:1}
.product-name{font-weight:600}
.qty-badge{background:#eef2ff;color:#0b5cff;padding:4px 8px;border-radius:12px;font-weight:600;font-size:12px}

/* --- Summary box --- */
.summary-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0}
.summary-total{font-size:18px;font-weight:800;color:#c20000}

/* --- Coupon --- */
.coupon-box{display:flex;gap:8px}
.coupon-box input.form-control{border-top-right-radius:6px;border-bottom-right-radius:6px}
.coupon-box button{border-top-left-radius:6px;border-bottom-left-radius:6px;background:#f5f7fb;color:#0b2f5a;border:1px solid #e2e8f0}

/* --- Buttons --- */
.btn-place{background:#0b7bdc;border:none;padding:12px 16px;font-weight:700;border-radius:10px}
.btn-place[disabled]{opacity:.6}

/* --- Responsive --- */
@media (max-width:991px){
  .right-card{position:static;top:auto}
}
</style>
</head>
<body class="bg-light">

<div class="page-wrapper">

  <div class="row gx-4 gy-4">
    <!-- LEFT: form -->
    <div class="col-lg-7">
      <div class="left-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <div class="h-title">Thông tin giao hàng</div>
            <div class="text-muted small">Kiểm tra kỹ thông tin để giao hàng chính xác.</div>
          </div>
          <div class="text-end">
            <?php if (!empty($_SESSION['user'])): ?>
              <div class="small text-muted">Đăng nhập: <strong><?= esc($_SESSION['user']['email'] ?? $_SESSION['user']['ten'] ?? '') ?></strong></div>
            <?php else: ?>
              <a href="login.php" class="small">Đăng nhập</a>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <?php foreach($errors as $e) echo "<div><i class='bi bi-exclamation-triangle-fill'></i> " . esc($e) . "</div>"; ?>
          </div>
        <?php endif; ?>

        <form id="checkoutForm" method="post" novalidate>
          <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">

          <div class="mb-3">
            <label class="form-label">Họ và tên</label>
            <div class="input-icon">
              <i class="bi bi-person-fill"></i>
              <input name="name" id="name" class="form-control" placeholder="Nguyễn Văn A" required>
            </div>
            <div class="invalid-feedback">Vui lòng nhập họ tên.</div>
          </div>

          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Số điện thoại</label>
              <div class="input-icon">
                <i class="bi bi-telephone-fill"></i>
                <input name="phone" id="phone" class="form-control" placeholder="+84 912 345 678" required>
              </div>
              <div class="invalid-feedback">Số điện thoại không hợp lệ.</div>
            </div>

            <div class="col-md-6 mb-3">
              <label class="form-label">Email (không bắt buộc)</label>
              <div class="input-icon">
                <i class="bi bi-envelope-fill"></i>
                <input name="email" id="email" class="form-control" placeholder="you@example.com">
              </div>
              <div class="invalid-feedback">Email không đúng định dạng.</div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Số nhà, tên đường</label>
            <input name="address_street" id="address_street" class="form-control" placeholder="Số nhà, tên đường" required>
            <div class="invalid-feedback">Vui lòng nhập địa chỉ cụ thể.</div>
          </div>

          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Tỉnh / Thành</label>
              <select id="province" name="address_city" class="form-select" required>
                <?php foreach($provinces as $k=>$v): ?>
                  <option value="<?= esc($k) ?>"><?= esc($v) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback">Chọn tỉnh / thành.</div>
            </div>

            <div class="col-md-6 mb-3">
              <label class="form-label">Phường / Xã</label>
              <select id="ward" name="address_ward" class="form-select" required>
                <option value="">Chọn phường / xã</option>
              </select>
              <div class="invalid-feedback">Chọn phường / xã.</div>
            </div>
          </div>

          <hr>

          <div class="mb-3">
            <div class="h-title">Phương thức vận chuyển</div>
            <div class="border rounded p-3 text-center text-muted">
              <i class="bi bi-truck" style="font-size:28px;"></i>
              <div class="mt-2">Hệ thống sẽ hiển thị phương thức vận chuyển khi bạn chọn Tỉnh / Thành.</div>
            </div>
          </div>

          <hr>

          <div class="mb-3">
            <div class="h-title">Phương thức thanh toán</div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" name="payment_method" id="pay_cod" value="cod" checked>
              <label class="form-check-label" for="pay_cod"><strong>Thanh toán khi nhận hàng (COD)</strong></label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="payment_method" id="pay_bank" value="bank">
              <label class="form-check-label" for="pay_bank">Chuyển khoản ngân hàng</label>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center mt-3">
            <a href="cart.php" class="text-muted"><i class="bi bi-arrow-left"></i> Quay lại giỏ hàng</a>
            <button id="placeOrderBtn" type="submit" class="btn btn-place" disabled>
              <i class="bi bi-bag-check-fill me-2"></i>Hoàn tất đơn hàng
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- RIGHT: order summary -->
    <div class="col-lg-5">
      <div class="right-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="h-title">Đơn hàng</div>
          <div class="small text-muted"><?= count($cart) ?> sản phẩm</div>
        </div>

        <div>
          <?php if (empty($cart)): ?>
            <div class="text-center text-muted py-4">
              <i class="bi bi-cart-x" style="font-size:36px"></i>
              <div class="mt-2">Giỏ hàng đang rỗng</div>
            </div>
          <?php endif; ?>

          <?php foreach($cart as $it): ?>
            <div class="product-row">
              <div class="product-thumb">
              <img src="<?= esc(getProductImage($conn, $it['product_id'] ?? 0)) ?>" alt="">

                
              </div>
              <div class="product-meta">
                <div class="product-name"><?= esc($it['name'] ?? 'Sản phẩm') ?></div>
                <div class="text-muted small"><?= esc($it['variant'] ?? ($it['size'] ?? '')) ?></div>
              </div>
              <div class="text-end">
                <div class="qty-badge">x<?= (int)($it['qty'] ?? 1) ?></div>
                <div class="mt-1 fw-bold"><?= price(($it['price'] ?? $it['gia']) * ($it['qty'] ?? 1)) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="mt-3">
          <div class="coupon-box mb-3">
            <input id="couponInput" class="form-control" placeholder="Mã giảm giá (nếu có)">
            <button id="applyCouponBtn" class="btn">Áp dụng</button>
          </div>

          <div class="summary-row">
            <div class="text-muted">Tạm tính</div>
            <div class="fw-semibold"><?= price($subtotal) ?></div>
          </div>

          <div class="summary-row">
            <div class="text-muted">Phí vận chuyển</div>
            <div class="fw-semibold"><?= $shipping ? price($shipping) : '<span class="text-success">Miễn phí</span>' ?></div>
          </div>

          <div class="summary-row">
            <div class="text-muted">Giảm giá</div>
            <div class="fw-semibold text-success"><?= $discount ? '-' . price($discount) : '-' . price(0) ?></div>
          </div>

          <hr>

          <div class="d-flex justify-content-between align-items-center">
            <div class="small text-muted">Tổng cộng</div>
            <div class="summary-total"><?= price($total) ?></div>
          </div>

          <div class="mt-3">
            <small class="text-muted">Bằng cách đặt hàng, bạn đồng ý với <a href="terms.php">Điều khoản & Điều kiện</a>.</small>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS + tiny script -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
  // simple client-side validation + enable submit when basic fields valid
  const form = document.getElementById('checkoutForm');
  const btn = document.getElementById('placeOrderBtn');

  const nameEl = document.getElementById('name');
  const phoneEl = document.getElementById('phone');
  const streetEl = document.getElementById('address_street');
  const province = document.getElementById('province');
  const ward = document.getElementById('ward');

  function validPhone(v){
    return /^\+?\d{9,15}$/.test(v.replace(/\s+/g,''));
  }

  function checkForm(){
    const ok = nameEl.value.trim().length > 1
      && validPhone(phoneEl.value.trim())
      && streetEl.value.trim().length > 3
      && province.value
      && ward.value;
    btn.disabled = !ok;
    return ok;
  }

  [nameEl, phoneEl, streetEl, province, ward].forEach(el=>{
    el && el.addEventListener('input', ()=> {
      if (el.checkValidity && el.checkValidity()===false) {
        el.classList.add('is-invalid');
      } else {
        el.classList.remove('is-invalid');
      }
      checkForm();
    });
  });

  // Populate wards based on province selection (demo data from PHP)
  const wardsData = <?= json_encode($wards) ?>;

  // On province change populate ward list
  province.addEventListener('change', function(){
    const key = this.value;
    const opts = wardsData[key] || {'':'Chọn phường / xã'};
    ward.innerHTML = '';
    Object.keys(opts).forEach(k=>{
      const o = document.createElement('option'); o.value = k; o.textContent = opts[k];
      ward.appendChild(o);
    });
    checkForm();
  });

  // coupon apply (demo): call backend via fetch to apply coupon (not implemented server-side here)
  document.getElementById('applyCouponBtn').addEventListener('click', function(e){
    e.preventDefault();
    const code = document.getElementById('couponInput').value.trim();
    if(!code) {
      alert('Nhập mã giảm giá trước khi áp dụng');
      return;
    }
    // Demo: fake success if code == "AE10"
    if (code.toUpperCase() === 'AE10') {
      alert('Áp dụng mã thành công: -10% (demo)');
      // In real app: send fetch to server -> update session -> refresh or update DOM
      location.reload();
    } else {
      alert('Mã không hợp lệ hoặc đã hết hạn (demo)');
    }
  });

  // initial check
  checkForm();

  // prevent submit if JS validation fails
  form.addEventListener('submit', function(e){
    if(!checkForm()){
      e.preventDefault();
      e.stopPropagation();
      alert('Vui lòng kiểm tra lại thông tin giao hàng.');
    }
  });
})();
</script>

</body>
</html>
