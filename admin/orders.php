<?php
require_once __DIR__ . '/inc/header.php';

/* =====================================================
   XỬ LÝ CẬP NHẬT TRẠNG THÁI ĐƠN HÀNG
   ===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cap_nhat_trang_thai'])) {

    // CSRF
    if (!hash_equals($_SESSION['csrf_admin'], $_POST['csrf'] ?? '')) {
        die('CSRF không hợp lệ');
    }

    $idDonHang = (int)($_POST['id_don_hang'] ?? 0);
    $newStatus = $_POST['trang_thai'] ?? '';

    // Admin chỉ được set các trạng thái này
    $allowAdmin = ['moi', 'dang_giao', 'hoan_thanh'];

    // Lấy trạng thái hiện tại
    $stmCur = $conn->prepare(
        "SELECT trang_thai FROM don_hang WHERE id_don_hang = ?"
    );
    $stmCur->execute([$idDonHang]);
    $currentStatus = $stmCur->fetchColumn();

    if (!$currentStatus) {
        $_SESSION['flash_admin_error'] = 'Đơn hàng không tồn tại.';
    }
    elseif (in_array($currentStatus, ['hoan_thanh', 'user_huy'], true)) {
        $_SESSION['flash_admin_error'] = 'Đơn hàng đã kết thúc, không thể cập nhật.';
    }
    elseif (in_array($newStatus, $allowAdmin, true)) {
        $stmUp = $conn->prepare("
            UPDATE don_hang
            SET trang_thai = ?, updated_at = NOW()
            WHERE id_don_hang = ?
        ");
        $stmUp->execute([$newStatus, $idDonHang]);
        $_SESSION['flash_admin_success'] = 'Cập nhật trạng thái thành công.';
    } else {
        $_SESSION['flash_admin_error'] = 'Trạng thái không hợp lệ.';
    }

    header("Location: donhang.php");
    exit;
}

/* =====================================================
   LẤY DANH SÁCH ĐƠN HÀNG  (JOIN ĐÚNG BẢNG nguoi_dung)
   ===================================================== */
$sql = "
    SELECT 
        dh.id_don_hang,
        dh.ma_don,
        dh.trang_thai,
        dh.tong_tien,
        dh.ngay_dat,
        nd.ten,
        nd.email
    FROM don_hang dh
    LEFT JOIN nguoi_dung nd 
        ON dh.id_nguoi_dung = nd.id_nguoi_dung
    ORDER BY dh.ngay_dat DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   HIỂN THỊ TRẠNG THÁI (PHP 7.x OK)
   ===================================================== */
function hienTrangThai($st) {
    switch ($st) {
        case 'moi':
            return '<span class="badge bg-warning">Chờ xử lý</span>';
        case 'dang_giao':
            return '<span class="badge bg-info">Đang giao</span>';
        case 'hoan_thanh':
            return '<span class="badge bg-success">Hoàn thành</span>';
        case 'user_huy':
            return '<span class="badge bg-danger">Người dùng đã hủy</span>';
        default:
            return '<span class="badge bg-secondary">Không xác định</span>';
    }
}
?>

<h4 class="mb-4">📦 Quản lý đơn hàng</h4>

<?php if (!empty($_SESSION['flash_admin_success'])): ?>
<div class="alert alert-success">
    <?= $_SESSION['flash_admin_success']; unset($_SESSION['flash_admin_success']); ?>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_admin_error'])): ?>
<div class="alert alert-danger">
    <?= $_SESSION['flash_admin_error']; unset($_SESSION['flash_admin_error']); ?>
</div>
<?php endif; ?>

<div class="card shadow-sm">
<div class="card-body table-responsive">
<table class="table table-hover align-middle">
<thead class="table-light">
<tr>
    <th>ID</th>
    <th>Mã đơn</th>
    <th>Khách hàng</th>
    <th>Tổng tiền</th>
    <th>Trạng thái</th>
    <th>Cập nhật</th>
    <th>Ngày đặt</th>
</tr>
</thead>
<tbody>

<?php if (!empty($orders)): foreach ($orders as $o): ?>
<tr>
    <td>#<?= (int)$o['id_don_hang'] ?></td>
    <td><?= esc($o['ma_don']) ?></td>
    <td>
        <?= esc($o['ten'] ?? 'Khách vãng lai') ?><br>
        <small class="text-muted"><?= esc($o['email'] ?? '') ?></small>
    </td>
    <td class="fw-bold text-danger">
        <?= number_format((float)$o['tong_tien'], 0, ',', '.') ?> ₫
    </td>
    <td><?= hienTrangThai($o['trang_thai']) ?></td>

    <td>
        <?php if (!in_array($o['trang_thai'], ['hoan_thanh','user_huy'], true)): ?>
        <form method="post" class="d-flex gap-1">
            <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_admin'] ?>">
            <input type="hidden" name="id_don_hang" value="<?= (int)$o['id_don_hang'] ?>">

            <select name="trang_thai" class="form-select form-select-sm">
                <option value="moi" <?= $o['trang_thai']==='moi'?'selected':'' ?>>
                    Chờ xử lý
                </option>
                <option value="dang_giao" <?= $o['trang_thai']==='dang_giao'?'selected':'' ?>>
                    Đang giao
                </option>
                <option value="hoan_thanh" <?= $o['trang_thai']==='hoan_thanh'?'selected':'' ?>>
                    Hoàn thành
                </option>
            </select>

            <button type="submit" name="cap_nhat_trang_thai"
                    class="btn btn-sm btn-primary">
                Lưu
            </button>
        </form>
        <?php else: ?>
            <span class="text-muted">—</span>
        <?php endif; ?>
    </td>

    <td><?= date('d/m/Y H:i', strtotime($o['ngay_dat'])) ?></td>
</tr>
<?php endforeach; else: ?>
<tr>
    <td colspan="7" class="text-center text-muted">
        Chưa có đơn hàng
    </td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
