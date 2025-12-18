<?php
// admin/coupons.php
// Quản lý mã giảm giá - resilient to different DB column names (auto-mapping)

// header must provide $conn (PDO) and admin guard
require_once __DIR__ . '/inc/header.php';
/** @var PDO $conn */

// safe helpers
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('flash')) {
    function flash($k,$m){ $_SESSION['flash_admin_'.$k] = $m; }
}
if (!function_exists('flash_get_once')) {
    function flash_get_once($k) {
        $kk = 'flash_admin_'.$k; $v = $_SESSION[$kk] ?? null; if ($v) unset($_SESSION[$kk]); return $v;
    }
}
if (!isset($_SESSION['csrf_admin'])) $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));

/* ---------- utility: detect existing column name from candidate list ---------- */
function detect_column(PDO $conn, array $candidates) {
    // get columns once and cache in session for performance (per request)
    static $cols;
    if ($cols === null) {
        $cols = [];
        try {
            $stm = $conn->query("SHOW COLUMNS FROM ma_giam_gia");
            $cols = $stm->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!is_array($cols)) $cols = [];
        } catch (Exception $e) {
            $cols = [];
        }
    }
    foreach ($candidates as $cand) {
        foreach ($cols as $c) {
            if (strcasecmp($c, $cand) === 0) return $c;
        }
    }
    return null;
}

/* ---------- build mapping of logical fields -> actual DB columns ---------- */
$map = [
    'code'       => detect_column($conn, ['code','ma','coupon','coupon_code','ma_code','ma_giam']),
    'type'       => detect_column($conn, ['type','loai','kind']),
    'gia_tri'    => detect_column($conn, ['gia_tri','value','amount','gia','discount_value']),
    'min_order'  => detect_column($conn, ['min_order','min_total','min_amount']),
    'usage_limit'=> detect_column($conn, ['usage_limit','luot_dung','limit','usage']),
    'expiry'     => detect_column($conn, ['expiry_date','het_han','expires_at','expiry']),
    'trang_thai' => detect_column($conn, ['trang_thai','active','is_active','status']),
    'created_at' => detect_column($conn, ['created_at','created','ngay_tao']),
    'updated_at' => detect_column($conn, ['updated_at','updated','ngay_cap_nhat']),
    'id'         => detect_column($conn, ['id_ma','id','ma_id','id_giam_gia'])
];

