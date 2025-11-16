<?php
// sanpham.php - danh sách sản phẩm với header/menu + AJAX add-to-cart (fallback form)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

// cart count for header badge
$cart = $_SESSION['cart'] ?? [];
$cart_count = 0;
foreach ($cart as $it) $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1);

// params
$q = trim($_GET['q'] ?? '');
$cat = (int)($_GET['cat'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 12;
$offset = ($page -1) * $per_page;

// build where
$where = "WHERE 1=1"; $params = [];
if ($q !== '') { $where .= " AND (sp.ten LIKE :q OR sp.mo_ta LIKE :q)"; $params[':q'] = '%'.$q.'%'; }
if ($cat > 0) { $where .= " AND sp.id_danh_muc = :cat"; $params[':cat'] = $cat; }

// count
$countSql = "SELECT COUNT(*) FROM san_pham sp $where";
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$total_items = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_items / $per_page));

// fetch products
$sql = "
  SELECT sp.id_san_pham, sp.ten, sp.gia,
    (SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = sp.id_san_pham AND la_anh_chinh = 1 LIMIT 1) AS img,
    dm.ten AS danh_muc_ten
  FROM san_pham sp
  LEFT JOIN danh_muc dm ON sp.id_danh_muc = dm.id_danh_muc
  $where
  ORDER BY sp.id_san_pham DESC
  LIMIT :limit OFFSET :offset
