<?php
// orders.php - Danh sách đơn hàng của người dùng (đã sửa lỗi cú pháp)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// Basic helper
function e($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// ensure user logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php?back=" . urlencode(basename(__FILE__) . '?' . $_SERVER['QUERY_STRING']));
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['id_nguoi_dung'] ?? $user['id'] ?? $user['user_id'] ?? 0;

// ensure CSRF token
if (!isset($_SESSION['csrf'])) {
    try {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
    }
}

/* status badge mapping (reuse same logic as order_view) */
function status_badge($s){
    $s = strtolower((string)$s);
    $map = [
        'moi'=>'Chờ xử lý', 'new'=>'Chờ xử lý', 'processing'=>'Đang xử lý', 'dang_xu_ly'=>'Đang xử lý',
        'shipped'=>'Đã giao', 'delivered'=>'Đã giao', 'completed'=>'Hoàn tất',
        'cancel'=>'Đã huỷ', 'huy'=>'Đã huỷ', 'paid'=>'Đã thanh toán', 'da_thanh_toan'=>'Đã thanh toán'
    ];
    $label = $map[$s] ?? ucfirst($s);
    $cls = 'secondary';
    if (in_array($s, ['moi','new','processing','dang_xu_ly'])) $cls = 'warning';
    if (in_array($s, ['shipped','delivered','completed','paid','da_thanh_toan'])) $cls = 'success';
    if (in_array($s, ['cancel','huy'])) $cls = 'danger';
    return "<span class=\"badge bg-{$cls}\">" . e($label) . "</span>";
}

/* Handle POST actions (cancel order) */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        $flash = ['type'=>'danger','text'=>'Xác thực không hợp lệ (CSRF).'];
    } else {
        if ($action === 'cancel' && isset($_POST['order_id'])) {
            $oid = (int)$_POST['order_id'];
            if ($oid > 0) {
                // check current status
                $s = $conn->prepare("SELECT trang_thai FROM don_hang WHERE id_don_hang = :id AND id_nguoi_dung = :uid LIMIT 1");
                $s->execute([':id'=>$oid, ':uid'=>$user_id]);
                $cur = $s->fetchColumn();
                if (!$cur) {
                    $flash = ['type'=>'danger','text'=>'Không tìm thấy đơn hàng hoặc bạn không có quyền.'];
                } else {
                    $cur_l = strtolower((string)$cur);
                    if (in_array($cur_l, ['cancel','huy','completed','delivered','paid','da_thanh_toan'])) {
                        $flash = ['type'=>'warning','text'=>'Đơn hàng không thể huỷ (đã hoàn tất/đã huỷ/đã thanh toán).'];
                    } else {
                        // Try to update with 'ngay_cap_nhat' if column exists; otherwise fallback to update only trang_thai
                        try {
                            $u = $conn->prepare("UPDATE don_hang SET trang_thai = 'cancel', ngay_cap_nhat = NOW() WHERE id_don_hang = :id AND id_nguoi_dung = :uid");
                            $u->execute([':id'=>$oid, ':uid'=>$user_id]);
                            $flash = ['type'=>'success','text'=>'Đã huỷ đơn hàng #' . e($oid) . '.'];
                        } catch (PDOException $ex) {
                            // fallback if column 'ngay_cap_nhat' không tồn tại
                            try {
                                $u2 = $conn->prepare("UPDATE don_hang SET trang_thai = 'cancel' WHERE id_don_hang = :id AND id_nguoi_dung = :uid");
                                $u2->execute([':id'=>$oid, ':uid'=>$user_id]);
                                $flash = ['type'=>'success','text'=>'Đã huỷ đơn hàng #' . e($oid) . '.'];
                            } catch (PDOException $ex2) {
                                // nếu vẫn lỗi, báo lỗi chi tiết cho dev (không show raw error cho user)
                                error_log("orders.php - cancel update failed: " . $ex2->getMessage());
                                $flash = ['type'=>'danger','text'=>'Không thể huỷ đơn lúc này. Vui lòng thử lại hoặc liên hệ hỗ trợ.'];
                            }
                        }
                    }
                }
            } else {
                $flash = ['type'=>'danger','text'=>'ID đơn không hợp lệ.'];
            }
        } else {
            $flash = ['type'=>'danger','text'=>'Hành động không hợp lệ.'];
        }
    }
    // set session flash so it persists after redirect (optional)
    $_SESSION['flash_message'] = $flash;
    header('Location: ' . basename(__FILE__));
    exit;
}

// Read flash if available in session
if (isset($_SESSION['flash_message'])) {
    $flash = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Query params: page, status, q
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$allowed_status = ['all','moi','processing','shipped','completed','cancel','paid'];
$filter_status = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($filter_status, $allowed_status)) $filter_status = 'all';

$search_q = trim((string)($_GET['q'] ?? ''));

// Build WHERE and params
$where = " WHERE dh.id_nguoi_dung = :uid ";
$params = [':uid' => $user_id];

if ($filter_status !== 'all') {
    $where .= " AND LOWER(COALESCE(dh.trang_thai, '')) = :status ";
    $params[':status'] = $filter_status;
}

if ($search_q !== '') {
    // search by order code or phone or email
    $where .= " AND (dh.ma_don LIKE :q OR dh.so_dien_thoai LIKE :q OR dh.email LIKE :q) ";
    $params[':q'] = '%' . str_replace('%','\\%',$search_q) . '%';
}

// count total
$countSt = $conn->prepare("SELECT COUNT(*) FROM don_hang dh " . $where);
$countSt->execute($params);
$totalRows = (int)$countSt->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);

