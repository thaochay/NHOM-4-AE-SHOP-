<?php
// admin/order_view.php
// Xem chi tiết 1 đơn hàng (admin) — xem thông tin KH, địa chỉ, sản phẩm; thay đổi trạng thái, duyệt, xóa

require_once __DIR__ . '/inc/header.php';
/** @var PDO $conn */

if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}
if (!function_exists('flash')) {
    function flash($k,$m){ $_SESSION['flash_admin_'.$k]=$m; }
}
if (!function_exists('flash_get')) {
    function flash_get($k){ $kk='flash_admin_'.$k; $v=$_SESSION[$kk]??null; if($v) unset($_SESSION[$kk]); return $v; }
}

if (!isset($_SESSION['csrf_admin'])) $_SESSION['csrf_admin']=bin2hex(random_bytes(16));

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: orders.php'); exit;
}

/* Handle actions from this page (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf'] ?? '')) {
        flash('error','CSRF token không hợp lệ.');
        header('Location: order_view.php?id='.$id); exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'change_status') {
        $new = trim($_POST['status'] ?? '');
        if ($new === '') { flash('error','Trạng thái không hợp lệ'); header('Location: order_view.php?id='.$id); exit; }
        $u = $conn->prepare("UPDATE don_hang SET trang_thai = :st WHERE id_don_hang = :id");
        $u->execute([':st'=>$new, ':id'=>$id]);
        flash('success','Đã cập nhật trạng thái.');
        header('Location: order_view.php?id='.$id); exit;
    }

    if ($action === 'approve') {
        $u = $conn->prepare("UPDATE don_hang SET trang_thai = 'duyet' WHERE id_don_hang = :id");
        $u->execute([':id'=>$id]);
        flash('success','Đơn đã được duyệt.');
        header('Location: order_view.php?id='.$id); exit;
    }

    if ($action === 'delete') {
        try {
            $conn->beginTransaction();
            $d1 = $conn->prepare("DELETE FROM don_hang_chi_tiet WHERE id_don_hang = :id"); $d1->execute([':id'=>$id]);
            $d2 = $conn->prepare("DELETE FROM don_hang WHERE id_don_hang = :id"); $d2->execute([':id'=>$id]);
            $conn->commit();
            flash('success','Đã xóa đơn #' . $id);
            header('Location: orders.php'); exit;
        } catch (Exception $e) {
            $conn->rollBack();
            flash('error','Không thể xóa: '.$e->getMessage());
            header('Location: order_view.php?id='.$id); exit;
        }
    }
}

/* Fetch order + joins */
try {
    $q = "SELECT dh.*, u.ten AS kh_ten, u.email AS kh_email, u.dien_thoai AS kh_dienthoai,
                 mg.ma_code AS coupon_code, ad.* 
          FROM don_hang dh
          LEFT JOIN nguoi_dung u ON dh.id_nguoi_dung = u.id_nguoi_dung
          LEFT JOIN ma_giam_gia mg ON dh.id_ma_giam_gia = mg.id_ma_giam_gia
          LEFT JOIN dia_chi ad ON dh.id_dia_chi = ad.id_dia_chi
          WHERE dh.id_don_hang = :id LIMIT 1";
    $s = $conn->prepare($q); $s->execute([':id'=>$id]); $order = $s->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $order = null; }

if (!$order) { flash('error','Không tìm thấy đơn hàng'); header('Location: orders.php'); exit; }

/* Items */
try {
    $its = $conn->prepare("SELECT dhct.*, sp.ten AS ten_sp, sp.ma_san_pham FROM don_hang_chi_tiet dhct LEFT JOIN san_pham sp ON dhct.id_san_pham=sp.id_san_pham WHERE dhct.id_don_hang=:id");
    $its->execute([':id'=>$id]); $items = $its->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $items = []; }

$fs = flash_get('success'); $fe = flash_get('error');
?><!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Chi tiết đơn #<?= (int)$id ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style> body{background:#f6f8fb;font-family:Inter,system-ui,Roboto,Arial} .card{border-radius:12px} .small-muted{color:#6c757d} </style>
</head>
<body>
<?php if (file_exists(__DIR__.'/inc/topbar.php')) require_once __DIR__.'/inc/topbar.php'; ?>

