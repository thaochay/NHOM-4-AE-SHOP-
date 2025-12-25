<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../inc/helpers.php';

/* ===== AUTH ===== */
if (empty($_SESSION['is_admin']) || empty($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

/* ===== HELPERS ===== */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/* ===== FILTER DATE ===== */
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

/* ===== REPORT DATA ===== */
$stmt = $conn->prepare("
    SELECT 
        DATE(ngay_dat) AS ngay,
        COUNT(*) AS so_don,
        SUM(tong_tien) AS doanh_thu
    FROM don_hang
    WHERE trang_thai IN ('hoanthanh','paid','done',1)
      AND DATE(ngay_dat) BETWEEN :from AND :to
    GROUP BY DATE(ngay_dat)
    ORDER BY ngay ASC
");
$stmt->execute([
    'from' => $from,
    'to'   => $to
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRevenue = array_sum(array_column($rows,'doanh_thu'));
$totalOrders  = array_sum(array_column($rows,'so_don'));
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Báo cáo doanh thu — Admin</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

<style>
:root{
  --accent:#0b7bdc;
  --bg:#f4f7fb;
}
body{background:var(--bg)}
.sidebar{background:#fff;padding:18px;border-right:1px solid #eef3f8;min-height:calc(100vh - 72px)}
.main{padding:28px}
.nav-link{color:#374151;padding:10px 12px;border-radius:8px;margin-bottom:6px}
.nav-link.active{background:#eef4ff;font-weight:600}
#chartWrap{height:360px}
</style>
</head>

<body>

<!-- ===== TOPBAR ===== -->
<div class="topbar d-flex justify-content-between align-items-center bg-white px-4 py-3 border-bottom">
  <div class="d-flex align-items-center gap-3">
    <div style="width:42px;height:42px;background:var(--accent);color:#fff;border-radius:10px;
      display:flex;align-items:center;justify-content:center;font-weight:800">AE</div>
    <div>
      <div class="fw-bold">AE Shop — Admin</div>
      <div class="text-muted small">Báo cáo doanh thu</div>
    </div>
  </div>

  <div class="text-end small">
    <div>Xin chào <strong><?= esc($_SESSION['user']['ten'] ?? 'Admin') ?></strong></div>
    <a href="logout.php" class="text-danger text-decoration-none">
      <i class="bi bi-box-arrow-right"></i> Đăng xuất
    </a>
  </div>
</div>

<div class="container-fluid">
<div class="row g-0">

<!-- ===== SIDEBAR ===== -->
<aside class="col-md-2 sidebar">
<nav class="nav flex-column">

<a class="nav-link" href="index.php">
  <i class="bi bi-speedometer2 me-2"></i> Dashboard
</a>

<a class="nav-link" href="users.php">
  <i class="bi bi-people me-2"></i> Người dùng
</a>

<a class="nav-link" href="products.php">
  <i class="bi bi-box-seam me-2"></i> Sản phẩm
</a>
<a class="nav-link" href="products.php">
  <i class="bi bi-box-seam me-2"></i> Lịch sử kho hàng
</a>
<a class="nav-link" href="orders.php">
  <i class="bi bi-receipt me-2"></i> Đơn hàng
</a>

<a class="nav-link" href="coupons.php">
  <i class="bi bi-percent me-2"></i> Mã giảm giá
</a>

<!-- ACTIVE -->
<a class="nav-link active" href="reports.php">
  <i class="bi bi-bar-chart-line me-2"></i> Báo cáo doanh thu
</a>

</nav>
</aside>

<!-- ===== MAIN ===== -->
<main class="col-md-10 main">

<h4 class="mb-3">
<i class="bi bi-bar-chart-line"></i> Báo cáo doanh thu
</h4>

<!-- FILTER -->
<form class="row g-2 mb-4">
  <div class="col-md-3">
    <label class="form-label">Từ ngày</label>
    <input type="date" name="from" value="<?= esc($from) ?>" class="form-control">
  </div>
  <div class="col-md-3">
    <label class="form-label">Đến ngày</label>
    <input type="date" name="to" value="<?= esc($to) ?>" class="form-control">
  </div>
  <div class="col-md-2 d-flex align-items-end">
    <button class="btn btn-primary w-100">
      <i class="bi bi-filter"></i> Lọc
    </button>
  </div>
</form>

<!-- SUMMARY -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card p-3">
      <div class="text-muted">Tổng doanh thu</div>
      <h4><?= price($totalRevenue) ?></h4>
    </div>
  </div>

  <div class="col-md-3">
    <div class="card p-3">
      <div class="text-muted">Đơn hoàn thành</div>
      <h4><?= $totalOrders ?></h4>
    </div>
  </div>
</div>

<!-- CHART -->
<div class="card mb-4">
  <div class="card-header fw-semibold">
    Biểu đồ doanh thu theo ngày
  </div>
  <div class="card-body" id="chartWrap">
    <canvas id="revenueChart"></canvas>
  </div>
</div>

<!-- TABLE -->
<div class="card">
  <div class="card-body p-0">
    <table class="table table-bordered mb-0">
      <thead class="table-light">
        <tr>
          <th>Ngày</th>
          <th>Số đơn</th>
          <th>Doanh thu</th>
        </tr>
      </thead>
      <tbody>
      <?php if($rows): foreach($rows as $r): ?>
        <tr>
          <td><?= esc($r['ngay']) ?></td>
          <td><?= (int)$r['so_don'] ?></td>
          <td><?= price($r['doanh_thu']) ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="3" class="text-center text-muted">Không có dữ liệu</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</main>
</div>
</div>

<script>
const labels  = <?= json_encode(array_column($rows,'ngay')) ?>;
const revenue = <?= json_encode(array_map('floatval', array_column($rows,'doanh_thu'))) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('revenueChart'), {
  type: 'bar',
  data: {
    labels: labels,
    datasets: [{
      label: 'Doanh thu (VNĐ)',
      data: revenue
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      y: {
        beginAtZero: true,
        ticks: {
          callback: v => v.toLocaleString('vi-VN') + ' ₫'
        }
      }
    }
  }
});
</script>

</body>
</html>
