<?php
// admin/orders.php
// Quản lý đơn hàng - giao diện đẹp, bulk approve/export/delete, view -> order_view.php
require_once __DIR__ . '/inc/header.php'; // cung cấp $conn, admin guard, $_SESSION['csrf_admin']
/** @var PDO $conn */

// helpers (điều kiện tránh redeclare)
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}
if (!function_exists('flash')) {
    function flash($k,$m){ $_SESSION['flash_admin_'.$k] = $m; }
}
if (!function_exists('flash_get')) {
    function flash_get($k){
        $kk='flash_admin_'.$k; $v = $_SESSION[$kk] ?? null; if ($v) unset($_SESSION[$kk]); return $v;
    }
}

if (!isset($_SESSION['csrf_admin'])) $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));

/* ---------------- POST actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf_admin'] ?? '', $_POST['csrf'] ?? '')) {
        flash('error','CSRF token không hợp lệ.');
        header('Location: orders.php'); exit;
    }

    // Approve (duyệt) single or multiple
    if ($action === 'approve') {
        $ids = [];
        if (!empty($_POST['selection']) && is_array($_POST['selection'])) $ids = array_map('intval', $_POST['selection']);
        elseif (!empty($_POST['id'])) $ids[] = (int)$_POST['id'];

        if (empty($ids)) { flash('error','Chưa có đơn được chọn để duyệt.'); header('Location: orders.php'); exit; }

        try {
            $in = implode(',', array_fill(0,count($ids),'?'));
            // update without using updated_at (tránh lỗi nếu cột không tồn tại)
            $stmt = $conn->prepare("UPDATE don_hang SET trang_thai = 'duyet' WHERE id_don_hang IN ($in)");
            foreach ($ids as $i=>$val) $stmt->bindValue($i+1,$val,PDO::PARAM_INT);
            $stmt->execute();
            flash('success','Đã duyệt ' . count($ids) . ' đơn hàng.');
        } catch (Exception $e) {
            flash('error','Không thể duyệt: ' . $e->getMessage());
        }
        header('Location: orders.php'); exit;
    }

    // change single order status (from view)
    if ($action === 'change_status') {
        $id = (int)($_POST['id'] ?? 0);
        $new = trim($_POST['status'] ?? '');
        if ($id <=0 || $new === '') { flash('error','Dữ liệu không hợp lệ'); header('Location: orders.php'); exit; }
        try {
            $u = $conn->prepare("UPDATE don_hang SET trang_thai = :st WHERE id_don_hang = :id");
            $u->execute([':st'=>$new, ':id'=>$id]);
            flash('success','Cập nhật trạng thái đơn #' . $id);
        } catch (Exception $e) { flash('error','Lỗi: '.$e->getMessage()); }
        header('Location: orders.php?view='.$id); exit;
    }

    // delete single or multiple
    if ($action === 'delete') {
        $ids = [];
        if (!empty($_POST['selection']) && is_array($_POST['selection'])) $ids = array_map('intval', $_POST['selection']);
        elseif (!empty($_POST['id'])) $ids[] = (int)$_POST['id'];

        if (empty($ids)) { flash('error','Chưa có đơn được chọn để xóa.'); header('Location: orders.php'); exit; }

        try {
            $conn->beginTransaction();
            $in = implode(',', array_fill(0,count($ids),'?'));
            $d1 = $conn->prepare("DELETE FROM don_hang_chi_tiet WHERE id_don_hang IN ($in)");
            foreach ($ids as $i=>$v) $d1->bindValue($i+1,$v,PDO::PARAM_INT);
            $d1->execute();
            $d2 = $conn->prepare("DELETE FROM don_hang WHERE id_don_hang IN ($in)");
            foreach ($ids as $i=>$v) $d2->bindValue($i+1,$v,PDO::PARAM_INT);
            $d2->execute();
            $conn->commit();
            flash('success','Đã xóa ' . count($ids) . ' đơn hàng.');
        } catch (Exception $e) {
            $conn->rollBack();
            flash('error','Không thể xóa: ' . $e->getMessage());
        }
        header('Location: orders.php'); exit;
    }

    // export CSV
    if ($action === 'export_csv') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || count($ids)===0) { flash('error','Chưa chọn đơn để xuất'); header('Location: orders.php'); exit; }
        $ids = array_map('intval',$ids);
        $ph = implode(',', array_fill(0,count($ids),'?'));
        $sql = "SELECT dh.*, u.ten AS kh_ten, u.email AS kh_email FROM don_hang dh LEFT JOIN nguoi_dung u ON dh.id_nguoi_dung=u.id_nguoi_dung WHERE dh.id_don_hang IN ($ph) ORDER BY dh.ngay_dat DESC";
        $st = $conn->prepare($sql);
        foreach ($ids as $i=>$v) $st->bindValue($i+1,$v,PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=orders_export_'.date('Ymd_His').'.csv');
        echo "\xEF\xBB\xBF"; // BOM for Excel
        $out = fopen('php://output','w');
        fputcsv($out, ['id_don_hang','ma_don','kh_ten','kh_email','tong_tien','phi_van_chuyen','trang_thai','ngay_dat','ghi_chu']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id_don_hang'],$r['ma_don'],$r['kh_ten'] ?? '', $r['kh_email'] ?? '', $r['tong_tien'] ?? 0, $r['phi_van_chuyen'] ?? 0, $r['trang_thai'] ?? '', $r['ngay_dat'] ?? '', $r['ghi_chu'] ?? ''
            ]);
        }
        fclose($out); exit;
    }
}

/* ---------------- GET list + summary ---------------- */
$search = trim($_GET['q'] ?? '');
$tab = $_GET['tab'] ?? 'all'; // all | approved | pending
$page = max(1,(int)($_GET['p'] ?? 1));
$perPage = 30; $offset = ($page-1)*$perPage;

