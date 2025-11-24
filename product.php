<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/helpers.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM san_pham WHERE id_san_pham = :id LIMIT 1");
$stmt->execute(['id'=>$id]); $p = $stmt->fetch();
if (!$p) { header('Location: index.php'); exit; }

$imgs = $conn->prepare("SELECT * FROM anh_san_pham WHERE id_san_pham = :id ORDER BY la_anh_chinh DESC, thu_tu ASC");
$imgs->execute(['id'=>$id]); $imgs = $imgs->fetchAll();
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><title><?= esc($p['ten']) ?> - <?= esc(site_name($conn)) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/css/style.css" rel="stylesheet"></head><body>
<div class="container my-4">
  <a href="index.php" class="btn btn-link">&larr; Trang chủ</a>
  <div class="row">
    <div class="col-md-5">
      <?php if($imgs): ?>
        <img src="<?= esc($imgs[0]['duong_dan']) ?>" class="img-fluid" style="max-height:480px;object-fit:contain;">
      <?php else: ?>
        <img src="images/placeholder.jpg" class="img-fluid">
      <?php endif; ?>
    </div>
    <div class="col-md-7">
      <h3><?= esc($p['ten']) ?></h3>
      <p class="product-price"><?= price($p['gia']) ?></p>
      <p><?= nl2br(esc($p['mo_ta'])) ?></p>

      <form method="post" action="cart.php">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="id" value="<?= $p['id_san_pham'] ?>">
        <div class="mb-3" style="max-width:160px">
          <label>Số lượng</label>
          <input type="number" name="qty" class="form-control" value="1" min="1" max="<?= (int)$p['so_luong'] ?>">
        </div>
        <button class="btn btn-success">Thêm vào giỏ</button>
      </form>
    </div>
  </div>
</div>
</body></html>
