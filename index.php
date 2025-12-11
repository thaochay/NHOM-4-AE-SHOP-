<?php
// index.php (full file - danh mục nổi bật lấy ảnh trực tiếp từ /images/products/bomber/)
// Modified: mini-cart dropdown removed — clicking cart icon now goes straight to cart.php
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

/* get product image helper */
function getProductImage($conn, $product_id) {
    $placeholder = 'images/placeholder.jpg';
    try {
        $stmt = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id AND la_anh_chinh = 1 LIMIT 1");
        $stmt->execute([':id' => $product_id]);
        $path = $stmt->fetchColumn();
        if (!$path) {
            $stmt2 = $conn->prepare("SELECT duong_dan FROM anh_san_pham WHERE id_san_pham = :id ORDER BY thu_tu ASC, id_anh ASC LIMIT 1");
            $stmt2->execute([':id' => $product_id]);
            $path = $stmt2->fetchColumn();
        }
    } catch (Exception $e) {
        $path = null;
    }
    if (!$path) return $placeholder;
    $path = trim($path);
    if (preg_match('#^https?://#i', $path)) return $path;
    $candidates = [
        ltrim($path, '/'),
        'images/' . ltrim($path, '/'),
        'images/products/' . ltrim($path, '/'),
        'uploads/' . ltrim($path, '/'),
        'public/' . ltrim($path, '/'),
        'images/' . basename($path),
        'images/products/' . basename($path),
        'uploads/' . basename($path),
    ];
    $candidates = array_values(array_unique($candidates));
    foreach ($candidates as $c) {
        if (file_exists(__DIR__ . '/' . $c) && @filesize(__DIR__ . '/' . $c) > 0) {
            return $c;
        }
    }
    return $placeholder;
}

/* banner helper */
function getBannerImage($filename) {
    $placeholder = 'images/placeholder-banner.jpg';
    $candidates = [
        ltrim($filename, '/'),
        'images/' . ltrim($filename, '/'),
        'assets/images/' . ltrim($filename, '/'),
        'uploads/' . ltrim($filename, '/'),
        '../images/' . ltrim($filename, '/'),
    ];
    foreach (array_values(array_unique($candidates)) as $c) {
        if (file_exists(__DIR__ . '/' . $c) && @filesize(__DIR__ . '/' . $c) > 0) {
            return $c;
        }
    }
    return $placeholder;
}

/* rating helpers */
function getProductRating($conn, $product_id) {
    $result = ['avg'=>0, 'count'=>0];
    try {
        $stmt = $conn->prepare("SELECT rating, rating_count, so_sao FROM san_pham WHERE id_san_pham = :id LIMIT 1");
        $stmt->execute([':id'=>$product_id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            if (!empty($r['rating']) || !empty($r['rating_count']) || !empty($r['so_sao'])) {
                $avg = !empty($r['rating']) ? (float)$r['rating'] : (!empty($r['so_sao']) ? (float)$r['so_sao'] : 0);
                $cnt = !empty($r['rating_count']) ? (int)$r['rating_count'] : 0;
                return ['avg'=>$avg, 'count'=>$cnt];
            }
        }
    } catch (Exception $e) {}
    $tables = ['danh_gia','danhgia','reviews','product_reviews','rating','review'];
    $prodCols = ['id_san_pham','san_pham_id','product_id','product','id_product'];
    $ratingCols = ['rating','so_sao','diem','stars','score','point'];
    foreach ($tables as $t) {
        foreach ($prodCols as $pc) {
            foreach ($ratingCols as $rc) {
                try {
                    $sql = "SELECT AVG(`$rc`) AS avg_rating, COUNT(*) AS countv FROM `$t` WHERE `$pc` = :id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([':id'=>$product_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && ($row['countv'] ?? 0) > 0) {
                        $avg = round((float)$row['avg_rating'], 1);
                        $cnt = (int)$row['countv'];
                        return ['avg'=>$avg, 'count'=>$cnt];
                    }
                } catch (Exception $e) {}
            }
        }
    }
    return $result;
}

function render_rating_number($avg, $count) {
    $avg = max(0, min(5, (float)$avg));
    $cnt = (int)$count;
    if ($cnt <= 0) {
        // Không hiển thị "(Chưa có đánh giá)" — trả chuỗi rỗng
        return '';
    }
    $display = number_format($avg, 1, '.', '');
    return '<div class="d-flex align-items-center" style="gap:8px;"><div style="font-weight:700;color:#0b7bdc;">' . esc($display) . '*</div><div class="small text-muted">(' . $cnt . ')</div></div>';
}

/* short description helper */
if (!function_exists('short_desc')) {
    function short_desc($p, $len = 100) {
        // tìm trường mô tả phổ biến
        $fields = ['mo_ta','mo_ta_ngan','tom_tat','description','excerpt'];
        $txt = '';
        foreach ($fields as $f) {
            if (!empty($p[$f])) { $txt = trim(strip_tags($p[$f])); break; }
        }
        // fallback: không có mô tả -> trả chuỗi rỗng
        if ($txt === '') return '';
        if (mb_strlen($txt, 'UTF-8') <= $len) return $txt;
        $short = mb_substr($txt, 0, $len, 'UTF-8');
        // cố gắng cắt ở dấu cách cuối cùng
        $pos = mb_strrpos($short, ' ', 0, 'UTF-8');
        if ($pos !== false) $short = mb_substr($short, 0, $pos, 'UTF-8');
        return $short . '…';
    }
}

