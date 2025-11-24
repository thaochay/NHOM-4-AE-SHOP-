<?php
// sanpham_chitiet.php - trang chi tiết sản phẩm (giao diện cải tiến)
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

/* fallback helpers nếu inc/helpers.php thiếu */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/**
 * getProductImagesFromDB: trả về mảng các đường dẫn ảnh (ưu tiên la_anh_chinh)
 */
function getProductImagesFromDB($conn, $product_id, $placeholder = 'images/placeholder.jpg') {
    $out = [];
    try {
        $stmt = $conn->prepare("SELECT duong_dan, la_anh_chinh FROM anh_san_pham WHERE id_san_pham = :id ORDER BY la_anh_chinh DESC, thu_tu ASC, id_anh ASC");
        $stmt->execute(['id'=>$product_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }
    if (empty($rows)) {
        return [$placeholder];
    }
    foreach ($rows as $r) {
        $p = trim($r['duong_dan']);
        if ($p === '') continue;
        if (preg_match('#^https?://#i', $p)) {
            $out[] = $p; continue;
        }
        $candidates = [
            ltrim($p, '/'),
            'images/' . ltrim($p, '/'),
            'uploads/' . ltrim($p, '/'),
            'public/' . ltrim($p, '/'),
            'images/products/' . basename($p),
            'images/' . basename($p),
            '../' . ltrim($p, '/')
        ];
        $found = null;
        foreach (array_values(array_unique($candidates)) as $c) {
            if (file_exists(__DIR__ . '/' . $c) && filesize(__DIR__ . '/' . $c) > 0) {
                $found = $c; break;
            }
        }
        if ($found) $out[] = $found;
        else $out[] = ltrim($p, '/');
    }
    $out = array_values(array_unique($out));
    if (empty($out)) return [$placeholder];
    return $out;
}

/* ----- get id param and validate ----- */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Thiếu ID</title></head><body><h2>ID sản phẩm không hợp lệ</h2><p><a href='sanpham.php'>Về danh sách sản phẩm</a></p></body></html>";
    exit;
}

/* fetch product */
$sql = "SELECT sp.*, dm.ten AS danh_muc_ten
        FROM san_pham sp
        LEFT JOIN danh_muc dm ON sp.id_danh_muc = dm.id_danh_muc
        WHERE sp.id_san_pham = :id
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute(['id'=>$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    echo "<!doctype html><html><head><meta charset='utf-8'><title>Không tìm thấy</title></head><body><h2>Không tìm thấy sản phẩm</h2><p><a href='sanpham.php'>Quay về</a></p></body></html>";
    exit;
}

/* images */
$images = getProductImagesFromDB($conn, $id);
$mainImage = $images[0] ?? 'images/placeholder.jpg';

/* cart count for header (simple) */
$cart = $_SESSION['cart'] ?? [];
$cart_count = 0; foreach ($cart as $it) $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1);

/* related products (small, by same category) */
$related = [];
try {
    if (!empty($product['id_danh_muc'])) {
        $rstmt = $conn->prepare("SELECT id_san_pham, ten, gia FROM san_pham WHERE id_danh_muc = :cat AND id_san_pham != :id AND trang_thai = 1 ORDER BY id_san_pham DESC LIMIT 4");
        $rstmt->execute(['cat'=>$product['id_danh_muc'], 'id'=>$id]);
        $related = $rstmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) { $related = []; }

?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title><?= esc($product['ten']) ?> — <?= esc(site_name($conn)) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --brand:#0b7bdc;
      --muted:#6c757d;
      --card:#ffffff;
      --soft:#f5f9ff;
    }
    body { background: linear-gradient(180deg,#fbfdff,#f7fbff); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; color:#102a43; }
    .topbar { background:#fff; border-bottom:1px solid #eef3f8; }
    .logo-mark { width:48px;height:48px;border-radius:10px;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800 }
    .product-hero { display:grid; grid-template-columns: 1fr 420px; gap:28px; align-items:start; margin:36px auto; max-width:1180px; padding:0 16px; }
    @media (max-width:991px){ .product-hero{grid-template-columns:1fr; } .gallery-main{order:0} .info{order:1} }

    /* gallery */
    .gallery-card { background:var(--card); border-radius:14px; padding:18px; border:1px solid #eef6ff; box-shadow:0 8px 30px rgba(11,38,80,0.03); }
    .gallery-main { display:flex; gap:12px; align-items:flex-start; }
    .gallery-left { flex:1; display:flex; flex-direction:column; gap:12px; align-items:center; }
    .main-img { width:100%; border-radius:12px; background:#fff; padding:18px; display:flex; align-items:center; justify-content:center; border:1px solid #eef6ff; max-height:520px; }
    .main-img img { max-width:100%; max-height:460px; object-fit:contain; }
    .thumbs { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; justify-content:center; }
    .thumb { width:76px; height:76px; border-radius:8px; overflow:hidden; border:2px solid transparent; cursor:pointer; box-shadow:0 6px 20px rgba(11,38,80,0.03); }
    .thumb img{ width:100%; height:100%; object-fit:cover; display:block; }

    /* info card */
    .info-card { background:linear-gradient(180deg,#fff,#fbfdff); border-radius:14px; padding:22px; border:1px solid #eef6ff; box-shadow:0 8px 30px rgba(11,38,80,0.03); }
    .product-title { font-size:1.5rem; font-weight:800; margin-bottom:6px; color:#0b2136; }
    .tiny { color:var(--muted); font-size:0.92rem; }
    .price { font-size:1.6rem; font-weight:900; color:var(--brand); }
    .old-price { text-decoration: line-through; color:#9fb0c8; font-weight:600; margin-left:12px; font-size:0.95rem; }

    .stock-badge { padding:8px 10px; border-radius:10px; font-weight:700; font-size:0.92rem; }
    .buy-actions { margin-top:18px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
    .qty { width:110px; }

    .color-swatch { width:34px;height:34px;border-radius:8px;border:2px solid #fff;box-shadow:0 6px 18px rgba(11,38,80,0.06);cursor:pointer;display:inline-block;margin-right:8px; }
    .color-swatch.active { outline:3px solid rgba(11,123,220,0.18); transform:translateY(-2px); }

    .size-btn { padding:6px 10px;border-radius:8px;border:1px solid #e6eefb;background:#fff;cursor:pointer;margin-right:6px;margin-bottom:6px; }
    .size-btn.active { background:var(--brand); color:#fff; border-color:var(--brand); }

    .tabs { margin-top:22px; }
    .tab-pane { padding:14px 6px; background:#fff; border-radius:10px; border:1px solid #eef6ff; }

    /* related */
    .related { margin-top:30px; }
    .related .card { border-radius:12px; border:1px solid #eef6ff; overflow:hidden; transition:transform .16s; }
    .related .card:hover { transform:translateY(-6px); box-shadow:0 16px 40px rgba(11,38,80,0.06); }

    footer.site-footer { margin-top:48px; padding:20px 0; color:#fff; background:linear-gradient(90deg,var(--brand),#0b5ea8); }
  </style>
</head>
<body>

<!-- top nav minimal -->
<header class="topbar">
  <div class="container d-flex align-items-center justify-content-between py-2">
    <a href="index.php" class="d-flex align-items-center gap-3 text-decoration-none">
      <div class="logo-mark">AE</div>
      <div class="d-none d-md-block">
        <div style="font-weight:800;color:#062a3b"><?= esc(site_name($conn)) ?></div>
        <div style="font-size:13px;color:var(--muted)">Thời trang nam cao cấp</div>
      </div>
    </a>
    <div class="d-flex align-items-center gap-3">
      <a href="sanpham.php" class="btn btn-sm btn-outline-secondary">Danh sách sản phẩm</a>
      <a href="cart.php" class="btn btn-sm btn-primary">Giỏ hàng <span class="badge bg-white text-primary"><?= (int)$cart_count ?></span></a>
    </div>
  </div>
</header>

<main class="container">
  <div class="product-hero">

    <!-- LEFT: gallery -->
    <div class="gallery-card gallery-main">
      <div class="gallery-left" style="width:100%">
        <div class="main-img" id="main-img-box">
          <img id="mainImg" src="<?= esc($mainImage) ?>" alt="<?= esc($product['ten']) ?>">
        </div>

        <div class="thumbs" id="thumbs">
          <?php foreach ($images as $i => $p): ?>
            <div class="thumb" data-src="<?= esc($p) ?>">
              <img src="<?= esc($p) ?>" alt="thumb-<?= $i ?>">
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- RIGHT: info -->
    <aside class="info-card info">
      <?php if (!empty($product['danh_muc_ten'])): ?>
        <div class="tiny mb-1"><?= esc($product['danh_muc_ten']) ?></div>
      <?php endif; ?>
      <div class="product-title"><?= esc($product['ten']) ?></div>

      <div class="d-flex align-items-center gap-3 mb-2">
        <div class="price"><?= price($product['gia']) ?></div>
        <?php if (!empty($product['gia_cu']) && $product['gia_cu'] > $product['gia']): ?>
          <div class="old-price"><?= number_format($product['gia_cu'],0,',','.') ?> ₫</div>
          <div class="badge bg-danger text-white" style="padding:6px 8px;border-radius:10px;font-weight:700;">
            -<?= (int)round((($product['gia_cu']-$product['gia'])/$product['gia_cu'])*100) ?>%
          </div>
        <?php endif; ?>
      </div>

      <div class="tiny text-muted mb-3"><?= nl2br(esc(mb_substr(strip_tags($product['mo_ta'] ?? ''),0,220))) ?></div>

      <div class="d-flex align-items-center gap-3 mb-3">
        <div class="stock-badge badge <?= ((int)$product['so_luong']>0)?'bg-success text-white':'bg-secondary text-white' ?>">
          <?= ((int)$product['so_luong']>0)?('Còn ' . (int)$product['so_luong'].' sản phẩm'):'Hết hàng' ?>
        </div>
        <div class="tiny text-muted">Mã: <?= esc($product['ma_san_pham'] ?? '#'.$product['id_san_pham']) ?></div>
      </div>

      <!-- options (dummy) -->
      <div>
        <div class="mb-2"><strong>Màu sắc</strong></div>
        <div id="swatches" class="mb-3">
          <!-- show a few colored swatches (if DB has color info you can replace) -->
          <span class="color-swatch" data-color="#0b7bdc" style="background:#0b7bdc"></span>
          <span class="color-swatch" data-color="#111111" style="background:#111"></span>
          <span class="color-swatch" data-color="#7a6f6f" style="background:#7a6f6f"></span>
        </div>

        <div class="mb-2"><strong>Kích thước</strong></div>
        <div id="sizes" class="mb-3">
          <button type="button" class="size-btn">S</button>
          <button type="button" class="size-btn active">M</button>
          <button type="button" class="size-btn">L</button>
          <button type="button" class="size-btn">XL</button>
        </div>
      </div>

      <div class="buy-actions">
        <form id="addForm" method="post" action="cart.php?action=add" class="d-flex align-items-center gap-2">
          <input type="hidden" name="id" value="<?= (int)$product['id_san_pham'] ?>">
          <input type="number" name="qty" value="1" min="1" class="form-control qty">
          <button id="addBtn" class="btn btn-primary px-4 py-2" type="submit"><i class="bi bi-cart-plus me-2"></i>Thêm vào giỏ</button>
        </form>
        <a href="checkout.php" class="btn btn-outline-success px-4 py-2">Mua ngay</a>
      </div>

      <div class="tabs">
        <ul class="nav nav-tabs" id="detailTab" role="tablist">
          <li class="nav-item" role="presentation"><button class="nav-link active" id="desc-tab" data-bs-toggle="tab" data-bs-target="#desc" type="button">Mô tả</button></li>
          <li class="nav-item" role="presentation"><button class="nav-link" id="spec-tab" data-bs-toggle="tab" data-bs-target="#spec" type="button">Thông số</button></li>
          <li class="nav-item" role="presentation"><button class="nav-link" id="rev-tab" data-bs-toggle="tab" data-bs-target="#rev" type="button">Đánh giá</button></li>
        </ul>
        <div class="tab-content mt-3">
          <div class="tab-pane fade show active tab-pane" id="desc">
            <div class="small text-muted"><?= nl2br(esc($product['mo_ta'] ?? 'Không có mô tả chi tiết.')) ?></div>
          </div>
          <div class="tab-pane fade tab-pane" id="spec">
            <div class="specs small text-muted">
              <div><strong>Loại:</strong> <?= esc($product['danh_muc_ten'] ?? 'N/A') ?></div>
              <div><strong>Nhà cung cấp:</strong> <?= esc($product['id_ncc'] ?? 'N/A') ?></div>
              <div><strong>Số lượng:</strong> <?= (int)$product['so_luong'] ?></div>
              <div><strong>Trạng thái:</strong> <?= ((int)$product['trang_thai']? 'Hiển thị':'Ẩn') ?></div>
            </div>
          </div>
          <div class="tab-pane fade tab-pane" id="rev">
            <div class="small text-muted">Chưa có đánh giá. Hãy là người đánh giá đầu tiên!</div>
          </div>
        </div>
      </div>
    </aside>
  </div>

  <!-- related -->
  <?php if (!empty($related)): ?>
  <section class="related container mt-4">
    <h5 class="mb-3">Sản phẩm liên quan</h5>
    <div class="row g-3">
      <?php foreach ($related as $r): 
        $rimg = getProductImagesFromDB($conn, $r['id_san_pham'])[0] ?? 'images/placeholder.jpg';
      ?>
        <div class="col-6 col-md-3">
          <a href="sanpham_chitiet.php?id=<?= (int)$r['id_san_pham'] ?>" class="text-decoration-none text-dark">
            <div class="card p-2">
              <img src="<?= esc($rimg) ?>" style="height:160px;object-fit:contain;width:100%;background:#fff;border-radius:6px" alt="<?= esc($r['ten']) ?>">
              <div class="p-2">
                <div class="small text-muted mb-1"><?= esc(mb_strimwidth($r['ten'],0,50,'...')) ?></div>
                <div class="fw-semibold"><?= price($r['gia']) ?></div>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</main>

<footer class="site-footer">
  <div class="container text-center text-white">
    <small><?= esc(site_name($conn)) ?> © <?= date('Y') ?> — Hotline: 0123 456 789</small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// thumbnail clicks -> main image
document.querySelectorAll('.thumb').forEach(function(t){
  t.addEventListener('click', function(){
    var src = this.getAttribute('data-src');
    document.getElementById('mainImg').src = src;
    document.querySelectorAll('.thumb').forEach(x=>x.style.borderColor='transparent');
    this.style.borderColor = 'rgba(11,123,220,0.18)';
  });
});

// swatches & sizes interactions
document.querySelectorAll('.color-swatch').forEach(function(s){
  s.addEventListener('click', function(){ document.querySelectorAll('.color-swatch').forEach(x=>x.classList.remove('active')); this.classList.add('active'); });
});
document.querySelectorAll('.size-btn').forEach(function(b){
  b.addEventListener('click', function(){ document.querySelectorAll('.size-btn').forEach(x=>x.classList.remove('active')); this.classList.add('active'); });
});

// AJAX add-to-cart (fallback to normal form submit)
document.getElementById('addForm').addEventListener('submit', async function(e){
  e.preventDefault();
  const f = this;
  const data = new FormData(f);
  data.append('ajax','1');
  try {
    const res = await fetch(f.action, { method:'POST', body:data, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} });
    const json = await res.json();
    if (json.success) {
      const btn = document.getElementById('addBtn');
      btn.classList.remove('btn-primary'); btn.classList.add('btn-success');
      btn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Đã thêm';
      // update cart badge if present
      var badge = document.querySelector('.topbar .badge');
      if (badge && json.cart && json.cart.items_count !== undefined) badge.textContent = json.cart.items_count;
      setTimeout(()=>{ btn.classList.remove('btn-success'); btn.classList.add('btn-primary'); btn.innerHTML = '<i class="bi bi-cart-plus me-2"></i>Thêm vào giỏ'; }, 1400);
    } else {
      alert(json.message || 'Không thể thêm vào giỏ');
      f.submit();
    }
  } catch (err) {
    console.error(err);
    f.submit();
  }
});
</script>
</body>
</html>
