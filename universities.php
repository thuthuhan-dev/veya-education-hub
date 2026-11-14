<?php
// universities.php — Veya Education Hub
session_start();
require_once "db_connect.php";

// ── Pre-load saved university IDs for current user ────────────────────────
$saved_ids = [];
if (isset($_SESSION['user_id'])) {
    $sv_uid = (int)$_SESSION['user_id'];
    $sv = $conn->prepare("SELECT university_id FROM saved_universities WHERE user_id=?");
    $sv->bind_param("i", $sv_uid); $sv->execute();
    $sv_rows = $sv->get_result()->fetch_all(MYSQLI_ASSOC); $sv->close();
    foreach($sv_rows as $sv_row) $saved_ids[] = (int)$sv_row['university_id'];
}

// ── Currency from URL or session ───────────────────────────────────────────
$pref_currency = $_GET['currency'] ?? $_SESSION['preferred_currency_code'] ?? 'MMK';
$valid_currencies = ['MMK','THB','SGD','MYR','USD','EUR'];
if (!in_array($pref_currency, $valid_currencies)) $pref_currency = 'MMK';

// Load currencies and exchange rates
$currency_symbols = [];
$cur_res = $conn->query("SELECT currency_code, symbol FROM currencies");
while ($row = $cur_res->fetch_assoc()) $currency_symbols[$row['currency_code']] = $row['symbol'];

$exchange_rates = [];
$rate_res = $conn->query("SELECT from_currency, to_currency, rate FROM exchange_rates");
while ($row = $rate_res->fetch_assoc()) $exchange_rates[$row['from_currency']][$row['to_currency']] = (float)$row['rate'];

function convert_fee($amount, $from, $to, $rates) {
    if ($from === $to) return $amount;

    // 1. Direct rate
    if (isset($rates[$from][$to])) {
        return $amount * $rates[$from][$to];
    }
    // 2. Two-step via USD
    if (isset($rates[$from]['USD']) && isset($rates['USD'][$to])) {
        return $amount * $rates[$from]['USD'] * $rates['USD'][$to];
    }
    // 3. Two-step via MMK
    if (isset($rates[$from]['MMK']) && isset($rates['MMK'][$to])) {
        return $amount * $rates[$from]['MMK'] * $rates['MMK'][$to];
    }
    return null;
}
function format_fee($amount, $currency, $symbols) {
    if ($amount === null) return null;
    $sym = $symbols[$currency] ?? $currency;
    if ($amount >= 1000000) return $sym . number_format($amount / 1000000, 1) . 'M';
    if ($amount >= 1000)    return $sym . number_format($amount / 1000, 0) . 'K';
    return $sym . number_format($amount, 0);
}

// ── GET params ────────────────────────────────────────────────────────────
$search         = trim($_GET['q']       ?? '');
$filter_countries = array_map('intval', array_filter($_GET['countries'] ?? []));
$filter_type    = $_GET['type']          ?? '';
$filter_level   = $_GET['level']         ?? '';
$filter_field   = $_GET['field']         ?? '';
$sort           = $_GET['sort']          ?? 'newest';
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 8;
$offset         = ($page - 1) * $per_page;

// ── Sidebar data ──────────────────────────────────────────────────────────
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

