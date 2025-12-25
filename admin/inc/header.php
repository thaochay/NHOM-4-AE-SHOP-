<?php
// admin/inc/header.php
if (session_status() === PHP_SESSION_NONE) session_start();

/* ===== DB + HELPERS ===== */
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../inc/helpers.php';

/* ===== FALLBACK ===== */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('site_name')) {
    function site_name($conn = null){ return 'AE SHOP'; }
}

/* ===== CHECK ADMIN ===== */
if (empty($_SESSION['user']) || empty($_SESSION['is_admin'])) {
    header('Location: ../login.php');
    exit;
}

$user = $_SESSION['user'];
$currentPage = basename($_SERVER['PHP_SELF']);

/* ===== CSRF ADMIN ===== */
if (empty($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(16));
}

/* ===== QUICK STATS ===== */
try {
    $countUsers = (int)$conn->query("SELECT COUNT(*) FROM nguoi_dung")->fetchColumn();
    $countOrdersNew = (int)$conn->query("
        SELECT COUNT(*) FROM don_hang 
        WHERE trang_thai IN ('moi','da_xac_nhan')
    ")->fetchColumn();
} catch (Throwable $e) {
    $countUsers = 0;
    $countOrdersNew = 0;
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title><?= esc(site_name($conn)) ?> — Admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>
body{
  background:#f4f6fb;
  font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial;
}
.topbar{
  background:#fff;
  border-bottom:1px solid #eef3f8;
  padding:12px 20px;
}
.sidebar{
  background:#fff;
  min-height:calc(100vh - 64px);
  border-right:1px solid #eef3f8;
  padding:20px 12px;
}
.brand{
  display:flex;
  align-items:center;
  gap:10px;
  font-weight:800;
}
.brand-logo{
  width:38px;
  height:38px;
  border-radius:10px;
  background:#0b7bdc;
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:center;
}
.nav-link{
  color:#1f2937;
  border-radius:10px;
  padding:10px 14px;
  margin-bottom:4px;
  display:flex;
  align-items:center;
}
.nav-link i{
  margin-right:8px;
}
.nav-link.active,
.nav-link:hover{
  background:#f1f6ff;
  color:#0b7bdc;
}
.nav-link .badge{
  margin-left:auto;
}
.main{
  padding:24px;
}
.small-muted{
  color:#6c757d;
  font-size:.9rem;
}
</style>
</head>
<body>

<!-- ===== TOPBAR ===== -->
<div class="topbar d-flex justify-content-between align-items-center">
  <div class="brand">
    <div class="brand-logo">AE</div>
    <div>
      <div><?= esc(site_name($conn)) ?> — Admin</div>
      <div class="small-muted">Bảng điều khiển quản trị</div>
    </div>
  </div>

  <div class="d-flex align-items-center gap-3">
    <span class="small-muted">
      <?= esc($user['ten'] ?? 'Admin') ?> · <span class="text-success">Administrator</span>
    </span>
    <a href="../index.php" class="btn btn-sm btn-outline-secondary">Xem site</a>
    <a href="../logout.php" class="btn btn-sm btn-outline-danger">Đăng xuất</a>
  </div>
</div>

<div class="container-fluid">
  <div class="row">

    <!-- ===== SIDEBAR ===== -->
    <aside class="col-md-2 sidebar">
      <nav class="nav flex-column">

        <a class="nav-link <?= $currentPage==='index.php'?'active':'' ?>" href="index.php">
          <i class="bi bi-speedometer2"></i> Dashboard
        </a>

        <a class="nav-link <?= $currentPage==='users.php'?'active':'' ?>" href="users.php">
          <i class="bi bi-people"></i> Người dùng
          <?php if($countUsers): ?>
            <span class="badge bg-secondary"><?= $countUsers ?></span>
          <?php endif; ?>
        </a>

        <a class="nav-link <?= $currentPage==='products.php'?'active':'' ?>" href="products.php">
          <i class="bi bi-box-seam"></i> Sản phẩm
        </a>

        <a class="nav-link <?= $currentPage==='inventory_log.php'?'active':'' ?>" href="inventory_log.php">
          <i class="bi bi-clock-history"></i> Lịch sử tồn kho
        </a>

        <a class="nav-link <?= $currentPage==='orders.php'?'active':'' ?>" href="orders.php">
          <i class="bi bi-receipt"></i> Đơn hàng
          <?php if($countOrdersNew): ?>
            <span class="badge bg-danger"><?= $countOrdersNew ?></span>
          <?php endif; ?>
        </a>

        <a class="nav-link <?= $currentPage==='coupons.php'?'active':'' ?>" href="coupons.php">
          <i class="bi bi-percent"></i> Mã giảm giá
        </a>

        <!-- ✅ BÁO CÁO DOANH THU (ĐÃ SỬA ĐÚNG) -->
        <a class="nav-link <?= $currentPage==='reports.php'?'active':'' ?>" href="reports.php">
          <i class="bi bi-bar-chart-line"></i> Báo cáo doanh thu
        </a>

        <hr>

        <div class="small-muted ps-2">
          Phiên quản trị<br>
          <?= esc($user['email'] ?? '') ?>
        </div>

      </nav>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="col-md-10 main">
