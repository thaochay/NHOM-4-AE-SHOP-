<?php
session_start();
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/cart_functions.php';
include __DIR__ . '/header.php';

$items = cart_get_items();
$total = cart_total_money();
?>
<div class="container-lg my-4">
  <h3 class="mb-3">Giỏ hàng của bạn</h3>

  <?php if (empty($items)): ?>
    <div class="alert alert-info">Giỏ hàng trống. <a href="products.php">Mua sản phẩm ngay</a></div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th width="80">Ảnh</th>
            <th>Sản phẩm</th>
            <th width="120">Đơn giá</th>
            <th width="150">Số lượng</th>
            <th width="140">Thành tiền</th>
            <th width="100"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $key => $row): 
             $price = isset($row['price']) ? (float)$row['price'] : 0;
             $qty = isset($row['qty']) ? (int)$row['qty'] : 0;
             $line = $price * $qty;
          ?>
            <tr data-key="<?= esc($key) ?>">
              <td>
                <?php if (!empty($row['image'])): ?>
                  <img src="<?= esc($row['image']) ?>" alt="" style="height:60px;object-fit:cover;border-radius:6px">
                <?php else: ?>
                  <div style="height:60px;width:60px;background:#f6f6f6;border-radius:6px"></div>
                <?php endif; ?>
              </td>

              <td>
                <div class="fw-semibold"><?= esc($row['name'] ?? '(Sản phẩm)') ?></div>
                <?php if (!empty($row['options'])): ?>
                  <div class="small text-muted">
                    <?php foreach($row['options'] as $k=>$v): ?>
                      <?= esc($k) ?>: <strong><?= esc($v) ?></strong>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>

              <td><?= price_format($price) ?></td>

              <td>
                <div class="input-group input-group-sm" style="width:140px">
                  <button class="btn btn-outline-secondary btn-decr" data-key="<?= esc($key) ?>" type="button">−</button>
                  <input class="form-control qty-input text-center" data-key="<?= esc($key) ?>" type="number" min="1" value="<?= $qty ?>">
                  <button class="btn btn-outline-secondary btn-incr" data-key="<?= esc($key) ?>" type="button">+</button>
                </div>
              </td>

              <td class="row-total"><?= price_format($line) ?></td>

              <td>
                <button class="btn btn-sm btn-danger btn-remove" data-key="<?= esc($key) ?>">Xóa</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between align-items-center">
      <div>
        <button id="clearCartBtn" class="btn btn-outline-danger btn-sm">Xóa toàn bộ</button>
      </div>
      <div class="text-end">
        <div class="mb-2">Tổng tiền: <strong id="cartTotal"><?= price_format($total) ?></strong></div>
        <a href="checkout.php" class="btn btn-primary">Tiến hành thanh toán</a>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
  function postAction(data){
    return fetch('inc/cart_actions.php', {
      method: 'POST',
      body: new URLSearchParams(data)
    }).then(r => r.json());
  }

  function refreshTotals(total){
    if (total !== undefined) {
      const el = document.getElementById('cartTotal');
      if (el) el.innerText = new Intl.NumberFormat('vi-VN', { style:'currency', currency:'VND', maximumFractionDigits:0 }).format(total);
    }
  }

  // increment / decrement
  document.querySelectorAll('.btn-incr').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-key');
      const input = document.querySelector('.qty-input[data-key="'+key+'"]');
      const v = Math.max(1, (parseInt(input.value || '1',10) + 1));
      input.value = v;
      postAction({ action:'update', product_key: key, qty: v }).then(j => {
        if (j.ok) location.reload();
        else alert(j.msg || 'Lỗi');
      });
    });
  });

  document.querySelectorAll('.btn-decr').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-key');
      const input = document.querySelector('.qty-input[data-key="'+key+'"]');
      const v = Math.max(1, (parseInt(input.value || '1',10) - 1));
      input.value = v;
      postAction({ action:'update', product_key: key, qty: v }).then(j => {
        if (j.ok) location.reload();
        else alert(j.msg || 'Lỗi');
      });
    });
  });

  document.querySelectorAll('.qty-input').forEach(input => {
    input.addEventListener('change', () => {
      const key = input.getAttribute('data-key');
      let v = Math.max(1, parseInt(input.value || '1',10));
      input.value = v;
      postAction({ action:'update', product_key: key, qty: v }).then(j => {
        if (j.ok) location.reload();
        else alert(j.msg || 'Lỗi');
      });
    });
  });

  document.querySelectorAll('.btn-remove').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-key');
      if (!confirm('Xóa sản phẩm này?')) return;
      postAction({ action:'remove', product_key: key }).then(j => {
        if (j.ok) location.reload();
        else alert(j.msg || 'Lỗi');
      });
    });
  });

  document.getElementById('clearCartBtn')?.addEventListener('click', () => {
    if (!confirm('Xóa toàn bộ giỏ hàng?')) return;
    postAction({ action:'clear' }).then(j => {
      if (j.ok) location.reload();
      else alert(j.msg || 'Lỗi');
    });
  });

});
</script>