$degree_levels = $conn->query("
    SELECT p.degree_level, COUNT(DISTINCT p.university_id) AS cnt
    FROM programs p
    JOIN universities u ON u.university_id = p.university_id AND u.is_active = 1
    GROUP BY p.degree_level
    ORDER BY FIELD(p.degree_level,'Foundation','Diploma','Bachelor','Master','PhD')
");

$fields_list = $conn->query("
    SELECT p.field_of_study, COUNT(DISTINCT p.university_id) AS cnt
    FROM programs p
    JOIN universities u ON u.university_id = p.university_id AND u.is_active = 1
    WHERE p.field_of_study IS NOT NULL AND p.field_of_study != ''
    GROUP BY p.field_of_study ORDER BY cnt DESC LIMIT 8
");

// ── WHERE clause ──────────────────────────────────────────────────────────
$where_parts = ["u.is_active = 1"];
$params = []; $param_types = "";

if ($search !== '') {
    $where_parts[] = "(u.name LIKE ? OR u.city LIKE ? OR u.description LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $param_types .= "sss";
}
if (!empty($filter_countries)) {
    $in_ph = implode(',', array_fill(0, count($filter_countries), '?'));
    $where_parts[] = "u.country_id IN ($in_ph)";
    foreach ($filter_countries as $cid) { $params[] = $cid; $param_types .= 'i'; }
}
if ($filter_type !== '') {
    $where_parts[] = "u.university_type = ?";
    $params[] = $filter_type; $param_types .= "s";
}
if ($filter_level !== '') {
    $where_parts[] = "EXISTS (SELECT 1 FROM programs p WHERE p.university_id = u.university_id AND p.degree_level = ?)";
    $params[] = $filter_level; $param_types .= "s";
}
if ($filter_field !== '') {
    $where_parts[] = "EXISTS (SELECT 1 FROM programs p WHERE p.university_id = u.university_id AND p.field_of_study = ?)";
    $params[] = $filter_field; $param_types .= "s";
}

$where_sql = "WHERE " . implode(" AND ", $where_parts);
$order_sql = match($sort) {
    'name_asc' => "ORDER BY u.name ASC",
    'rating'   => "ORDER BY avg_rating DESC, u.name ASC",
    'programs' => "ORDER BY program_count DESC",
    'ranking'  => "ORDER BY CASE WHEN u.world_ranking IS NULL OR u.world_ranking=0 THEN 9999 ELSE u.world_ranking END ASC",
    default    => "ORDER BY u.created_at DESC",
};

// ── Count ─────────────────────────────────────────────────────────────────
$count_stmt = $conn->prepare("SELECT COUNT(DISTINCT u.university_id) AS total FROM universities u JOIN countries c ON c.country_id = u.country_id $where_sql");
if ($param_types) $count_stmt->bind_param($param_types, ...$params);
$count_stmt->execute();
$total_results = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages   = max(1, ceil($total_results / $per_page));
$count_stmt->close();

// ── Main query ────────────────────────────────────────────────────────────
$main_sql = "
    SELECT u.university_id, u.name, u.city, u.university_type,
           u.website_url, u.image_url, u.established_year, u.description,
           c.country_name, c.country_code, c.currency_code,
           AVG(r.rating)                AS avg_rating,
           COUNT(DISTINCT r.review_id)  AS review_count,
           COUNT(DISTINCT p.program_id) AS program_count,
           MIN(tf.amount_local)         AS min_fee,
           MIN(tf.local_currency_code)  AS fee_currency
    FROM universities u
    JOIN countries c ON c.country_id = u.country_id
    LEFT JOIN reviews r ON r.university_id = u.university_id AND r.status = 'Approved'
    LEFT JOIN programs p ON p.university_id = u.university_id
    LEFT JOIN tuition_fees tf ON tf.program_id = p.program_id AND tf.fee_type = 'Tuition'
    $where_sql
    GROUP BY u.university_id, u.name, u.city, u.university_type,
             u.website_url, u.image_url, u.established_year, u.description,
             u.created_at, c.country_name, c.country_code, c.currency_code
    $order_sql
    LIMIT ? OFFSET ?
";
$all_params = [...$params, $per_page, $offset];
$all_types  = $param_types . "ii";
$stmt = $conn->prepare($main_sql);
if ($all_types) $stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$universities = $stmt->get_result();
$stmt->close();

// ── Helpers ───────────────────────────────────────────────────────────────
$flags = ['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$country_labels = ['TH'=>'Thailand','SG'=>'Singapore','MY'=>'Malaysia'];
$active_filters = array_filter([$search, !empty($filter_countries)?1:0, $filter_type,$filter_level,$filter_field]);

// Fallback photo if image_url is empty in the database
$fallback_photo = 'https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=800&q=80';

// Country badge colors
$country_colors = [
    'TH' => ['bg'=>'#FEF3C7','color'=>'#92400E','dot'=>'#F59E0B'],
    'SG' => ['bg'=>'#EFF6FF','color'=>'#1E40AF','dot'=>'#3B82F6'],
    'MY' => ['bg'=>'#F0FDF4','color'=>'#166534','dot'=>'#22C55E'],
];

// Build base query string for pagination/sort
function build_qs($overrides=[]) {
    global $search,$filter_countries,$filter_type,$filter_level,$filter_field,$sort,$pref_currency;
    $params = array_filter([
        'q'=>$search,'type'=>$filter_type,'level'=>$filter_level,
        'field'=>$filter_field,
        'sort'=>$sort!='newest'?$sort:'','currency'=>$pref_currency!='MMK'?$pref_currency:'',
    ]);
    // Merge overrides
    $params = array_merge($params, $overrides);
    // Handle countries array separately (http_build_query handles arrays)
    if (!isset($overrides['countries'])) {
        if (!empty($filter_countries)) $params['countries'] = $filter_countries;
    } elseif (empty($overrides['countries'])) {
        unset($params['countries']);
    }
    return http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Universities — Veya Education Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F8FAFF;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
img{display:block;max-width:100%;}

/* ── Animations ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .45s ease both;}
.d1{animation-delay:.05s}.d2{animation-delay:.1s}.d3{animation-delay:.15s}
.d4{animation-delay:.2s}.d5{animation-delay:.25s}.d6{animation-delay:.3s}
.d7{animation-delay:.35s}.d8{animation-delay:.4s}

/* ── NAV ── */
nav{
  position:sticky;top:0;z-index:100;
  background:rgba(255,255,255,.95);backdrop-filter:blur(16px);
  border-bottom:1px solid #E5E7EB;
  height:68px;padding:0 40px;
  display:flex;align-items:center;justify-content:space-between;gap:32px;
}
.nav-logo{display:flex;align-items:center;gap:9px;flex-shrink:0;}
.nav-logo img{height:28px;}
.nav-logo-text{font-size:18px;font-weight:800;letter-spacing:-.02em;color:#0A1628;}
.nav-links{display:flex;gap:4px;}
.nav-links a{font-size:14px;font-weight:500;color:#6B7280;padding:6px 14px;border-radius:8px;transition:all .15s;}
.nav-links a:hover,.nav-links a.active{color:#0A1628;background:#F3F4F6;font-weight:600;}
.nav-right{display:flex;gap:10px;align-items:center;flex-shrink:0;}
.nav-btn-login{font-size:13px;font-weight:600;color:#374151;padding:8px 18px;border-radius:8px;border:1px solid #E5E7EB;transition:all .15s;}
.nav-btn-login:hover{background:#F9FAFB;}
.nav-btn-signup{font-size:13px;font-weight:700;color:#fff;background:#1A56DB;padding:8px 20px;border-radius:8px;transition:background .15s;}
.nav-btn-signup:hover{background:#1E40AF;}
.nav-user{font-size:14px;color:#374151;display:flex;align-items:center;gap:12px;}
.nav-user a{font-weight:700;color:#1A56DB;}

/* ── PAGE HERO ── */
.page-hero{
  background:linear-gradient(135deg,#0A1628 0%,#1A2E50 60%,#1E3A5F 100%);
  padding:56px 40px 52px;position:relative;overflow:hidden;
}
.page-hero::before{
  content:"";position:absolute;top:-120px;right:-80px;
  width:420px;height:420px;border-radius:50%;
  background:radial-gradient(circle,rgba(26,86,219,.35),transparent 70%);pointer-events:none;
}
.page-hero::after{
  content:"";position:absolute;bottom:-60px;left:5%;
  width:260px;height:260px;border-radius:50%;
  background:radial-gradient(circle,rgba(96,165,250,.12),transparent 70%);pointer-events:none;
}
.hero-inner{max-width:1240px;margin:0 auto;position:relative;z-index:2;}
.hero-breadcrumb{
  display:flex;align-items:center;gap:8px;
  font-size:13px;color:rgba(255,255,255,.5);margin-bottom:20px;
}
.hero-breadcrumb a{color:rgba(255,255,255,.5);transition:color .15s;}
.hero-breadcrumb a:hover{color:rgba(255,255,255,.85);}
.hero-breadcrumb span{color:rgba(255,255,255,.25);}
.hero-top{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;}
.hero-text h1{
  font-family:'Instrument Serif',serif;font-weight:400;
  font-size:clamp(30px,4vw,50px);color:#fff;
  letter-spacing:-.02em;line-height:1.1;margin-bottom:10px;
}
.hero-text h1 em{font-style:italic;color:#60A5FA;}
.hero-text p{font-size:16px;color:rgba(255,255,255,.65);max-width:52ch;line-height:1.6;}
.hero-stat-pills{display:flex;gap:12px;flex-wrap:wrap;margin-top:28px;}
.stat-pill{
  display:flex;align-items:center;gap:8px;
  background:rgba(255,255,255,.1);backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.18);
  padding:10px 18px;border-radius:99px;
}
.stat-pill-num{font-size:18px;font-weight:800;color:#fff;}
.stat-pill-label{font-size:12px;color:rgba(255,255,255,.65);font-weight:500;}

/* Currency selector */
.currency-row{
  display:flex;align-items:center;gap:10px;margin-top:28px;flex-wrap:wrap;
}
.currency-label{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.5);}
.currency-pills{display:flex;gap:6px;flex-wrap:wrap;}
.cur-btn{
  font-size:13px;font-weight:700;
  padding:6px 14px;border-radius:99px;
  border:1px solid rgba(255,255,255,.25);color:rgba(255,255,255,.75);
  background:transparent;cursor:pointer;transition:all .15s;
}
.cur-btn:hover{border-color:rgba(255,255,255,.6);color:#fff;}
.cur-btn.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}

/* ── SEARCH BAR under hero ── */
.search-strip{
  background:#fff;border-bottom:1px solid #E5E7EB;
  padding:20px 40px;
}
.search-form{
  max-width:1240px;margin:0 auto;
  display:flex;gap:0;
  background:#F9FAFB;border:1.5px solid #E5E7EB;
  border-radius:12px;overflow:hidden;
  transition:border-color .2s,box-shadow .2s;
}
.search-form:focus-within{border-color:#93C5FD;box-shadow:0 0 0 4px rgba(147,197,253,.2);}
.sf-input{
  flex:1;border:none;background:transparent;outline:none;
  font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;color:#0A1628;
  padding:14px 20px;
}
.sf-input::placeholder{color:#9CA3AF;}
.sf-sep{width:1px;background:#E5E7EB;margin:10px 0;}
.sf-select{
  border:none;background:transparent;outline:none;
  font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;
  font-weight:600;color:#374151;padding:0 16px;cursor:pointer;
}
.sf-btn{
  margin:8px;padding:10px 28px;
  font-family:'Plus Jakarta Sans',sans-serif;
  font-size:14px;font-weight:700;color:#fff;
  background:#1A56DB;border:none;border-radius:8px;
  cursor:pointer;transition:background .15s;white-space:nowrap;
  display:flex;align-items:center;gap:8px;
}
.sf-btn:hover{background:#1E40AF;}
.sf-btn svg{width:16px;height:16px;}

/* ── MAIN LAYOUT ── */
.main-wrap{max-width:1240px;margin:0 auto;padding:32px 40px;display:grid;grid-template-columns:272px 1fr;gap:28px;align-items:start;}

/* ── SIDEBAR ── */
.sidebar{background:#fff;border:1px solid #E5E7EB;border-radius:16px;overflow:hidden;position:sticky;top:88px;}
.sb-head{
  padding:18px 20px 14px;border-bottom:1px solid #F3F4F6;
  display:flex;align-items:center;justify-content:space-between;
}
.sb-head h3{font-size:14px;font-weight:800;color:#0A1628;letter-spacing:.02em;display:flex;align-items:center;gap:8px;}
.sb-head h3 span{
  background:#1A56DB;color:#fff;
  font-size:10px;font-weight:700;
  padding:2px 7px;border-radius:99px;
}
.sb-reset{font-size:12px;font-weight:700;color:#EF4444;transition:color .15s;}
.sb-reset:hover{color:#DC2626;}

.sb-section{padding:16px 20px;border-bottom:1px solid #F9FAFB;}
.sb-section:last-child{border-bottom:none;}
.sb-section-title{
  font-size:10px;font-weight:800;letter-spacing:.1em;
  text-transform:uppercase;color:#9CA3AF;margin-bottom:10px;
}
.sb-option{
  display:flex;align-items:center;justify-content:space-between;
  padding:5px 0;cursor:pointer;
}
.sb-option label{
  display:flex;align-items:center;gap:9px;
  font-size:13px;font-weight:500;color:#374151;cursor:pointer;
}
.sb-option input[type="radio"],
.sb-option input[type="checkbox"]{
  width:15px;height:15px;accent-color:#1A56DB;cursor:pointer;flex-shrink:0;
}
.sb-option:hover label{color:#0A1628;}
.sb-count{
  font-size:11px;font-weight:600;color:#9CA3AF;
  background:#F3F4F6;padding:2px 7px;border-radius:99px;
}
.sb-select{
  width:100%;font-family:'Plus Jakarta Sans',sans-serif;
  font-size:13px;font-weight:500;color:#374151;
  background:#F9FAFB;border:1px solid #E5E7EB;
  padding:9px 12px;border-radius:8px;outline:none;cursor:pointer;
  transition:border-color .15s;
}
.sb-select:focus{border-color:#93C5FD;}

/* Pro tip card */
.pro-tip{
  margin:12px;border-radius:12px;
  background:linear-gradient(135deg,#0A1628,#1E3A5F);
  padding:16px;
}
.pro-tip-tag{font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#60A5FA;margin-bottom:8px;}
.pro-tip p{font-size:12px;color:rgba(255,255,255,.7);line-height:1.55;margin-bottom:12px;}
.pro-tip a{
  display:block;text-align:center;
  font-size:12px;font-weight:700;color:#0A1628;
  background:#fff;padding:8px;border-radius:7px;
  transition:background .15s;
}
.pro-tip a:hover{background:#F0F4FF;}

/* ── RESULTS AREA ── */
.results-head{
  display:flex;align-items:center;justify-content:space-between;
  gap:16px;margin-bottom:16px;flex-wrap:wrap;
}
.results-count{font-size:14px;color:#6B7280;font-weight:500;}
.results-count strong{color:#0A1628;font-weight:700;font-size:16px;}
.sort-row{display:flex;align-items:center;gap:8px;}
.sort-label{font-size:13px;color:#9CA3AF;font-weight:500;}
.sort-sel{
  font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;
  color:#0A1628;background:#fff;border:1px solid #E5E7EB;
  padding:8px 12px;border-radius:8px;cursor:pointer;outline:none;
  transition:border-color .15s;
}
.sort-sel:focus{border-color:#93C5FD;}

/* Active filter tags */
.filter-tags{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px;}
.ftag{
  display:inline-flex;align-items:center;gap:5px;
  background:#EFF6FF;color:#1A56DB;border:1px solid #BFDBFE;
  font-size:12px;font-weight:600;padding:4px 10px;border-radius:99px;
}
.ftag a{color:#93C5FD;font-size:13px;margin-left:2px;transition:color .15s;}
.ftag a:hover{color:#1A56DB;}

/* ── UNIVERSITY CARDS (2 col) ── */
.uni-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}

.uni-card{
  background:#fff;border:1px solid #E5E7EB;border-radius:18px;
  overflow:hidden;display:flex;flex-direction:column;
  transition:transform .25s ease,box-shadow .25s ease,border-color .2s;
}
.uni-card:hover{
  transform:translateY(-5px);
  box-shadow:0 20px 48px rgba(10,22,40,.1);
  border-color:#BFDBFE;
}

/* Card photo */
.uc-photo{height:188px;position:relative;overflow:hidden;flex-shrink:0;}
.uc-photo img{
  width:100%;height:100%;object-fit:cover;
  transition:transform .5s ease;
}
.uni-card:hover .uc-photo img{transform:scale(1.06);}
.uc-photo-overlay{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(10,22,40,.55) 0%,transparent 55%);
}

/* Badges */
.uc-badges{
  position:absolute;top:12px;left:12px;right:12px;
  display:flex;align-items:flex-start;justify-content:space-between;
}
.uc-type-badge{
  font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
  padding:4px 10px;border-radius:99px;
  background:rgba(255,255,255,.92);color:#0A1628;
}
.uc-save-btn{
  width:32px;height:32px;border-radius:50%;
  background:rgba(255,255,255,.9);border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  font-size:14px;transition:all .2s;flex-shrink:0;
  box-shadow:0 2px 6px rgba(10,22,40,.12);
}
.uc-save-btn:hover{background:#fff;transform:scale(1.12);}
.uc-save-btn.saved{background:#FEE2E2;box-shadow:0 2px 8px rgba(239,68,68,.3);}

/* Country tag bottom of photo */
.uc-country-tag{
  position:absolute;bottom:12px;left:12px;
  display:flex;align-items:center;gap:6px;
  font-size:12px;font-weight:700;
  padding:4px 10px;border-radius:99px;
  border:1px solid rgba(255,255,255,.3);
  backdrop-filter:blur(8px);color:#fff;
  background:rgba(10,22,40,.35);
}

/* Card body */
.uc-body{padding:18px 18px 0;flex:1;}
.uc-name{
  font-size:16px;font-weight:700;color:#0A1628;
  line-height:1.3;margin-bottom:4px;
}
.uc-location{
  font-size:12px;color:#6B7280;
  display:flex;align-items:center;gap:4px;margin-bottom:10px;
}
.uc-desc{
  font-size:13px;color:#6B7280;line-height:1.6;
  display:-webkit-box;-webkit-line-clamp:2;
  -webkit-box-orient:vertical;overflow:hidden;
  margin-bottom:14px;
}

/* Stats bar */
.uc-stats{
  display:grid;grid-template-columns:repeat(3,1fr);
  gap:0;border:1px solid #F3F4F6;border-radius:10px;
  overflow:hidden;background:#F9FAFB;
  margin-bottom:14px;
}
.uc-stat{
  padding:10px 8px;text-align:center;
  border-right:1px solid #F3F4F6;
}
.uc-stat:last-child{border-right:none;}
.uc-stat-label{
  font-size:9px;font-weight:700;letter-spacing:.08em;
  text-transform:uppercase;color:#9CA3AF;margin-bottom:4px;
}
.uc-stat-val{font-size:14px;font-weight:800;color:#0A1628;}
.uc-stat-val.fee{color:#1A56DB;}
.uc-stat-sub{font-size:10px;color:#9CA3AF;font-weight:500;}

/* Star rating */
.uc-rating{
  display:flex;align-items:center;gap:4px;margin-bottom:14px;
}
.star-on{color:#FBBF24;font-size:13px;}
.star-off{color:#E5E7EB;font-size:13px;}
.rating-text{font-size:12px;color:#6B7280;margin-left:4px;}

/* Card actions */
.uc-actions{
  display:flex;gap:8px;padding:0 18px 18px;
}
.uc-btn-view{
  flex:1;text-align:center;
  font-size:13px;font-weight:700;color:#fff;
  background:#1A56DB;padding:10px 16px;border-radius:9px;
  transition:background .15s,transform .1s;
}
.uc-btn-view:hover{background:#1E40AF;transform:translateY(-1px);}
.uc-btn-compare{
  font-size:13px;font-weight:600;color:#374151;
  background:#F3F4F6;padding:10px 14px;border-radius:9px;
  display:flex;align-items:center;gap:5px;
  transition:background .15s,color .15s;white-space:nowrap;
}
.uc-btn-compare:hover{background:#E5E7EB;color:#0A1628;}
.uc-btn-compare svg{width:14px;height:14px;}

/* Empty state */
.empty-state{
  grid-column:1/-1;text-align:center;
  padding:80px 40px;background:#fff;
  border:1px solid #E5E7EB;border-radius:18px;
}

/* ── PAGINATION ── */
.pagination{
  display:flex;align-items:center;justify-content:center;
  gap:5px;margin-top:36px;
}
.pg-btn{
  min-width:38px;height:38px;padding:0 12px;
  display:inline-flex;align-items:center;justify-content:center;
  border-radius:9px;font-size:14px;font-weight:600;
  border:1px solid #E5E7EB;color:#374151;background:#fff;
  transition:all .15s;cursor:pointer;
}
.pg-btn:hover:not(.disabled):not(.active){border-color:#93C5FD;color:#1A56DB;}
.pg-btn.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}
.pg-btn.disabled{opacity:.35;pointer-events:none;}
.pg-dots{font-size:14px;color:#D1D5DB;padding:0 4px;}

/* ── FOOTER ── */
footer{background:#0A1628;margin-top:72px;padding:64px 40px 0;}
.footer-top{
  max-width:1240px;margin:0 auto;
  display:grid;grid-template-columns:2fr 1fr 1fr 1.5fr;gap:48px;
  padding-bottom:48px;border-bottom:1px solid rgba(255,255,255,.08);
}
.footer-brand img{height:28px;filter:brightness(0) invert(1);opacity:.7;margin-bottom:14px;}
.footer-brand-name{font-size:18px;font-weight:800;color:rgba(255,255,255,.8);margin-bottom:10px;}
.footer-brand-desc{font-size:13px;color:rgba(255,255,255,.45);line-height:1.7;max-width:28ch;}
.footer-col h4{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.4);margin-bottom:16px;}
.footer-col a{display:block;font-size:14px;color:rgba(255,255,255,.5);margin-bottom:10px;transition:color .15s;}
.footer-col a:hover{color:rgba(255,255,255,.85);}
.footer-nl-desc{font-size:13px;color:rgba(255,255,255,.45);line-height:1.6;margin-bottom:14px;}
.footer-nl-form{display:flex;gap:0;border-radius:9px;overflow:hidden;border:1px solid rgba(255,255,255,.15);}
.footer-nl-input{
  flex:1;border:none;background:rgba(255,255,255,.07);outline:none;
  font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;
  color:#fff;padding:11px 14px;
}
.footer-nl-input::placeholder{color:rgba(255,255,255,.35);}
.footer-nl-btn{
  background:#1A56DB;border:none;color:#fff;
  padding:0 14px;cursor:pointer;font-size:18px;
  transition:background .15s;
}
.footer-nl-btn:hover{background:#1E40AF;}
.footer-bottom{
  max-width:1240px;margin:0 auto;
  display:flex;justify-content:space-between;align-items:center;
  padding:20px 0;font-size:12px;color:rgba(255,255,255,.3);flex-wrap:wrap;gap:10px;
}
.footer-bottom-links{display:flex;gap:20px;}
.footer-bottom-links a{color:rgba(255,255,255,.3);transition:color .15s;}
.footer-bottom-links a:hover{color:rgba(255,255,255,.6);}

/* ── Responsive ── */
@media(max-width:1100px){.uni-grid{grid-template-columns:1fr;}.footer-top{grid-template-columns:1fr 1fr;gap:32px;}}
@media(max-width:900px){
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
.mob-filter-btn:hover{border-color:#1A56DB;color:#1A56DB;}
.mob-filter-active{border-color:#1A56DB;color:#1A56DB;background:#EFF6FF;}
/* No results empty state */
.no-results{text-align:center;padding:64px 20px;background:#fff;border:1px dashed #E5E7EB;border-radius:16px;}
.no-results-icon{font-size:48px;margin-bottom:14px;}
.no-results h3{font-size:18px;font-weight:800;color:#0A1628;margin-bottom:8px;}
.no-results p{font-size:14px;color:#9CA3AF;line-height:1.7;margin-bottom:20px;}
@media(max-width:640px){.uni-grid{grid-template-columns:1fr;}.footer-top{grid-template-columns:1fr;}}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important;}}
</style>
</head>
<body>

<?php $nav_active = 'universities'; include 'includes/nav.php'; ?>

<!-- ══ PAGE HERO ══ -->
<div class="page-hero">
  <div class="hero-inner">
    <div class="hero-breadcrumb">
      <a href="index.php">Home</a><span>/</span><span>Universities</span>
    </div>
    <div class="hero-top">
      <div class="hero-text">
        <h1>Discover <em>Global Education</em></h1>
        <p>Explore curated programs from world-class universities across Thailand, Singapore, and Malaysia — with transparent fees and real student insights.</p>
      </div>
    </div>
    <div class="hero-stat-pills">
      <div class="stat-pill">
        <span class="stat-pill-num"><?= $total_results ?></span>
        <span class="stat-pill-label">Universities Found</span>
      </div>
      <div class="stat-pill">
        <span class="stat-pill-num">3</span>
        <span class="stat-pill-label">Countries</span>
      </div>
      <div class="stat-pill">
        <span class="stat-pill-num">60+</span>
        <span class="stat-pill-label">Programs</span>
      </div>
      <div class="stat-pill">
        <span class="stat-pill-num">30+</span>
        <span class="stat-pill-label">Scholarships</span>
      </div>
    </div>

    <!-- Currency Selector -->
    <div class="currency-row">
      <span class="currency-label">View fees in:</span>
      <div class="currency-pills">
        <?php foreach(['MMK'=>'MMK','THB'=>'THB','SGD'=>'SGD','MYR'=>'MYR','USD'=>'USD','EUR'=>'EUR'] as $code=>$label): ?>
          <a href="?<?= build_qs(['currency'=>$code,'page'=>1]) ?>"
             class="cur-btn <?= $pref_currency===$code?'active':'' ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ══ SEARCH STRIP ══ -->
<div class="search-strip">
  <form method="GET" action="universities.php#results" class="search-form">
    <input type="hidden" name="currency" value="<?= htmlspecialchars($pref_currency) ?>">
    <?php foreach($filter_countries as $cid): ?><input type="hidden" name="countries[]" value="<?= $cid ?>"><?php endforeach; ?>
    <?php if($filter_type):    ?><input type="hidden" name="type"    value="<?= htmlspecialchars($filter_type) ?>"><?php endif; ?>
    <?php if($filter_level):   ?><input type="hidden" name="level"   value="<?= htmlspecialchars($filter_level) ?>"><?php endif; ?>
    <?php if($sort!=='newest'): ?><input type="hidden" name="sort"   value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
    <input class="sf-input" type="text" name="q"
           placeholder="Search university name, city, or program…"
           value="<?= htmlspecialchars($search) ?>">
    <div class="sf-sep"></div>
    <div style="margin-bottom:8px;">
      <div style="font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#9CA3AF;margin-bottom:8px;">Country</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;">
        <?php $countries_list->data_seek(0); while($c=$countries_list->fetch_assoc()):
          $cid = $c['country_id'];
          $active = in_array($cid, $filter_countries);
          $toggled = $active
            ? array_values(array_filter($filter_countries, fn($id)=>$id!==$cid))
            : array_values(array_merge($filter_countries, [$cid]));
          $qs = build_qs(['countries'=>$toggled,'page'=>1]);
        ?>
        <a href="?<?=$qs?>#results"
           style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:99px;font-size:12px;font-weight:700;text-decoration:none;transition:all .15s;border:1.5px solid <?=$active?'#1A56DB':'#E5E7EB'?>;background:<?=$active?'#1A56DB':'#fff'?>;color:<?=$active?'#fff':'#374151'?>;">
          <?=$flags[$c['country_code']]??''?> <?=htmlspecialchars($c['country_name'])?>
          <span style="font-size:10px;opacity:.7;">(<?=$c['cnt']?>)</span>
        </a>
        <?php endwhile; ?>
        <?php if (!empty($filter_countries)): ?>
        <a href="?<?=build_qs(['countries'=>[],'page'=>1])?>#results"
           style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:99px;font-size:11px;font-weight:700;text-decoration:none;border:1.5px solid #FCA5A5;background:#FEF2F2;color:#EF4444;">
          Clear ×
        </a>
        <?php endif; ?>
      </div>
    </div>
    <button class="sf-btn" type="submit">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      Search
    </button>
  </form>
</div>

<!-- ══ MAIN LAYOUT ══ -->
<div class="main-wrap">

  <!-- Mobile filter toggle (shows only on mobile) -->
  <button class="mob-filter-btn" id="mobFilterBtn" onclick="toggleMobFilter()">
    <span>⚙️</span>
    <span id="mobFilterLabel">Show Filters</span>
    <?php $af=count(array_filter([$search,!empty($filter_countries)?1:0,$filter_type,$filter_level,$filter_field])); if($af>0): ?>
      <span style="background:#1A56DB;color:#fff;font-size:10px;padding:2px 7px;border-radius:99px;"><?=$af?> active</span>
    <?php endif; ?>
  </button>

  <!-- SIDEBAR -->
  <aside class="sidebar" id="filterSidebar">
    <div class="sb-head">
      <h3>
        🎯 Filters
        <?php if(count($active_filters)>0): ?><span><?= count($active_filters) ?></span><?php endif; ?>
      </h3>
      <?php if(count($active_filters)>0): ?>
        <a href="universities.php?currency=<?= htmlspecialchars($pref_currency) ?>" class="sb-reset">Reset all</a>
      <?php endif; ?>
    </div>

    <form method="GET" action="universities.php#results" id="filter-form">
      <input type="hidden" name="currency" value="<?= htmlspecialchars($pref_currency) ?>">
      <?php if($search): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
      <?php if($sort!=='newest'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
      <?php foreach($filter_countries as $cid): ?><input type="hidden" name="countries[]" value="<?= (int)$cid ?>"><?php endforeach; ?>

      <!-- COUNTRY -->
      <div class="sb-section">
        <div class="sb-section-title">Country</div>
        <div class="sb-option">
          <?php $countries_list->data_seek(0); while($c=$countries_list->fetch_assoc()):
              $cid=$c['country_id']; $active=in_array($cid,$filter_countries);
              $toggled=$active ? array_values(array_filter($filter_countries,fn($id)=>$id!==$cid)) : array_values(array_merge($filter_countries,[$cid]));
            ?>
            <a href="?<?=build_qs(['countries'=>$toggled,'page'=>1])?>#results"
               class="fs-opt <?=$active?'active':''?>">
              <?=$flags[$c['country_code']]??''?> <?=htmlspecialchars($c['country_name'])?>
            </a>
            <?php endwhile; ?>
      </div>

      <!-- PROGRAM CATEGORY -->
      <div class="sb-section">
        <div class="sb-section-title">Program Category</div>
        <select class="sb-select" name="field" onchange="this.form.submit()">
          <option value="">All Categories</option>
          <?php while($f=$fields_list->fetch_assoc()): ?>
            <option value="<?=htmlspecialchars($f['field_of_study'])?>"
                    <?=$filter_field===$f['field_of_study']?'selected':''?>>
              <?=htmlspecialchars($f['field_of_study'])?> (<?=$f['cnt']?>)
            </option>
          <?php endwhile; ?>
        </select>
      </div>

      <!-- DEGREE LEVEL -->
      <div class="sb-section">
        <div class="sb-section-title">Degree Level</div>
        <div class="sb-option">
          <label><input type="radio" name="level" value="" <?=!$filter_level?'checked':''?> onchange="this.form.submit()"><span>All Levels</span></label>
        </div>
        <?php $degree_levels->data_seek(0); while($dl=$degree_levels->fetch_assoc()): ?>
        <div class="sb-option">
          <label>
            <input type="radio" name="level" value="<?=htmlspecialchars($dl['degree_level'])?>"
                   <?=$filter_level===$dl['degree_level']?'checked':''?> onchange="this.form.submit()">
            <span><?=htmlspecialchars($dl['degree_level'])?></span>
          </label>
          <span class="sb-count"><?=$dl['cnt']?></span>
        </div>
        <?php endwhile; ?>
      </div>

      <!-- UNIVERSITY TYPE -->
      <div class="sb-section">
        <div class="sb-section-title">University Type</div>
        <?php foreach([''=> 'All Types','Public'=>'🏛️ Public','Private'=>'🏫 Private'] as $val=>$lbl): ?>
        <div class="sb-option">
          <label>
            <input type="radio" name="type" value="<?=$val?>"
                   <?=$filter_type===$val?'checked':''?> onchange="this.form.submit()">
            <span><?=$lbl?></span>
          </label>
        </div>
        <?php endforeach; ?>
      </div>

    </form>

    <!-- Pro Tip -->
    <div class="pro-tip">
      <div class="pro-tip-tag">💡 Pro Tip</div>
      <p>Create a free account to save universities, compare fees side-by-side, and get matched scholarships for your profile.</p>
      <a href="register.php">Setup Your Profile →</a>
    </div>
  </aside>

  <!-- RESULTS -->
  <div>
    <!-- Active filters -->
    <?php if(count($active_filters)>0): ?>
    <div class="filter-tags">
      <?php if($search): ?>
        <span class="ftag">🔍 "<?=htmlspecialchars($search)?>"<a href="?<?=build_qs(['q'=>'','page'=>1])?>">×</a></span>
      <?php endif; ?>
      <?php if(!empty($filter_countries)):
          $countries_list->data_seek(0);
          while($c=$countries_list->fetch_assoc()){
            if(in_array($c['country_id'],$filter_countries))
              echo '<span class="ftag">'.($flags[$c['country_code']]??'').' '.htmlspecialchars($c['country_name']).' <a href="?'.build_qs(['countries'=>[],'page'=>1]).'#results">×</a></span>';
          }
        endif; ?>
      <?php if($filter_type): ?>
        <span class="ftag">🏫 <?=htmlspecialchars($filter_type)?><a href="?<?=build_qs(['type'=>'','page'=>1])?>">×</a></span>
      <?php endif; ?>
      <?php if($filter_level): ?>
        <span class="ftag">🎓 <?=htmlspecialchars($filter_level)?><a href="?<?=build_qs(['level'=>'','page'=>1])?>">×</a></span>
      <?php endif; ?>
      <?php if($filter_field): ?>
        <span class="ftag">📚 <?=htmlspecialchars($filter_field)?><a href="?<?=build_qs(['field'=>'','page'=>1])?>">×</a></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Results bar -->
    <div id="results" class="results-head">
      <div class="results-count"><strong><?=number_format($total_results)?></strong> universities found</div>
      <div class="sort-row">
        <span class="sort-label">Sort by:</span>
        <select class="sort-sel" onchange="window.location='?'+new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)),...{sort:this.value,page:1}}).toString()">
          <option value="newest"   <?=$sort==='newest'  ?'selected':''?>>Newest First</option>
          <option value="name_asc" <?=$sort==='name_asc'?'selected':''?>>Name A–Z</option>
          <option value="rating"   <?=$sort==='rating'  ?'selected':''?>>Highest Rated</option>
          <option value="programs" <?=$sort==='programs' ?'selected':''?>>Most Programs</option>
        </select>
      </div>
    </div>

    <!-- University grid -->
    <div class="uni-grid">
    <?php if($universities->num_rows > 0):
      $i=0;
      while($u=$universities->fetch_assoc()):
        $i++;
        $photo   = !empty($u['image_url']) ? $u['image_url'] : $fallback_photo;
        $rating  = $u['avg_rating']  ? round((float)$u['avg_rating'],1) : null;
        $filled  = $rating ? round($rating) : 0;
        $cc      = $u['country_code'];
        $col     = $country_colors[$cc] ?? ['bg'=>'#F3F4F6','color'=>'#374151','dot'=>'#9CA3AF'];

        // Fee conversion
        $fee_display = null; $fee_label = 'No fee listed';
        if ($u['min_fee'] && $u['fee_currency']) {
            $conv = convert_fee((float)$u['min_fee'], $u['fee_currency'], $pref_currency, $exchange_rates);
            $fee_display = format_fee($conv ?? (float)$u['min_fee'], $conv ? $pref_currency : $u['fee_currency'], $currency_symbols);
            $fee_label   = $fee_display ? $fee_display.'/Yr' : 'See website';
        }

        // Duration from programs (avg)
        $dur_res = $conn->query("SELECT AVG(duration_months) AS avg_dur FROM programs WHERE university_id=".(int)$u['university_id']);
        $avg_dur = $dur_res->fetch_assoc()['avg_dur'];
        $duration_label = $avg_dur ? round($avg_dur/12,1).' Yrs' : '—';
    ?>
    <div class="uni-card fade-up d<?=min($i,8)?>">
      <div class="uc-photo">
        <img src="<?=$photo?>" alt="<?=htmlspecialchars($u['name'])?>" loading="lazy">
        <div class="uc-photo-overlay"></div>
        <div class="uc-badges">
          <span class="uc-type-badge"><?=htmlspecialchars($u['university_type'])?></span>
          <button class="uc-save-btn <?= in_array((int)$u['university_id'],$saved_ids)?'saved':'' ?>"
                onclick="toggleSave(this,<?=$u['university_id']?>)"
                title="<?= in_array((int)$u['university_id'],$saved_ids)?'Remove from saved':'Save university' ?>">
          <?= in_array((int)$u['university_id'],$saved_ids)?'❤️':'🤍' ?>
        </button>
        </div>
        <div class="uc-country-tag">
          <?=$flags[$cc]??''?> <?=htmlspecialchars($u['country_name'])?>
        </div>
      </div>

      <div class="uc-body">
        <div class="uc-name"><?=htmlspecialchars($u['name'])?></div>
        <div class="uc-location">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <?=htmlspecialchars($u['city'])?>, <?=htmlspecialchars($u['country_name'])?>
          <?php if($u['established_year']): ?>&nbsp;·&nbsp;Est. <?=$u['established_year']?><?php endif; ?>
        </div>
        <div class="uc-desc"><?=htmlspecialchars(substr($u['description'],0,130))?><?=strlen($u['description'])>130?'…':''?></div>

        <!-- Stats -->
        <div class="uc-stats">
          <div class="uc-stat">
            <div class="uc-stat-label">Avg Tuition</div>
            <div class="uc-stat-val fee"><?=$fee_display?:'-'?></div>
            <div class="uc-stat-sub">per year</div>
          </div>
          <div class="uc-stat">
            <div class="uc-stat-label">Programs</div>
            <div class="uc-stat-val"><?=$u['program_count']?>+</div>
            <div class="uc-stat-sub">available</div>
          </div>
          <div class="uc-stat">
            <div class="uc-stat-label">Duration</div>
            <div class="uc-stat-val"><?=$duration_label?></div>
            <div class="uc-stat-sub">average</div>
          </div>
        </div>

        <!-- Rating -->
        <div class="uc-rating">
          <?php for($s=1;$s<=5;$s++) echo '<span class="'.($s<=$filled?'star-on':'star-off').'">★</span>'; ?>
          <span class="rating-text">
            <?=$rating?"$rating (".$u['review_count']." ".($u['review_count']==1?'review':'reviews').")":'No reviews yet'?>
          </span>
        </div>
      </div>

      <div class="uc-actions">
        <a href="university_detail.php?id=<?=$u['university_id']?>" class="uc-btn-view">View Courses</a>
        <a href="compare.php?ids=<?=$u['university_id']?>"
           class="uc-btn-compare"
           data-id="<?=$u['university_id']?>"
           onclick="event.preventDefault();toggleCompare(<?=$u['university_id']?>,<?=json_encode($u['name'])?>,this)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 17H5a2 2 0 0 0-2 2v0M15 17h4a2 2 0 0 1 2 2v0M12 3v14M7 7l5-5 5 5"/></svg>
          Compare
        </a>
      </div>
    </div>
    <?php endwhile;
    else: ?>
      <div class="no-results">
        <div class="no-results-icon">🔍</div>
        <h3>No universities found</h3>
        <p>Try removing some filters or searching with different keywords.<br>
        <?php if(!empty($filter_countries)): ?>Currently filtering by <?=count($filter_countries)?> <?=count($filter_countries)===1?'country':'countries'?>. <?php endif; ?>
        </p>
        <a href="universities.php" style="display:inline-block;padding:11px 24px;background:#1A56DB;color:#fff;border-radius:9px;font-weight:700;font-size:13px;text-decoration:none;">Clear all filters</a>
      </div>
    <?php endif; ?>
    </div>

    <!-- PAGINATION -->
    <?php if($total_pages > 1): ?>
    <nav class="pagination">
      <a href="?<?=build_qs(['page'=>$page-1])?>" class="pg-btn <?=$page<=1?'disabled':''?>">‹</a>
      <?php
      for($p=1;$p<=$total_pages;$p++){
        if($p===1||$p===$total_pages||abs($p-$page)<=1){
          echo "<a href='?".build_qs(['page'=>$p])."' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        }elseif(abs($p-$page)===2){
          echo "<span class='pg-dots'>…</span>";
        }
      }
      ?>
      <a href="?<?=build_qs(['page'=>$page+1])?>" class="pg-btn <?=$page>=$total_pages?'disabled':''?>">›</a>
    </nav>
    <?php endif; ?>
  </div>
</div>

<!-- ══ FOOTER ══ -->

<?php include 'includes/footer.php'; ?>


<!-- ── TOAST NOTIFICATION ── -->
<div id="toast" style="
  position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(80px);
  background:#0A1628;color:#fff;font-family:'Plus Jakarta Sans',sans-serif;
  font-size:13px;font-weight:600;padding:12px 20px;border-radius:99px;
  box-shadow:0 8px 28px rgba(10,22,40,.25);
  display:flex;align-items:center;gap:8px;
  z-index:9999;transition:all .3s cubic-bezier(.34,1.56,.64,1);
  pointer-events:none;opacity:0;white-space:nowrap;">
  <span id="toast-icon">❤️</span>
  <span id="toast-msg">Saved!</span>
</div>

<!-- ── FLOATING COMPARE BAR ── -->
<div id="compareBar" style="
  position:fixed;bottom:0;left:0;right:0;
  background:linear-gradient(90deg,#1A56DB,#1E40AF);
  padding:12px 32px;display:none;
  align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:12px;
  z-index:998;box-shadow:0 -4px 24px rgba(10,22,40,.2);
  font-family:'Plus Jakarta Sans',sans-serif;">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <span style="font-size:13px;font-weight:700;color:rgba(255,255,255,.7);">⚖️ Compare:</span>
    <div id="compareSlots" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
  </div>
  <div style="display:flex;align-items:center;gap:10px;">
    <button onclick="clearCompare()" style="font-size:12px;font-weight:700;color:rgba(255,255,255,.6);background:none;border:none;cursor:pointer;padding:6px;">Clear all</button>
    <button onclick="goCompare()" id="compareNowBtn" style="
      padding:10px 24px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;
      color:#1A56DB;background:#fff;border:none;border-radius:8px;cursor:pointer;
      transition:all .15s;opacity:.5;pointer-events:none;">
      Compare Now →
    </button>
  </div>
</div>

<script>
// ── TOAST ──────────────────────────────────────────────────────────────
let _toastTimer;
function showToast(msg, icon='✅') {
  const t = document.getElementById('toast');
  document.getElementById('toast-msg').textContent  = msg;
  document.getElementById('toast-icon').textContent = icon;
  t.style.opacity   = '1';
  t.style.transform = 'translateX(-50%) translateY(0)';
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(() => {
    t.style.opacity   = '0';
    t.style.transform = 'translateX(-50%) translateY(80px)';
  }, 2400);
}

// ── SAVE / UNSAVE ──────────────────────────────────────────────────────
function toggleSave(btn, id) {
  const isSaved = btn.textContent.trim() === '❤️';
  btn.textContent = isSaved ? '🤍' : '❤️';
  btn.title       = isSaved ? 'Save university' : 'Remove from saved';
  btn.classList.toggle('saved', !isSaved);
  btn.style.transform = 'scale(1.35)';
  setTimeout(() => btn.style.transform = '', 200);
  <?php if(isset($_SESSION['user_id'])): ?>
  fetch(`save_university.php?id=${id}&action=${isSaved?'remove':'add'}`)
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        showToast(isSaved ? 'Removed from saved' : 'Saved to your list!', isSaved ? '🤍' : '❤️');
      }
    })
    .catch(() => {
      // Revert on error
      btn.textContent = isSaved ? '❤️' : '🤍';
      showToast('Something went wrong', '⚠️');
    });
  <?php else: ?>
  if (!isSaved) {
    btn.textContent = '🤍';
    window.location = 'login.php?next=' + encodeURIComponent(location.href);
  }
  <?php endif; ?>
}

// ── FLOATING COMPARE BAR ───────────────────────────────────────────────
// Restore compare state from sessionStorage (survives page navigation)
let compareSet = JSON.parse(sessionStorage.getItem('veya_compare') || '{}');

function saveCompareState() {
    sessionStorage.setItem('veya_compare', JSON.stringify(compareSet));
}

function toggleCompare(id, name, btn) {
    if (compareSet[id]) {
        delete compareSet[id];
        btn.textContent = '⚖️ Compare';
        btn.style.background = '';
        btn.style.color = '';
    } else {
        if (Object.keys(compareSet).length >= 3) {
            showToast('Max 3 universities — remove one first', '⚠️');
            return;
        }
        compareSet[id] = name;
        btn.textContent = '✓ Added';
        btn.style.background = '#1A56DB';
        btn.style.color = '#fff';
    }
    saveCompareState();
    renderCompareBar();
}

function renderCompareBar() {
    const ids   = Object.keys(compareSet);
    const bar   = document.getElementById('compareBar');
    const slots = document.getElementById('compareSlots');
    const goBtn = document.getElementById('compareNowBtn');

    if (ids.length === 0) { bar.style.display = 'none'; return; }
    bar.style.display = 'flex';

    slots.innerHTML = ids.map(id => `
        <div style="display:flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);
             padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700;color:#fff;">
            ${compareSet[id].length > 22 ? compareSet[id].slice(0,22)+'…' : compareSet[id]}
            <button onclick="removeCmpById(${id})" style="background:none;border:none;cursor:pointer;
                color:rgba(255,255,255,.6);font-size:14px;line-height:1;padding:0 0 0 4px;">✕</button>
        </div>
    `).join('');

    goBtn.style.opacity = ids.length >= 2 ? '1' : '.5';
    goBtn.style.pointerEvents = ids.length >= 2 ? 'auto' : 'none';
}

function removeCmpById(id) {
    const btn = document.querySelector(`.uc-btn-compare[data-id="${id}"]`);
    if (btn) { btn.textContent = '⚖️ Compare'; btn.style.background = ''; btn.style.color = ''; }
    delete compareSet[id];
    saveCompareState();
    renderCompareBar();
}

function clearCompare() {
    Object.keys(compareSet).forEach(id => removeCmpById(parseInt(id)));
    compareSet = {};
    saveCompareState();
    renderCompareBar();
}

function goCompare() {
    const ids = Object.keys(compareSet).join(',');
    if (ids) {
        sessionStorage.removeItem('veya_compare'); // clear after going to compare
        window.location = `compare.php?ids=${ids}`;
    }
}

// Restore button states for already-selected universities on page load
document.addEventListener('DOMContentLoaded', () => {
    renderCompareBar();
    Object.keys(compareSet).forEach(id => {
        const btn = document.querySelector(`.uc-btn-compare[data-id="${id}"]`);
        if (btn) {
            btn.textContent = '✓ Added';
            btn.style.background = '#1A56DB';
            btn.style.color = '#fff';
        }
    });
});

// ── Mobile filter toggle ───────────────────────────────────────────────
function toggleMobFilter() {
    const sidebar = document.getElementById('filterSidebar');
    const label   = document.getElementById('mobFilterLabel');
    const btn     = document.getElementById('mobFilterBtn');
    const isOpen  = sidebar.classList.toggle('mob-open');
    label.textContent = isOpen ? 'Hide Filters' : 'Show Filters';
    btn.classList.toggle('mob-filter-active', isOpen);
}
if ((location.search || location.hash === '#results') && document.getElementById('results')) {
  const el = document.getElementById('results');
  const y  = el.getBoundingClientRect().top + window.scrollY - 84;
  window.scrollTo({ top: y, behavior: 'smooth' });
}

// ── SORT SELECT ────────────────────────────────────────────────────────
document.querySelector('.sort-sel')?.addEventListener('change', function() {
  const p = new URLSearchParams(location.search);
  p.set('sort', this.value); p.set('page', '1');
  location.href = '?' + p.toString() + '#results';
});
</script>
</body>
</html>