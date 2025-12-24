<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

if (empty($_SESSION['user']['id_nguoi_dung'])) {
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user']['id_nguoi_dung'];

if (!function_exists('esc')) {
    function esc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('price')) {
    function price($v){ return number_format((float)$v,0,',','.') . ' ₫'; }
}

function getProductImage($conn, $pid){
    $stmt = $conn->prepare("
        SELECT duong_dan
        FROM anh_san_pham
        WHERE id_san_pham = ?
        ORDER BY la_anh_chinh DESC, thu_tu ASC
        LIMIT 1
    ");
    $stmt->execute([$pid]);
    return $stmt->fetchColumn() ?: 'images/placeholder.jpg';
}

/* ===== LOAD WISHLIST ===== */
$stmt = $conn->prepare("
    SELECT sp.id_san_pham, sp.ten, sp.gia, sp.mo_ta
    FROM wishlist w
    JOIN san_pham sp ON sp.id_san_pham = w.id_san_pham
    WHERE w.id_nguoi_dung = ?
    ORDER BY w.created_at DESC
");
$stmt->execute([$userId]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Yêu thích</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>

<?php require __DIR__.'/inc/header.php'; ?>

<div class="container my-4">
  <h4 class="mb-3">❤️ Sản phẩm yêu thích</h4>

  <?php if (!$products): ?>
    <div class="alert alert-info">
      Bạn chưa có sản phẩm yêu thích nào.
      <a href="sanpham.php" class="alert-link">Xem sản phẩm</a>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <?php foreach($products as $p):
      $pid = (int)$p['id_san_pham'];
      $img = getProductImage($conn, $pid);
    ?>
    <div class="col-6 col-md-4 col-lg-3">
      <div class="card h-100 shadow-sm">
        <img src="<?= esc($img) ?>" class="card-img-top p-3" style="height:180px;object-fit:contain">

        <div class="card-body d-flex flex-column">
          <h6 class="fw-bold"><?= esc($p['ten']) ?></h6>
          <div class="text-danger fw-bold mb-2"><?= price($p['gia']) ?></div>

          <div class="mt-auto d-flex gap-2">
            <!-- ADD TO CART (AJAX – CHUẨN cart.php) -->
            <form method="post"
                  action="cart.php?action=add"
                  onsubmit="return ajaxAddFromWishlist(this)">
              <input type="hidden" name="id" value="<?= $pid ?>">
              <input type="hidden" name="qty" value="1">
              <input type="hidden" name="ajax" value="1">
              <button class="btn btn-sm btn-success">
                <i class="bi bi-cart-plus"></i>
              </button>
            </form>

            <a href="sanpham_chitiet.php?id=<?= $pid ?>"
               class="btn btn-sm btn-outline-secondary">
              Xem
            </a>

            <button class="btn btn-sm btn-outline-danger"
                    onclick="removeWishlist(<?= $pid ?>, this)">
              <i class="bi bi-heart-fill"></i>
            </button>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
async function ajaxAddFromWishlist(form){
  const fd = new FormData(form);

  try {
    const res = await fetch(form.action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });
    const json = await res.json();

    if (json.success && json.cart) {
      const badge = document.getElementById('cartBadge');
      if (badge) badge.textContent = json.cart.items_count;
      alert('✅ Đã thêm vào giỏ hàng');
    }
  } catch (e) {
    alert('Lỗi kết nối');
  }
  return false;
}

async function removeWishlist(pid, btn){
  if (!confirm('Bỏ khỏi yêu thích?')) return;

  const fd = new FormData();
  fd.append('id', pid);
  fd.append('action', 'remove');

  const res = await fetch('wishlist_ajax.php', {
    method: 'POST',
    body: fd,
    credentials: 'same-origin'
  });
  const json = await res.json();

  if (json.ok) {
    btn.closest('.col-6').remove();
  }
}
</script>

</body>
</html>
