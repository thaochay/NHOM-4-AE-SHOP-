<?php
// sanpham_chitiet.php - full file with size click blue flash + related products with promo badge
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

/* fallback helpers */
if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

/* helper: get images */
function getProductImagesFromDB($conn, $product_id, $placeholder = 'images/placeholder.jpg') {
    $out = [];
    try {
        $stmt = $conn->prepare("SELECT duong_dan, la_anh_chinh FROM anh_san_pham WHERE id_san_pham = :id ORDER BY la_anh_chinh DESC, thu_tu ASC, id_anh ASC");
        $stmt->execute(['id'=>$product_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $rows = []; }
    if (empty($rows)) return [$placeholder];
    foreach ($rows as $r) {
        $p = trim($r['duong_dan']);
        if ($p === '') continue;
        if (preg_match('#^https?://#i', $p)) { $out[] = $p; continue; }
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
            if (file_exists(__DIR__ . '/' . $c) && @filesize(__DIR__ . '/' . $c) > 0) { $found = $c; break; }
        }
        $out[] = $found ?: ltrim($p, '/');
    }
    $out = array_values(array_unique($out));
    return empty($out) ? [$placeholder] : $out;
}

/* get product id */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo "ID sản phẩm không hợp lệ"; exit;
}

/* fetch product */
$sql = "SELECT sp.*, dm.ten AS danh_muc_ten
        FROM san_pham sp
        LEFT JOIN danh_muc dm ON sp.id_danh_muc = dm.id_danh_muc
        WHERE sp.id_san_pham = :id LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute(['id'=>$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$product) { http_response_code(404); echo "Không tìm thấy sản phẩm"; exit; }

/* images */
$images = getProductImagesFromDB($conn, $id);
$mainImage = $images[0] ?? 'images/placeholder.jpg';

/* cart count */
$cart = $_SESSION['cart'] ?? [];
$cart_count = 0;
foreach ($cart as $it) { $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1); }

/* stock + sku */
$in_stock = (isset($product['so_luong']) ? ((int)$product['so_luong'] > 0) : true);
$sku = !empty($product['ma_san_pham']) ? $product['ma_san_pham'] : ('#' . ($product['id_san_pham'] ?? $id));

/* sample sizes (you can change to populate from DB) */
$sizes = ['S','M','L','XL'];