// fallback: if table doesn't have an id-like column, we'll use id_ma or id
if (!$map['id']) {
    $map['id'] = detect_column($conn, ['id','id_ma']);
}

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf'] ?? '')) {
        flash('error','CSRF token không hợp lệ.');
        header('Location: coupons.php'); exit;
    }

    // SAVE (create / update)
    if ($action === 'save') {
        $id = max(0, (int)($_POST['id'] ?? 0));
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $type = ($_POST['type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
        $value = (float)($_POST['value'] ?? 0);
        $min_order = trim($_POST['min_order'] ?? '') === '' ? null : (float)$_POST['min_order'];
        $usage_limit = trim($_POST['usage_limit'] ?? '') === '' ? null : (int)$_POST['usage_limit'];
        $expiry = trim($_POST['expiry'] ?? '') === '' ? null : $_POST['expiry'];
        $trang_thai = !empty($_POST['trang_thai']) ? 1 : 0;

        // validate
        $errors = [];
        if ($code === '') $errors[] = 'Code không được để trống.';
        if ($value <= 0) $errors[] = 'Giá trị mã phải > 0.';

        // check uniqueness on detected code column (if exists)
        if ($map['code']) {
            try {
                $sql = "SELECT COUNT(*) FROM ma_giam_gia WHERE `" . $map['code'] . "` = :code";
                $params = [':code'=>$code];
                if ($id > 0 && $map['id']) { $sql .= " AND `" . $map['id'] . "` != :id"; $params[':id'] = $id; }
                $st = $conn->prepare($sql);
                $st->execute($params);
                if ((int)$st->fetchColumn() > 0) $errors[] = 'Code đã tồn tại.';
            } catch (Exception $e) {
                // ignore uniqueness check failure
            }
        }

        if ($errors) {
            flash('error', implode('<br>',$errors));
            $_SESSION['coupon_form'] = compact('id','code','type','value','min_order','usage_limit','expiry','trang_thai');
            header('Location: coupons.php' . ($id ? '?edit='.$id : '?new=1'));
            exit;
        }

        // build columns/values dynamically based on $map
        $toSave = [];
        if ($map['code']) $toSave[$map['code']] = $code;
        if ($map['type']) $toSave[$map['type']] = $type;
        if ($map['gia_tri']) $toSave[$map['gia_tri']] = $value;
        if ($map['min_order']) $toSave[$map['min_order']] = $min_order;
        if ($map['usage_limit']) $toSave[$map['usage_limit']] = $usage_limit;
        if ($map['expiry']) $toSave[$map['expiry']] = $expiry;
        if ($map['trang_thai']) $toSave[$map['trang_thai']] = $trang_thai;

        try {
            if ($id > 0 && $map['id']) {
                // UPDATE
                $sets = [];
                $params = [];
                foreach ($toSave as $col=>$val) {
                    $sets[] = "`$col` = :$col";
                    $params[":$col"] = $val;
                }
                // updated_at
                if ($map['updated_at']) {
                    $sets[] = "`{$map['updated_at']}` = NOW()";
                }
                $params[':id'] = $id;
                $sql = "UPDATE ma_giam_gia SET " . implode(', ', $sets) . " WHERE `{$map['id']}` = :id";
                $u = $conn->prepare($sql);
                $u->execute($params);
                flash('success','Cập nhật mã thành công.');
                header('Location: coupons.php?edit='.$id);
                exit;
            } else {
                // INSERT
                $cols = [];
                $placeholders = [];
                $params = [];
                foreach ($toSave as $col=>$val) {
                    $cols[] = "`$col`";
                    $placeholders[] = ":$col";
                    $params[":$col"] = $val;
                }
                if ($map['created_at']) {
                    $cols[] = "`{$map['created_at']}`";
                    $placeholders[] = "NOW()";
                }
                $sql = "INSERT INTO ma_giam_gia (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $ins = $conn->prepare($sql);
                $ins->execute($params);
                // get inserted id (if id column exists)
                $newId = $conn->lastInsertId();
                flash('success','Tạo mã mới thành công' . ($newId ? " (ID {$newId})" : '.') );
                if ($newId) header('Location: coupons.php?edit='.$newId);
                else header('Location: coupons.php');
                exit;
            }
        } catch (Exception $e) {
            flash('error','Lỗi lưu mã: ' . $e->getMessage());
            header('Location: coupons.php');
            exit;
        }
    }

    // DELETE (supports id)
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $map['id']) {
            try {
                $d = $conn->prepare("DELETE FROM ma_giam_gia WHERE `{$map['id']}` = :id");
                $d->execute([':id'=>$id]);
                flash('success','Đã xóa mã #' . $id);
            } catch (Exception $e) {
                flash('error','Lỗi: ' . $e->getMessage());
            }
        } else {
            flash('error','Không tìm thấy id để xóa hoặc bảng thiếu khóa chính.');
        }
        header('Location: coupons.php'); exit;
    }

    // TOGGLE active/status
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $map['id'] && $map['trang_thai']) {
            try {
                $c = $conn->prepare("SELECT `{$map['trang_thai']}` FROM ma_giam_gia WHERE `{$map['id']}` = :id LIMIT 1");
                $c->execute([':id'=>$id]);
                $val = (int)$c->fetchColumn();
                $nv = $val ? 0 : 1;
                $u = $conn->prepare("UPDATE ma_giam_gia SET `{$map['trang_thai']}` = :nv WHERE `{$map['id']}` = :id");
                $u->execute([':nv'=>$nv, ':id'=>$id]);
                flash('success','Đã cập nhật trạng thái mã #'.$id);
            } catch (Exception $e) {
                flash('error','Lỗi: ' . $e->getMessage());
            }
        } else {
            flash('error','Không thể đổi trạng thái: thiếu cột trạng thái hoặc id.');
        }
        header('Location: coupons.php'); exit;
    }
}

/* ---------- GET: list, pagination, search ---------- */
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

// build a flexible WHERE using code column if exists, otherwise use wildcard on any likely text columns
$where = "1=1";
$params = [];
if ($search !== '') {
    if ($map['code']) {
        $where = "`{$map['code']}` LIKE :kw";
        $params[':kw'] = '%'.$search.'%';
    } else {
        // fallback: try to match on any varchar/text column - simple approach: search in gia_tri too
        $where = "(CAST(`{$map['id']}` AS CHAR) LIKE :kw OR :kw2 LIKE '%')"; // trivial fallback - will return all
        $params[':kw'] = '%'.$search.'%';
        $params[':kw2'] = $search;
    }
}

// total count
try {
    $countSql = "SELECT COUNT(*) FROM ma_giam_gia WHERE $where";
    $st = $conn->prepare($countSql);
    $st->execute($params);
    $total = (int)$st->fetchColumn();
} catch (Exception $e) { $total = 0; }
$pages = max(1, ceil($total / $perPage));

