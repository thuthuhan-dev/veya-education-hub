<?php
// dashboard.php — Veya Education Hub (Student Dashboard)
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?next=dashboard.php");
    exit;
}
require_once "db_connect.php";

$user_id       = (int)$_SESSION['user_id'];
$pref_currency = $_SESSION['preferred_currency_code'] ?? 'MMK';

// ── Handle review deletion (user deletes their own review) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_review') {
    if (!csrf_verify()) { header("Location: dashboard.php"); exit; }
    $rev_id = (int)($_POST['review_id'] ?? 0);
    if ($rev_id > 0) {
        $s = $conn->prepare("DELETE FROM reviews WHERE review_id=? AND user_id=?");
        $s->bind_param("ii", $rev_id, $user_id);
        $s->execute(); $s->close();
    }
    header("Location: dashboard.php"); exit;
}
// ── User profile ──────────────────────────────────────────────────────
$u_stmt = $conn->prepare("
    SELECT u.*, c.country_name
    FROM users u
    LEFT JOIN countries c ON c.country_id = u.home_country_id
    WHERE u.user_id = ?
");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$user = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

// ── Exchange rates ────────────────────────────────────────────────────
$currency_symbols = [];
$cur_res = $conn->query("SELECT currency_code, symbol FROM currencies");
while ($r = $cur_res->fetch_assoc()) $currency_symbols[$r['currency_code']] = $r['symbol'];

$exchange_rates = [];
$rate_res = $conn->query("SELECT from_currency, to_currency, rate FROM exchange_rates");
while ($r = $rate_res->fetch_assoc()) $exchange_rates[$r['from_currency']][$r['to_currency']] = (float)$r['rate'];

function convert_fee($amount, $from, $to, $rates) {
    if ($from === $to) return $amount;
    if (isset($rates[$from][$to])) return $amount * $rates[$from][$to];
    if (isset($rates[$from]['USD']) && isset($rates['USD'][$to]))
        return $amount * $rates[$from]['USD'] * $rates['USD'][$to];
    if (isset($rates[$from]['MMK']) && isset($rates['MMK'][$to]))
        return $amount * $rates[$from]['MMK'] * $rates['MMK'][$to];
    return null;
}
function format_fee($amount, $currency, $symbols) {
    if ($amount === null) return null;
    $sym = $symbols[$currency] ?? $currency;
    if ($amount >= 1000000) return $sym . number_format($amount/1000000, 1) . 'M';
    if ($amount >= 1000)    return $sym . number_format($amount/1000, 0) . 'K';
    return $sym . number_format($amount, 0);
}

// ── Saved universities ────────────────────────────────────────────────
$saved_stmt = $conn->prepare("
    SELECT u.university_id, u.name, u.city, u.university_type, u.image_url,
           c.country_name, c.country_code, c.currency_code,
           AVG(r.rating) AS avg_rating,
           COUNT(DISTINCT p.program_id) AS program_count,
           MIN(tf.amount_local) AS min_fee,
           MIN(tf.local_currency_code) AS fee_currency,
           su.saved_at
    FROM saved_universities su
    JOIN universities u ON u.university_id = su.university_id
    JOIN countries c ON c.country_id = u.country_id
    LEFT JOIN reviews r ON r.university_id = u.university_id AND r.status = 'Approved'
    LEFT JOIN programs p ON p.university_id = u.university_id
    LEFT JOIN tuition_fees tf ON tf.program_id = p.program_id AND tf.fee_type = 'Tuition'
    WHERE su.user_id = ?
    GROUP BY su.saved_at, u.university_id, u.name, u.city, u.university_type,
             u.image_url, c.country_name, c.country_code, c.currency_code
    ORDER BY su.saved_at DESC
");
$saved_stmt->bind_param("i", $user_id);
$saved_stmt->execute();
$saved_unis = $saved_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$saved_stmt->close();

// ── Saved scholarships ────────────────────────────────────────────────
$saved_sch_stmt = $conn->prepare("
    SELECT s.scholarship_id, s.name AS sch_name, s.description,
           s.amount_local, s.local_currency_code, s.application_deadline,
           s.eligibility, s.award_type, s.renewable, s.application_url,
           u.name AS uni_name, u.university_id,
           c.country_name, c.country_code,
           ss.saved_at
    FROM saved_scholarships ss
    JOIN scholarships s ON s.scholarship_id = ss.scholarship_id
    JOIN universities u ON u.university_id = s.university_id
    JOIN countries c ON c.country_id = u.country_id
    WHERE ss.user_id = ?
    ORDER BY ss.saved_at DESC
");
$saved_sch_stmt->bind_param("i", $user_id);
$saved_sch_stmt->execute();
$saved_schs = $saved_sch_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$saved_sch_stmt->close();

// ── My reviews ────────────────────────────────────────────────────────
$rev_stmt = $conn->prepare("
    SELECT r.*, u.name AS uni_name, u.university_id, u.image_url AS uni_image,
           c.country_code
    FROM reviews r
    JOIN universities u ON u.university_id = r.university_id
    JOIN countries c ON c.country_id = u.country_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");
$rev_stmt->bind_param("i", $user_id);
$rev_stmt->execute();
$my_reviews = $rev_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rev_stmt->close();

// ── Stats ─────────────────────────────────────────────────────────────
$saved_count     = count($saved_unis);
$review_count    = count($my_reviews);
$approved_count  = count(array_filter($my_reviews, fn($r) => $r['status'] === 'Approved'));
$pending_count   = count(array_filter($my_reviews, fn($r) => $r['status'] === 'Pending'));

// ── Recommended scholarships (open ones user hasn't saved yet) ────────
$saved_sch_ids_arr = array_column($saved_schs, 'scholarship_id');
$exclude_in = !empty($saved_sch_ids_arr)
    ? 'AND s.scholarship_id NOT IN ('.implode(',', array_map('intval', $saved_sch_ids_arr)).')'
    : '';
$rec_sch = $conn->query("
    SELECT s.scholarship_id, s.name, s.amount_local, s.local_currency_code,
           s.application_deadline, u.name AS uni_name, u.university_id, c.country_code
    FROM scholarships s
    JOIN universities u ON u.university_id = s.university_id
    JOIN countries c ON c.country_id = u.country_id
    WHERE (s.application_deadline >= CURDATE() OR s.application_deadline IS NULL)
    $exclude_in
    ORDER BY s.application_deadline ASC
    LIMIT 4
")->fetch_all(MYSQLI_ASSOC);

$flags = ['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$fallback_photo = 'https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=600&q=80';
$member_since = $user['created_at'] ? date('M Y', strtotime($user['created_at'])) : 'Recently';

$avatar_colors = ['linear-gradient(135deg,#6366F1,#8B5CF6)','linear-gradient(135deg,#059669,#10B981)','linear-gradient(135deg,#D97706,#F59E0B)','linear-gradient(135deg,#DC2626,#EF4444)','linear-gradient(135deg,#0891B2,#06B6D4)'];
$avatar_color  = $avatar_colors[$user_id % count($avatar_colors)];
$initial       = strtoupper($user['full_name'][0] ?? 'U');

function safe_img($raw) {
    global $fallback_photo;
    $img = trim($raw ?? '');
    if (empty($img)) return $fallback_photo;
    $img = str_replace('\\', '/', $img);
    return str_starts_with($img, 'http') ? $img : htmlspecialchars($img, ENT_QUOTES, 'UTF-8');
}

$status_styles = [
    'Pending'  => ['bg'=>'#FEF3C7','color'=>'#92400E','icon'=>'⏳'],
    'Approved' => ['bg'=>'#ECFDF5','color'=>'#065F46','icon'=>'✅'],
    'Rejected' => ['bg'=>'#FEF2F2','color'=>'#991B1B','icon'=>'❌'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard — Veya</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F8FAFF;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
img{display:block;max-width:100%;}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.fu{animation:fadeUp .45s ease both;}
.d1{animation-delay:.05s}.d2{animation-delay:.1s}.d3{animation-delay:.15s}.d4{animation-delay:.2s}

/* NAV */
nav{position:sticky;top:0;z-index:100;background:rgba(255,255,255,.95);backdrop-filter:blur(16px);border-bottom:1px solid #E5E7EB;height:68px;padding:0 40px;display:flex;align-items:center;justify-content:space-between;gap:24px;}
.nav-logo{display:flex;align-items:center;gap:9px;}
.nav-logo img{height:28px;}
.nav-logo-text{font-size:18px;font-weight:800;letter-spacing:-.02em;color:#0A1628;}
.nav-links{display:flex;gap:4px;}
.nav-links a{font-size:14px;font-weight:500;color:#6B7280;padding:6px 14px;border-radius:8px;transition:all .15s;}
.nav-links a:hover,.nav-links a.active{color:#0A1628;background:#F3F4F6;font-weight:600;}
.nav-right{display:flex;align-items:center;gap:10px;}
.nav-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;}
.nav-name{font-size:14px;font-weight:600;color:#0A1628;}
.nav-logout{font-size:13px;font-weight:600;color:#6B7280;padding:7px 14px;border-radius:8px;border:1px solid #E5E7EB;transition:all .15s;}
.nav-logout:hover{background:#FEF2F2;color:#EF4444;border-color:#FECACA;}

/* WELCOME */
.welcome{background:linear-gradient(135deg,#0A1628 0%,#1E3A5F 100%);padding:48px 40px;position:relative;overflow:hidden;}
.welcome::before{content:"";position:absolute;top:-80px;right:-60px;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(26,86,219,.3),transparent 70%);pointer-events:none;}
.wc-inner{max-width:1240px;margin:0 auto;position:relative;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;}
.wc-left{display:flex;align-items:center;gap:18px;}
.wc-av{width:68px;height:68px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:#fff;border:3px solid rgba(255,255,255,.25);flex-shrink:0;}
.wc-h1{font-family:'Instrument Serif',serif;font-size:clamp(20px,3vw,30px);color:#fff;font-weight:400;margin-bottom:4px;}
.wc-sub{font-size:13px;color:rgba(255,255,255,.55);}
.wc-stats{display:flex;gap:10px;flex-wrap:wrap;}
.wstat{background:rgba(255,255,255,.1);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.15);border-radius:12px;padding:14px 18px;text-align:center;min-width:84px;}
.wstat-n{font-size:24px;font-weight:800;color:#fff;line-height:1;margin-bottom:3px;}
.wstat-l{font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.5);}

/* LAYOUT */
.wrap{max-width:1240px;margin:0 auto;padding:32px 40px;display:grid;grid-template-columns:1fr 310px;gap:24px;align-items:start;}

/* SECTION */
.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
.sec-title{font-size:17px;font-weight:800;color:#0A1628;display:flex;align-items:center;gap:8px;}
.sec-badge{background:#EFF6FF;color:#1A56DB;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;}
.sec-lnk{font-size:13px;font-weight:700;color:#1A56DB;}
.sec-lnk:hover{color:#1E40AF;}

/* SAVED GRID */
.saved-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:28px;}
.scard{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;transition:transform .2s,box-shadow .2s,border-color .2s;}
.scard:hover{transform:translateY(-3px);box-shadow:0 10px 28px rgba(10,22,40,.08);border-color:#BFDBFE;}
.scard-img{height:114px;position:relative;overflow:hidden;flex-shrink:0;}
.scard-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s;}
.scard:hover .scard-img img{transform:scale(1.06);}
.scard-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(10,22,40,.5),transparent 55%);}
.scard-badge{position:absolute;top:8px;left:8px;font-size:9px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:3px 8px;border-radius:99px;background:rgba(255,255,255,.9);color:#0A1628;}
.scard-unsave{position:absolute;top:8px;right:8px;width:26px;height:26px;border-radius:50%;background:rgba(255,255,255,.9);border:none;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;transition:all .15s;}
.scard-unsave:hover{background:#fff;transform:scale(1.12);}
.scard-body{padding:12px;flex:1;display:flex;flex-direction:column;}
.scard-country{font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280;margin-bottom:4px;}
.scard-name{font-size:14px;font-weight:700;color:#0A1628;line-height:1.3;margin-bottom:3px;}
.scard-city{font-size:12px;color:#9CA3AF;margin-bottom:9px;flex:1;}
.scard-foot{display:flex;align-items:center;justify-content:space-between;padding-top:9px;border-top:1px solid #F3F4F6;}
.scard-fee{font-size:13px;font-weight:800;color:#1A56DB;}
.scard-view{font-size:11px;font-weight:700;color:#1A56DB;background:#EFF6FF;padding:5px 10px;border-radius:6px;transition:background .15s;}
.scard-view:hover{background:#DBEAFE;}
.saved-empty{grid-column:1/-1;text-align:center;padding:48px 24px;background:#fff;border:1px dashed #E5E7EB;border-radius:14px;}
.saved-empty .ei{font-size:36px;margin-bottom:10px;}
.saved-empty h3{font-size:15px;font-weight:700;color:#0A1628;margin-bottom:6px;}
.saved-empty p{font-size:13px;color:#9CA3AF;margin-bottom:16px;}
.saved-empty a{display:inline-block;padding:9px 20px;background:#1A56DB;color:#fff;font-weight:700;font-size:13px;border-radius:8px;}

/* REVIEWS */
.rv-list{display:flex;flex-direction:column;gap:10px;margin-bottom:28px;}
.rv-item{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:14px;display:flex;gap:12px;align-items:flex-start;transition:box-shadow .2s;}
.rv-item:hover{box-shadow:0 4px 16px rgba(10,22,40,.06);}
.rv-thumb{width:48px;height:48px;border-radius:8px;object-fit:cover;flex-shrink:0;background:#F3F4F6;}
.rv-body{flex:1;min-width:0;}
.rv-uni{font-size:11px;font-weight:700;color:#6B7280;margin-bottom:2px;}
.rv-ttl{font-size:14px;font-weight:700;color:#0A1628;margin-bottom:3px;}
.rv-stars{display:flex;gap:2px;margin-bottom:5px;}
.son{color:#FBBF24;font-size:12px;}.soff{color:#E5E7EB;font-size:12px;}
.rv-cmt{font-size:13px;color:#6B7280;line-height:1.55;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.rv-meta{display:flex;align-items:center;gap:8px;margin-top:7px;}
.rv-status{font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:99px;}
.rv-date{font-size:11px;color:#9CA3AF;}
.rv-empty{text-align:center;padding:36px 24px;background:#fff;border:1px dashed #E5E7EB;border-radius:12px;}

/* SIDEBAR */
.sdb-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;margin-bottom:14px;}
.sdb-top{background:linear-gradient(135deg,#0A1628,#1E3A5F);padding:22px;text-align:center;}
.sdb-av{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff;border:3px solid rgba(255,255,255,.25);margin:0 auto 10px;}
.sdb-name{font-size:15px;font-weight:700;color:#fff;margin-bottom:3px;}
.sdb-email{font-size:11px;color:rgba(255,255,255,.5);}
.sdb-body{padding:14px;}
.sdb-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #F3F4F6;font-size:13px;}
.sdb-row:last-child{border-bottom:none;}
.sdb-label{color:#9CA3AF;font-weight:500;}
.sdb-val{font-weight:700;color:#0A1628;}
.sdb-edit{display:block;width:100%;margin-top:12px;padding:10px;text-align:center;font-size:13px;font-weight:700;color:#1A56DB;background:#EFF6FF;border-radius:8px;transition:background .15s;}
.sdb-edit:hover{background:#DBEAFE;}

/* Currency card */
.cur-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:14px;margin-bottom:14px;}
.cur-card-h{font-size:13px;font-weight:800;color:#0A1628;margin-bottom:10px;display:flex;align-items:center;gap:6px;}
.cur-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;}
.cur-btn{text-align:center;padding:8px 4px;border:1px solid #E5E7EB;border-radius:8px;font-size:12px;font-weight:700;color:#374151;cursor:pointer;background:#fff;font-family:'Plus Jakarta Sans',sans-serif;transition:all .15s;}
.cur-btn:hover{border-color:#1A56DB;color:#1A56DB;}
.cur-btn.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}
.cur-hint{font-size:11px;color:#9CA3AF;margin-top:8px;}

/* Scholarships */
.sch-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;margin-bottom:14px;}
.sch-card-h{padding:12px 14px;border-bottom:1px solid #F3F4F6;display:flex;align-items:center;justify-content:space-between;}
.sch-card-h h3{font-size:13px;font-weight:800;color:#0A1628;display:flex;align-items:center;gap:6px;}
.sch-item{padding:10px 14px;border-bottom:1px solid #F9FAFB;display:flex;gap:9px;align-items:flex-start;transition:background .15s;}
.sch-item:last-child{border-bottom:none;}
.sch-item:hover{background:#F9FAFB;}
.sch-item-icon{font-size:18px;flex-shrink:0;margin-top:1px;}
.sch-item-name{font-size:12px;font-weight:600;color:#0A1628;line-height:1.3;margin-bottom:2px;}
.sch-item-uni{font-size:11px;color:#9CA3AF;}
.sch-item-amt{font-size:12px;font-weight:700;color:#059669;margin-top:2px;}
.sch-item-dl{font-size:11px;font-weight:600;color:#EF4444;margin-top:1px;}
.sch-item-dl.ok{color:#059669;}

/* Quick actions */
.qa-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:14px;margin-bottom:14px;}
.qa-h{font-size:13px;font-weight:800;color:#0A1628;margin-bottom:10px;}
.qa-btn{display:flex;align-items:center;gap:9px;padding:10px 10px;border-radius:9px;font-size:13px;font-weight:600;color:#374151;transition:background .15s;margin-bottom:4px;}
.qa-btn:hover{background:#F3F4F6;color:#0A1628;}
.qa-btn:last-child{margin-bottom:0;}
.qa-icon{font-size:16px;width:24px;text-align:center;flex-shrink:0;}
.qa-red{color:#EF4444!important;}

/* Footer */
footer{background:#0A1628;margin-top:64px;padding:28px 40px;}
.ft-inner{max-width:1240px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;font-size:12px;color:rgba(255,255,255,.3);flex-wrap:wrap;gap:10px;}
.ft-links{display:flex;gap:18px;}
.ft-links a{color:rgba(255,255,255,.3);transition:color .15s;}
.ft-links a:hover{color:rgba(255,255,255,.7);}

@media(max-width:1024px){.wrap{grid-template-columns:1fr;}.saved-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:640px){.saved-grid{grid-template-columns:1fr;}.nav-links{display:none;}.welcome,.wrap{padding-left:20px;padding-right:20px;}.wc-inner{flex-direction:column;align-items:flex-start;}.wc-left{align-items:flex-start;}}
</style>
</head>
<body>

<!-- NAV -->
<?php $nav_active = 'dashboard'; include 'includes/nav.php'; ?>

<!-- WELCOME BANNER -->
<?php $is_new_user = isset($_GET['welcome']) && $_GET['welcome'] === '1'; ?>
<div class="welcome">
  <div class="wc-inner">
    <div class="wc-left">
      <div class="wc-av" style="background:<?= $avatar_color ?>"><?= $initial ?></div>
      <div>
        <div class="wc-h1"><?= $is_new_user ? 'Welcome to Veya, ' : 'Welcome back, ' ?><?= htmlspecialchars(explode(' ',$user['full_name'])[0]) ?>! <?= $is_new_user ? '🎉' : '👋' ?></div>
        <div class="wc-sub"><?= $is_new_user ? 'Your account is ready — start exploring universities!' : 'Member since '.$member_since.' · '.htmlspecialchars($pref_currency).' · '.htmlspecialchars($user['role']) ?></div>
      </div>
    </div>
    <div class="wc-stats">
      <div class="wstat"><div class="wstat-n"><?= $saved_count ?></div><div class="wstat-l">Saved</div></div>
      <div class="wstat"><div class="wstat-n"><?= $review_count ?></div><div class="wstat-l">Reviews</div></div>
      <div class="wstat"><div class="wstat-n"><?= $approved_count ?></div><div class="wstat-l">Published</div></div>
      <div class="wstat"><div class="wstat-n"><?= count($saved_schs) ?></div><div class="wstat-l">Saved Scholarships</div></div>
    </div>
  </div>
</div>

<!-- BODY -->
<div class="wrap">

  <!-- MAIN -->
  <div>

    <!-- Saved Universities -->
    <div class="sec-hd">
      <div class="sec-title">🏫 Saved Universities <?php if($saved_count): ?><span class="sec-badge"><?= $saved_count ?></span><?php endif; ?></div>
      <a href="universities.php" class="sec-lnk">Browse more →</a>
    </div>
    <div class="saved-grid fu d1">
      <?php if (!empty($saved_unis)): foreach ($saved_unis as $u):
        $photo = safe_img($u['image_url']);
        $fee_display = null;
        if ($u['min_fee'] && $u['fee_currency']) {
            $conv = convert_fee((float)$u['min_fee'], $u['fee_currency'], $pref_currency, $exchange_rates);
            $fee_display = format_fee($conv ?? (float)$u['min_fee'], $conv ? $pref_currency : $u['fee_currency'], $currency_symbols);
        }
      ?>
      <div class="scard" id="saved-<?= $u['university_id'] ?>">
        <div class="scard-img">
          <img src="<?= $photo ?>" alt="<?= htmlspecialchars($u['name']) ?>" loading="lazy">
          <div class="scard-overlay"></div>
          <span class="scard-badge"><?= htmlspecialchars($u['university_type']) ?></span>
          <button class="scard-unsave" onclick="unsave(<?= $u['university_id'] ?>)" title="Remove">❤️</button>
        </div>
        <div class="scard-body">
          <div class="scard-country"><?= $flags[$u['country_code']]??'' ?> <?= htmlspecialchars($u['country_name']) ?></div>
          <div class="scard-name"><?= htmlspecialchars($u['name']) ?></div>
          <div class="scard-city">📍 <?= htmlspecialchars($u['city'] ?? '—') ?></div>
          <div class="scard-foot">
            <div class="scard-fee"><?= $fee_display ? $fee_display.'/yr' : '—' ?></div>
            <a href="university_detail.php?id=<?= $u['university_id'] ?>" class="scard-view">View →</a>
          </div>
        </div>
      </div>
      <?php endforeach; else: ?>
      <div class="saved-empty">
        <div class="ei">🔖</div>
        <h3>No saved universities yet</h3>
        <p>Browse universities and click 🤍 to save ones you're interested in.</p>
        <a href="universities.php">Browse Universities</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- Saved Scholarships -->
    <div class="sec-hd">
      <div class="sec-title">🏅 Saved Scholarships
        <?php if(count($saved_schs)): ?>
          <span class="sec-badge"><?= count($saved_schs) ?></span>
        <?php endif; ?>
      </div>
      <a href="scholarships.php" style="font-size:13px;font-weight:600;color:#059669;">Browse more →</a>
    </div>
    <div class="fu d1" style="margin-bottom:28px;">
      <?php if(empty($saved_schs)): ?>
        <div style="text-align:center;padding:40px 20px;background:#fff;border:1px dashed #E5E7EB;border-radius:14px;">
          <div style="font-size:36px;margin-bottom:10px;">🏅</div>
          <div style="font-size:15px;font-weight:700;color:#0A1628;margin-bottom:6px;">No saved scholarships yet</div>
          <div style="font-size:13px;color:#9CA3AF;margin-bottom:16px;">Save scholarships to track deadlines and apply later.</div>
          <a href="scholarships.php" style="font-size:13px;font-weight:700;color:#fff;background:#059669;padding:10px 22px;border-radius:9px;text-decoration:none;">Browse Scholarships →</a>
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <?php foreach($saved_schs as $sch):
            $deadline = $sch['application_deadline'];
            $is_past  = $deadline && strtotime($deadline) < time();
            $days_left = $deadline ? ceil((strtotime($deadline)-time())/86400) : null;
            $flags = ['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
          ?>
          <div style="background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:16px;display:flex;align-items:flex-start;gap:14px;transition:box-shadow .2s;" onmouseover="this.style.boxShadow='0 4px 16px rgba(10,22,40,.08)'" onmouseout="this.style.boxShadow=''">
            <!-- Medal icon -->
            <div style="width:44px;height:44px;border-radius:10px;background:#ECFDF5;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;">🏅</div>
            <!-- Info -->
            <div style="flex:1;min-width:0;">
              <div style="font-size:14px;font-weight:700;color:#0A1628;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($sch['sch_name']) ?></div>
              <div style="font-size:12px;color:#6B7280;margin-bottom:6px;"><?= $flags[$sch['country_code']]??'' ?> <?= htmlspecialchars($sch['uni_name']) ?></div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <?php if($sch['award_type']): ?>
                  <span style="font-size:10px;font-weight:700;background:#EFF6FF;color:#1E40AF;padding:2px 8px;border-radius:99px;"><?= htmlspecialchars($sch['award_type']) ?></span>
                <?php endif; ?>
                <?php if($sch['renewable']): ?>
                  <span style="font-size:10px;font-weight:700;background:#F0FDF4;color:#065F46;padding:2px 8px;border-radius:99px;">🔄 Renewable</span>
                <?php endif; ?>
                <?php if(!$deadline): ?>
                  <span style="font-size:11px;color:#059669;font-weight:600;">Open — no deadline</span>
                <?php elseif($is_past): ?>
                  <span style="font-size:11px;color:#9CA3AF;">Deadline passed</span>
                <?php elseif($days_left <= 30): ?>
                  <span style="font-size:11px;color:#D97706;font-weight:700;">⚠️ <?= $days_left ?> days left</span>
                <?php else: ?>
                  <span style="font-size:11px;color:#374151;">📅 <?= date('d M Y', strtotime($deadline)) ?></span>
                <?php endif; ?>
              </div>
            </div>
            <!-- Actions -->
            <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;flex-shrink:0;">
              <?php if(!empty($sch['application_url'])): ?>
                <a href="<?= htmlspecialchars($sch['application_url']) ?>" target="_blank"
                   style="font-size:11px;font-weight:700;color:#fff;background:#059669;padding:6px 12px;border-radius:7px;white-space:nowrap;text-decoration:none;">Apply →</a>
              <?php endif; ?>
              <a href="university_detail.php?id=<?= $sch['university_id'] ?>&tab=scholarships"
                 style="font-size:11px;font-weight:600;color:#1A56DB;padding:5px 10px;background:#EFF6FF;border-radius:7px;white-space:nowrap;text-decoration:none;">View</a>
              <button onclick="unsaveScholarship(this,<?= $sch['scholarship_id'] ?>)"
                      style="font-size:11px;font-weight:600;color:#EF4444;background:#FEF2F2;padding:5px 10px;border-radius:7px;border:none;cursor:pointer;white-space:nowrap;">Remove</button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- My Reviews -->
    <div class="sec-hd">
      <div class="sec-title">✍️ My Reviews <?php if($review_count): ?><span class="sec-badge"><?= $review_count ?></span><?php endif; ?></div>
      <?php if($pending_count > 0): ?>
        <span style="font-size:12px;color:#92400E;background:#FEF3C7;padding:3px 10px;border-radius:99px;font-weight:600;">⏳ <?= $pending_count ?> pending approval</span>
      <?php endif; ?>
    </div>

    <?php if (!empty($my_reviews)): ?>
    <div class="rv-list fu d2">
      <?php foreach ($my_reviews as $rev):
        $ss = $status_styles[$rev['status']] ?? ['bg'=>'#F3F4F6','color'=>'#374151','icon'=>'?'];
        $filled = (int)$rev['rating'];
        $thumb = safe_img($rev['uni_image'] ?? '');
      ?>
      <div class="rv-item" id="rv-<?= $rev['review_id'] ?>">
        <img class="rv-thumb" src="<?= $thumb ?>" alt="" loading="lazy" onerror="this.style.background='#E5E7EB';this.src=''">
        <div class="rv-body">
          <div class="rv-uni"><?= $flags[$rev['country_code']]??'' ?> <?= htmlspecialchars($rev['uni_name']) ?></div>
          <?php if($rev['title']): ?><div class="rv-ttl"><?= htmlspecialchars($rev['title']) ?></div><?php endif; ?>
          <div class="rv-stars">
            <?php for($s=1;$s<=5;$s++) echo '<span class="'.($s<=$filled?'son':'soff').'">★</span>'; ?>
          </div>
          <div class="rv-cmt"><?= htmlspecialchars($rev['comment'] ?? '') ?></div>
          <div class="rv-meta">
            <span class="rv-status" style="background:<?= $ss['bg'] ?>;color:<?= $ss['color'] ?>">
              <?= $ss['icon'].' '.htmlspecialchars($rev['status']) ?>
            </span>
            <span class="rv-date"><?= date('d M Y', strtotime($rev['created_at'])) ?></span>
            <button onclick="deleteReview(<?= $rev['review_id'] ?>)"
                    style="margin-left:auto;font-size:11px;font-weight:700;color:#EF4444;background:#FEF2F2;border:none;padding:4px 10px;border-radius:6px;cursor:pointer;transition:all .15s;"
                    onmouseover="this.style.background='#EF4444';this.style.color='#fff'"
                    onmouseout="this.style.background='#FEF2F2';this.style.color='#EF4444'">
              🗑️ Delete
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="rv-empty fu d2">
      <div style="font-size:28px;margin-bottom:8px;">💬</div>
      <div style="font-size:14px;font-weight:700;color:#0A1628;margin-bottom:5px;">No reviews yet</div>
      <div style="font-size:12px;color:#9CA3AF;margin-bottom:14px;">Share your experience to help other students.</div>
      <a href="universities.php" style="display:inline-block;padding:8px 18px;background:#1A56DB;color:#fff;border-radius:8px;font-size:13px;font-weight:700;">Find a University</a>
    </div>
    <?php endif; ?>

  </div><!-- /main -->

  <!-- SIDEBAR -->
  <aside>

    <!-- Profile -->
    <div class="sdb-card fu d1">
      <div class="sdb-top">
        <div class="sdb-av" style="background:<?= $avatar_color ?>"><?= $initial ?></div>
        <div class="sdb-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="sdb-email"><?= htmlspecialchars($user['email']) ?></div>
      </div>
      <div class="sdb-body">
        <div class="sdb-row"><span class="sdb-label">Role</span><span class="sdb-val"><?= htmlspecialchars($user['role']) ?></span></div>
        <div class="sdb-row"><span class="sdb-label">Home Country</span><span class="sdb-val"><?= htmlspecialchars($user['country_name'] ?? 'Not set') ?></span></div>
        <div class="sdb-row"><span class="sdb-label">Currency</span><span class="sdb-val"><?= htmlspecialchars($pref_currency) ?></span></div>
        <div class="sdb-row"><span class="sdb-label">Member Since</span><span class="sdb-val"><?= $member_since ?></span></div>
        <a href="profile.php" class="sdb-edit">✏️ Edit Profile</a>
      </div>
    </div>

    <!-- Currency preference -->
    <div class="cur-card fu d2">
      <div class="cur-card-h">💱 Fee Display Currency</div>
      <form method="POST" action="profile.php">
        <input type="hidden" name="action" value="update_currency">
        <div class="cur-grid">
          <?php foreach(['MMK','THB','SGD','MYR','USD','EUR'] as $c): ?>
            <button type="submit" name="currency" value="<?= $c ?>"
                    class="cur-btn <?= $pref_currency===$c?'active':'' ?>">
              <?= $c ?>
            </button>
          <?php endforeach; ?>
        </div>
      </form>
      <div class="cur-hint">Click any currency to update your preference.</div>
    </div>

    <!-- Scholarships -->
    <?php if (!empty($rec_sch)): ?>
    <div class="sch-card fu d3">
      <div class="sch-card-h">
        <h3>🏅 Open Scholarships</h3>
        <a href="scholarships.php" style="font-size:11px;font-weight:700;color:#1A56DB;">View all →</a>
      </div>
      <?php foreach($rec_sch as $s):
        $dl = $s['application_deadline'];
        $ok = !$dl || strtotime($dl) >= time();
        $fee_disp = null;
        if ($s['amount_local'] && $s['local_currency_code']) {
            $conv = convert_fee((float)$s['amount_local'], $s['local_currency_code'], $pref_currency, $exchange_rates);
            $fee_disp = format_fee($conv ?? (float)$s['amount_local'], $conv ? $pref_currency : $s['local_currency_code'], $currency_symbols);
        }
      ?>
      <a href="university_detail.php?id=<?= $s['university_id'] ?>&tab=scholarships" class="sch-item">
        <div class="sch-item-icon"><?= $flags[$s['country_code']]??'🏅' ?></div>
        <div>
          <div class="sch-item-name"><?= htmlspecialchars(mb_strimwidth($s['name'],0,42,'…')) ?></div>
          <div class="sch-item-uni"><?= htmlspecialchars(mb_strimwidth($s['uni_name'],0,28,'…')) ?></div>
          <?php if($fee_disp): ?><div class="sch-item-amt"><?= $fee_disp ?>/yr</div><?php endif; ?>
          <?php if($dl): ?>
            <div class="sch-item-dl <?= $ok?'ok':'' ?>"><?= $ok?'📅 '.date('d M Y',strtotime($dl)):'❌ Closed' ?></div>
          <?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Quick actions -->
    <div class="qa-card fu d4">
      <div class="qa-h">⚡ Quick Actions</div>
      <a href="universities.php" class="qa-btn"><span class="qa-icon">🔍</span>Browse Universities</a>
      <a href="scholarships.php" class="qa-btn"><span class="qa-icon">🏅</span>Find Scholarships</a>
      <a href="compare.php"      class="qa-btn"><span class="qa-icon">⚖️</span>Compare Universities</a>
      <a href="profile.php"      class="qa-btn"><span class="qa-icon">👤</span>Edit My Profile</a>
      <?php if($user['role']==='Admin'): ?>
      <a href="admin/dashboard.php" class="qa-btn" style="color:#1A56DB;"><span class="qa-icon">⚙️</span>Admin Dashboard</a>
      <?php endif; ?>
      <a href="logout.php" class="qa-btn qa-red"><span class="qa-icon">🚪</span>Log Out</a>
    </div>

  </aside>
</div>

<!-- FOOTER -->

<?php include 'includes/footer.php'; ?>


<script>
function unsave(id) {
    if(!confirm('Remove this university from your saved list?')) return;
    fetch(`save_university.php?id=${id}&action=remove`)
        .then(() => {
            const card = document.getElementById('saved-'+id);
            card.style.transition='opacity .3s,transform .3s';
            card.style.opacity='0';card.style.transform='scale(.95)';
            setTimeout(()=>{ card.remove(); }, 300);
        }).catch(()=>{});
}
function unsaveScholarship(btn, id) {
    if(!confirm('Remove this scholarship from your saved list?')) return;
    btn.disabled = true; btn.textContent = 'Removing…';
    fetch(`save_scholarship.php?id=${id}&action=remove`)
        .then(() => {
            const row = btn.closest('[style*="background:#fff"]');
            if(row){ row.style.transition='opacity .3s'; row.style.opacity='0'; setTimeout(()=>row.remove(),300); }
        })
        .catch(() => { btn.disabled=false; btn.textContent='Remove'; });
}
function deleteReview(reviewId) {
    if(!confirm('Delete this review? This cannot be undone.')) return;
    const card = document.getElementById('rv-' + reviewId);
    if(card){ card.style.transition='opacity .3s'; card.style.opacity='0'; }
    const form = document.createElement('form');
    form.method = 'POST'; form.action = 'dashboard.php';
    form.innerHTML = `<input type="hidden" name="action" value="delete_review">`
                   + `<input type="hidden" name="review_id" value="${reviewId}">`
                   + `<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">`;
    document.body.appendChild(form);
    setTimeout(() => form.submit(), 300);
}
</script>
</body>
</html>