// fetch rows
$sql = "SELECT dh.* FROM don_hang dh " . $where . " ORDER BY dh.ngay_dat DESC LIMIT :limit OFFSET :offset";
$stmt = $conn->prepare($sql);
// bind params
foreach ($params as $k=>$v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// small helper to format money
function fm($n){ return number_format((float)$n,0,',','.').' ₫'; }

?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Đơn hàng của tôi — <?= e(function_exists('site_name') ? site_name($conn) : 'Shop') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{ background:#f5f8fb; padding:20px; font-family:system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
    .page { max-width:1100px; margin:0 auto; }
    .card-order { border-radius:12px; background:#fff; padding:14px; box-shadow:0 12px 30px rgba(11,38,80,0.04); }
    .small-muted { color:#6c757d; font-size:13px; }
  </style>
</head>
<body>
<div class="page">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Đơn hàng của tôi</h4>
    <div>
      <a href="index.php" class="btn btn-link">Trang chủ</a>
      <a href="cart.php" class="btn btn-outline-secondary">Giỏ hàng</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] ?? 'info') ?>"><?= e($flash['text'] ?? '') ?></div>
  <?php endif; ?>

  <div class="card card-order mb-3">
    <form class="row g-2 align-items-center" method="get" action="orders.php">
      <div class="col-auto">
        <label class="visually-hidden">Trạng thái</label>
        <select name="status" class="form-select">
          <option value="all" <?= $filter_status==='all' ? 'selected':'' ?>>Tất cả trạng thái</option>
          <option value="moi" <?= $filter_status==='moi' ? 'selected':'' ?>>Chờ xử lý</option>
          <option value="processing" <?= $filter_status==='processing' ? 'selected':'' ?>>Đang xử lý</option>
          <option value="shipped" <?= $filter_status==='shipped' ? 'selected':'' ?>>Đang giao</option>
          <option value="completed" <?= $filter_status==='completed' ? 'selected':'' ?>>Hoàn tất</option>
          <option value="paid" <?= $filter_status==='paid' ? 'selected':'' ?>>Đã thanh toán</option>
          <option value="cancel" <?= $filter_status==='cancel' ? 'selected':'' ?>>Đã huỷ</option>
        </select>
      </div>
      <div class="col">
        <input type="text" name="q" value="<?= e($search_q) ?>" class="form-control" placeholder="Tìm theo mã đơn, email, số điện thoại...">
      </div>
      <div class="col-auto">
        <button class="btn btn-primary">Lọc</button>
      </div>
    </form>
  </div>

  <?php if (empty($orders)): ?>
    <div class="card card-order text-center p-5">
      <div class="mb-3"><strong>Bạn chưa có đơn hàng nào.</strong></div>
      <a href="index.php" class="btn btn-primary">Mua sắm ngay</a>
    </div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach($orders as $od): 
        $oid = (int)$od['id_don_hang'];
        $code = $od['ma_don'] ?? $oid;
        $created = $od['ngay_dat'] ?? $od['created_at'] ?? '';
        $total = $od['tong_tien'] ?? $od['tong'] ?? 0;
        $status = $od['trang_thai'] ?? '';
      ?>
      <div class="col-12">
        <div class="card card-order d-flex flex-row align-items-center gap-3">
          <div style="min-width:120px">
            <div class="small-muted">Mã đơn</div>
            <div class="fw-bold">#<?= e($code) ?></div>
            <div class="small-muted"><?= e($created) ?></div>
          </div>

          <div class="flex-grow-1">
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <div class="small-muted">Tổng tiền</div>
                <div class="fw-bold"><?= fm($total) ?></div>
              </div>
              <div class="text-end">
                <div class="small-muted">Trạng thái</div>
                <div><?= status_badge($status) ?></div>
              </div>
            </div>
            <div class="small-muted mt-2">Phương thức: <?= e($od['phuong_thuc_thanh_toan'] ?? $od['phuong_thuc'] ?? 'COD') ?></div>
          </div>

          <div style="min-width:200px; text-align:right">
            <a href="order_view.php?id=<?= $oid ?>" class="btn btn-outline-secondary btn-sm mb-2">Xem chi tiết</a>
            <!-- Reorder: small form -->
            <form method="post" action="checkout.php" style="display:inline-block">
              <input type="hidden" name="action" value="reorder">
              <input type="hidden" name="order_id" value="<?= $oid ?>">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf']) ?>">
              <button class="btn btn-outline-primary btn-sm mb-2" type="submit">Mua lại</button>
            </form>

            <!-- Cancel: only show if order can be cancelled -->
            <?php 
              $lower = strtolower((string)$status);
              if (!in_array($lower, ['cancel','huy','completed','delivered','paid','da_thanh_toan'])): 
            ?>
              <form method="post" action="orders.php" style="display:inline-block" onsubmit="return confirm('Bạn có chắc muốn huỷ đơn #' + <?= json_encode($code) ?> + ' ?');">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="order_id" value="<?= $oid ?>">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf']) ?>">
                <button class="btn btn-danger btn-sm mb-2" type="submit">Huỷ đơn</button>
              </form>
            <?php else: ?>
              <div class="small-muted mt-2">Không thể huỷ</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <nav class="mt-4" aria-label="Orders pagination">
        <ul class="pagination justify-content-center">
          <?php
            $qs = $_GET;
            for ($p=1;$p<=$totalPages;$p++):
              $qs['page']=$p;
              $link = basename(__FILE__) . '?' . http_build_query($qs);
          ?>
            <li class="page-item <?= $p===$page ? 'active':'' ?>"><a class="page-link" href="<?= e($link) ?>"><?= $p ?></a></li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>

  <?php endif; ?>

</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