/* load data */
try { $cats = $conn->query("SELECT * FROM danh_muc WHERE trang_thai=1 ORDER BY thu_tu ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $cats = []; }
try { $latest = $conn->query("SELECT * FROM san_pham WHERE trang_thai=1 ORDER BY created_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $latest = []; }

$images = [
  'áo khoác'      => 'images/products/aokhoac/aokhoan1mau.png',
  'áo nỉ trơn'    => 'images/products/aonitron/aonitron1mau.jpg',
  'áo bomber'     => 'images/products/bomber/bomber2mau.png',
  'quần jean'     => 'images/products/quanjean/quanjean1mau.jpg',
  'sơ mi dài tay' => 'images/products/somidaitay/somi1mau.jpg',
];

$cat_image_by_name = [];
foreach ($images as $k => $v) {
    $key = trim(mb_strtolower($k, 'UTF-8'));
    $cat_image_by_name[$key] = $v;
}

/* CUSTOM PROMOS */
$promos = [];
$wanted_groups = [
    'áo khoác' => 3,
    'áo nỉ trơn' => 3,
    'áo bomber' => 3,
    'áo sơ mi' => 3,
    'quần jean' => 3
];

try {
    $collected_ids = [];
    foreach ($wanted_groups as $keyword => $limit) {
        $sql = "SELECT * FROM san_pham WHERE trang_thai=1 AND ten LIKE :kw ORDER BY created_at DESC LIMIT :lim";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':kw', '%' . $keyword . '%', PDO::PARAM_STR);
        $stmt->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            foreach ($rows as $r) {
                $id = isset($r['id_san_pham']) ? $r['id_san_pham'] : (isset($r['id']) ? $r['id'] : null);
                if ($id === null) continue;
                if (in_array($id, $collected_ids, true)) continue;
                $collected_ids[] = $id;
                $promos[] = $r;
            }
        }
    }
} catch (Exception $e) {
    $promos = [];
}

/* CART / header helpers */
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart_count = 0;
foreach ($_SESSION['cart'] as $it) { $cart_count += isset($it['qty']) ? (int)$it['qty'] : (isset($it['sl']) ? (int)$it['sl'] : 1); }
$user_name = !empty($_SESSION['user']['ten']) ? $_SESSION['user']['ten'] : null;
$banner1 = getBannerImage('aeshop2.png');
$banner2 = getBannerImage('viet1.png');
$banner3 = getBannerImage('ae2.png');

/* For header nav (use $cats as menu source) */
$catsMenu = $cats;
?>
<!doctype html>
<html lang="vi">
<head>
  <meta charset="utf-8">
  <title><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">

  <!-- SWIPER (added for banner only) -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.css" />
  <style>
  :root{ --accent:#0b7bdc; --muted:#6c757d; --nav-bg:#ffffff; --overlay-bg: rgba(12,17,20,0.08); --overlay-text:#061023; --circle-bg:#ffffff; --circle-icon:#0b7bdc; }
  body{ background:#f8fbff; font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial; color:#0f1724; }

  .ae-navbar{ background:var(--nav-bg); box-shadow:0 6px 18px rgba(11,38,80,0.04); }
  .ae-logo-mark{ width:46px;height:46px;border-radius:10px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800; }

  .hero-image { width:100%; height:220px; object-fit:cover; display:block; }
  @media (min-width:768px){ .hero-image { height:320px } }

  .cats-section { margin-bottom:28px; }
  .cats-head .title { display:none; }

  .cats-grid {
    display:grid;
    gap:28px;
    grid-template-columns: repeat(1, 1fr);
    align-items:stretch;
  }
  @media (min-width:576px) and (max-width:767px){
    .cats-grid{ grid-template-columns: repeat(2, 1fr); }
  }
  @media (min-width:768px) and (max-width:991px){
    .cats-grid{ grid-template-columns: repeat(3, 1fr); }
  }
  @media (min-width:992px){
    .cats-grid{ grid-template-columns: repeat(5, 1fr); }
  }

  .cat-card-hero { position:relative; overflow:hidden; border-radius:12px; min-height:360px; display:flex; flex-direction:column; justify-content:flex-end; background:#fff; box-shadow:0 10px 30px rgba(2,6,23,0.05); transition: transform .25s ease, box-shadow .25s ease; cursor:pointer; }
  .cat-card-hero .cat-image { position:absolute; left:0; right:0; top:0; bottom:0; z-index:1; background-size:cover; background-position:center; transition: transform .45s cubic-bezier(.2,.8,.2,1); }
  .cat-ov { position:relative; z-index:3; width:100%; background: linear-gradient(180deg, rgba(255,255,255,0.00), rgba(8,12,18,0.10)); backdrop-filter: blur(4px); padding:18px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
  .cat-ov .cat-name { font-weight:700; color:#071427; font-size:1.05rem; text-shadow: 0 1px 0 rgba(255,255,255,0.6); }
  .cat-ov .cat-action { width:48px; height:48px; border-radius:50%; background:var(--circle-bg); display:flex; align-items:center; justify-content:center; box-shadow:0 8px 18px rgba(2,6,23,0.08); transition: transform .18s ease, box-shadow .18s ease, background .18s; flex:0 0 48px; }
  .cat-link { display:block; text-decoration:none; color:inherit; height:100%; }
  .card-pro { border-radius:12px; overflow:hidden; background:#fff; box-shadow:0 8px 30px rgba(2,6,23,0.06); display:flex; flex-direction:column; height:100%; transition:transform .14s; }
  .card-media { background:linear-gradient(180deg,#fff,#f7fbff); display:flex; align-items:center; justify-content:center; padding:18px; min-height:220px; cursor:pointer; }
  .card-media img{ max-width:100%; max-height:200px; object-fit:contain; }
  .card-body-pro{ padding:14px; display:flex; flex-direction:column; gap:8px; flex:1; }
  .pro-title{ font-weight:700; font-size:1rem; line-height:1.2; min-height:48px; color:#0b1a2b; }
  .pro-desc{ color:#6b7280; font-size:0.9rem; min-height:36px; }
  .pro-price{ font-weight:800; color:var(--accent); font-size:1.05rem; }
  .pro-old{ text-decoration:line-through; color:#93a3b3; font-size:0.9rem; }
  .badge-discount{ position:absolute; left:12px; top:12px; background:#ef4444; color:#fff; padding:6px 10px; border-radius:999px; font-weight:700; z-index:9; }
  .badge-new{ position:absolute; left:12px; top:12px; background:var(--accent); color:#fff; padding:6px 10px; border-radius:999px; font-weight:700; z-index:9; }
  .card-actions { display:flex; gap:8px; margin-top:10px; }
  .btn-primary-filled{ background:var(--accent); color:#fff; border:none; padding:10px 12px; border-radius:10px; font-weight:700; }
  .btn-outline-ghost{ background:transparent; border:1px solid rgba(11,123,220,0.12); color:var(--accent); padding:8px 12px; border-radius:10px; }
  .products-grid { display:grid; grid-template-columns: repeat(4,1fr); gap:18px; grid-auto-rows:1fr; }
  @media(max-width:1199px){ .products-grid{ grid-template-columns: repeat(3,1fr); } }
  @media(max-width:767px){ .products-grid{ grid-template-columns: repeat(2,1fr); } }
  @media(max-width:479px){ .products-grid{ grid-template-columns: repeat(1,1fr); } }

  /* ===== New styles: add button, size pills, buy now ===== */
  .btn-add-primary {
    background: linear-gradient(90deg,#0b6ff0,#0b7bdc);
    border: none;
    color: #fff;
    padding: 10px 16px;
    border-radius: 10px;
    font-weight: 700;
    box-shadow: 0 12px 30px rgba(11,123,220,0.14);
    display: inline-flex;
    align-items: center;
    gap: .6rem;
  }
  .btn-add-primary i { font-size: 16px; line-height: 1; color: #fff; }

  .size-pill {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:38px;
    height:38px;
    padding:0 10px;
    border-radius:8px;
    border:1px solid rgba(11,123,220,0.12);
    background:#fff;
    color:#0b1a2b;
    font-weight:700;
    cursor:pointer;
    transition: all .15s ease;
    box-shadow: 0 6px 18px rgba(2,6,23,0.03);
  }
  .size-pill.active,
  .size-pill:active {
    background: linear-gradient(90deg,#0b6ff0,#0b7bdc);
    color: #fff;
    border-color: transparent;
    transform: translateY(-2px);
    box-shadow: 0 14px 30px rgba(11,123,220,0.14);
  }
  .size-pill:hover { transform: translateY(-3px); }

  .qty-compact {
    width:110px;
    border-radius:8px;
    border:1px solid rgba(11,123,220,0.12);
    padding:8px 12px;
    font-weight:700;
    text-align:center;
  }

  .btn-buy-now {
    border:2px solid #16a34a;
    color:#16a34a;
    background:#fff;
    padding:10px 14px;
    border-radius:10px;
    font-weight:700;
  }

  #qv-sizes .size-pill { margin-right:8px; margin-bottom:8px; }

  @media (max-width:767px){
    .btn-add-primary { padding:10px; font-size:14px; }
    .size-pill{ min-width:34px; height:34px; }
  }

  /* Quickview specific */
  .qv-left { padding:1.2rem; }
  .qv-right { padding:1.2rem 1.6rem; border-left:1px solid #eef3f7; }

  .qv-title { font-weight:800; font-size:1.2rem; margin-bottom:6px; }
  .qv-meta { font-size:0.92rem; color:#556070; margin-bottom:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .qv-meta .sku-label{ color:#6b7280; }
  .qv-meta .sku-value{ font-weight:700; color:#0b1a2b; }
  .qv-meta .status { font-weight:700; }
  .qv-meta .brand { color:#374151; font-weight:700; }

  .qv-price-box { background:#fff; border:1px solid #f1f5f9; padding:14px; border-radius:8px; margin:12px 0; }
  .qv-price { font-size:1.25rem; font-weight:800; color:#ef4444; }

  .size-pill {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:56px; height:42px; padding:0 12px; border-radius:8px;
    border:1px solid #e6eef9; background:#fff; color:#0b1a2b; font-weight:700;
    cursor:pointer; position:relative; margin-right:8px; margin-bottom:8px;
    box-shadow: 0 6px 18px rgba(2,6,23,0.03);
  }
  .size-pill.selected{
    background: #fff;
    color: #0b1a2b;
    outline: 3px solid #ef4444;
    transform: translateY(-3px);
    box-shadow:0 14px 30px rgba(239,68,68,0.12);
  }
  .size-pill .tick {
    position:absolute; top:-8px; right:-8px; width:18px; height:18px;
    background:#ef4444; color:#fff; border-radius:3px; font-size:11px;
    display:flex; align-items:center; justify-content:center; font-weight:800;
    box-shadow:0 6px 16px rgba(239,68,68,0.16);
    display:none;
  }
  .size-pill.selected .tick{ display:flex; }

  .size-pill.disabled {
    opacity:0.35; pointer-events:none;
  }
  .size-pill.disabled::after {
    content: "✕"; position:absolute; font-size:20px; color:#d1d5db; right:6px; bottom:6px;
  }

  .qty-group { display:flex; align-items:center; gap:8px; }
  .qty-btn { width:38px; height:38px; border-radius:8px; border:1px solid #e6eef9; background:#fff; display:flex;align-items:center;justify-content:center; cursor:pointer; font-weight:700; }
  .qty-input { width:70px; text-align:center; border-radius:8px; border:1px solid #e6eef9; padding:8px; }

  .btn-add-danger {
    background: linear-gradient(180deg,#ef4444,#dc2626);
    color:#fff; border:none; padding:14px 20px; border-radius:8px; font-weight:800;
    font-size:1rem; text-align:center; box-shadow: 0 14px 30px rgba(220,38,38,0.2);
  }

  .btn-buy-subtle {
    background:#fff; border:1px solid #e6eef9; color:#0b1a2b; padding:12px 14px; border-radius:8px; font-weight:700;
  }

  .qv-thumbs img{ width:64px; height:64px; object-fit:cover; border-radius:6px; border:1px solid #f1f5f9; cursor:pointer; margin-right:8px; }
  .qv-thumbs img.active { outline:3px solid rgba(239,68,68,0.12); transform:translateY(-4px); }

  /* Header/nav styles borrowed from category.php look */
  .ae-header { background:#fff; border-bottom:1px solid #eef3f8; position:sticky; top:0; z-index:1050; }
  .brand { display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; }
  .nav-center { display:flex; gap:8px; align-items:center; justify-content:center; flex:1; }
  .nav-center .nav-link { color:#333; padding:8px 12px; border-radius:8px; font-weight:600; text-decoration:none; transition:all .15s; }
  .nav-center .nav-link.active, .nav-center .nav-link:hover { color:var(--accent); background:rgba(11,123,220,0.06); }
  .nav-orders{ padding-inline:0.9rem; margin-left:.25rem; border-radius:999px; background:rgba(11,123,220,.06); display:flex; align-items:center; gap:.35rem; text-decoration:none; color:inherit; }
  .nav-orders:hover{ background:rgba(11,123,220,.12); color:var(--accent); }
  @media (max-width:991px){ .nav-center{ display:none; } .search-input{ display:none; } }

  /* SWIPER hero styles (banner only) */
  .hero-swiper { width:100%; position:relative; overflow:hidden; }
  .hero-slide { position:relative; width:100%; height:260px; display:flex; align-items:center; justify-content:center; }
  @media(min-width:768px){ .hero-slide{ height:360px; } }
  .hero-slide .bg-img { position:absolute; inset:0; background-size:cover; background-position:center; transform:scale(1.02); transition:transform .9s ease; }
  .hero-slide .overlay {
    position:relative; z-index:5; width:100%; height:100%;
    display:flex; align-items:center; justify-content:center;
    background: linear-gradient(180deg, rgba(0,0,0,0.06) 0%, rgba(0,0,0,0.12) 60%, rgba(255,255,255,0) 100%);
  }
  .hero-caption {
    max-width:1200px; width:100%; padding:28px 18px; display:flex; gap:24px; align-items:center;
    justify-content:space-between; color:#fff; text-shadow:0 6px 18px rgba(0,0,0,0.35);
  }
  .hero-caption .left { flex:1; min-width:0; }
  .hero-caption h1 { font-size:clamp(20px, 3.4vw, 44px); margin:6px 0 8px; font-weight:800; color:#fff; }
  .hero-caption .cta { display:flex; gap:10px; align-items:center; margin-top:8px; }

  .swiper-pagination { bottom:18px !important; }
  .swiper-button-next, .swiper-button-prev {
    color:#fff; width:52px; height:52px; border-radius:10px; background:rgba(0,0,0,0.28);
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 8px 20px rgba(2,6,23,0.12);
  }
  .swiper-slide-active .bg-img{ transform:scale(1.06); }

  /* ---------- Transparent/blurred category footer (like sample) ---------- */
  .cat-bg { transition: transform .6s ease, filter .4s ease; background-position:center; background-size:cover; }
  .cat-card-pro:hover .cat-bg { transform: scale(1.04); filter: brightness(.88) saturate(.95); }

  /* FOOT overlay: trong suốt + blur (giữ chữ nổi) */
  .cat-foot {
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    /* trong suốt (gradient để chữ dễ đọc) */
    background: linear-gradient(180deg, rgba(0,0,0,0.00) 0%, rgba(6,8,12,0.26) 60%, rgba(6,8,12,0.36) 100%);
    color: #fff;
    backdrop-filter: blur(6px) saturate(1.05);
    -webkit-backdrop-filter: blur(6px) saturate(1.05);
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.03);
  }

  /* Tiêu đề: chữ to, nét mảnh hơn để giống mẫu */
  .cat-title {
    font-weight:600;
    font-size:1.18rem;
    color: #fff;
    letter-spacing: .6px;
    text-shadow: 0 6px 18px rgba(0,0,0,0.45);
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    margin:0;
  }

  /* CTA: nút tròn trắng với icon đen, bóng nhẹ */
  .cat-cta {
    min-width:48px;
    height:48px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:50%;
    background:#ffffff;
    color:#061022;
    box-shadow: 0 10px 26px rgba(2,6,23,0.10);
    text-decoration:none;
    flex-shrink:0;
    transition: transform .16s ease, box-shadow .16s ease;
    border: none;
  }
  .cat-cta i { font-size:1.05rem; }

  /* hover hiệu ứng nhẹ cho nút */
  .cat-cta:hover { transform: translateX(4px) scale(1.02); box-shadow: 0 14px 34px rgba(2,6,23,0.14); }

  /* Nếu muốn nút có viền mảnh màu accent (tùy chọn) */
  /* .cat-cta { border: 1px solid rgba(11,123,220,0.06); } */

  /* Một chút giảm padding để phần overlay "sát" hơn */
  .cat-card-pro { overflow: hidden; border-radius:12px; }
  .cat-card-pro .cat-bg { min-height: 200px; }

  /* Responsive nhỏ: giảm padding overlay */
  @media (max-width:767px){
    .cat-foot { padding:10px 12px; gap:8px; }
    .cat-title { font-size:1.02rem; }
    .cat-cta { min-width:44px; height:44px; }
  }


    /* ======= STYLE CARD GIỐNG INDEX (torano-card) ======= */

    .card-pro.torano-card{
      border-radius:12px;
      overflow:hidden;
      background:linear-gradient(180deg,#ffffff,#fbfdff);
      box-shadow:0 8px 20px rgba(9,30,66,0.05);
      transition:transform .22s cubic-bezier(.2,.9,.3,1), box-shadow .22s;
      position:relative;
      display:flex;
      flex-direction:column;
      height:100%;
    }
    .card-pro.torano-card:hover{
      transform:translateY(-6px);
      box-shadow:0 18px 40px rgba(11,37,80,0.12);
    }

    /* ensure each grid cell stretches and cards match height */
    .products-grid > article { display:flex; flex-direction:column; height:100%; }

    .card-pro .card-media{
      padding:14px;
      min-height:200px;
      display:flex;
      align-items:center;
      justify-content:center;
      background:linear-gradient(180deg,#fff,#f7fbff);
      border-bottom:1px solid #eef3f7;
      text-decoration:none; color:inherit;
    }
    .card-pro .card-media img{
      width:auto;
      max-width:100%;
      max-height:160px;
      object-fit:contain;
      border-radius:6px;
      background:#fff;
      padding:6px;
      box-shadow:0 6px 18px rgba(9,30,66,0.05);
      transition:transform .36s cubic-bezier(.2,.9,.3,1);
    }
    .card-pro.torano-card:hover .card-media img{
      transform:scale(1.03) translateY(-3px);
    }

    .torano-badge{
      position:absolute;
      left:12px;
      top:12px;
      background:linear-gradient(180deg,#ff6b6b,#ef4444);
      color:#fff;
      padding:6px 10px;
      border-radius:999px;
      font-weight:800;
      font-size:12px;
      box-shadow:0 8px 20px rgba(239,68,68,0.14);
      z-index:10;
    }

    .card-body-pro{
      padding:12px 12px 14px;
      display:flex;
      flex-direction:column;
      gap:8px;
      flex:1;
      font-size:13px;
      justify-content:space-between; /* push actions to bottom for consistent layout */
    }
    .card-body-pro .small-muted-top{
      color:var(--muted);
      font-size:12px;
    }
    .pro-title{
      font-weight:800;
      font-size:0.95rem;
      color:#081127;
      line-height:1.2;
      min-height:2.2em;
      overflow:hidden;
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
    }

    .pro-short-desc{ color:#4b5563; font-size:13px; min-height:2.2em; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

    .pro-price{
      color:#ef4444;
      font-weight:900;
      font-size:0.98rem;
      letter-spacing:.2px;
    }
    .pro-old{
      text-decoration:line-through;
      color:#9aa6b2;
      font-size:0.82rem;
      margin-top:2px;
    }

    /* Adjusted actions: smaller buttons, inline on larger screens */
    .card-actions{
      display:flex;
      gap:8px;
      margin-top:8px;
      align-items:center;
    }

    /* smaller, sleeker add button */
    .btn-add-primary{
      background:linear-gradient(90deg,#0b6ff0,#0b7bdc);
      border:none;
      color:#fff;
      padding:5px 10px;
      border-radius:8px;
      font-weight:800;
      font-size:12px;
      box-shadow:0 8px 18px rgba(11,123,220,0.14);
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:8px;
      min-width:96px;
      min-height:38px;
      transition:transform .12s ease, box-shadow .12s ease;
    }
    .btn-add-primary i{ font-size:14px; }
    .btn-add-primary:hover{ transform:translateY(-2px); box-shadow:0 12px 26px rgba(11,123,220,0.18); }

    /* smaller outline button */
    .btn-outline-ghost{
      background:#fff;
      border:1px solid rgba(11,123,220,0.12);
      color:var(--accent);
      padding:3px 10px;
      border-radius:6px;
      font-size:12px;
      font-weight:500;
      text-decoration:none;
      text-align:center;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap:6px;
      flex:1;
      min-width:90px;
      min-height:38px;
      transition:background .12s ease, transform .12s ease;
    }
    .btn-outline-ghost i{ font-size:12px; }
    .btn-outline-ghost:hover{ background:rgba(11,123,220,0.04); transform:translateY(-2px); }

    .small-muted{ color:var(--muted); font-size:12.5px; }

    .pagination .page-link{ color:#0b1724; font-size:13px; padding:6px 9px; }
    .pagination .page-item.active .page-link{ background:var(--accent); border-color:var(--accent); color:#fff; }

    /* QuickView small styles */
    .qv-thumb{ border-radius:8px; margin-right:8px; cursor:pointer; border:2px solid transparent; width:70px; height:70px; object-fit:cover; }
    .qv-thumb.active{ border-color:var(--accent); }
    .swatch{ width:28px; height:28px; display:inline-block; border-radius:50%; margin-right:6px; cursor:pointer; border:1px solid rgba(0,0,0,0.04); }

    /* mini cart pulse when item added */
    @keyframes cartPulse {
      0%   { transform: scale(1); }
      30%  { transform: scale(1.08); }
      60%  { transform: scale(0.98); }
      100% { transform: scale(1); }
    }
    .cart-pulse {
      animation: cartPulse .6s ease;
    }

    @media(max-width:767px){
      .card-actions{ flex-direction:column; align-items:stretch; }
      .btn-outline-ghost{ width:100%; flex:none; }
      .btn-add-primary{ width:100%; }
    }

    @media(max-width:991px){
      .nav-center{ display:none; }
      .search-inline{ display:none; }
      .brand-mark{ width:40px;height:40px; }
    }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/inc/header.php'; ?>

<!-- Mobile offcanvas -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
  <div class="offcanvas-header">
    <h5 id="mobileMenuLabel"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></h5>
    <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Đóng"></button>
  </div>
  <div class="offcanvas-body">
    <form action="sanpham.php" method="get" class="mb-3 d-flex">
      <input class="form-control me-2" name="q" placeholder="Tìm sản phẩm..." value="<?= esc($_GET['q'] ?? '') ?>">
      <button class="btn btn-dark">Tìm</button> 
    </form>

    <ul class="list-unstyled">
      <li class="mb-2"><a href="index.php" class="text-decoration-none">Trang chủ</a></li>
      <li class="mb-2"><a href="sanpham.php" class="text-decoration-none">Sản phẩm</a></li>
      <?php foreach($catsMenu as $c): ?>
        <li class="mb-2 ps-2"><a href="sanpham.php?slug=<?= urlencode($c['slug']) ?>" class="text-decoration-none"><?= esc($c['ten']) ?></a></li>
      <?php endforeach; ?>
      <li class="mb-2"><a href="about.php" class="text-decoration-none">Giới thiệu</a></li>
      <li class="mb-2"><a href="contact.php" class="text-decoration-none">Liên hệ</a></li>
    </ul>
  </div>
</div>

<!-- HERO (Swiper: banner text removed per request) -->
<section class="py-0">
  <div class="container-fluid px-0">
    <div class="hero-swiper">
      <div class="swiper mySwiperHero">
        <div class="swiper-wrapper">
          <?php $slides = [$banner1,$banner2,$banner3]; foreach($slides as $i=>$b): ?>
            <div class="swiper-slide hero-slide" data-swiper-parallax="-200">
              <div class="bg-img" style="background-image:url('<?= esc($b) ?>');"></div>
              <div class="overlay">
                <div class="hero-caption container">
                  <div class="left">
                    <!-- banner title/subtitle removed as requested -->
                    <div class="cta">
                    
                    </div>
                  </div>
                  <!-- right promo box removed as requested -->
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- navigation -->
        <div class="swiper-button-next" aria-label="Next"></div>
        <div class="swiper-button-prev" aria-label="Prev"></div>

        <!-- pagination (dots) -->
        <div class="swiper-pagination"></div>
      </div>
    </div>
  </div>
</section>

<div class="container my-4">
<!-- DANH MỤC SẢN PHẨM – ẢNH LẤY TRỰC TIẾP TỪ /images/ -->
<section class="images">
  <div class="d-flex justify-content-between align-items-center mb-3 px-1">
    <h5 class="fw-bold mb-0">Danh mục nổi bật</h5>
    <a href="sanpham.php" class="text-muted small">Xem tất cả <i class="bi bi-arrow-right"></i></a>
  </div>

  <style>
    /* Grid 5 cột */
    .cats-grid-pro { display: grid; gap: 22px; grid-template-columns: repeat(2, 1fr); }
    @media (min-width: 576px) { .cats-grid-pro { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 900px) { .cats-grid-pro { grid-template-columns: repeat(4, 1fr); } }
    @media (min-width: 1200px) { .cats-grid-pro { grid-template-columns: repeat(5, 1fr); } }

    .cat-card-pro {
      position: relative;
      border-radius: 12px;
      overflow: hidden;
      height: 320px;
      background: #fff;
      box-shadow: 0 8px 28px rgba(8,12,20,0.06);
      transition: transform .22s ease, box-shadow .22s ease;
      display: flex;
      flex-direction: column;
      cursor: pointer;
    }
    .cat-card-pro:hover { transform: translateY(-4px); box-shadow:0 14px 36px rgba(8,12,20,0.10); }

    .cat-bg {
      flex: 1 1 auto;
      background-size: cover;
      background-position: center;
      transition: transform .6s ease, filter .4s ease;
      min-height: 220px;
    }
    .cat-card-pro:hover .cat-bg { transform: scale(1.06); filter: brightness(.86); }

    /* FOOT overlay đen mờ */
    /* replaced by the transparent overlay CSS higher in <head> to keep consistent */
    .cat-foot {
      /* fallback for older browsers */
      display:flex;
      align-items:center;
      gap:12px;
      padding:18px 16px;
      background: linear-gradient(180deg, rgba(0,0,0,0.28), rgba(0,0,0,0.48));
      color: #fff;
      backdrop-filter: blur(6px);
    }

    /* CHỈ GIỮ TÊN */
    .cat-title {
      font-weight:600;
      font-size:1.25rem;
      color:#fff;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      margin:0;
    }

    /* CTA nút trắng */
    .cat-cta {
      min-width:48px; height:48px;
      display:flex; align-items:center; justify-content:center;
      border-radius:50%;
      background:#fff; color:#0b7bdc;
      box-shadow:0 10px 26px rgba(2,6,23,0.12);
      text-decoration:none;
      flex-shrink:0;
    }
    .cat-cta:hover { transform: translateX(6px); transition:.18s; }
  </style>

  <div class="cats-grid-pro">
    <?php foreach ($cats as $c):
      $name = trim($c['ten'] ?? '');
      $imgKey = mb_strtolower($name, 'UTF-8');
      // nếu tên danh mục (lowercase) có trong map, lấy ảnh; nếu không, fallback 'images/ae.jpg'
      $img  = isset($cat_image_by_name[$imgKey]) ? $cat_image_by_name[$imgKey] : 'images/ae.jpg';
      $link = 'sanpham.php?slug=' . urlencode($c['slug'] ?? '');
    ?>
    <div class="cat-card-pro" data-link="<?= esc($link) ?>" role="button" tabindex="0" onclick="window.location.href='<?= $link ?>'">
      <div class="cat-bg" style="background-image:url('<?= esc($img) ?>');"></div>

      <div class="cat-foot">
        <div class="cat-title"><?= esc($name) ?></div>

        <!-- Nút CTA vẫn hoạt động -->
        <a class="cat-cta" href="<?= esc($link) ?>">
          <i class="bi bi-arrow-right"></i>
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<script>
  // click cả card (backup, nhưng onclick trên div cũng đã xử lý)
  document.addEventListener("click", function(e){
    const card = e.target.closest(".cat-card-pro");
    if (!card) return;
    if (e.target.closest(".cat-cta")) return;
    window.location.href = card.dataset.link;
  });
</script>

  <!-- ===== REPLACE: SẢN PHẨM KHUYẾN MÃI (BEGIN) ===== -->
<section class="torano-section torano-promos-section mb-5">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="torano-title mb-0"><span class="torano-dot"></span>Sản phẩm khuyến mãi</h3>

    <div class="torano-controls d-flex align-items-center gap-2">
      <a href="sanpham.php" class="text-muted small me-2">Xem thêm</a>
      <button class="torano-nav prev-promos" aria-label="Prev"><i class="bi bi-chevron-left"></i></button>
      <button class="torano-nav next-promos" aria-label="Next"><i class="bi bi-chevron-right"></i></button>
    </div>
  </div>

  <?php if (!empty($promos)): ?>
    <div class="swiper torano-promos">
      <div class="swiper-wrapper">
        <?php foreach ($promos as $p):
          $imgp = getProductImage($conn, $p['id_san_pham'] ?? $p['id']);
          $discount = (isset($p['gia_cu']) && $p['gia_cu'] && $p['gia_cu']>$p['gia']) ? round((($p['gia_cu']-$p['gia'])/$p['gia_cu'])*100) : 0;
          $rating = getProductRating($conn, $p['id_san_pham'] ?? $p['id']);
          $short = short_desc($p, 90); // mô tả ngắn ~90 ký tự
        ?>
        <div class="swiper-slide">
          <div class="card-pro torano-card">
            <?php if ($discount): ?>
              <div class="torano-badge">-<?= $discount ?>%</div>
            <?php endif; ?>

            <a class="card-media" href="sanpham_chitiet.php?id=<?= (int)($p['id_san_pham'] ?? $p['id']) ?>">
              <img class="lazy-img" data-src="<?= esc($imgp) ?>" alt="<?= esc($p['ten']) ?>" src="images/placeholder.jpg">
            </a>

            <div class="card-body-pro">
              <div class="pro-title"><?= esc($p['ten']) ?></div>

              <?php if (!empty($short)): ?>
                <div class="pro-desc"><?= esc($short) ?></div>
              <?php endif; ?>

              <div class="d-flex justify-content-between align-items-center mt-2">
                <div>
                  <div class="pro-price"><?= number_format($p['gia'],0,',','.') ?> ₫</div>
                  <?php if (isset($p['gia_cu']) && $p['gia_cu'] && $p['gia_cu']>$p['gia']): ?><div class="pro-old"><?= number_format($p['gia_cu'],0,',','.') ?> ₫</div><?php endif; ?>
                </div>
                <div class="torano-rate"><?= render_rating_number($rating['avg'], $rating['count']) ?></div>
              </div>

              <div class="card-actions mt-3">
    <?php
      $dp = [
        'id' => $p['id_san_pham'] ?? $p['id'],
        'name' => $p['ten'],
        'price' => $p['gia'],
        'img' => $imgp,
        'thumbs' => [$imgp],
        'rating' => $rating['avg'],
        'rating_count' => $rating['count'],
        'mo_ta' => $p['mo_ta'] ?? ($p['mo_ta_ngan'] ?? '')
      ];
    ?>

    <!-- Nút THÊM (bên trái) -->
    <a
      type="button"
      class="btn-add-primary qv-open"
      data-product='<?= htmlspecialchars(json_encode($dp, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
      style="flex:1; display:flex; align-items:center; justify-content:center; gap:.5rem;">
      <i class="bi bi-cart-plus"></i> Thêm
    </a>
  

    <!-- Nút XEM (bên phải) -->
    <a
      href="sanpham_chitiet.php?id=<?= (int)($p['id_san_pham'] ?? $p['id']) ?>"
      class="btn-outline-ghost"
      style="flex:1; display:flex; align-items:center; justify-content:center; gap:.5rem;">
       Xem
    </a>
</div>

            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="text-center mt-4">
      <a href="sanpham.php" class="btn torano-cta">XEM TẤT CẢ <strong>SẢN PHẨM KHUYẾN MÃI</strong></a>
    </div>
  <?php else: ?>
    <div class="alert alert-info">Hiện không có sản phẩm khuyến mãi.</div>
  <?php endif; ?>
</section>
<!-- ===== REPLACE: SẢN PHẨM KHUYẾN MÃI (END) ===== -->

<!-- ===== REPLACE: SẢN PHẨM MỚI (BEGIN) ===== -->
<section class="torano-section torano-latest-section mb-5">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="torano-title mb-0"><span class="torano-dot blue"></span> Sản phẩm mới</h3>

    <div class="torano-controls d-flex align-items-center gap-2">
      <a href="sanpham.php" class="text-muted small me-2">Xem thêm</a>
      <button class="torano-nav prev-latest" aria-label="Prev"><i class="bi bi-chevron-left"></i></button>
      <button class="torano-nav next-latest" aria-label="Next"><i class="bi bi-chevron-right"></i></button>
    </div>
  </div>

  <?php if (!empty($latest)): ?>
    <div class="swiper torano-latest">
      <div class="swiper-wrapper">
        <?php foreach ($latest as $p):
          $imgp = getProductImage($conn, $p['id_san_pham'] ?? $p['id']);
          $rating = getProductRating($conn, $p['id_san_pham'] ?? $p['id']);
          $is_new = false;
          if (!empty($p['created_at'])) {
            $ts = strtotime($p['created_at']);
            if ($ts && (time() - $ts) < 60*60*24*30) $is_new = true;
          }
          $short = short_desc($p, 90);
        ?>
        <div class="swiper-slide">
          <div class="card-pro torano-card">
            <?php if ($is_new): ?><div class="torano-badge new">NEW</div><?php endif; ?>

            <a class="card-media" href="sanpham_chitiet.php?id=<?= (int)($p['id_san_pham'] ?? $p['id']) ?>">
              <img class="lazy-img" data-src="<?= esc($imgp) ?>" alt="<?= esc($p['ten']) ?>" src="images/placeholder.jpg">
            </a>

            <div class="card-body-pro">
              <div class="pro-title"><?= esc($p['ten']) ?></div>

              <?php if (!empty($short)): ?>
                <div class="pro-desc"><?= esc($short) ?></div>
              <?php endif; ?>

              <div class="d-flex justify-content-between align-items-center mt-2">
                <div class="pro-price"><?= number_format($p['gia'],0,',','.') ?> ₫</div>
                <div class="torano-rate"><?= render_rating_number($rating['avg'], $rating['count']) ?></div>
              </div>

              <div class="card-actions mt-3">
    <?php
      $dp = [
        'id' => $p['id_san_pham'] ?? $p['id'],
        'name' => $p['ten'],
        'price' => $p['gia'],
        'img' => $imgp,
        'thumbs' => [$imgp],
        'rating' => $rating['avg'],
        'rating_count' => $rating['count'],
        'mo_ta' => $p['mo_ta'] ?? ($p['mo_ta_ngan'] ?? '')
      ];
    ?>

    <!-- Nút THÊM (bên trái) -->
    <button
      type="button"
      class="btn-add-primary qv-open"
      data-product='<?= htmlspecialchars(json_encode($dp, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
      style="flex:1; display:flex; align-items:center; justify-content:center; gap:.5rem;">
      <i class="bi bi-cart-plus"></i> Thêm
    </button>

    <!-- Khoảng cách giữa 2 nút -->
    <div style="width:12px"></div>

    <!-- Nút XEM (bên phải) -->
    <a
      href="sanpham_chitiet.php?id=<?= (int)($p['id_san_pham'] ?? $p['id']) ?>"
      class="btn-outline-ghost"
      style="flex:1; display:flex; align-items:center; justify-content:center; gap:.5rem;">
       Xem
    </a>
</div>

            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-info">Chưa có sản phẩm mới.</div>
  <?php endif; ?>
</section>
<!-- ===== REPLACE: SẢN PHẨM MỚI (END) ===== -->

<!-- ===== JS Init for both Swipers (thêm gần cuối file, sau swiper bundle) ===== -->
<script>
document.addEventListener('DOMContentLoaded', function(){
  // lazy loader helper
  function lazySlideImages(sw){
    try {
      const slides = sw.slides || [];
      const perView = Math.ceil(sw.params.slidesPerView || 1);
      const start = Math.max(0, sw.activeIndex - perView - 1);
      const end = Math.min(slides.length -1, sw.activeIndex + perView + 1);
      for (let i = start; i <= end; i++){
        const s = slides[i];
        if (!s) continue;
        const img = s.querySelector('.lazy-img');
        if (img && img.dataset && img.dataset.src && img.src.indexOf('placeholder') !== -1){
          img.src = img.dataset.src;
        }
      }
    } catch(e){ console.error(e); }
  }

  // Promos swiper (5 on large screens)
  const promos = new Swiper('.torano-promos', {
    slidesPerView: 5,
    spaceBetween: 22,
    navigation: false,
    pagination: { el: '.torano-promos .swiper-pagination', clickable:true },
    breakpoints: {
      0: { slidesPerView: 1.05, spaceBetween: 12 },
      576: { slidesPerView: 2, spaceBetween: 12 },
      768: { slidesPerView: 3, spaceBetween: 14 },
      992: { slidesPerView: 4, spaceBetween: 16 },
      1200: { slidesPerView: 5, spaceBetween: 20 }
    },
    on: { init: function(){ lazySlideImages(this); }, slideChange: function(){ lazySlideImages(this); } },
    a11y: true
  });
  document.querySelectorAll('.prev-promos').forEach(b=>b.addEventListener('click', ()=>promos.slidePrev()));
  document.querySelectorAll('.next-promos').forEach(b=>b.addEventListener('click', ()=>promos.slideNext()));

  // Latest swiper (same settings)
  const latest = new Swiper('.torano-latest', {
    slidesPerView: 5,
    spaceBetween: 22,
    navigation: false,
    pagination: { el: '.torano-latest .swiper-pagination', clickable:true },
    breakpoints: {
      0: { slidesPerView: 1.05, spaceBetween: 12 },
      576: { slidesPerView: 2, spaceBetween: 12 },
      768: { slidesPerView: 3, spaceBetween: 14 },
      992: { slidesPerView: 4, spaceBetween: 16 },
      1200: { slidesPerView: 5, spaceBetween: 20 }
    },
    on: { init: function(){ lazySlideImages(this); }, slideChange: function(){ lazySlideImages(this); } },
    a11y: true
  });
  document.querySelectorAll('.prev-latest').forEach(b=>b.addEventListener('click', ()=>latest.slidePrev()));
  document.querySelectorAll('.next-latest').forEach(b=>b.addEventListener('click', ()=>latest.slideNext()));
});
</script>

  <!-- CONTACT -->
  <section class="py-5">
    <div class="row g-4">
      <div class="col-lg-7">
        <h4>Liên hệ</h4>
        <p class="text-muted">Gửi yêu cầu hoặc đặt câu hỏi — chúng tôi luôn sẵn sàng hỗ trợ bạn.</p>
        <form action="contact.php" method="post" class="row g-3">
          <input type="hidden" name="source" value="index_contact">
          <div class="col-md-6"><input name="ten" class="form-control" placeholder="Họ & tên" required></div>
          <div class="col-md-6"><input name="email" class="form-control" placeholder="Email" type="email" required></div>
          <div class="col-md-6"><input name="dien_thoai" class="form-control" placeholder="Số điện thoại (tuỳ chọn)"></div>
          <div class="col-md-6"><input name="tieu_de" class="form-control" placeholder="Tiêu đề"></div>
          <div class="col-12"><textarea name="noi_dung" class="form-control" rows="6" placeholder="Nội dung..." required></textarea></div>
          <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary">Gửi liên hệ</button><button type="reset" class="btn btn-outline-secondary">Làm lại</button></div>
        </form>
      </div>
      <div class="col-lg-5">
        <div class="p-3 bg-white rounded shadow-sm">
          <h6>Thông tin cửa hàng</h6>
          <p class="mb-1 small fw-semibold"><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?></p>
          <p class="small text-muted mb-1">Địa chỉ: 89 Lê Đức Thọ - Nam Từ Liêm - Hà Nội</p>
          <p class="small mb-1">Hotline: <a href="tel:0123456789">0123 456 789</a></p>
          <p class="small mb-2">Email: <a href="mailto:info@example.com">info@example.com</a></p>
          <hr>
          <h6 class="small">Giờ mở cửa</h6>
          <p class="small text-muted mb-2">T2 - T7: 08:30 — 18:00<br>CN: Nghỉ</p>
          <h6 class="small">Các kênh hỗ trợ</h6>
          <p class="small mb-1"><i class="bi bi-telephone"></i> Zalo: <a href="tel:0123456789">0123 456 789</a></p>
          <p class="small mb-1"><i class="bi bi-instagram"></i> <a href="#" target="_blank">@aeshop</a></p>
          <p class="small"><i class="bi bi-facebook"></i> <a href="#" target="_blank">facebook.com/aeshop</a></p>
        </div>
      </div>
    </div>
  </section>

</div>

<!-- QUICKVIEW modal (unchanged layout except we keep qv-short-desc but JS will set empty if no desc) -->
<div class="modal fade" id="quickViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-0">
        <div class="row g-0">
          <div class="col-md-6 qv-left">
            <div class="text-center">
              <img id="qv-main-img" src="images/placeholder.jpg" class="img-fluid" style="max-height:520px; object-fit:contain;">
            </div>
            <div class="d-flex qv-thumbs mt-3 px-2" id="qv-thumbs"></div>
          </div>

          <div class="col-md-6 qv-right">
            <div class="d-flex justify-content-between align-items-start">
              <div style="flex:1; min-width:0;">
                <div class="qv-title" id="qv-title">Tên sản phẩm</div>

                <div class="qv-meta">
                  <div class="sku-label">Mã sản phẩm: <span id="qv-sku-text" class="sku-value">-</span></div>
                  <div class="status" id="qv-status" style="color:#16a34a">Còn hàng</div>
                  <div class="brand" id="qv-brand" style="margin-left:6px;">Thương hiệu: <strong> - </strong></div>
                </div>

                <div id="qv-rate" class="mb-2 small text-muted"></div>
              </div>

              <div class="text-end" style="min-width:180px;">
                <div class="qv-price-box">
                  <div class="small text-muted">Giá:</div>
                  <div class="qv-price" id="qv-price">0 ₫</div>
                </div>
              </div>
            </div>

            <div id="qv-short-desc" class="small text-muted mt-2"></div>

            <hr>

            <div class="mt-2">
              <div class="small mb-2"><strong>Màu sắc:</strong> <span id="qv-color-name" class="small text-primary"> - </span></div>
              <div id="qv-colors" class="mb-3"></div>

              <div class="small mb-2"><strong>Kích thước:</strong></div>
              <div id="qv-sizes" class="mb-3"></div>

              <div class="d-flex align-items-center justify-content-start gap-3 mb-3">
                <div class="qty-group">
                  <button type="button" class="qty-btn" id="qv-dec">−</button>
                  <input type="number" id="qv-qty" class="qty-input" value="1" min="1">
                  <button type="button" class="qty-btn" id="qv-inc">+</button>
                </div>
                <div class="small text-muted">Giao hàng 1-3 ngày • Đổi trả 7 ngày</div>
              </div>

              <div class="d-grid gap-2 mb-3">
                <form id="qv-addform" method="post" action="cart.php" class="d-flex gap-2">
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="id" id="qv-id" value="">
                  <input type="hidden" name="qty" id="qv-id-qty" value="1">
                  <input type="hidden" name="size" id="qv-size" value="">
                  <input type="hidden" name="color" id="qv-color" value="">
                  <button id="qv-addbtn" type="submit" class="btn-add-danger w-100">
                    <i class="bi bi-cart-plus me-2"></i> THÊM VÀO GIỎ
                  </button>
                </form>

                <button id="qv-buy" type="button" class="btn-buy-subtle">Mua ngay</button>
              </div>

              <div class="small text-muted">Chia sẻ:</div>
              <div class="mt-2">
                <a class="me-2" href="#"><i class="bi bi-facebook"></i></a>
                <a class="me-2" href="#"><i class="bi bi-messenger"></i></a>
                <a class="me-2" href="#"><i class="bi bi-twitter"></i></a>
                <a class="me-2" href="#"><i class="bi bi-pinterest"></i></a>
              </div>

            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Đóng</button></div>
    </div>
  </div>
</div>

<footer class="bg-dark text-white py-4 mt-4">
  <div class="container text-center">
    <small><?= esc(function_exists('site_name') ? site_name($conn) : 'AE Shop') ?> — © <?= date('Y') ?> — Hotline: 0123 456 789</small>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Swiper JS (banner only) -->
<script src="https://cdn.jsdelivr.net/npm/swiper@9/swiper-bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  // init hero swiper
  const heroSwiper = new Swiper(".mySwiperHero", {
    loop: true,
    effect: 'fade',
    fadeEffect: { crossFade: true },
    speed: 900,
    autoplay: {
      delay: 4500,
      disableOnInteraction: false,
    },
    navigation: {
      nextEl: '.swiper-button-next',
      prevEl: '.swiper-button-prev',
    },
    pagination: {
      el: '.swiper-pagination',
      clickable: true,
    },
    on: {
      slideChangeTransitionStart: function(){
        // subtle parallax: scale bg of active slide
        document.querySelectorAll('.hero-slide .bg-img').forEach(el=>el.style.transform='scale(1.02)');
        const active = document.querySelector('.swiper-slide-active .bg-img');
        if(active) active.style.transform='scale(1.06)';
      }
    },
    breakpoints: {
      0: { slidesPerView:1 },
      992: { slidesPerView:1 }
    },
    a11y: true
  });

  // pause autoplay on hover (like many stores)
  const heroEl = document.querySelector('.mySwiperHero');
  if (heroEl) {
    heroEl.addEventListener('mouseenter', ()=> heroSwiper.autoplay.stop());
    heroEl.addEventListener('mouseleave', ()=> heroSwiper.autoplay.start());
  }
});
</script>

<script>
/* QuickView: populate modal with SKU & status + size pill behavior + add-to-cart */
function openQuickView(el){
  try {
    const target = el && el.getAttribute && el.getAttribute('data-product') ? el : (el && el.closest ? el.closest('[data-product]') : null);
    const dataAttr = target ? target.getAttribute('data-product') : null;
    const data = dataAttr ? JSON.parse(dataAttr) : null;
    if (!data) { console.warn('No data-product'); return; }

    // Basic populate
    document.getElementById('qv-title').textContent = data.name || '';
    document.getElementById('qv-sku-text').textContent = data.sku || (data.sku_text || ('#' + (data.id||'')));
    // status
    const statusEl = document.getElementById('qv-status');
    const inStock = (data.in_stock === undefined) ? true : !!data.in_stock;
    statusEl.textContent = inStock ? 'Còn hàng' : 'Hết hàng';
    statusEl.style.color = inStock ? '#16a34a' : '#ef4444';

    // brand (optional)
    if (data.brand) {
      document.getElementById('qv-brand').innerHTML = 'Thương hiệu: <strong>' + (data.brand) + '</strong>';
    } else {
      document.getElementById('qv-brand').innerHTML = '';
    }

    // rating
    const qvRateEl = document.getElementById('qv-rate');
    let avg = parseFloat(data.rating || 0), cnt = parseInt(data.rating_count || 0);
    if (cnt>0) {
      qvRateEl.innerHTML = '<strong style="color:#0b7bdc;">' + avg.toFixed(1) + '</strong> ★ <span class="small text-muted">(' + cnt + ')</span>';
    } else {
      qvRateEl.innerHTML = ''; // không hiển thị gì khi chưa có đánh giá
    }

    // desc
    const desc = data.mo_ta || '';
    // không hiển thị văn bản "(Không có mô tả...)" nữa; để trống nếu không có
    document.getElementById('qv-short-desc').innerHTML = desc ? desc.replace(/\n/g,'<br>') : '';

    // price
    document.getElementById('qv-price').textContent = new Intl.NumberFormat('vi-VN').format(data.price || data.gia_raw || 0) + ' ₫';

    // image + thumbs
    document.getElementById('qv-main-img').src = data.img || 'images/placeholder.jpg';
    const thumbsBox = document.getElementById('qv-thumbs'); thumbsBox.innerHTML = '';
    const thumbs = Array.isArray(data.thumbs) && data.thumbs.length ? data.thumbs : [data.img || 'images/placeholder.jpg'];
    thumbs.forEach((t,i)=>{
      const im = document.createElement('img');
      im.src = t; im.alt = data.name || '';
      if (i===0) im.classList.add('active');
      im.onclick = function(){ document.getElementById('qv-main-img').src = t; document.querySelectorAll('#qv-thumbs img').forEach(x=>x.classList.remove('active')); this.classList.add('active'); };
      thumbsBox.appendChild(im);
    });

    // colors (optional)
    const colorsBox = document.getElementById('qv-colors'); colorsBox.innerHTML = '';
    if (data.colors && data.colors.length){
      document.getElementById('qv-color-name').textContent = data.colors[0].name || '';
      data.colors.forEach(c => {
        const b = document.createElement('button'); b.type='button'; b.className='size-pill';
        b.style.minWidth='44px'; b.textContent = c.name || ''; b.dataset.color = c.value || c.name || '';
        b.onclick = function(){ document.getElementById('qv-color').value = this.dataset.color || ''; document.querySelectorAll('#qv-colors .size-pill').forEach(x=>x.classList.remove('selected')); this.classList.add('selected'); };
        colorsBox.appendChild(b);
      });
    } else {
      document.getElementById('qv-color-name').textContent = '';
    }

    // sizes: create pills; support available boolean per size
    const sizeBox = document.getElementById('qv-sizes'); sizeBox.innerHTML = '';
    const sizes = Array.isArray(data.sizes) ? data.sizes : (data.size_options || ['S','M','L','XL']);
    sizes.forEach((s, idx) => {
      let label = s, available = true;
      if (typeof s === 'object'){ label = s.label || s.size || s.name; available = (s.available === undefined) ? true : !!s.available; }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'size-pill' + (available ? '' : ' disabled');
      btn.innerHTML = '<span class="size-label">'+label+'</span><span class="tick">✓</span>';
      btn.dataset.size = label;
      if (!available) { btn.setAttribute('aria-disabled','true'); }
      btn.onclick = function(){
        if (!available) return;
        document.querySelectorAll('#qv-sizes .size-pill').forEach(x=>x.classList.remove('selected'));
        this.classList.add('selected');
        document.getElementById('qv-size').value = this.dataset.size || '';
      };
      sizeBox.appendChild(btn);
    });

    // ensure qty default
    document.getElementById('qv-qty').value = 1;
    document.getElementById('qv-id').value = data.id || '';
    document.getElementById('qv-id-qty').value = 1;
    document.getElementById('qv-size').value = '';

    // toggle add button disabled if out of stock
    const addBtn = document.getElementById('qv-addbtn');
    if (!inStock){
      addBtn.disabled = true;
      addBtn.style.opacity = 0.6;
    } else {
      addBtn.disabled = false;
      addBtn.style.opacity = 1;
    }

    // show modal
    const myModal = new bootstrap.Modal(document.getElementById('quickViewModal'));
    myModal.show();
  } catch (err) {
    console.error('openQuickView error', err);
  }
}

/* attach triggers and form handling */
document.addEventListener('DOMContentLoaded', function(){
  // attach click handlers on qv-open buttons
  document.querySelectorAll('.qv-open').forEach(btn=>{
    btn.addEventListener('click', function(e){
      e.preventDefault();
      openQuickView(this);
    });
  });

  // qty buttons
  const dec = document.getElementById('qv-dec'), inc = document.getElementById('qv-inc'), qty = document.getElementById('qv-qty'), qtyHidden = document.getElementById('qv-id-qty');
  if (dec && inc && qty) {
    dec.addEventListener('click', ()=> {
      const v = Math.max(1, parseInt(qty.value||1) - 1);
      qty.value = v; if (qtyHidden) qtyHidden.value = v;
    });
    inc.addEventListener('click', ()=> {
      const v = Math.max(1, parseInt(qty.value||1) + 1);
      qty.value = v; if (qtyHidden) qtyHidden.value = v;
    });
    qty.addEventListener('input', ()=> { const v = Math.max(1, parseInt(qty.value||1) || 1); qty.value = v; if (qtyHidden) qtyHidden.value = v; });
  }

  // ajax add-to-cart
  const qvForm = document.getElementById('qv-addform');
  if (qvForm){
    qvForm.addEventListener('submit', function(ev){
      ev.preventDefault();
      const id = document.getElementById('qv-id').value || '';
      const qtyVal = Math.max(1, parseInt(document.getElementById('qv-qty').value||1));
      const size = document.getElementById('qv-size').value || '';
      if (!id){ alert('Sản phẩm không hợp lệ'); return; }
      // nếu muốn bắt buộc chọn size, bật dòng dưới:
      // if (!size){ alert('Vui lòng chọn kích thước'); return; }

      const fd = new FormData();
      fd.append('action','add');
      fd.append('id', id);
      fd.append('qty', qtyVal);
      if (size) fd.append('size', size);
      fd.append('ajax','1');

      const btn = document.getElementById('qv-addbtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Đang thêm...';

      fetch('cart.php?action=add', { method:'POST', body: fd, credentials:'same-origin' })
        .then(r => r.json ? r.json() : r.text())
        .then(res => {
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-cart-plus me-2"></i> THÊM VÀO GIỎ';
          let json = res;
          if (typeof res === 'string'){ try{ json = JSON.parse(res);}catch(e){ json = null; } }
          if (json && json.success){
            // 1) Cập nhật badge
            if (json.cart && typeof json.cart.items_count !== 'undefined'){
              const badge = document.getElementById('cartBadge');
              if (badge) badge.textContent = json.cart.items_count;
              // nhẹ nhàng hiệu ứng
              if (badge){
                badge.classList.add('cart-pulse');
                setTimeout(()=> badge.classList.remove('cart-pulse'), 700);
              }
            }

            // 2) Cập nhật nội dung mini cart (nếu server gửi html và bạn vẫn muốn lưu cho dev/debug)
            const mini = document.getElementById('miniCartContent');
            if (mini){
              if (json.mini_cart_html){
                mini.innerHTML = json.mini_cart_html;
              } else if (json.cart && Array.isArray(json.cart.items)){
                // fallback: build HTML client-side từ data
                let html = '';
                if (json.cart.items.length === 0){
                  html = '<div class="small text-muted">Bạn chưa có sản phẩm nào trong giỏ.</div><div class="mt-3 d-grid gap-2"><a href="sanpham.php" class="btn btn-primary btn-sm">Mua ngay</a></div>';
                } else {
                  html += '<div style="max-height:240px;overflow:auto">';
                  let total = 0;
                  json.cart.items.forEach(it=>{
                    const qty = parseInt(it.qty||it.quantity||1);
                    const price = parseFloat(it.price||it.gia||0);
                    const subtotal = qty * price;
                    total += subtotal;
                    const img = it.img ? it.img : 'images/placeholder.jpg';
                    const name = it.name || it.ten || '';
                    html += '<div class="d-flex gap-2 align-items-center py-2">';
                    html += '<img src="'+img+'" style="width:56px;height:56px;object-fit:cover;border-radius:8px" alt="'+(name)+'">';
                    html += '<div class="flex-grow-1"><div class="small fw-semibold mb-1">'+(name)+'</div><div class="small text-muted">'+qty+' x '+new Intl.NumberFormat("vi-VN").format(price)+' ₫</div></div>';
                    html += '<div class="small">'+new Intl.NumberFormat("vi-VN").format(subtotal)+' ₫</div>';
                    html += '</div>';
                  });
                  html += '</div>';
                  html += '<div class="d-flex justify-content-between align-items-center mt-3"><div class="text-muted small">Tạm tính</div><div class="fw-semibold">'+new Intl.NumberFormat("vi-VN").format(total)+' ₫</div></div>';
                  html += '<div class="mt-3 d-grid gap-2"><a href="cart.php" class="btn btn-outline-secondary btn-sm">Giỏ hàng</a><a href="checkout.php" class="btn btn-primary btn-sm">Thanh toán</a></div>';
                }
                mini.innerHTML = html;
              }
            }

            // 3) Toast thông báo nhỏ
            const tmp = document.createElement('div'); tmp.className='toast-positioned';
            tmp.innerHTML = '<div class="toast align-items-center text-bg-success border-0 show" role="status" aria-live="polite" aria-atomic="true"><div class="d-flex"><div class="toast-body">Đã thêm vào giỏ hàng</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>';
            document.body.appendChild(tmp); setTimeout(()=> tmp.remove(), 2300);

            // đóng modal
            const modalEl = document.getElementById('quickViewModal'); const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
          } else {
            alert((json && json.message) ? json.message : 'Lỗi khi thêm vào giỏ hàng');
          }
        }).catch(err=>{
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-cart-plus me-2"></i> THÊM VÀO GIỎ';
          alert('Lỗi kết nối. Vui lòng thử lại.');
        });
    });
  }

  // buy now: add and go to checkout
  const buyBtn = document.getElementById('qv-buy');
  if (buyBtn){
    buyBtn.addEventListener('click', function(){
      const id = document.getElementById('qv-id').value || '';
      const qtyVal = Math.max(1, parseInt(document.getElementById('qv-qty').value||1));
      const size = document.getElementById('qv-size').value || '';
      if (!id){ alert('Sản phẩm không hợp lệ'); return; }
      const fd = new FormData();
      fd.append('action','add'); fd.append('id', id); fd.append('qty', qtyVal);
      if (size) fd.append('size', size);
      fetch('cart.php', { method:'POST', body: fd, credentials:'same-origin' })
        .then(()=> { window.location.href = 'checkout.php'; })
        .catch(()=> { window.location.href = 'cart.php'; });
    });
  }
});
</script>

<!-- ===== ENHANCED INTERACTION JS: ripple + tilt + keyboard UX ===== -->
<script>
document.addEventListener('DOMContentLoaded', function(){

  // Ripple for add buttons
  document.querySelectorAll('.btn-add-primary, .btn-add-danger').forEach(btn=>{
    btn.style.position = 'relative';
    btn.style.overflow = 'hidden';
    btn.addEventListener('click', function(e){
      const rect = this.getBoundingClientRect();
      const circle = document.createElement('span');
      const size = Math.max(rect.width, rect.height) * 1.5;
      circle.style.width = circle.style.height = size + 'px';
      circle.style.position = 'absolute';
      circle.style.left = (e.clientX - rect.left - size/2) + 'px';
      circle.style.top = (e.clientY - rect.top - size/2) + 'px';
      circle.style.background = 'rgba(255,255,255,0.18)';
      circle.style.borderRadius = '50%';
      circle.style.transform = 'scale(0)';
      circle.style.transition = 'transform .5s ease, opacity .6s ease';
      circle.style.pointerEvents = 'none';
      this.appendChild(circle);
      requestAnimationFrame(()=> circle.style.transform = 'scale(1)');
      setTimeout(()=> { circle.style.opacity = '0'; }, 300);
      setTimeout(()=> { try{ this.removeChild(circle); }catch(e){} }, 800);
    });
  });

  // subtle 3D tilt on .torano-card
  document.querySelectorAll('.torano-card').forEach(card=>{
    let rect = null;
    function handleMove(e){
      if (!rect) rect = card.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width; // 0..1
      const y = (e.clientY - rect.top) / rect.height;
      const rx = (y - 0.5) * 6; // rotateX
      const ry = (x - 0.5) * -6; // rotateY
      card.style.transform = `translateY(-8px) scale(1.01) rotateX(${rx}deg) rotateY(${ry}deg)`;
    }
    card.addEventListener('mousemove', handleMove);
    card.addEventListener('mouseleave', function(){
      rect = null;
      card.style.transform = '';
    });
  });

  // improve focus keyboard UX: allow Enter on card to open product
  document.querySelectorAll('.torano-card, .cat-card-pro').forEach(el=>{
    el.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ') {
        const a = el.querySelector('a') || el.querySelector('.card-media');
        if (a && a.href) window.location.href = a.href;
      }
    });
  });

});
</script>

</body>
</html>
