<?php
// checkout.php - thanh toán (tương thích don_hang + chi_tiet_don_hang)
// Sử dụng: ghi đè file hiện có bằng file này
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$cart = $_SESSION['cart'] ?? [];
if (!is_array($cart)) $cart = [];

/* helpers fallback */  
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/* TÍNH TỔNG */
$subtotal = 0.0;
foreach ($cart as $it) {
    $price = isset($it['price']) ? (float)$it['price'] : (float)($it['gia'] ?? 0);
    $qty   = isset($it['qty']) ? (int)$it['qty'] : 1;
    $subtotal += $price * $qty;
}
$shipping = ($subtotal >= 1000000 || $subtotal == 0) ? 0.0 : 30000.0;
$discount = 0.0;
$total = $subtotal + $shipping - $discount;

/* state */
$errors = [];
$success = false;
$order_id = null;
$order_code = null;

/* detect logged-in user id (common keys) */
$user_id = null;
if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
    $u = $_SESSION['user'];
    if (isset($u['id_nguoi_dung'])) $user_id = (int)$u['id_nguoi_dung'];
    elseif (isset($u['id'])) $user_id = (int)$u['id'];
    elseif (isset($u['user_id'])) $user_id = (int)$u['user_id'];
}

/* POST xử lý đặt hàng */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = "Lỗi bảo mật (CSRF). Vui lòng thử lại.";
    }

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $payment = $_POST['payment_method'] ?? 'cod';

    if ($name === '') $errors[] = "Vui lòng nhập họ tên";
    if ($phone === '') $errors[] = "Vui lòng nhập số điện thoại";
    if ($address === '') $errors[] = "Vui lòng nhập địa chỉ giao hàng";
    if (empty($cart)) $errors[] = "Giỏ hàng trống";

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // tạo mã đơn (unique)
            $order_code = 'DH' . date('YmdHis') . random_int(100, 999);

            // ghi_chu lưu JSON thông tin khách
            $note = [
                'khach_hang' => [
                    'ten' => $name,
                    'dien_thoai' => $phone,
                    'email' => $email,
                    'dia_chi' => $address,
                    'phuong_thuc' => $payment
                ],
                'meta' => [
                    'created_at' => date('c'),
                    'user_id_session' => $user_id
                ]
            ];
            $ghi_chu = json_encode($note, JSON_UNESCAPED_UNICODE);

            // INSERT vào don_hang theo schema bạn cung cấp
            $stmt = $conn->prepare("
                INSERT INTO don_hang
                (ma_don, id_nguoi_dung, id_dia_chi, id_ma_giam_gia, trang_thai, tong_tien, phi_van_chuyen, ngay_dat, ghi_chu)
                VALUES
                (:ma_don, :id_nguoi_dung, NULL, NULL, :trang_thai, :tong_tien, :phi_vc, NOW(), :ghi_chu)
            ");

            $stmt->execute([
                ':ma_don' => $order_code,
                ':id_nguoi_dung' => $user_id ?: null,
                ':trang_thai' => 'moi',
                ':tong_tien' => $total,
                ':phi_vc' => $shipping,
                ':ghi_chu' => $ghi_chu
            ]);

            $order_id = $conn->lastInsertId();

            // Chuẩn bị INSERT vào chi_tiet_don_hang (theo schema bạn gửi)
            $stmtItem = $conn->prepare("
                INSERT INTO chi_tiet_don_hang (id_don_hang, id_san_pham, id_chi_tiet, so_luong, gia, thanh_tien)
                VALUES (:id_don_hang, :id_san_pham, :id_chi_tiet, :so_luong, :gia, :thanh_tien)
            ");

            foreach ($cart as $id => $it) {
                $pid = isset($it['id']) ? (int)$it['id'] : (int)$id;
                $price = isset($it['price']) ? (float)$it['price'] : (float)($it['gia'] ?? 0);
                $qty = isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1);
                $thanh_tien = $price * $qty;

                // id_chi_tiet optional (null) - nếu bạn có variants có thể map vào $it['id_chi_tiet']
                $id_chi_tiet = $it['id_chi_tiet'] ?? null;

                $stmtItem->execute([
                    ':id_don_hang' => $order_id,
                    ':id_san_pham' => $pid,
                    ':id_chi_tiet' => $id_chi_tiet,
                    ':so_luong' => $qty,
                    ':gia' => $price,
                    ':thanh_tien' => $thanh_tien
                ]);
            }

            $conn->commit();

            // clear cart
            unset($_SESSION['cart']);

            // set flash và redirect về trang orders.php để người dùng xem đơn
            $_SESSION['flash_success'] = "Đặt hàng thành công! Mã đơn: {$order_code}";
            // chuyển sang orders.php?id=...
            header('Location: orders.php?id=' . urlencode($order_id));
            exit;

        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = "Lỗi hệ thống: " . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Thanh toán | <?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container my-5">
  <a href="cart.php" class="btn btn-link">&larr; Quay lại giỏ hàng</a>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $er): ?>
          <li><?= esc($er) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-md-7">
      <div class="card p-4">
        <h4>Thông tin thanh toán</h4>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">

          <div class="mb-3">
            <label>Họ và tên</label>
            <input name="name" class="form-control" required value="<?= esc($_POST['name'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label>Số điện thoại</label>
            <input name="phone" class="form-control" required value="<?= esc($_POST['phone'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label>Email (tuỳ chọn)</label>
            <input name="email" class="form-control" value="<?= esc($_POST['email'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label>Địa chỉ giao hàng</label>
            <textarea name="address" class="form-control" rows="3" required><?= esc($_POST['address'] ?? '') ?></textarea>
          </div>

          <div class="mb-3">
            <label>Phương thức thanh toán</label>
            <select name="payment_method" class="form-select">
              <option value="cod" <?= (($_POST['payment_method'] ?? '') === 'cod') ? 'selected' : '' ?>>Thanh toán khi nhận hàng (COD)</option>
              <option value="bank" <?= (($_POST['payment_method'] ?? '') === 'bank') ? 'selected' : '' ?>>Chuyển khoản</option>
              <option value="momo" <?= (($_POST['payment_method'] ?? '') === 'momo') ? 'selected' : '' ?>>Ví MoMo</option>
            </select>
          </div>

          <button class="btn btn-primary w-100">Đặt hàng</button>
        </form>
      </div>
    </div>

    <div class="col-md-5">
      <div class="card p-4">
        <h4>Đơn hàng</h4>
        <ul class="list-group mb-3">
          <?php foreach ($cart as $item):
              $nameItem = $item['name'] ?? $item['ten'] ?? 'Sản phẩm';
              $priceItem = isset($item['price']) ? (float)$item['price'] : (float)($item['gia'] ?? 0);
              $qtyItem = isset($item['qty']) ? (int)$item['qty'] : (isset($item['sl']) ? (int)$item['sl'] : 1);
          ?>
            <li class="list-group-item d-flex justify-content-between">
              <div><?= esc($nameItem) ?> <small class="text-muted">x<?= $qtyItem ?></small></div>
              <div><?= price($priceItem * $qtyItem) ?></div>
            </li>
          <?php endforeach; ?>
        </ul>

        <div class="d-flex justify-content-between">
          <span>Tạm tính:</span>
          <span><?= price($subtotal) ?></span>
        </div>

        <div class="d-flex justify-content-between">
          <span>Vận chuyển:</span>
          <span><?= $shipping == 0 ? 'Miễn phí' : price($shipping) ?></span>
        </div>

        <hr>
        <div class="d-flex justify-content-between fw-bold fs-5">
          <span>Tổng:</span>
          <span><?= price($total) ?></span>
        </div>

      </div>
    </div>
  </div>

</div>
</body>
</html>