// fetch rows (select *)
try {
    $listSql = "SELECT * FROM ma_giam_gia WHERE $where ORDER BY " . ($map['created_at'] ?? ($map['id'] ?? '1')) . " DESC LIMIT :off, :lim";
    $listStmt = $conn->prepare($listSql);
    foreach ($params as $k=>$v) $listStmt->bindValue($k,$v);
    $listStmt->bindValue(':off', (int)$offset, PDO::PARAM_INT);
    $listStmt->bindValue(':lim', (int)$perPage, PDO::PARAM_INT);
    $listStmt->execute();
    $coupons = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $coupons = [];
}

// prepare edit/new
$formData = $_SESSION['coupon_form'] ?? null; if ($formData) unset($_SESSION['coupon_form']);
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editCoupon = null;
if ($editId > 0 && $map['id']) {
    try {
        $stm = $conn->prepare("SELECT * FROM ma_giam_gia WHERE `{$map['id']}` = :id LIMIT 1");
        $stm->execute([':id'=>$editId]);
        $editCoupon = $stm->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $editCoupon = null; }
} elseif (isset($_GET['new'])) {
    $editCoupon = [];
}

/* flash */
$fs = flash_get_once('success'); $fe = flash_get_once('error');

?><!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Quản lý mã giảm giá — Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f6f8fb;font-family:Inter,system-ui,Roboto,Arial}
    .card{border-radius:12px}
    .small-muted{color:#6c757d}
    .table thead th{border-bottom:2px solid #eef3f8}
    .mono {font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, "Roboto Mono", monospace}
  </style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/inc/topbar.php')) require_once __DIR__ . '/inc/topbar.php'; ?>

<div class="container-fluid my-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4>Mã giảm giá</h4>
    <div class="d-flex gap-2">
      <a href="coupons.php?new=1" class="btn btn-primary btn-sm">Tạo mã mới</a>
      <a href="index.php" class="btn btn-outline-secondary btn-sm">← Dashboard</a>
    </div>
  </div>

  <?php if ($fs): ?><div class="alert alert-success"><?= esc($fs) ?></div><?php endif; ?>
  <?php if ($fe): ?><div class="alert alert-danger"><?= esc($fe) ?></div><?php endif; ?>

  <div class="card p-3 mb-3">
    <form method="get" class="d-flex gap-2">
      <input name="q" class="form-control form-control-sm" placeholder="Tìm code..." value="<?= esc($search) ?>">
      <button class="btn btn-sm btn-dark">Tìm</button>
      <a href="coupons.php" class="btn btn-sm btn-outline-secondary">Làm mới</a>
      <div class="ms-auto small-muted">Tổng: <?= $total ?> mã</div>
    </form>
  </div>

  <div class="card p-3 mb-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead><tr><th>#</th><th>Code</th><th>Loại</th><th>Giá trị</th><th>Giới hạn</th><th>Hết hạn</th><th>Trạng thái</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($coupons as $c): 
               // read values using map; fallback to common keys if present
               $idVal = $map['id'] ? ($c[$map['id']] ?? '') : ($c['id_ma'] ?? $c['id'] ?? '');
               $codeVal = $map['code'] ? ($c[$map['code']] ?? '') : ($c['code'] ?? $c['ma'] ?? '');
               $typeVal = $map['type'] ? ($c[$map['type']] ?? '') : ($c['type'] ?? '');
               $valVal = $map['gia_tri'] ? ($c[$map['gia_tri']] ?? '') : ($c['gia_tri'] ?? $c['value'] ?? '');
               $limitVal = $map['usage_limit'] ? ($c[$map['usage_limit']] ?? '') : ($c['usage_limit'] ?? '');
               $expiryVal = $map['expiry'] ? ($c[$map['expiry']] ?? '') : ($c['expiry_date'] ?? '');
               $statusVal = $map['trang_thai'] ? ($c[$map['trang_thai']] ?? '') : ($c['trang_thai'] ?? $c['trangthai'] ?? $c['active'] ?? '');
          ?>
            <tr>
              <td class="mono"><?= esc($idVal) ?></td>
              <td class="fw-semibold"><?= esc($codeVal) ?></td>
              <td><?= esc($typeVal) ?></td>
              <td><?= esc($valVal) ?> <?= (strtolower($typeVal) === 'percent') ? '%' : '₫' ?></td>
              <td><?= $limitVal ? esc($limitVal) : 'Không giới hạn' ?></td>
              <td><?= $expiryVal ? esc($expiryVal) : '-' ?></td>
              <td><?= ((int)$statusVal === 1) ? '<span class="badge bg-success">Hoạt động</span>' : '<span class="badge bg-secondary">Đã khoá</span>' ?></td>
              <td class="text-end">
                <?php if ($map['id']): ?>
                  <a class="btn btn-sm btn-outline-primary" href="coupons.php?edit=<?= urlencode($idVal) ?>">Sửa</a>
                  <form method="post" style="display:inline" onsubmit="return confirm('Xóa mã?');">
                    <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= esc($idVal) ?>">
                    <button class="btn btn-sm btn-danger">Xóa</button>
                  </form>

                  <?php if ($map['trang_thai']): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= esc($idVal) ?>">
                    <button class="btn btn-sm btn-outline-secondary"><?= ((int)$statusVal === 1) ? 'Khoá' : 'Kích hoạt' ?></button>
                  </form>
                  <?php endif; ?>

                <?php else: ?>
                  <span class="small-muted">Thiếu khóa chính (id)</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($coupons)): ?><tr><td colspan="8" class="text-center text-muted">Chưa có mã giảm giá.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="mt-2">
      <nav><ul class="pagination">
        <?php for ($i=1;$i<=$pages;$i++): ?>
          <li class="page-item <?= $i===$page ? 'active' : '' ?>"><a class="page-link" href="?p=<?= $i ?>&q=<?= urlencode($search) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
      </ul></nav>
    </div>
  </div>

  <?php if ($editCoupon !== null): ?>
    <div class="card p-3 mb-3">
      <h5><?= ($editId ? 'Sửa mã #' . esc($editId) : 'Tạo mã mới') ?></h5>
      <?php if ($msg = flash_get_once('error')): ?><div class="alert alert-danger"><?= esc($msg) ?></div><?php endif; ?>

      <?php
         // preload values from $editCoupon or from previous form
         $d = $formData ?? $editCoupon;
         $d_id = $editId ?: 0;
         $d_code = $map['code'] ? ($d[$map['code']] ?? '') : ($d['code'] ?? ($d['ma'] ?? ''));
         $d_type = $map['type'] ? ($d[$map['type']] ?? 'fixed') : ($d['type'] ?? 'fixed');
         $d_val = $map['gia_tri'] ? ($d[$map['gia_tri']] ?? 0) : ($d['gia_tri'] ?? $d['value'] ?? 0);
         $d_min = $map['min_order'] ? ($d[$map['min_order']] ?? '') : ($d['min_order'] ?? '');
         $d_limit = $map['usage_limit'] ? ($d[$map['usage_limit']] ?? '') : ($d['usage_limit'] ?? '');
         $d_expiry = $map['expiry'] ? ($d[$map['expiry']] ?? '') : ($d['expiry_date'] ?? '');
         $d_status = $map['trang_thai'] ? ($d[$map['trang_thai']] ?? 1) : ($d['trang_thai'] ?? 1);
      ?>

      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= esc($d_id) ?>">

        <div class="col-md-4">
          <label class="form-label small">Code</label>
          <input name="code" class="form-control" required value="<?= esc($d_code) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label small">Loại</label>
          <select name="type" class="form-select">
            <option value="fixed" <?= $d_type === 'fixed' ? 'selected' : '' ?>>Tiền cố định</option>
            <option value="percent" <?= $d_type === 'percent' ? 'selected' : '' ?>>Phần trăm</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small">Giá trị</label>
          <input name="value" type="number" step="0.01" min="0" class="form-control" required value="<?= esc($d_val) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Min đơn (tùy)</label>
          <input name="min_order" type="number" step="0.01" min="0" class="form-control" value="<?= esc($d_min) ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label small">Số lượt dùng</label>
          <input name="usage_limit" type="number" min="0" class="form-control" value="<?= esc($d_limit) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Ngày hết hạn</label>
          <input name="expiry" type="date" class="form-control" value="<?= esc($d_expiry) ?>">
        </div>

        <div class="col-md-3 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="tt" name="trang_thai" <?= ((int)$d_status === 1) ? 'checked' : '' ?>>
            <label class="form-check-label small" for="tt">Kích hoạt</label>
          </div>
        </div>

        <div class="col-12 text-end">
          <button class="btn btn-primary"><?= $d_id ? 'Lưu thay đổi' : 'Tạo mã' ?></button>
          <a href="coupons.php" class="btn btn-outline-secondary">Hủy</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

</div>

<?php if (file_exists(__DIR__ . '/inc/footer.php')) require_once __DIR__ . '/inc/footer.php'; ?>
</body>
</html>
