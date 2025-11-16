<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

define('SITE_TITLE','DSQ2 SHOP - Premium');
define('FALLBACK_IMAGE','images/ae.jpg');

if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$heroSlides = [
  FALLBACK_IMAGE,
  'images/ds.jpg',
  'images/pho.jpg',
  'images/bia1.jpg',
  'images/biads.jpg'
];

$categories = $conn->query("
    SELECT id, ten, hinh_anh
    FROM danh_muc
    WHERE trang_thai = 1
      AND ten IN ('Áo Nam','Quần Nam')
")->fetchAll();

$products   = $conn->query("SELECT id, ten, gia, images FROM san_pham WHERE trang_thai=1 ORDER BY id DESC LIMIT 48")->fetchAll();

$sale_products = array_slice($products,0,8);
foreach($sale_products as &$p){ $p['gia_sale'] = (int)($p['gia'] * 0.85); }
unset($p);

$products_visible = array_slice($products,0,24);
$products_more = array_slice($products,24);

include __DIR__ . '/header.php';
?>

<style>
.hero-overlay{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; }
.hero-card{ background: linear-gradient(180deg, rgba(0,0,0,.35), rgba(0,0,0,.5)); color:#fff; padding:28px; border-radius:12px; max-width:760px; box-shadow:0 8px 30px rgba(0,0,0,.35); }
.cat-img{ height:140px; background-position:center; background-size:cover; border-radius:8px; }
.product-card{ transition:transform .18s ease, box-shadow .18s ease; border-radius:12px; overflow:hidden; position:relative; }
.product-card:hover{ transform:translateY(-6px); box-shadow:0 10px 30px rgba(0,0,0,.12); }
.prod-img{ width:100%; height:220px; object-fit:cover; display:block; background:#fff; }
.sale-badge{ position:absolute; left:12px; top:12px; background:#e63946; color:#fff; padding:6px 8px; font-weight:600; border-radius:8px; font-size:.85rem; z-index:3; }
.card-cta { position:absolute; right:12px; bottom:12px; display:flex; gap:8px; z-index:3; }
.card-cta form{ margin:0; }
.section-title{ display:flex; align-items:center; justify-content:space-between; }
.feature-list{ list-style:none; padding:0; margin:0; display:flex; gap:12px; }
.feature-list li{ background:#f8f9fa; padding:8px 12px; border-radius:999px; font-size:.9rem; }
@media(max-width:576px){ .prod-img{ height:160px; } .cat-img{ height:110px; } .card-cta{ right:8px; bottom:8px; } }
</style>

<div class="container-lg mt-4">
  <div class="position-relative rounded-3 overflow-hidden">
    <div id="heroCarousel" class="carousel slide" data-bs-ride="carousel" aria-label="Ảnh nổi bật">
      <div class="carousel-inner rounded-3">
        <?php foreach($heroSlides as $k => $img): ?>
          <div class="carousel-item <?= $k==0?'active':'' ?>">
            <img src="<?= esc($img) ?>" class="d-block w-100" style="height:460px;object-fit:cover;filter:brightness(.65)" loading="lazy" alt="Slide <?= $k+1 ?>">
            <?php if($k===0): ?>
              <div class="hero-overlay">
                <div class="hero-card text-center">
                  <h1 class="mb-2">Chào mừng đến với <span class="fw-bold"><?= esc(SITE_TITLE) ?></span></h1>
                  <p class="mb-3 small">Thời trang trẻ trung • Giá tốt • Giao hàng nhanh toàn quốc</p>
                  <div class="d-flex justify-content-center gap-2">
                    <a href="products.php" class="btn btn-dark btn-lg">Xem sản phẩm</a>
                    <a href="products.php?filter=sale" class="btn btn-outline-light btn-lg">Khuyến mãi</a>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Previous</span>
      </button>
      <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
        <span class="carousel-control-next-icon" aria-hidden="true"></span>
        <span class="visually-hidden">Next</span>
      </button>
    </div>
  </div>
</div>

<div class="container-lg mt-4">
  <ul class="feature-list">
    <li>Giao hàng 48h</li>
    <li>Đổi trả trong 7 ngày</li>
    <li>Hỗ trợ 24/7</li>
    <li>Thanh toán an toàn</li>
  </ul>
</div>

<div class="container-lg mt-5">
  <div class="section-title mb-3">
    <h3 class="fw-bold">Danh mục nổi bật</h3>
    <a href="products.php" class="small text-decoration-none">Xem tất cả →</a>
  </div>
  <div class="row g-3">
    <?php foreach($categories as $cat): $img = $cat['hinh_anh'] ?: FALLBACK_IMAGE; ?>
      <div class="col-6 col-md-3">
        <a href="products.php?danh_muc=<?= $cat['id'] ?>" class="card text-dark text-decoration-none p-2 h-100 border-0 shadow-sm">
          <div class="cat-img" style="background-image:url('<?= esc($img) ?>')"></div>
          <div class="card-body text-center">
            <strong><?= esc($cat['ten']) ?></strong>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="container-lg mt-5">
  <div class="section-title mb-3">
    <h3 class="fw-bold">Sản phẩm khuyến mãi</h3>
    <a href="products.php?filter=sale" class="small text-decoration-none">Xem thêm →</a>
  </div>
  <div class="row g-4">
    <?php foreach($sale_products as $p): $img = first_image($p['images']); ?>
      <div class="col-6 col-md-3">
        <div class="card product-card h-100 border-0 shadow-sm">

          <a href="product_detail.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark">
            <img src="<?= esc($img) ?>" 
                 onerror="this.onerror=null; this.src='<?= esc(FALLBACK_IMAGE) ?>';"
                 class="prod-img" loading="lazy" alt="<?= esc($p['ten']) ?>">
          </a>

          <div class="sale-badge">-<?= round(100*(1 - ($p['gia_sale']/$p['gia']))) ?>%</div>

          <div class="card-body">
            <h6 class="small mb-1 text-truncate" title="<?= esc($p['ten']) ?>">
              <a href="product_detail.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark"><?= esc($p['ten']) ?></a>
            </h6>

            <div class="text-muted text-decoration-line-through small mb-1"><?= price_format($p['gia']) ?></div>
            <div class="fw-bold"><?= price_format($p['gia_sale']) ?></div>
          </div>

          <div class="card-cta">
            <form action="cart_add.php" method="post" class="d-inline">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="qty" value="1">
              <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
              <button class="btn btn-sm btn-outline-dark" type="submit" title="Thêm vào giỏ">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-cart-plus" viewBox="0 0 16 16">
                  <path d="M8 7.5a.5.5 0 0 1 .5.5v1.5H10a.5.5 0 0 1 0 1H8.5V12a.5.5 0 0 1-1 0v-1.5H6a.5.5 0 0 1 0-1h1.5V8a.5.5 0 0 1 .5-.5z"/>
                  <path d="M0 1.5A.5.5 0 0 1 .5 1h1a.5.5 0 0 1 .485.379L2.89 5H14.5a.5.5 0 0 1 .49.598l-1.5 7A.5.5 0 0 1 13 13H4a.5.5 0 0 1-.49-.402L1.61 2H.5a.5.5 0 0 1-.5-.5z"/>
                </svg>
              </button>
            </form>

            <a href="product_detail.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-dark" title="Xem chi tiết">Xem</a>
          </div>

        </div>
      </div>
    <?php endforeach; ?> 
  </div>
</div>

<div class="container-lg mt-5">
  <div class="section-title mb-3">
    <h3 class="fw-bold">Sản phẩm mới</h3>
    <div>
      <a href="products.php?sort=new" class="small text-decoration-none me-3">Mới nhất</a>
      <a href="products.php?sort=popular" class="small text-decoration-none">Phổ biến</a>
    </div>
  </div>

  <div class="row g-4" id="productsGrid">
    <?php foreach($products_visible as $p): $img = first_image($p['images']); ?>
      <div class="col-6 col-md-3">
        <div class="card product-card h-100 border-0 shadow-sm">
          <a href="product_detail.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark">
            <img src="<?= esc($img) ?>" onerror="this.onerror=null; this.src='<?= esc(FALLBACK_IMAGE) ?>';" class="prod-img" loading="lazy" alt="<?= esc($p['ten']) ?>">
          </a>
          <div class="card-body">
            <h6 class="small mb-1 text-truncate" title="<?= esc($p['ten']) ?>">
              <a href="product_detail.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark"><?= esc($p['ten']) ?></a>
            </h6>
            <div class="fw-bold"><?= price_format($p['gia']) ?></div>
          </div>

          <div class="card-cta">
            <form action="cart_add.php" method="post" class="d-inline">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="qty" value="1">
              <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
              <button class="btn btn-sm btn-outline-dark" type="submit" title="Thêm vào giỏ">
                + Thêm
              </button>
            </form>
            <a href="product_detail.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-dark">Xem</a>
          </div>

        </div>
      </div>
    <?php endforeach; ?> 
  </div>

  <?php if(count($products_more) > 0): ?>
    <div class="text-center mt-4">
      <button id="loadMoreBtn" class="btn btn-outline-dark">Xem thêm sản phẩm</button>
    </div>
  <?php endif; ?>
</div>

<!-- Hidden additional products (revealed by JS) -->
<div class="container-lg mt-4 d-none" id="moreProductsSection">
  <h3 class="fw-bold mb-3">Bạn có thể quan tâm</h3>
  <div class="row g-4">
    <?php foreach($products_more as $p): $img = first_image($p['images']); ?>
      <div class="col-6 col-md-3">
        <div class="card product-card h-100 border-0 shadow-sm">
          <a href="product_detail.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark">
            <img src="<?= esc($img) ?>" onerror="this.onerror=null; this.src='<?= esc(FALLBACK_IMAGE) ?>';" class="prod-img" loading="lazy" alt="<?= esc($p['ten']) ?>">
          </a>
          <div class="card-body">
            <h6 class="small mb-1 text-truncate" title="<?= esc($p['ten']) ?>">
              <a href="product_detail.php?id=<?= $p['id'] ?>" class="text-decoration-none text-dark"><?= esc($p['ten']) ?></a>
            </h6>
            <div class="fw-bold"><?= price_format($p['gia']) ?></div>
          </div>

          <div class="card-cta">
            <form action="cart_add.php" method="post" class="d-inline">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="qty" value="1">
              <input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
              <button class="btn btn-sm btn-outline-dark" type="submit" title="Thêm vào giỏ">+ Thêm</button>
            </form>
            <a href="product_detail.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-dark">Xem</a>
          </div>

        </div>
      </div>
    <?php endforeach; ?> 
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const btn = document.getElementById('loadMoreBtn');
  const more = document.getElementById('moreProductsSection');
  if(btn && more){
    btn.addEventListener('click', function(){
      more.classList.remove('d-none');
      btn.closest('.text-center').remove();
      setTimeout(()=>{
        more.scrollIntoView({behavior:'smooth', block:'start'});
      }, 120);
    });
  }
});
</script>
