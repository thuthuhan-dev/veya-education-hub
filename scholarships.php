<?php
// scholarships.php — Veya Education Hub
session_start();
require_once "db_connect.php";

// Pre-load saved scholarship IDs for current user
$saved_sch_ids = [];
if (isset($_SESSION['user_id'])) {
    $sv_uid = (int)$_SESSION['user_id'];
    $sv = $conn->prepare("SELECT scholarship_id FROM saved_scholarships WHERE user_id=?");
    $sv->bind_param("i", $sv_uid); $sv->execute();
    foreach ($sv->get_result()->fetch_all(MYSQLI_ASSOC) as $r)
        $saved_sch_ids[] = (int)$r['scholarship_id'];
    $sv->close();
}

// ── Currency ──────────────────────────────────────────────────────────
$pref_currency = $_GET['currency'] ?? $_SESSION['preferred_currency_code'] ?? 'MMK';
$valid_currencies = ['MMK','THB','SGD','MYR','USD','EUR'];
if (!in_array($pref_currency, $valid_currencies)) $pref_currency = 'MMK';

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
    if ($amount >= 1000000) return $sym . number_format($amount/1000000, 2) . 'M';
    if ($amount >= 1000)    return $sym . number_format($amount/1000, 1) . 'K';
    return $sym . number_format($amount, 0);
}

// ── GET params ────────────────────────────────────────────────────────
$search         = trim($_GET['q']        ?? '');
$filter_countries = array_map('intval', array_filter($_GET['countries'] ?? []));
$filter_type    = $_GET['type']          ?? ''; // full | partial
$filter_status  = $_GET['status']        ?? ''; // open | closing_soon
$sort           = $_GET['sort']          ?? 'deadline';
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 9;
$offset         = ($page - 1) * $per_page;

// ── Sidebar data ──────────────────────────────────────────────────────
$countries_list = $conn->query("
    SELECT c.country_id, c.country_name, c.country_code, c.cnt
    FROM (
        SELECT c.country_id, c.country_name, c.country_code,
               COUNT(DISTINCT u.university_id) AS cnt
        FROM countries c
        LEFT JOIN universities u ON u.country_id = c.country_id AND u.is_active = 1
        WHERE c.is_active = 1 AND c.has_universities = 1
        GROUP BY c.country_id, c.country_name, c.country_code
    ) c
    ORDER BY c.country_name
");

// ── WHERE clause ──────────────────────────────────────────────────────
$where_parts = ["u.is_active = 1"];
$params = []; $types = "";

if ($search !== '') {
    $where_parts[] = "(s.name LIKE ? OR s.description LIKE ? OR s.eligibility LIKE ? OR u.name LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "ssss";
}
if (!empty($filter_countries)) {
    $in_ph = implode(',', array_fill(0, count($filter_countries), '?'));
    $where_parts[] = "c.country_id IN ($in_ph)";
    foreach ($filter_countries as $cid) { $params[] = $cid; $types .= 'i'; }
}
if ($filter_type === 'full') {
    // "Full" = amount covers tuition fully — we treat higher amounts as full
    $where_parts[] = "s.amount_local >= 10000";
}
if ($filter_status === 'open') {
    $where_parts[] = "(s.application_deadline IS NULL OR s.application_deadline >= CURDATE())";
}
if ($filter_status === 'closing_soon') {
    $where_parts[] = "(s.application_deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY))";
}

$where_sql = "WHERE " . implode(" AND ", $where_parts);

$order_sql = match($sort) {
    'amount'     => "ORDER BY s.amount_local DESC",
    'name_asc'   => "ORDER BY s.name ASC",
    'newest'     => "ORDER BY s.scholarship_id DESC",
    default      => "ORDER BY s.application_deadline ASC",
};

// ── Count ─────────────────────────────────────────────────────────────
$count_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM scholarships s
    JOIN universities u ON u.university_id = s.university_id
    JOIN countries c ON c.country_id = u.country_id
    $where_sql
");
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, ceil($total / $per_page));
$count_stmt->close();

// ── Main query ────────────────────────────────────────────────────────
$all_params = [...$params, $per_page, $offset];
$all_types  = $types . "ii";