";
$stmt = $conn->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k,$v);
$stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// categories
$cats = [];
try { $cstmt = $conn->query("SELECT id_danh_muc, ten, slug FROM danh_muc ORDER BY ten ASC"); $cats = $cstmt->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

function buildUrl($overrides = []) {
  $qs = array_merge($_GET, $overrides);
  return basename($_SERVER['PHP_SELF']) . '?' . http_build_query($qs);
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title>Sản phẩm — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    /* header */
    .site-top { border-bottom:1px solid #eef3f8; background:#fff; position:sticky; top:0; z-index:1030; }
    .brand { display:flex; align-items:center; gap:10px; text-decoration:none; color:inherit; }
    .brand-mark { width:48px;height:48px;border-radius:8px;background:#0b1220;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800; }
    .nav-center { display:flex; gap:8px; align-items:center; justify-content:center; flex:1; }
    .nav-center .nav-link { color:#333; padding:8px 12px; border-radius:8px; font-weight:600; text-decoration:none; }
    .nav-center .nav-link.active, .nav-center .nav-link:hover { color:#0d6efd; background:rgba(13,110,253,0.04); }
    .search-input { width:360px; max-width:35vw; }
    .icon-circle { width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#f6f8fb;color:#0b1220; }
    .cart-badge { font-size:.72rem; position:relative; top:-10px; left:-6px; }
    @media (max-width:991px) { .nav-center{ display:none; } .search-input{ display:none; } }
    /* products */
    .card-product { border:1px solid #eef2f7; border-radius:12px; transition:.15s; }
    .card-product:hover { box-shadow:0 8px 24px rgba(0,0,0,0.06); transform:translateY(-4px); }
    .prod-img { width:100%; height:200px; object-fit:cover; border-radius:12px 12px 0 0; }
    .price { color:#0d6efd; font-weight:700; }
  </style>
</head>
<body>

<!-- HEADER -->
<header class="site-top">
  <div class="container d-flex align-items-center gap-3 py-2">
    <a href="index.php" class="brand">
      <div class="brand-mark">AE</div>
      <div class="d-none d-md-block">
        <div style="font-weight:800"><?= esc(site_name($conn)) ?></div>
        <div style="font-size:12px;color:#6c757d">Thời trang nam cao cấp</div>
      </div>
    </a>

    <nav class="nav-center d-none d-lg-flex" role="navigation" aria-label="Main menu">
      <a class="nav-link" href="index.php">Trang chủ</a>

      <div class="nav-item dropdown">
        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">Sản phẩm</a>
        <ul class="dropdown-menu p-2">
          <?php if (!empty($cats)): foreach($cats as $c): ?>
            <li><a class="dropdown-item" href="category.php?slug=<?= urlencode($c['slug'] ?? '') ?>"><?= esc($c['ten']) ?></a></li>
          <?php endforeach; else: ?>
            <li><span class="dropdown-item text-muted">Chưa có danh mục</span></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider"></li>
          <li><a class="dropdown-item" href="sanpham.php">Xem tất cả sản phẩm</a></li>
        </ul>
      </div>

      <a class="nav-link" href="sale.php">Khuyến mãi</a>
      <a class="nav-link" href="about.php">Giới thiệu</a>
    </nav>

    <div class="ms-auto d-flex align-items-center gap-2">
      <form class="d-none d-lg-flex" action="sanpham.php" method="get" role="search">
        <div class="input-group input-group-sm shadow-sm" style="border-radius:10px; overflow:hidden;">
          <input name="q" class="form-control form-control-sm search-input" placeholder="Tìm sản phẩm, mã..." value="<?= esc($q) ?>">
          <button class="btn btn-dark btn-sm" type="submit"><i class="bi bi-search"></i></button>
        </div>
      </form>

      <div class="dropdown">
        <a href="account.php" class="text-decoration-none d-flex align-items-center" data-bs-toggle="dropdown" aria-expanded="false">
          <div class="icon-circle"><i class="bi bi-person-fill"></i></div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end p-2">
          <?php if (empty($_SESSION['user'])): ?>
            <li><a class="dropdown-item" href="login.php">Đăng nhập</a></li>
            <li><a class="dropdown-item" href="register.php">Tạo tài khoản</a></li>
          <?php else: ?>
            <li><a class="dropdown-item" href="account.php">Tài khoản</a></li>
            <li><a class="dropdown-item" href="orders.php">Đơn hàng</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php">Đăng xuất</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="dropdown">
        <a href="#" id="cartMenu" data-bs-toggle="dropdown" class="text-decoration-none position-relative d-flex align-items-center">
          <div class="icon-circle"><i class="bi bi-bag-fill"></i></div>
          <span class="d-none d-md-inline ms-2 small">Giỏ hàng</span>
          <span id="cart-count-badge" class="badge bg-danger rounded-pill cart-badge"><?= (int)$cart_count ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-end p-3" style="min-width:320px;">
          <h6 class="mb-2">Giỏ hàng</h6>
          <?php if (empty($_SESSION['cart'])): ?>
            <div class="small text-muted">Chưa có sản phẩm.</div>
            <div class="mt-3"><a href="sanpham.php" class="btn btn-sm btn-outline-primary w-100">Mua ngay</a></div>
          <?php else: ?>
            <div style="max-height:260px; overflow:auto;">
              <?php $total=0; foreach($_SESSION['cart'] as $item):
                $qty = isset($item['qty']) ? (int)$item['qty'] : (isset($item['sl']) ? (int)$item['sl'] : 1);
                $price = isset($item['price']) ? (float)$item['price'] : (isset($item['gia']) ? (float)$item['gia'] : 0);
                $name = $item['name'] ?? $item['ten'] ?? '';
                $img = $item['img'] ?? $item['hinh'] ?? 'images/placeholder.jpg';
                $subtotal = $qty * $price;
                $total += $subtotal;
              ?>
                <div class="d-flex align-items-center py-2">
                  <img src="<?= esc($img) ?>" style="width:56px;height:56px;object-fit:cover;border-radius:8px" alt="">
                  <div class="ms-2 flex-grow-1">
                    <div class="small fw-semibold"><?= esc($name) ?></div>
                    <div class="small text-muted"><?= $qty ?> x <?= number_format($price,0,',','.') ?> ₫</div>
                  </div>
                  <div class="ms-2 small"><?= number_format($subtotal,0,',','.') ?> ₫</div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
              <div class="fw-semibold">Tạm tính</div>
              <div class="fw-semibold"><?= number_format($total,0,',','.') ?> ₫</div>
            </div>
            <div class="mt-3 d-grid gap-2">
              <a href="cart.php" class="btn btn-sm btn-outline-secondary">Giỏ hàng</a>
              <a href="checkout.php" class="btn btn-sm btn-primary">Thanh toán</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <button class="btn btn-light d-lg-none ms-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu" aria-controls="mobileMenu">
        <i class="bi bi-list"></i>
      </button>
    </div>
  </div>
</header>

<!-- Mobile offcanvas -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
  <div class="offcanvas-header">
    <h5 id="mobileMenuLabel"><?= esc(site_name($conn)) ?></h5>
    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Đóng"></button>
  </div>
  <div class="offcanvas-body">
    <form action="sanpham.php" method="get" class="mb-3 d-flex">
      <input class="form-control me-2" name="q" placeholder="Tìm sản phẩm..." value="<?= esc($q) ?>">
      <button class="btn btn-dark">Tìm</button>
    </form>
    <ul class="list-unstyled">
      <li class="mb-2"><a href="index.php" class="text-decoration-none">Trang chủ</a></li>
      <li class="mb-2"><a href="sanpham.php" class="text-decoration-none">Sản phẩm</a></li>
      <?php foreach($cats as $c): ?>
        <li class="mb-2 ps-2"><a href="category.php?slug=<?= urlencode($c['slug'] ?? '') ?>" class="text-decoration-none"><?= esc($c['ten']) ?></a></li>
      <?php endforeach; ?>
      <li class="mb-2"><a href="sale.php" class="text-decoration-none">Khuyến mãi</a></li>
      <li class="mb-2"><a href="about.php" class="text-decoration-none">Giới thiệu</a></li>
    </ul>
  </div>
</div>

<!-- PAGE CONTENT -->
<div class="container my-5">
  <div class="d-flex justify-content-between mb-4 align-items-center">
    <h3 class="mb-0">Sản phẩm</h3>
    <a href="index.php" class="btn btn-link">&larr; Trang chủ</a>
  </div>

  <div class="row g-3">
    <div class="col-md-3">
      <div class="card p-3">
        <form method="get" action="sanpham.php">
          <label class="form-label small">Tìm kiếm</label>
          <input name="q" value="<?= esc($q) ?>" class="form-control mb-3" placeholder="Tên sản phẩm...">
          <label class="form-label small">Danh mục</label>
          <select name="cat" class="form-select mb-3">
            <option value="0">Tất cả</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= (int)$c['id_danh_muc'] ?>" <?= $cat === (int)$c['id_danh_muc'] ? 'selected':'' ?>><?= esc($c['ten']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary w-100">Lọc</button>
        </form>
        <hr>
        <div class="small text-muted">Hiển thị <?= count($products) ?> / <?= $total_items ?> sản phẩm</div>
      </div>
    </div>

    <div class="col-md-9">
      <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">
        <?php if (empty($products)): ?>
          <div class="col-12"><div class="alert alert-info">Không tìm thấy sản phẩm.</div></div>
        <?php endif; ?>

        <?php foreach ($products as $p):
          $pid = (int)$p['id_san_pham'];
          $img = $p['img'] ?: 'images/placeholder.jpg';
          $name = $p['ten'];
          $price = (float)$p['gia'];
          $detailUrl = 'sanpham_chitiet.php?id=' . $pid;
        ?>
        <div class="col">
          <div class="card card-product h-100">
            <a href="<?= esc($detailUrl) ?>" class="text-decoration-none text-dark">
              <img src="<?= esc($img) ?>" class="prod-img" alt="<?= esc($name) ?>">
            </a>
            <div class="card-body d-flex flex-column">
              <?php if (!empty($p['danh_muc_ten'])): ?><div class="small text-muted"><?= esc($p['danh_muc_ten']) ?></div><?php endif; ?>
              <a href="<?= esc($detailUrl) ?>" class="text-decoration-none text-dark"><h6><?= esc($name) ?></h6></a>
              <div class="price mb-3"><?= price($price) ?></div>

              <!-- form fallback (will submit to cart.php?action=add) -->
              <form method="post" action="<?= esc(dirname($_SERVER['PHP_SELF']) . '/cart.php?action=add') ?>" class="mt-auto d-grid">
                <input type="hidden" name="id" value="<?= $pid ?>">
                <input type="hidden" name="qty" value="1">
                <input type="hidden" name="back" value="<?= esc($_SERVER['REQUEST_URI']) ?>">
                <button type="submit" class="btn btn-outline-primary add-to-cart-btn" data-id="<?= $pid ?>">
                  <i class="bi bi-cart-plus"></i> Thêm vào giỏ
                </button>
              </form>

            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- pagination -->
      <nav class="mt-4">
        <ul class="pagination">
          <?php
            $start = max(1, $page - 3);
            $end = min($total_pages, $page + 3);
            if ($page > 1) echo '<li class="page-item"><a class="page-link" href="'. buildUrl(['page'=>$page-1]) .'">&laquo;</a></li>';
            else echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
            for ($i=$start;$i<=$end;$i++){
              if ($i==$page) echo '<li class="page-item active"><span class="page-link">'.$i.'</span></li>';
              else echo '<li class="page-item"><a class="page-link" href="'. buildUrl(['page'=>$i]) .'">'.$i.'</a></li>';
            }
            if ($page < $total_pages) echo '<li class="page-item"><a class="page-link" href="'. buildUrl(['page'=>$page+1]) .'">&raquo;</a></li>';
            else echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
          ?>
        </ul>
      </nav>

    </div>
  </div>
</div>

<!-- scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- simple AJAX add-to-cart: sends POST to cart.php?action=add with ajax=1 -->
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.querySelectorAll('.add-to-cart-btn').forEach(function(btn){
    btn.addEventListener('click', async function(e){
      // prevent form submit to use AJAX
      e.preventDefault();
      const id = this.dataset.id;
      const form = this.closest('form');
      if (!form) return;
      const formData = new FormData(form);
      formData.append('ajax', '1');
      try {
        const res = await fetch(form.action, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.success) {
          // quick UI feedback
          const old = btn.innerHTML;
          btn.classList.remove('btn-outline-primary'); btn.classList.add('btn-success');
          btn.innerHTML = '<i class="bi bi-check-lg"></i> Đã thêm';
          // update cart count badge if exists
          const el = document.querySelector('#cart-count-badge');
          if (el && data.cart && typeof data.cart.items_count !== 'undefined') {
            el.textContent = data.cart.items_count;
          }
          setTimeout(()=>{ btn.classList.remove('btn-success'); btn.classList.add('btn-outline-primary'); btn.innerHTML = old; }, 1500);
        } else {
          alert(data.message || 'Không thể thêm vào giỏ.');
          form.submit(); // fallback
        }
      } catch (err) {
        console.error(err);
        // fallback to normal submit
        form.submit();
      }
    });
  });
});
</script>

</body>
</html>