$whereParts = ["1=1"];
$params = [];

if ($search !== '') {
    $whereParts[] = "(dh.ma_don LIKE :kw OR u.ten LIKE :kw OR u.email LIKE :kw)";
    $params[':kw'] = '%'.$search.'%';
}

if ($tab === 'approved') {
    $whereParts[] = "dh.trang_thai = 'duyet'";
} elseif ($tab === 'pending') {
    $whereParts[] = "dh.trang_thai != 'duyet'";
}

$where = implode(' AND ', $whereParts);

// counts for summary
try {
    $cntAll = (int)$conn->query("SELECT COUNT(*) FROM don_hang")->fetchColumn();
} catch (Exception $e) { $cntAll = 0; }
try {
    $cntApproved = (int)$conn->query("SELECT COUNT(*) FROM don_hang WHERE trang_thai = 'duyet'")->fetchColumn();
} catch (Exception $e) { $cntApproved = 0; }
try {
    $cntPending = (int)$conn->query("SELECT COUNT(*) FROM don_hang WHERE trang_thai != 'duyet'")->fetchColumn();
} catch (Exception $e) { $cntPending = 0; }

// total pages
try {
    $cntQ = "SELECT COUNT(*) FROM don_hang dh LEFT JOIN nguoi_dung u ON dh.id_nguoi_dung=u.id_nguoi_dung WHERE $where";
    $st = $conn->prepare($cntQ); $st->execute($params); $total = (int)$st->fetchColumn();
} catch (Exception $e) { $total = 0; }
$pages = max(1,ceil($total/$perPage));

// fetch list
$listQ = "SELECT dh.id_don_hang, dh.ma_don, dh.trang_thai, dh.tong_tien, dh.phi_van_chuyen, dh.ngay_dat, u.ten AS kh_ten, u.email AS kh_email
          FROM don_hang dh LEFT JOIN nguoi_dung u ON dh.id_nguoi_dung=u.id_nguoi_dung
          WHERE $where ORDER BY dh.ngay_dat DESC LIMIT :off,:lim";
$st = $conn->prepare($listQ);
foreach ($params as $k=>$v) $st->bindValue($k,$v);
$st->bindValue(':off',(int)$offset,PDO::PARAM_INT);
$st->bindValue(':lim',(int)$perPage,PDO::PARAM_INT);
try { $st->execute(); $orders = $st->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $orders = []; }