$stmt = $conn->prepare("
    SELECT s.scholarship_id, s.name, s.description, s.eligibility,
           s.amount_local, s.local_currency_code, s.application_deadline,
           u.university_id, u.name AS uni_name, u.image_url, u.university_type,
           c.country_name, c.country_code, c.currency_code
    FROM scholarships s
    JOIN universities u ON u.university_id = s.university_id
    JOIN countries c ON c.country_id = u.country_id
    $where_sql
    $order_sql
    LIMIT ? OFFSET ?
");
if ($all_types) $stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$scholarships = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Helpers ───────────────────────────────────────────────────────────
$flags = ['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$today = new DateTime();

function deadline_status($deadline_str) {
    if (!$deadline_str) return ['label'=>'Open', 'class'=>'ok', 'days'=>null];
    $d    = new DateTime($deadline_str);
    $now  = new DateTime();
    $diff = (int)$now->diff($d)->days;
    $past = $d < $now;
    if ($past)       return ['label'=>'Deadline passed', 'class'=>'past',  'days'=>-$diff];
    if ($diff <= 30) return ['label'=>"Closes in $diff days", 'class'=>'urgent', 'days'=>$diff];
    if ($diff <= 90) return ['label'=>"Closes in $diff days", 'class'=>'soon',   'days'=>$diff];
    return ['label'=>'Open — '.date('d M Y', strtotime($deadline_str)), 'class'=>'ok', 'days'=>$diff];
}

function build_qs_sch($overrides=[]) {
    global $search,$filter_countries,$filter_type,$filter_status,$sort,$pref_currency;
    $params = array_filter([
        'q'=>$search,'type'=>$filter_type,'status'=>$filter_status,
        'sort'=>$sort??'','currency'=>$pref_currency!='MMK'?$pref_currency:'',
    ]);
    $params = array_merge($params, $overrides);
    if (!isset($overrides['countries'])) {
        if (!empty($filter_countries)) $params['countries'] = $filter_countries;
    } elseif (empty($overrides['countries'])) { unset($params['countries']); }
    return http_build_query($params);
}

$active_filters = array_filter([$search, !empty($filter_countries)?1:0, $filter_type, $filter_status]);

// ── Stats ─────────────────────────────────────────────────────────────
$stats = $conn->query("
    SELECT
      COUNT(*) AS total_sch,
      SUM(CASE WHEN s.application_deadline >= CURDATE() OR s.application_deadline IS NULL THEN 1 ELSE 0 END) AS open_sch,
      SUM(CASE WHEN s.application_deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS closing_soon,
      COUNT(DISTINCT s.university_id) AS unis_with_sch
    FROM scholarships s
    JOIN universities u ON u.university_id = s.university_id AND u.is_active = 1
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Scholarships — Veya Education Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F8FAFF;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
img{display:block;max-width:100%;}

@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .45s ease both;}
.d1{animation-delay:.05s}.d2{animation-delay:.1s}.d3{animation-delay:.15s}
.d4{animation-delay:.2s}.d5{animation-delay:.25s}.d6{animation-delay:.3s}
.d7{animation-delay:.35s}.d8{animation-delay:.4s}.d9{animation-delay:.45s}

/* ── NAV ── */
nav{position:sticky;top:0;z-index:100;background:rgba(255,255,255,.95);backdrop-filter:blur(16px);border-bottom:1px solid #E5E7EB;height:68px;padding:0 40px;display:flex;align-items:center;justify-content:space-between;gap:32px;}
.nav-logo{display:flex;align-items:center;gap:9px;}
.nav-logo img{height:28px;}
.nav-logo-text{font-size:18px;font-weight:800;letter-spacing:-.02em;color:#0A1628;}
.nav-links{display:flex;gap:4px;}
.nav-links a{font-size:14px;font-weight:500;color:#6B7280;padding:6px 14px;border-radius:8px;transition:all .15s;}
.nav-links a:hover,.nav-links a.active{color:#0A1628;background:#F3F4F6;font-weight:600;}
.nav-right{display:flex;gap:10px;align-items:center;}
.nav-btn-l{font-size:13px;font-weight:600;color:#374151;padding:8px 18px;border-radius:8px;border:1px solid #E5E7EB;transition:all .15s;}
.nav-btn-l:hover{background:#F9FAFB;}
.nav-btn-s{font-size:13px;font-weight:700;color:#fff;background:#1A56DB;padding:8px 20px;border-radius:8px;transition:background .15s;}
.nav-btn-s:hover{background:#1E40AF;}
.nav-user{font-size:14px;color:#374151;display:flex;align-items:center;gap:12px;}
.nav-user a{font-weight:700;color:#1A56DB;}

/* ── HERO ── */
.page-hero{background:linear-gradient(135deg,#064E3B 0%,#065F46 50%,#047857 100%);padding:56px 40px 52px;position:relative;overflow:hidden;}
.page-hero::before{content:"";position:absolute;top:-80px;right:-80px;width:360px;height:360px;border-radius:50%;background:radial-gradient(circle,rgba(16,185,129,.3),transparent 70%);pointer-events:none;}
.page-hero::after{content:"";position:absolute;bottom:-60px;left:5%;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,rgba(5,150,105,.25),transparent 70%);pointer-events:none;}
.hero-inner{max-width:1240px;margin:0 auto;position:relative;z-index:2;}
.hero-bc{display:flex;align-items:center;gap:8px;font-size:12px;color:rgba(255,255,255,.5);margin-bottom:18px;}
.hero-bc a{color:rgba(255,255,255,.5);transition:color .15s;}
.hero-bc a:hover{color:#fff;}
.hero-bc span{color:rgba(255,255,255,.25);}
.hero-title{font-family:'Instrument Serif',serif;font-weight:400;font-size:clamp(28px,4vw,48px);color:#fff;letter-spacing:-.02em;margin-bottom:8px;}
.hero-title em{font-style:italic;color:#6EE7B7;}
.hero-sub{font-size:16px;color:rgba(255,255,255,.65);max-width:52ch;line-height:1.6;margin-bottom:28px;}
.hero-stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:28px;}
.hstat{display:flex;flex-direction:column;align-items:center;background:rgba(255,255,255,.12);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.18);border-radius:12px;padding:14px 22px;min-width:100px;text-align:center;}
.hstat-num{font-size:22px;font-weight:800;color:#fff;line-height:1;margin-bottom:3px;}
.hstat-label{font-size:10px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;color:rgba(255,255,255,.55);}
.currency-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.cur-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.45);}
.cur-pills{display:flex;gap:6px;flex-wrap:wrap;}
.cur-pill{font-size:12px;font-weight:700;padding:5px 13px;border-radius:99px;border:1px solid rgba(255,255,255,.25);color:rgba(255,255,255,.75);transition:all .15s;}
.cur-pill:hover{border-color:rgba(255,255,255,.6);color:#fff;}
.cur-pill.active{background:#059669;border-color:#059669;color:#fff;}

/* ── SEARCH ── */
.search-strip{background:#fff;border-bottom:1px solid #E5E7EB;padding:20px 40px;}
.search-form{max-width:1240px;margin:0 auto;display:flex;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:12px;overflow:hidden;transition:border-color .2s,box-shadow .2s;}
.search-form:focus-within{border-color:#6EE7B7;box-shadow:0 0 0 4px rgba(16,185,129,.12);}
.sf-input{flex:1;border:none;background:transparent;outline:none;font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;color:#0A1628;padding:14px 20px;}
.sf-input::placeholder{color:#9CA3AF;}
.sf-sep{width:1px;background:#E5E7EB;margin:10px 0;}
.sf-select{border:none;background:transparent;outline:none;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:600;color:#374151;padding:0 16px;cursor:pointer;}
.sf-btn{margin:8px;padding:10px 28px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;color:#fff;background:#059669;border:none;border-radius:8px;cursor:pointer;transition:background .15s;white-space:nowrap;display:flex;align-items:center;gap:8px;}
.sf-btn:hover{background:#047857;}

/* ── LAYOUT ── */
.main-wrap{max-width:1240px;margin:0 auto;padding:32px 40px;display:grid;grid-template-columns:260px 1fr;gap:28px;align-items:start;}

/* ── SIDEBAR ── */
.sidebar{background:#fff;border:1px solid #E5E7EB;border-radius:16px;overflow:hidden;position:sticky;top:88px;}
.sb-head{padding:16px 20px 14px;border-bottom:1px solid #F3F4F6;display:flex;align-items:center;justify-content:space-between;}
.sb-head h3{font-size:14px;font-weight:800;color:#0A1628;display:flex;align-items:center;gap:8px;}
.sb-head h3 .badge{background:#059669;color:#fff;font-size:10px;padding:2px 7px;border-radius:99px;}
.sb-reset{font-size:12px;font-weight:700;color:#EF4444;}
.sb-reset:hover{color:#DC2626;}
.sb-sec{padding:14px 20px;border-bottom:1px solid #F9FAFB;}
.sb-sec:last-child{border-bottom:none;}
.sb-sec-title{font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#9CA3AF;margin-bottom:10px;}
.sb-opt{display:flex;align-items:center;justify-content:space-between;padding:5px 0;}
.sb-opt label{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:500;color:#374151;cursor:pointer;}
.sb-opt input[type="radio"]{width:15px;height:15px;accent-color:#059669;cursor:pointer;flex-shrink:0;}
.sb-opt:hover label{color:#0A1628;}
.sb-count{font-size:11px;font-weight:600;color:#9CA3AF;background:#F3F4F6;padding:2px 7px;border-radius:99px;}

/* Tip card */
.sb-tip{margin:12px;border-radius:12px;background:linear-gradient(135deg,#064E3B,#065F46);padding:16px;}
.sb-tip-tag{font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#6EE7B7;margin-bottom:8px;}
.sb-tip p{font-size:12px;color:rgba(255,255,255,.7);line-height:1.55;margin-bottom:12px;}
.sb-tip a{display:block;text-align:center;font-size:12px;font-weight:700;color:#064E3B;background:#fff;padding:8px;border-radius:7px;}

/* ── RESULTS AREA ── */
.results-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px;flex-wrap:wrap;}
.results-count{font-size:14px;color:#6B7280;font-weight:500;}
.results-count strong{color:#0A1628;font-weight:700;font-size:16px;}
.sort-row{display:flex;align-items:center;gap:8px;}
.sort-label{font-size:13px;color:#9CA3AF;}
.sort-sel{font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;color:#0A1628;background:#fff;border:1px solid #E5E7EB;padding:8px 12px;border-radius:8px;cursor:pointer;outline:none;}
.sort-sel:focus{border-color:#6EE7B7;}

.filter-tags{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px;}
.ftag{display:inline-flex;align-items:center;gap:5px;background:#ECFDF5;color:#065F46;border:1px solid #A7F3D0;font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px;}
.ftag a{color:#6EE7B7;font-size:13px;margin-left:2px;transition:color .15s;}
.ftag a:hover{color:#059669;}

/* ── SCHOLARSHIP CARDS ── */
.sch-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
/* Tablet: 2 columns, but last lone card stays at 50% width not full width */
@media(max-width:1100px) and (min-width:769px){
    .sch-grid{grid-template-columns:repeat(2,1fr);}
}
/* Last odd card in 2-col grid: keep at half width instead of full width */
@media(max-width:1100px) and (min-width:769px){
    .sch-grid .sch-card:last-child:nth-child(odd){
        max-width:calc(50% - 10px);
        justify-self:start;
    }
}

.sch-card{background:#fff;border:1px solid #E5E7EB;border-radius:16px;overflow:hidden;display:flex;flex-direction:column;transition:transform .25s,box-shadow .25s,border-color .2s;}
.sch-card:hover{transform:translateY(-5px);box-shadow:0 16px 40px rgba(10,22,40,.09);border-color:#A7F3D0;}

/* Card top banner */
.sch-banner{height:90px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;}
.sch-banner img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.25;}
.sch-banner-overlay{position:absolute;inset:0;background:inherit;}
.sch-icon{position:relative;z-index:2;font-size:32px;}
.sch-country-badge{position:absolute;top:10px;left:10px;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:3px 9px;border-radius:99px;background:rgba(255,255,255,.9);color:#0A1628;z-index:2;}
.sch-save-btn{position:absolute;top:10px;right:10px;width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.9);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;z-index:2;transition:all .2s;box-shadow:0 2px 6px rgba(10,22,40,.12);}
.sch-save-btn:hover{background:#fff;transform:scale(1.1);}
.sch-save-btn.saved{background:#FEE2E2;box-shadow:0 2px 8px rgba(239,68,68,.3);}

/* Card body */
.sch-body{padding:16px;flex:1;display:flex;flex-direction:column;}
.sch-uni-name{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#9CA3AF;margin-bottom:6px;display:flex;align-items:center;gap:5px;}
.sch-name{font-size:15px;font-weight:700;color:#0A1628;line-height:1.3;margin-bottom:8px;}
.sch-desc{font-size:13px;color:#6B7280;line-height:1.6;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:12px;flex:1;}

/* Amount row */
.sch-amount-row{display:flex;align-items:center;justify-content:space-between;background:#ECFDF5;border-radius:8px;padding:10px 12px;margin-bottom:10px;}
.sch-amount-label{font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#065F46;}
.sch-amount-val{font-size:18px;font-weight:800;color:#059669;}
.sch-amount-per{font-size:10px;color:#6EE7B7;font-weight:500;}

/* Deadline */
.sch-deadline{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:6px 10px;border-radius:7px;margin-bottom:10px;}
.sch-deadline.ok{background:#F0FDF4;color:#059669;}
.sch-deadline.soon{background:#FEF3C7;color:#92400E;}
.sch-deadline.urgent{background:#FEF2F2;color:#DC2626;}
.sch-deadline.past{background:#F3F4F6;color:#9CA3AF;}

/* Eligibility */
.sch-elig{font-size:12px;color:#374151;background:#F9FAFB;border-radius:7px;padding:9px 10px;line-height:1.55;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:12px;}
.sch-elig-label{font-size:9px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;display:block;margin-bottom:3px;}

/* Actions */
.sch-actions{display:flex;gap:8px;}
.sch-btn-view{flex:1;text-align:center;font-size:13px;font-weight:700;color:#fff;background:#059669;padding:10px;border-radius:9px;transition:background .15s;}
.sch-btn-view:hover{background:#047857;}
.sch-btn-uni{font-size:13px;font-weight:600;color:#374151;background:#F3F4F6;padding:10px 14px;border-radius:9px;transition:background .15s;}
.sch-btn-uni:hover{background:#E5E7EB;}

/* Empty */
.empty-state{grid-column:1/-1;text-align:center;padding:80px;background:#fff;border:1px solid #E5E7EB;border-radius:16px;}

/* ── PAGINATION ── */
.pagination{display:flex;align-items:center;justify-content:center;gap:5px;margin-top:36px;}
.pg-btn{min-width:38px;height:38px;padding:0 12px;display:inline-flex;align-items:center;justify-content:center;border-radius:9px;font-size:14px;font-weight:600;border:1px solid #E5E7EB;color:#374151;background:#fff;transition:all .15s;}
.pg-btn:hover:not(.disabled):not(.active){border-color:#059669;color:#059669;}
.pg-btn.active{background:#059669;border-color:#059669;color:#fff;}
.pg-btn.disabled{opacity:.35;pointer-events:none;}
.pg-dots{font-size:14px;color:#D1D5DB;padding:0 4px;}

/* ── FOOTER ── */
footer{background:#0A1628;margin-top:72px;padding:56px 40px 0;}
.footer-top{max-width:1240px;margin:0 auto;display:grid;grid-template-columns:2fr 1fr 1fr 1.5fr;gap:48px;padding-bottom:48px;border-bottom:1px solid rgba(255,255,255,.08);}
.footer-brand img{height:26px;filter:brightness(0) invert(1);opacity:.7;margin-bottom:12px;}
.footer-brand-name{font-size:17px;font-weight:800;color:rgba(255,255,255,.8);margin-bottom:8px;}
.footer-brand-desc{font-size:13px;color:rgba(255,255,255,.4);line-height:1.7;max-width:26ch;}
.footer-col h4{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:rgba(255,255,255,.35);margin-bottom:14px;}
.footer-col a{display:block;font-size:14px;color:rgba(255,255,255,.5);margin-bottom:9px;transition:color .15s;}
.footer-col a:hover{color:rgba(255,255,255,.85);}
.footer-nl-desc{font-size:13px;color:rgba(255,255,255,.45);line-height:1.6;margin-bottom:12px;}
.footer-nl-form{display:flex;border-radius:9px;overflow:hidden;border:1px solid rgba(255,255,255,.15);}
.footer-nl-input{flex:1;border:none;background:rgba(255,255,255,.07);outline:none;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;color:#fff;padding:11px 14px;}
.footer-nl-input::placeholder{color:rgba(255,255,255,.3);}
.footer-nl-btn{background:#059669;border:none;color:#fff;padding:0 14px;cursor:pointer;font-size:18px;transition:background .15s;}
.footer-nl-btn:hover{background:#047857;}
.footer-bottom{max-width:1240px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;padding:20px 0;font-size:12px;color:rgba(255,255,255,.3);flex-wrap:wrap;gap:10px;}
.footer-bottom-links{display:flex;gap:20px;}
.footer-bottom-links a{color:rgba(255,255,255,.3);transition:color .15s;}
.footer-bottom-links a:hover{color:rgba(255,255,255,.6);}

/* Responsive */

@media(max-width:860px){
  .main-wrap{grid-template-columns:1fr;}
  .sidebar{position:static;display:none;}
  .sidebar.mob-open{display:block;}
  .nav-links{display:none;}
  .page-hero,.search-strip,.main-wrap{padding-left:20px;padding-right:20px;}
  .mob-filter-btn{display:flex!important;}
}
.mob-filter-btn{
  display:none;width:100%;align-items:center;justify-content:center;gap:8px;
  padding:11px;background:#fff;border:1.5px solid #E5E7EB;border-radius:10px;
  font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#374151;
  cursor:pointer;margin-bottom:12px;transition:all .15s;
}
.mob-filter-btn:hover{border-color:#059669;color:#059669;}
.mob-filter-active{border-color:#059669;color:#059669;background:#ECFDF5;}
@media(max-width:768px){.sch-grid{grid-template-columns:1fr;}.footer-top{grid-template-columns:1fr;}}
</style>
</head>
<body>

<?php $nav_active = 'scholarships'; include 'includes/nav.php'; ?>

<!-- HERO -->
<div class="page-hero">
  <div class="hero-inner">
    <div class="hero-bc">
      <a href="index.php">Home</a><span>/</span><span>Scholarships</span>
    </div>
    <h1 class="hero-title">Find Your <em>Scholarship</em></h1>
    <p class="hero-sub">Discover funding opportunities at universities across Thailand, Singapore, and Malaysia — fees shown in your preferred currency.</p>

    <div class="hero-stats">
      <div class="hstat">
        <div class="hstat-num"><?= $stats['total_sch'] ?></div>
        <div class="hstat-label">Total</div>
      </div>
      <div class="hstat">
        <div class="hstat-num"><?= $stats['open_sch'] ?></div>
        <div class="hstat-label">Open Now</div>
      </div>
      <div class="hstat">
        <div class="hstat-num"><?= $stats['closing_soon'] ?></div>
        <div class="hstat-label">Closing Soon</div>
      </div>
      <div class="hstat">
        <div class="hstat-num"><?= $stats['unis_with_sch'] ?></div>
        <div class="hstat-label">Universities</div>
      </div>
    </div>

    <div class="currency-row">
      <span class="cur-label">View amounts in:</span>
      <div class="cur-pills">
        <?php foreach (['MMK','THB','SGD','MYR','USD','EUR'] as $c): ?>
          <a href="?<?= build_qs_sch(['currency'=>$c,'page'=>1]) ?>"
             class="cur-pill <?= $pref_currency===$c?'active':'' ?>"><?= $c ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- SEARCH -->
<div class="search-strip">
  <form method="GET" action="scholarships.php" class="search-form">
    <input type="hidden" name="currency" value="<?= htmlspecialchars($pref_currency) ?>">
    <?php foreach($filter_countries as $cid): ?><input type="hidden" name="countries[]" value="<?= $cid ?>"><?php endforeach; ?>
    <?php if($filter_type):    ?><input type="hidden" name="type"    value="<?= htmlspecialchars($filter_type) ?>"><?php endif; ?>
    <?php if($filter_status):  ?><input type="hidden" name="status"  value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
    <?php if($sort !== 'deadline'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
    <input class="sf-input" type="text" name="q"
           placeholder="Search scholarships, universities, eligibility…"
           value="<?= htmlspecialchars($search) ?>">
    <div class="sf-sep"></div>
    <div style="margin-bottom:8px;">
      <div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#9CA3AF;margin-bottom:8px;margin-top:6px;">Country</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;">
        <?php $countries_list->data_seek(0); while($c=$countries_list->fetch_assoc()):
          $cid=$c['country_id']; $active=in_array($cid,$filter_countries);
          $toggled=$active ? array_values(array_filter($filter_countries,fn($id)=>$id!==$cid)) : array_values(array_merge($filter_countries,[$cid]));
          $qs=build_qs_sch(['countries'=>$toggled,'page'=>1]);
        ?>
        <a href="?<?=$qs?>"
           style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:99px;font-size:12px;font-weight:700;text-decoration:none;transition:all .15s;border:1.5px solid <?=$active?'#059669':'#E5E7EB'?>;background:<?=$active?'#059669':'#fff'?>;color:<?=$active?'#fff':'#374151'?>;">
          <?=$flags[$c['country_code']]??''?> <?=htmlspecialchars($c['country_name'])?>
          <span style="font-size:10px;opacity:.7;">(<?=$c['cnt']?>)</span>
        </a>
        <?php endwhile; ?>
        <?php if(!empty($filter_countries)): ?>
        <a href="?<?=build_qs_sch(['countries'=>[],'page'=>1])?>"
           style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:99px;font-size:11px;font-weight:700;text-decoration:none;border:1.5px solid #FCA5A5;background:#FEF2F2;color:#EF4444;">
          Clear ×
        </a>
        <?php endif; ?>
      </div>
    </div>
    <button class="sf-btn" type="submit">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      Search
    </button>
  </form>
</div>

<!-- LAYOUT -->
<div class="main-wrap">

  <!-- Mobile filter toggle -->
  <button class="mob-filter-btn" id="mobFilterBtn" onclick="toggleMobFilter()">
    <span>⚙️</span>
    <span id="mobFilterLabel">Show Filters</span>
    <?php $af=count(array_filter([$search,!empty($filter_countries)?1:0,$filter_type,$filter_status])); if($af>0): ?>
      <span style="background:#059669;color:#fff;font-size:10px;padding:2px 7px;border-radius:99px;"><?=$af?> active</span>
    <?php endif; ?>
  </button>

  <!-- SIDEBAR -->
  <aside class="sidebar" id="filterSidebar">
    <div class="sb-head">
      <h3>
        🎯 Filters
        <?php if(count($active_filters)>0): ?><span class="badge"><?= count($active_filters) ?></span><?php endif; ?>
      </h3>
      <?php if(count($active_filters)>0): ?>
        <a href="scholarships.php?currency=<?= htmlspecialchars($pref_currency) ?>" class="sb-reset">Reset all</a>
      <?php endif; ?>
    </div>

    <form method="GET" action="scholarships.php" id="filter-form">
      <input type="hidden" name="currency" value="<?= htmlspecialchars($pref_currency) ?>">
      <?php if($search): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
      <?php if($sort !== 'deadline'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
      <?php foreach($filter_countries as $cid): ?><input type="hidden" name="countries[]" value="<?= (int)$cid ?>"><?php endforeach; ?>

      <!-- Country -->
      <div class="sb-sec">
        <div class="sb-sec-title">Country</div>
        <div class="sb-opt">
          <?php $countries_list->data_seek(0); while($c=$countries_list->fetch_assoc()):
              $cid=$c['country_id']; $active=in_array($cid,$filter_countries);
              $toggled=$active ? array_values(array_filter($filter_countries,fn($id)=>$id!==$cid)) : array_values(array_merge($filter_countries,[$cid]));
            ?>
            <a href="?<?=build_qs_sch(['countries'=>$toggled,'page'=>1])?>"
               class="fs-opt <?=$active?'active':''?>">
              <?=$flags[$c['country_code']]??''?> <?=htmlspecialchars($c['country_name'])?>
            </a>
            <?php endwhile; ?>
      </div>

      <!-- Deadline Status -->
      <div class="sb-sec">
        <div class="sb-sec-title">Deadline Status</div>
        <?php foreach([''=> 'All Scholarships','open'=>'✅ Open Now','closing_soon'=>'⚠️ Closing in 60 Days'] as $val=>$lbl): ?>
        <div class="sb-opt">
          <label>
            <input type="radio" name="status" value="<?=$val?>" <?=$filter_status===$val?'checked':''?> onchange="this.form.submit()">
            <span><?=$lbl?></span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Scholarship Type -->
      <div class="sb-sec">
        <div class="sb-sec-title">Scholarship Type</div>
        <?php foreach([''=> 'All Types','full'=>'🏆 Full Scholarship','partial'=>'🥈 Partial Scholarship'] as $val=>$lbl): ?>
        <div class="sb-opt">
          <label>
            <input type="radio" name="type" value="<?=$val?>" <?=$filter_type===$val?'checked':''?> onchange="this.form.submit()">
            <span><?=$lbl?></span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>

    </form>

    <div class="sb-tip">
      <div class="sb-tip-tag">💡 Pro Tip</div>
      <p>Deadlines approach fast — save scholarships you're interested in to get reminders. Create a free account.</p>
      <a href="register.php">Create Free Account →</a>
    </div>
  </aside>

  <!-- RESULTS -->
  <div>
    <!-- Active filter tags -->
    <?php if(count($active_filters)>0): ?>
    <div class="filter-tags">
      <?php if($search): ?>
        <span class="ftag">🔍 "<?=htmlspecialchars($search)?>" <a href="?<?=build_qs_sch(['q'=>'','page'=>1])?>">×</a></span>
      <?php endif; ?>
      <?php if(!empty($filter_countries)):
          $countries_list->data_seek(0);
          while($c=$countries_list->fetch_assoc()) {
            if(in_array($c['country_id'],$filter_countries))
              echo '<span class="ftag">'.($flags[$c['country_code']]??'').' '.htmlspecialchars($c['country_name']).' <a href="?'.build_qs_sch(['countries'=>[],'page'=>1]).'">×</a></span>';
          }
        endif; ?>
      <?php if($filter_status): ?>
        <span class="ftag">📅 <?=htmlspecialchars($filter_status)?> <a href="?<?=build_qs_sch(['status'=>'','page'=>1])?>">×</a></span>
      <?php endif; ?>
      <?php if($filter_type): ?>
        <span class="ftag">🏆 <?=htmlspecialchars($filter_type)?> <a href="?<?=build_qs_sch(['type'=>'','page'=>1])?>">×</a></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Results bar -->
    <div class="results-head">
      <div class="results-count">
        <strong><?= number_format($total) ?></strong> scholarships found
      </div>
      <div class="sort-row">
        <span class="sort-label">Sort by:</span>
        <select class="sort-sel" onchange="const p=new URLSearchParams(location.search);p.set('sort',this.value);p.set('page','1');location.href='?'+p.toString()">
          <option value="deadline" <?=$sort==='deadline' ?'selected':''?>>Deadline (soonest)</option>
          <option value="amount"   <?=$sort==='amount'   ?'selected':''?>>Highest Amount</option>
          <option value="name_asc" <?=$sort==='name_asc' ?'selected':''?>>Name A–Z</option>
          <option value="newest"   <?=$sort==='newest'   ?'selected':''?>>Newest First</option>
        </select>
      </div>
    </div>

    <!-- Cards -->
    <div class="sch-grid">
    <?php if (!empty($scholarships)):
      $banner_colors = [
        'TH' => ['bg'=>'linear-gradient(135deg,#0891B2,#0E7490)','icon'=>'🇹🇭'],
        'SG' => ['bg'=>'linear-gradient(135deg,#7C3AED,#6D28D9)','icon'=>'🇸🇬'],
        'MY' => ['bg'=>'linear-gradient(135deg,#059669,#047857)','icon'=>'🇲🇾'],
      ];
      foreach ($scholarships as $i => $s):
        $bc     = $banner_colors[$s['country_code']] ?? ['bg'=>'linear-gradient(135deg,#374151,#1F2937)','icon'=>'🎓'];
        $ds     = deadline_status($s['application_deadline']);
        $is_local = $s['image_url'] && !str_starts_with($s['image_url'], 'http');
        $img_src  = $is_local
            ? htmlspecialchars(str_replace('\\','/',$s['image_url']),ENT_QUOTES)
            : htmlspecialchars($s['image_url'] ?? '',ENT_QUOTES);

        // Fee conversion
        $fee_display = null;
        if ($s['amount_local'] && $s['local_currency_code']) {
            $conv = convert_fee((float)$s['amount_local'], $s['local_currency_code'], $pref_currency, $exchange_rates);
            $fee_display = format_fee($conv ?? (float)$s['amount_local'],
                                      $conv ? $pref_currency : $s['local_currency_code'],
                                      $currency_symbols);
        }
    ?>
    <div class="sch-card fade-up d<?= min($i%9+1,9) ?>">
      <div class="sch-banner" style="background:<?= $bc['bg'] ?>">
        <?php if($img_src): ?><img src="<?=$img_src?>" alt="" loading="lazy"><?php endif; ?>
        <div class="sch-icon"><?= $bc['icon'] ?></div>
        <span class="sch-country-badge"><?= htmlspecialchars($s['country_name']) ?></span>
        <button class="sch-save-btn <?= in_array((int)$s['scholarship_id'],$saved_sch_ids)?'saved':'' ?>"
                onclick="toggleSchSave(this,<?=$s['scholarship_id']?>)"
                title="<?= in_array((int)$s['scholarship_id'],$saved_sch_ids)?'Remove from saved':'Save scholarship' ?>">
          <?= in_array((int)$s['scholarship_id'],$saved_sch_ids)?'❤️':'🤍' ?>
        </button>
      </div>

      <div class="sch-body">
        <div class="sch-uni-name">
          <?= $flags[$s['country_code']]??'' ?>
          <?= htmlspecialchars(mb_strimwidth($s['uni_name'],0,30,'…')) ?>
        </div>
        <div class="sch-name"><?= htmlspecialchars($s['name']) ?></div>
        <div class="sch-desc"><?= htmlspecialchars($s['description'] ?? '') ?></div>

        <?php if($fee_display): ?>
        <div class="sch-amount-row">
          <div>
            <div class="sch-amount-label">Scholarship Value</div>
            <div class="sch-amount-val"><?= $fee_display ?></div>
          </div>
          <div class="sch-amount-per">per year</div>
        </div>
        <?php endif; ?>

        <div class="sch-deadline <?= $ds['class'] ?>">
          <?php
          $icons = ['ok'=>'✅','soon'=>'⚠️','urgent'=>'🔴','past'=>'❌'];
          echo ($icons[$ds['class']]??'📅').' '.htmlspecialchars($ds['label']);
          ?>
        </div>

        <?php if($s['eligibility']): ?>
        <div class="sch-elig">
          <span class="sch-elig-label">Who can apply</span>
          <?= htmlspecialchars(mb_strimwidth($s['eligibility'],0,120,'…')) ?>
        </div>
        <?php endif; ?>

        <div class="sch-actions">
          <a href="university_detail.php?id=<?=$s['university_id']?>&tab=scholarships" class="sch-btn-view">View Scholarship →</a>
          <a href="university_detail.php?id=<?=$s['university_id']?>" class="sch-btn-uni">🏫</a>
        </div>
      </div>
    </div>
    <?php endforeach;
    else: ?>
      <div style="text-align:center;padding:64px 20px;background:#fff;border:1px dashed #E5E7EB;border-radius:16px;">
        <div style="font-size:48px;margin-bottom:14px;">🔍</div>
        <div style="font-size:18px;font-weight:800;color:#0A1628;margin-bottom:8px;">No scholarships found</div>
        <div style="font-size:14px;color:#9CA3AF;line-height:1.7;margin-bottom:20px;">
          Try removing some filters or changing your search keywords.
          <?php if(!empty($filter_countries)): ?>
            <br>Currently filtering by <?=count($filter_countries)?> <?=count($filter_countries)===1?'country':'countries'?>.
          <?php endif; ?>
        </div>
        <a href="scholarships.php" style="display:inline-block;padding:11px 24px;background:#059669;color:#fff;border-radius:9px;font-weight:700;font-size:13px;text-decoration:none;">Clear all filters</a>
      </div>
    <?php endif; ?>
    </div>

    <!-- PAGINATION -->
    <?php if($total_pages > 1): ?>
    <nav class="pagination">
      <a href="?<?=build_qs_sch(['page'=>$page-1])?>" class="pg-btn <?=$page<=1?'disabled':''?>">‹</a>
      <?php
      for($p=1;$p<=$total_pages;$p++){
        if($p===1||$p===$total_pages||abs($p-$page)<=1){
          echo "<a href='?".build_qs_sch(['page'=>$p])."' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        }elseif(abs($p-$page)===2){
          echo "<span class='pg-dots'>…</span>";
        }
      }
      ?>
      <a href="?<?=build_qs_sch(['page'=>$page+1])?>" class="pg-btn <?=$page>=$total_pages?'disabled':''?>">›</a>
    </nav>
    <?php endif; ?>
  </div>
</div>

<!-- FOOTER -->

<?php include 'includes/footer.php'; ?>


<script>
function toggleSchSave(btn, id) {
  const isSaved = btn.textContent.trim() === '❤️';
  btn.textContent = isSaved ? '🤍' : '❤️';
  btn.classList.toggle('saved', !isSaved);
  btn.style.transform = 'scale(1.3)';
  setTimeout(() => btn.style.transform = '', 200);
  <?php if(isset($_SESSION['user_id'])): ?>
  fetch(`save_scholarship.php?id=${id}&action=${isSaved?'remove':'add'}`)
    .then(r=>r.json())
    .then(d=>{ if(!d.success){ btn.textContent=isSaved?'❤️':'🤍'; btn.classList.toggle('saved',isSaved); } })
    .catch(()=>{ btn.textContent=isSaved?'❤️':'🤍'; });
  <?php else: ?>
  if (!isSaved) { btn.textContent='🤍'; btn.classList.remove('saved'); window.location='login.php?next=scholarships.php'; }
  <?php endif; ?>
}
function toggleMobFilter() {
  const sidebar = document.getElementById('filterSidebar');
  const label   = document.getElementById('mobFilterLabel');
  const btn     = document.getElementById('mobFilterBtn');
  const isOpen  = sidebar.classList.toggle('mob-open');
  label.textContent = isOpen ? 'Hide Filters' : 'Show Filters';
  btn.classList.toggle('mob-filter-active', isOpen);
}
</script>
</body>
</html>