/* related products (same category) - include promotion detect */
$related = [];
try {
    if (!empty($product['id_danh_muc'])) {
        $rstmt = $conn->prepare("SELECT id_san_pham, ten, gia, gia_cu FROM san_pham WHERE id_danh_muc = :cat AND id_san_pham != :id AND trang_thai = 1 ORDER BY id_san_pham DESC LIMIT 8");
        $rstmt->execute(['cat'=>$product['id_danh_muc'], 'id'=>$id]);
        $related = $rstmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $related = [];
}
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title><?= esc($product['ten']) ?> — <?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --accent: #ef4444; /* red */
      --accent-dark: #d02424;
      --muted: #6b7280;
      --card: #fff;
      --soft: #fbfdff;
      --border: #eef6ff;
      --blue-flash: rgba(11,123,220,0.14);
      --blue-solid: #0b7bdc;
    }
    body{ background: linear-gradient(180deg,#fbfdff,#f7fbff); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial; color:#0f1724; }
    .container-main { max-width:1180px; margin:28px auto; padding:0 12px; display:grid; grid-template-columns: 1fr 480px; gap:28px; align-items:start; }
    @media(max-width:992px){ .container-main{ grid-template-columns:1fr; } }

    /* gallery */
    .gallery { background:var(--card); border-radius:12px; padding:18px; border:1px solid var(--border); box-shadow:0 10px 30px rgba(2,6,23,0.04); }
    .main-image { border-radius:12px; padding:20px; background:#fff; border:1px solid #f1f7ff; display:flex; align-items:center; justify-content:center; cursor:pointer; max-height:540px; }
    .main-image img{ max-width:100%; max-height:480px; object-fit:contain; display:block; }

    .thumbs { display:flex; gap:10px; margin-top:12px; flex-wrap:wrap; }
    .thumb { width:72px; height:72px; border-radius:8px; overflow:hidden; border:2px solid transparent; background:#fff; box-shadow:0 8px 20px rgba(2,6,23,0.04); cursor:pointer; display:flex; align-items:center; justify-content:center; }
    .thumb img{ width:100%; height:100%; object-fit:cover; }

    /* info */
    .info-card { background:linear-gradient(180deg,#fff,#fbfdff); border-radius:12px; padding:22px; border:1px solid var(--border); box-shadow:0 10px 30px rgba(2,6,23,0.04); }
    .title { font-weight:800; font-size:1.45rem; color:#071427; margin-bottom:6px; }
    .meta { color:var(--muted); font-size:0.95rem; margin-bottom:8px; display:flex; gap:12px; align-items:center; flex-wrap:wrap; }

    .price-box { border:1px solid #f1f5f9; padding:12px 16px; border-radius:12px; text-align:right; }
    .price { font-size:1.3rem; font-weight:900; color:var(--accent); }
    .sku { color:#6b7280; font-size:0.95rem; margin-bottom:6px; }

    /* size pills */
    .sizes { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
    .size-pill { position:relative; padding:10px 14px; border-radius:10px; background:#fff; border:1px solid #eef6ff; box-shadow:0 6px 18px rgba(2,6,23,0.03); font-weight:800; cursor:pointer; min-width:56px; text-align:center; transition: transform .12s ease, box-shadow .12s ease; }
    .size-pill:hover{ transform: translateY(-4px); }
    .size-pill.disabled{ opacity:.36; pointer-events:none; }
    .size-pill.selected{ box-shadow: 0 18px 40px rgba(239,68,68,0.12); transform: translateY(-6px); }
    .size-pill .tick { position:absolute; right:-6px; top:-6px; background:var(--accent); color:#fff; width:20px; height:20px; display:flex; align-items:center; justify-content:center; font-size:12px; border-radius:4px; font-weight:800; box-shadow:0 10px 20px rgba(239,68,68,0.16); display:none; }
    .size-pill.selected .tick{ display:flex; }

    /* blue flash (temporary) */
    .size-pill.flash {
      animation: sizeFlash .42s ease-in-out;
    }
    @keyframes sizeFlash {
      0% { box-shadow: 0 0 0 0 rgba(11,123,220,0.0); border-color: #eef6ff; transform: translateY(0); }
      20% { box-shadow: 0 6px 18px rgba(11,123,220,0.10); border-color: var(--blue-solid); transform: translateY(-3px); }
      60% { box-shadow: 0 12px 28px rgba(11,123,220,0.12); transform: translateY(-6px); }
      100% { box-shadow: 0 18px 40px rgba(11,123,220,0.08); transform: translateY(-6px); }
    }

    /* qty */
    .qty { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
    .qty .btn { width:44px; height:44px; border-radius:8px; background:#fff; border:1px solid #eef6ff; display:flex; align-items:center; justify-content:center; font-weight:800; cursor:pointer; }
    .qty input { width:90px; text-align:center; border:1px solid #eef6ff; border-radius:8px; padding:8px; font-weight:700; }

    /* add button big red */
    .btn-add { background: linear-gradient(180deg,var(--accent),var(--accent-dark)); color:#fff; border:none; padding:14px 18px; border-radius:10px; font-weight:900; font-size:1.02rem; box-shadow:0 18px 40px rgba(220,38,38,0.18); width:100%; }
    .btn-buy { background:#fff; border:1px solid #eef6ff; color:#0b2136; padding:12px 16px; border-radius:10px; font-weight:800; width:100%; margin-top:10px; }

    .small-muted { color:var(--muted); font-size:0.92rem; }

    /* related */
    .related { margin-top:28px; }
    .related-grid { display:grid; grid-template-columns: repeat(4,1fr); gap:16px; }
    @media(max-width:1199px){ .related-grid{ grid-template-columns: repeat(3,1fr); } }
    @media(max-width:767px){ .related-grid{ grid-template-columns: repeat(2,1fr); } }
    .prod-card { background:#fff; border-radius:12px; padding:10px; border:1px solid #eef6ff; display:flex; flex-direction:column; gap:8px; position:relative; overflow:hidden; }
    .prod-card img { height:160px; object-fit:contain; background:#fff; border-radius:8px; }

    .promo-badge { position:absolute; left:12px; top:12px; background:var(--accent); color:#fff; padding:6px 10px; border-radius:999px; font-weight:800; font-size:0.85rem; }

    footer { margin-top:36px; text-align:center; color:#94a3b8; padding:28px 0; }
  </style>
</head>
<body>

<div class="container-main">
  <!-- LEFT: gallery -->
  <div class="gallery">
    <div class="main-image" id="mainImageBox" title="Click để phóng to">
      <img id="mainImg" src="<?= esc($mainImage) ?>" alt="<?= esc($product['ten']) ?>">
    </div>

    <div class="thumbs mt-3" id="thumbs">
      <?php foreach ($images as $i => $p): ?>
        <div class="thumb" data-src="<?= esc($p) ?>">
          <img src="<?= esc($p) ?>" alt="thumb-<?= $i ?>">
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Related area also shows under gallery for visual balance -->
    <div class="related mt-4">
      <h5>Sản phẩm liên quan</h5>
      <?php if (empty($related)): ?>
        <div class="small-muted">Không có sản phẩm liên quan.</div>
      <?php else: ?>
        <div class="related-grid mt-2">
          <?php foreach ($related as $r):
            $rimg = getProductImagesFromDB($conn, $r['id_san_pham'])[0] ?? 'images/placeholder.jpg';
            $hasPromo = (!empty($r['gia_cu']) && $r['gia_cu'] > $r['gia']);
            $discountPct = $hasPromo ? (int) round((($r['gia_cu'] - $r['gia']) / $r['gia_cu']) * 100) : 0;
          ?>
            <a href="sanpham_chitiet.php?id=<?= (int)$r['id_san_pham'] ?>" class="text-decoration-none text-dark">
              <div class="prod-card">
                <?php if ($hasPromo): ?><div class="promo-badge">-<?= $discountPct ?>%</div><?php endif; ?>
                <img src="<?= esc($rimg) ?>" alt="<?= esc($r['ten']) ?>">
                <div class="small-muted" style="min-height:36px"><?= esc(mb_strimwidth($r['ten'],0,60,'...')) ?></div>
                <div style="display:flex;justify-content:space-between;align-items:center">
                  <div class="fw-bold"><?= price($r['gia']) ?></div>
                  <?php if ($hasPromo): ?><div class="small-muted" style="text-decoration:line-through"><?= price($r['gia_cu']) ?></div><?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- RIGHT: info -->
  <div class="info">
    <div class="info-card">
      <?php if (!empty($product['danh_muc_ten'])): ?>
        <div class="small-muted mb-1"><?= esc($product['danh_muc_ten']) ?></div>
      <?php endif; ?>

      <div class="title"><?= esc($product['ten']) ?></div>

      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <div class="sku"><strong>Mã sản phẩm:</strong> <?= esc($sku) ?></div>
          <div class="small-muted">
            <strong>Tình trạng:</strong>
            <span class="status" style="color: <?= $in_stock ? '#16a34a' : 'var(--accent)'; ?>;">
              <?= $in_stock ? 'Còn hàng' : 'Hết hàng' ?>
            </span>
          </div>
        </div>

        <div style="min-width:160px;">
          <div class="price-box">
            <div class="small-muted">Giá:</div>
            <div class="price"><?= price($product['gia']) ?></div>
            <?php if (!empty($product['gia_cu']) && $product['gia_cu'] > $product['gia']): ?>
              <div class="small-muted" style="text-decoration:line-through; font-size:0.9rem;"><?= price($product['gia_cu']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="small-muted mb-3"><?= nl2br(esc(mb_substr(strip_tags($product['mo_ta'] ?? ''),0,240))) ?></div>

      <!-- sizes -->
      <div class="mb-2"><strong>Kích thước:</strong></div>
      <div id="sizesBox" class="sizes mb-3">
        <?php foreach ($sizes as $s): ?>
          <div class="size-pill <?= ($s === 'M' ? 'selected' : '') ?>" data-size="<?= esc($s) ?>">
            <span class="label"><?= esc($s) ?></span>
            <span class="tick">✓</span>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- qty + delivery -->
      <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="qty d-flex align-items-center">
          <button type="button" class="btn" id="decBtn">−</button>
          <input type="number" id="qtyInput" value="1" min="1">
          <button type="button" class="btn" id="incBtn">+</button>
        </div>
        <div class="small-muted">Giao hàng 1-3 ngày • Đổi trả 7 ngày</div>
      </div>

      <!-- add to cart + buy now -->
      <div>
        <form id="addForm" method="post" action="cart.php?action=add" class="mb-2">
          <input type="hidden" name="id" value="<?= (int)$product['id_san_pham'] ?>">
          <input type="hidden" name="size" id="formSize" value="<?= esc(in_array('M',$sizes) ? 'M' : '') ?>">
          <input type="hidden" name="qty" id="formQty" value="1">
          <input type="hidden" name="ajax" value="1">
          <div class="d-grid">
            <button id="addBtn" class="btn-add" type="submit">
              <i class="bi bi-cart-plus me-2"></i> THÊM VÀO GIỎ
            </button>
          </div>
        </form>

        <div class="d-grid">
          <a id="buyNow" href="checkout.php" class="btn-buy text-center">Mua ngay</a>
        </div>
      </div>

      <div class="tabs mt-4">
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-desc" type="button">Mô tả</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-spec" type="button">Thông số</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rev" type="button">Đánh giá</button></li>
        </ul>
        <div class="tab-content mt-3">
          <div id="tab-desc" class="tab-pane fade show active">
            <div class="tab-pane-inner small-muted"><?= nl2br(esc($product['mo_ta'] ?? 'Không có mô tả chi tiết.')) ?></div>
          </div>
          <div id="tab-spec" class="tab-pane fade">
            <div class="small-muted">
              <div><strong>Danh mục:</strong> <?= esc($product['danh_muc_ten'] ?? '-') ?></div>
              <div><strong>Số lượng kho:</strong> <?= (int)($product['so_luong'] ?? 0) ?></div>
            </div>
          </div>
          <div id="tab-rev" class="tab-pane fade">
            <div class="small-muted">Chưa có đánh giá.</div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<footer>
  <div class="small-muted"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?> • Hotline: 0123 456 789</div>
</footer>

<!-- Simple zoom modal (reused) -->
<div id="zoomBackdrop" style="display:none;position:fixed;inset:0;background:rgba(6,10,15,0.6);align-items:center;justify-content:center;z-index:12000;">
  <div style="width:90vw;max-width:1100px;height:80vh;display:flex;align-items:center;justify-content:center;">
    <img id="zoomImg" src="<?= esc($mainImage) ?>" style="max-width:none;will-change:transform;cursor:grab;">
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Thumbnail -> main image
  document.querySelectorAll('.thumb').forEach(function(el){
    el.addEventListener('click', function(){
      var src = this.getAttribute('data-src');
      document.getElementById('mainImg').src = src;
      document.getElementById('zoomImg').src = src;
      document.querySelectorAll('.thumb').forEach(t=>t.style.borderColor='transparent');
      this.style.borderColor = 'rgba(11,123,220,0.12)';
    });
  });

  // Size pill selection with blue flash effect
  function flashAndSelect(pill) {
    // Add flash class to produce quick blue animation then mark selected
    pill.classList.add('flash');
    // ensure we still toggle selected state after short delay to let flash show
    setTimeout(function(){
      document.querySelectorAll('.size-pill').forEach(x=>x.classList.remove('selected'));
      pill.classList.add('selected');
      // update hidden form input
      var s = pill.dataset.size || '';
      var input = document.getElementById('formSize');
      if (input) input.value = s;
      // remove flash after animation ends
      setTimeout(function(){ pill.classList.remove('flash'); }, 420);
    }, 60);
  }

  document.querySelectorAll('.size-pill').forEach(function(p){
    p.addEventListener('click', function(){
      if (this.classList.contains('disabled')) return;
      flashAndSelect(this);
    });
  });

  // qty controls
  var dec = document.getElementById('decBtn'), inc = document.getElementById('incBtn'), qtyInput = document.getElementById('qtyInput'), formQty = document.getElementById('formQty');
  dec.addEventListener('click', function(){ qtyInput.value = Math.max(1, (parseInt(qtyInput.value)||1)-1); formQty.value = qtyInput.value; });
  inc.addEventListener('click', function(){ qtyInput.value = Math.max(1, (parseInt(qtyInput.value)||1)+1); formQty.value = qtyInput.value; });
  qtyInput.addEventListener('input', function(){ formQty.value = Math.max(1, parseInt(this.value||1)); });

  // AJAX add to cart
  var addForm = document.getElementById('addForm');
  addForm.addEventListener('submit', function(e){
    e.preventDefault();
    var form = this;
    var fd = new FormData(form);
    fd.set('qty', document.getElementById('qtyInput').value || 1);
    var btn = document.getElementById('addBtn');
    btn.disabled = true;
    var oldHTML = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Đang thêm...';

    fetch(form.action, { method:'POST', body: fd, credentials:'same-origin' })
      .then(r => r.json ? r.json() : r.text())
      .then(res => {
        btn.disabled = false;
        btn.innerHTML = oldHTML;
        var json = res;
        if (typeof res === 'string') {
          try { json = JSON.parse(res); } catch(e){ json = null; }
        }
        if (json && json.success) {
          btn.innerHTML = '<i class="bi bi-cart-check me-2"></i> ĐÃ THÊM VÀO GIỎ';
          // update header badge if exists
          var topBadge = document.querySelector('.badge');
          if (topBadge && json.cart && typeof json.cart.items_count !== 'undefined') topBadge.textContent = json.cart.items_count;
          setTimeout(function(){ btn.innerHTML = oldHTML; }, 1400);
        } else {
          alert((json && json.message) ? json.message : 'Lỗi khi thêm vào giỏ hàng');
        }
      }).catch(err=>{
        btn.disabled = false;
        btn.innerHTML = oldHTML;
        console.error(err);
        alert('Lỗi kết nối, vui lòng thử lại.');
      });
  });

  // Zoom: click main image opens simple zoom backdrop
  var mainImg = document.getElementById('mainImg'), zoomBackdrop = document.getElementById('zoomBackdrop'), zoomImg = document.getElementById('zoomImg');
  mainImg.addEventListener('click', function(){ openZoom(this.src); });
  if (zoomBackdrop) zoomBackdrop.addEventListener('click', function(e){ if (e.target === zoomBackdrop) closeZoom(); });

  var scale = 1, tx = 0, ty = 0, isDrag=false, sx=0, sy=0;
  function openZoom(src) {
    zoomImg.src = src || zoomImg.src;
    scale = 1; tx = 0; ty = 0; applyTransform();
    zoomBackdrop.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
  function closeZoom() {
    zoomBackdrop.style.display = 'none';
    document.body.style.overflow = '';
    scale=1; tx=0; ty=0; applyTransform();
  }
  function applyTransform(){ zoomImg.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')'; }

  // drag to pan when zoomed
  zoomImg.addEventListener('mousedown', function(e){ if (scale<=1) return; isDrag=true; sx=e.clientX; sy=e.clientY; zoomImg.style.cursor='grabbing'; });
  window.addEventListener('mousemove', function(e){ if(!isDrag) return; var dx = e.clientX - sx; var dy = e.clientY - sy; sx = e.clientX; sy = e.clientY; tx += dx; ty += dy; applyTransform(); });
  window.addEventListener('mouseup', function(){ if(isDrag){ isDrag=false; zoomImg.style.cursor='grab'; } });

  // wheel zoom inside zoomBackdrop
  if (document.querySelector('.zoom-card')) {
    document.querySelector('.zoom-card').addEventListener('wheel', function(e){ e.preventDefault(); var delta = e.deltaY < 0 ? 0.12 : -0.12; scale = Math.min(4, Math.max(1, +(scale+delta).toFixed(2))); applyTransform(); }, { passive:false });
  }

  // set default selected size into hidden input on load
  (function(){
    var sel = document.querySelector('.size-pill.selected');
    if (sel) document.getElementById('formSize').value = sel.dataset.size || '';
    document.getElementById('formQty').value = document.getElementById('qtyInput').value || 1;
  })();
</script>
</body>
</html>