/* flash */
$fs = flash_get('success'); $fe = flash_get('error');
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Quản lý đơn hàng — Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{--accent:#0b7bdc;--muted:#6c757d}
    body{background:#f4f7fb;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial;color:#1f2937}
    .top-actions .btn{min-width:140px}
    .summary .card{border-radius:12px}
    .table thead th{vertical-align:middle}
    .badge-status{padding:6px 10px;border-radius:999px;font-size:.85rem}
    .row-highlight{background:linear-gradient(90deg, rgba(11,123,220,0.04), rgba(11,123,220,0.01))}
    .small-muted{color:var(--muted)}
    .nav-tabs .nav-link.active{background:linear-gradient(90deg,#e8f7ff,#fff);border-radius:8px}
    @media (max-width:768px){ .top-actions .btn {min-width:unset;width:100%} .summary .d-flex.flex-column{gap:.5rem} }
  </style>
</head>
<body>
<?php if (file_exists(__DIR__.'/inc/topbar.php')) require_once __DIR__.'/inc/topbar.php'; ?>

<div class="container-fluid my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">Quản lý đơn hàng</h4>
      <div class="small-muted">Quản lý, duyệt, xuất và theo dõi đơn hàng</div>
    </div>
    <div class="d-flex gap-2">
      <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Dashboard</a>
      <a href="../" class="btn btn-outline-primary btn-sm"><i class="bi bi-house"></i> Trang cửa hàng</a>
    </div>
  </div>

  <?php if ($fs): ?><div class="alert alert-success"><?= esc($fs) ?></div><?php endif; ?>
  <?php if ($fe): ?><div class="alert alert-danger"><?= esc($fe) ?></div><?php endif; ?>

  <!-- summary + tabs -->
  <div class="row g-3 mb-3 summary">
    <div class="col-md-4">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small-muted">Tổng đơn</div>
            <h3 class="mb-0"><?= $cntAll ?></h3>
            <div class="small-muted">Tổng số đơn trong hệ thống</div>
          </div>
          <div><i class="bi bi-receipt-cutoff" style="font-size:32px;color:var(--accent)"></i></div>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small-muted">Đã duyệt</div>
            <h3 class="mb-0 text-success"><?= $cntApproved ?></h3>
            <div class="small-muted">Đơn đã được duyệt</div>
          </div>
          <div><i class="bi bi-check-circle" style="font-size:32px;color:#28a745"></i></div>
        </div>
      </div>
    </div>

    <div class="col-md-4">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small-muted">Chưa duyệt</div>
            <h3 class="mb-0 text-warning"><?= $cntPending ?></h3>
            <div class="small-muted">Đơn chờ xử lý</div>
          </div>
          <div><i class="bi bi-clock-history" style="font-size:32px;color:#f59e0b"></i></div>
        </div>
      </div>
    </div>
  </div>

  <!-- search + actions -->
  <div class="card p-3 mb-3">
    <div class="row g-2 align-items-center">
      <div class="col-md-6">
        <form method="get" class="d-flex gap-2">
          <input name="q" class="form-control form-control-sm" placeholder="Tìm mã/khách/email" value="<?= esc($search) ?>">
          <select name="tab" class="form-select form-select-sm" style="max-width:160px">
            <option value="all" <?= $tab==='all' ? 'selected' : '' ?>>Tất cả</option>
            <option value="approved" <?= $tab==='approved' ? 'selected' : '' ?>>Đã duyệt</option>
            <option value="pending" <?= $tab==='pending' ? 'selected' : '' ?>>Chưa duyệt</option>
          </select>
          <button class="btn btn-dark btn-sm">Tìm</button>
          <a href="orders.php" class="btn btn-outline-secondary btn-sm">Làm mới</a>
        </form>
      </div>

      <div class="col-md-6 text-end top-actions">
        <div class="d-inline-block me-2">
          <button id="bulkApproveBtn" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Duyệt chọn</button>
        </div>
        <div class="d-inline-block me-2">
          <button id="exportCsvBtn" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> Xuất CSV chọn</button>
        </div>
        <div class="d-inline-block">
          <button id="bulkDeleteBtn" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Xóa chọn</button>
        </div>

        <!-- hidden forms for actions -->
        <form id="approveForm" method="post" class="d-none">
          <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
          <input type="hidden" name="action" value="approve">
        </form>
        <form id="exportForm" method="post" class="d-none">
          <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
          <input type="hidden" name="action" value="export_csv">
        </form>
        <form id="deleteForm" method="post" class="d-none">
          <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf_admin']) ?>">
          <input type="hidden" name="action" value="delete">
        </form>
      </div>
    </div>
  </div>

  <!-- orders table -->
  <div class="card p-3">
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:40px"><input id="chkAll" type="checkbox"></th>
            <th>#</th>
            <th>Mã đơn</th>
            <th>Khách</th>
            <th>Tổng</th>
            <th>Trạng thái</th>
            <th>Ngày</th>
            <th style="width:220px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($orders as $o):
            $isApproved = ($o['trang_thai'] === 'duyet');
            $rowClass = $isApproved ? 'row-highlight' : '';
          ?>
            <tr class="<?= $rowClass ?>">
              <td><input class="chk form-check-input" type="checkbox" value="<?= (int)$o['id_don_hang'] ?>"></td>
              <td><?= (int)$o['id_don_hang'] ?></td>
              <td><strong><?= esc($o['ma_don'] ?? '') ?></strong></td>
              <td>
                <div class="fw-semibold"><?= esc($o['kh_ten'] ?? '') ?></div>
                <div class="small-muted"><?= esc($o['kh_email'] ?? '') ?></div>
              </td>
              <td class="fw-semibold"><?= number_format($o['tong_tien'] ?? 0,0,',','.') ?> ₫</td>
              <td>
                <?php if ($o['trang_thai'] === 'duyet'): ?>
                  <span class="badge bg-success badge-status"><i class="bi bi-check-circle me-1"></i> Đã duyệt</span>
                <?php elseif ($o['trang_thai'] === 'huy'): ?>
                  <span class="badge bg-danger badge-status">Đã huỷ</span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark badge-status"><?= esc($o['trang_thai']) ?></span>
                <?php endif; ?>
              </td>
              <td class="small-muted"><?= esc($o['ngay_dat'] ?? '') ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="order_view.php?id=<?= (int)$o['id_don_hang'] ?>"><i class="bi bi-eye"></i> Xem</a>

                <?php if (!$isApproved): ?>
                  <button class="btn btn-sm btn-success btn-approve-one" data-ids='["<?= (int)$o['id_don_hang'] ?>"]'><i class="bi bi-check-lg"></i> Duyệt</button>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-success" disabled><i class="bi bi-check2-circle"></i> Đã duyệt</button>
                <?php endif; ?>

                <button class="btn btn-sm btn-danger btn-delete-one" data-ids='["<?= (int)$o['id_don_hang'] ?>"]'><i class="bi bi-trash"></i> Xóa</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($orders)): ?><tr><td colspan="8" class="text-center text-muted">Chưa tìm thấy đơn hàng.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-3">
      <div class="small-muted">Hiển thị <?= count($orders) ?> / <?= $total ?> đơn</div>
      <nav><ul class="pagination mb-0">
        <?php for($i=1;$i<=$pages;$i++): ?>
          <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?p=<?= $i ?>&q=<?= urlencode($search) ?>&tab=<?= urlencode($tab) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
      </ul></nav>
    </div>
  </div>
</div>

<!-- Confirm modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Xác nhận hành động</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p id="confirmMsg">Bạn có chắc muốn thực hiện hành động cho <strong id="confirmCount">0</strong> đơn?</p>
        <div id="confirmList" style="max-height:160px;overflow:auto;font-size:.95rem"></div>
      </div>
      <div class="modal-footer">
        <button type="button" id="confirmCancel" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
        <button type="button" id="confirmOk" class="btn btn-primary">Xác nhận</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // utilities
  function getSelectedIds(){
    return Array.from(document.querySelectorAll('.chk:checked')).map(x => x.value);
  }

  // select all
  document.getElementById('chkAll')?.addEventListener('change', function(){ document.querySelectorAll('.chk').forEach(c=>c.checked=this.checked); });

  // modal instance
  const confirmModalEl = document.getElementById('confirmModal');
  const confirmModal = new bootstrap.Modal(confirmModalEl, {});

  // open modal and populate (store ids array in data-ids)
  function openConfirm(action, ids){
    const countSpan = document.getElementById('confirmCount');
    const listDiv = document.getElementById('confirmList');
    countSpan.textContent = ids.length;
    listDiv.innerHTML = ids.map(i => '<div>#'+i+'</div>').join('');
    confirmModalEl.dataset.action = action;
    confirmModalEl.dataset.ids = JSON.stringify(ids);
    confirmModal.show();
  }

  // bulk approve
  document.getElementById('bulkApproveBtn')?.addEventListener('click', function(){
    const ids = getSelectedIds();
    if (ids.length === 0) return alert('Chưa chọn đơn hàng.');
    openConfirm('approve', ids);
  });

  // export csv selected
  document.getElementById('exportCsvBtn')?.addEventListener('click', function(){
    const ids = getSelectedIds();
    if (ids.length === 0) return alert('Chưa chọn đơn hàng để xuất.');
    const f = document.getElementById('exportForm');
    // remove old inputs
    f.querySelectorAll('input[name="ids[]"]').forEach(n=>n.remove());
    ids.forEach(id => {
      const ip = document.createElement('input'); ip.type='hidden'; ip.name='ids[]'; ip.value=id; f.appendChild(ip);
    });
    f.submit();
  });

  // bulk delete
  document.getElementById('bulkDeleteBtn')?.addEventListener('click', function(){
    const ids = getSelectedIds();
    if (ids.length === 0) return alert('Chưa chọn đơn hàng để xóa.');
    openConfirm('delete', ids);
  });

  // approve single
  document.querySelectorAll('.btn-approve-one').forEach(b => b.addEventListener('click', function(){
    const ids = JSON.parse(this.dataset.ids || '[]');
    openConfirm('approve', ids);
  }));

  // delete single
  document.querySelectorAll('.btn-delete-one').forEach(b => b.addEventListener('click', function(){
    const ids = JSON.parse(this.dataset.ids || '[]');
    openConfirm('delete', ids);
  }));

  // modal confirm action
  document.getElementById('confirmOk').addEventListener('click', function(){
    const action = confirmModalEl.dataset.action;
    let ids = [];
    try { ids = JSON.parse(confirmModalEl.dataset.ids || '[]'); } catch(e){ ids = []; }
    if (!Array.isArray(ids) || ids.length === 0) {
      confirmModal.hide();
      return alert('Không có đơn nào để xử lý.');
    }

    if (action === 'approve') {
      const form = document.getElementById('approveForm');
      form.querySelectorAll('input[name="selection[]"]')?.forEach(n=>n.remove());
      ids.forEach(id => {
        const ip = document.createElement('input'); ip.type='hidden'; ip.name='selection[]'; ip.value=id; form.appendChild(ip);
      });
      form.submit();
    } else if (action === 'delete') {
      if (!confirm('Xác nhận xóa ' + ids.length + ' đơn? Hành động không thể hoàn tác.')) return;
      const form = document.getElementById('deleteForm');
      form.querySelectorAll('input[name="selection[]"]')?.forEach(n=>n.remove());
      ids.forEach(id => {
        const ip = document.createElement('input'); ip.type='hidden'; ip.name='selection[]'; ip.value=id; form.appendChild(ip);
      });
      form.submit();
    }
    confirmModal.hide();
  });

  // ensure modal cancel clears dataset
  document.getElementById('confirmCancel').addEventListener('click', function(){
    confirmModalEl.dataset.action = '';
    confirmModalEl.dataset.ids = '[]';
  });
</script>
</body>
</html>