<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4>Chi tiết đơn #<?= (int)$order['id_don_hang'] ?></h4>
    <div class="d-flex gap-2">
      <a href="orders.php" class="btn btn-outline-secondary btn-sm">← Quay lại</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Duyệt đơn này?');">
        <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
        <input type="hidden" name="action" value="approve">
        <button class="btn btn-sm btn-success">Duyệt</button>
      </form>
      <form method="post" style="display:inline" onsubmit="return confirm('Xóa đơn này?');">
        <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="btn btn-sm btn-danger">Xóa</button>
      </form>
    </div>
  </div>

  <?php if ($fs): ?><div class="alert alert-success"><?= esc($fs) ?></div><?php endif; ?>
  <?php if ($fe): ?><div class="alert alert-danger"><?= esc($fe) ?></div><?php endif; ?>

  <div class="card p-3 mb-3">
    <div class="row">
      <div class="col-md-6">
        <h6>Khách hàng</h6>
        <div class="fw-semibold"><?= esc($order['kh_ten'] ?? '') ?></div>
        <div class="small-muted"><?= esc($order['kh_email'] ?? '') ?> • <?= esc($order['kh_dienthoai'] ?? '') ?></div>
        <div class="mt-2">Mã đơn: <strong><?= esc($order['ma_don'] ?? '') ?></strong></div>
      </div>
      <div class="col-md-6 text-end">
        <div class="small-muted">Trạng thái</div>
        <div class="h5"><?= esc($order['trang_thai'] ?? '') ?></div>
        <div class="small-muted mt-2">Ngày đặt: <?= esc($order['ngay_dat'] ?? '') ?></div>
        <div class="h4 mt-2"><?= price($order['tong_tien'] ?? 0) ?></div>
      </div>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <h6>Sản phẩm</h6>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead><tr><th>#</th><th>Tên</th><th>SKU</th><th>Số lượng</th><th>Giá</th><th>Thành tiền</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= (int)($it['id_dhct'] ?? 0) ?></td>
              <td><?= esc($it['ten_sp'] ?? 'Sản phẩm #' . (int)$it['id_san_pham']) ?></td>
              <td class="small-muted"><?= esc($it['ma_san_pham'] ?? '') ?></td>
              <td><?= (int)($it['so_luong'] ?? 0) ?></td>
              <td><?= number_format((float)($it['gia'] ?? 0),0,',','.') ?> ₫</td>
              <td><?= number_format(((float)($it['gia'] ?? 0) * (int)($it['so_luong'] ?? 0)),0,',','.') ?> ₫</td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($items)): ?><tr><td colspan="6" class="text-center text-muted">Không có sản phẩm.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card p-3 mb-3">
    <div class="row">
      <div class="col-md-6">
        <h6>Địa chỉ giao hàng</h6>
        <?php if (!empty($order['id_dia_chi'])): ?>
          <div><?= esc($order['dia_chi'] ?? ($order['address'] ?? '')) ?></div>
          <div class="small-muted"><?= esc($order['ten'] ?? $order['kh_ten'] ?? '') ?> • <?= esc($order['kh_dienthoai'] ?? '') ?></div>
        <?php else: ?>
          <div class="small-muted">Không có địa chỉ lưu.</div>
        <?php endif; ?>
      </div>
      <div class="col-md-6 text-end">
        <div class="small-muted">Phí vận chuyển: <?= price($order['phi_van_chuyen'] ?? 0) ?></div>
        <div class="small-muted">Mã giảm giá: <?= esc($order['coupon_code'] ?? '-') ?></div>
        <div class="fw-semibold mt-2">Tổng: <?= price($order['tong_tien'] ?? 0) ?></div>
      </div>
    </div>
  </div>

  <div class="card p-3">
    <h6>Ghi chú</h6>
    <div class="small-muted"><?= nl2br(esc($order['ghi_chu'] ?? '')) ?></div>

    <hr>
    <form method="post" class="row g-2" onsubmit="return confirm('Cập nhật trạng thái?');">
      <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
      <input type="hidden" name="action" value="change_status">
      <div class="col-md-4">
        <select name="status" class="form-select">
          <option value="moi">moi</option>
          <option value="chuaxuly">chuaxuly</option>
          <option value="dangvanchuyen">dangvanchuyen</option>
          <option value="hoanthanh">hoanthanh</option>
          <option value="huy">huy</option>
          <option value="duyet">duyet</option>
        </select>
      </div>
      <div class="col-md-8 text-end">
        <button class="btn btn-primary">Cập nhật</button>
      </div>
    </form>
  </div>

</div>
</body>
</html>
