<?php
// university_detail.php — Veya Education Hub
session_start();
require_once "db_connect.php";

// ── Validate university ID ────────────────────────────────────────────
$uni_id = (int)($_GET['id'] ?? 0);
if ($uni_id <= 0) { header("Location: universities.php"); exit; }

// Check if current user has saved this university + their saved scholarships
$is_saved      = false;
$saved_sch_ids = [];
if (isset($_SESSION['user_id'])) {
    $sv_uid = (int)$_SESSION['user_id'];

    $sv = $conn->prepare("SELECT 1 FROM saved_universities WHERE user_id=? AND university_id=?");
    $sv->bind_param("ii", $sv_uid, $uni_id);
    $sv->execute();
    $is_saved = (bool)$sv->get_result()->fetch_assoc();
    $sv->close();

    $sv2 = $conn->prepare("SELECT scholarship_id FROM saved_scholarships WHERE user_id=?");
    $sv2->bind_param("i", $sv_uid); $sv2->execute();
    foreach ($sv2->get_result()->fetch_all(MYSQLI_ASSOC) as $r)
        $saved_sch_ids[] = (int)$r['scholarship_id'];
    $sv2->close();
}

// ── Currency preference ───────────────────────────────────────────────
$pref_currency = $_GET['currency'] ?? $_SESSION['preferred_currency_code'] ?? 'MMK';
$valid_currencies = ['MMK','THB','SGD','MYR','USD','EUR'];
if (!in_array($pref_currency, $valid_currencies)) $pref_currency = 'MMK';

// ── Load currencies + exchange rates ─────────────────────────────────
$currency_symbols = [];
$cur_res = $conn->query("SELECT currency_code, symbol FROM currencies");
while ($row = $cur_res->fetch_assoc()) $currency_symbols[$row['currency_code']] = $row['symbol'];

$exchange_rates = [];
$rate_res = $conn->query("SELECT from_currency, to_currency, rate FROM exchange_rates");
while ($row = $rate_res->fetch_assoc()) $exchange_rates[$row['from_currency']][$row['to_currency']] = (float)$row['rate'];

function convert_fee($amount, $from, $to, $rates) {
    if ($from === $to) return $amount;

    // 1. Direct rate exists — use it
    if (isset($rates[$from][$to])) {
        return $amount * $rates[$from][$to];
    }

    // 2. Two-step via USD (most reliable intermediate)
    if (isset($rates[$from]['USD']) && isset($rates['USD'][$to])) {
        return $amount * $rates[$from]['USD'] * $rates['USD'][$to];
    }

    // 3. Two-step via MMK
    if (isset($rates[$from]['MMK']) && isset($rates['MMK'][$to])) {
        return $amount * $rates[$from]['MMK'] * $rates['MMK'][$to];
    }

    // 4. No conversion possible — return original amount in original currency
    return null;
}
function format_fee($amount, $currency, $symbols) {
    if ($amount === null) return null;
    $sym = $symbols[$currency] ?? $currency;
    if ($amount >= 1000000) return $sym . number_format($amount / 1000000, 2) . 'M';
    if ($amount >= 1000)    return $sym . number_format($amount / 1000, 1) . 'K';
    return $sym . number_format($amount, 0);
}

// ── Fetch university ──────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT u.*, c.country_name, c.country_code, c.currency_code
    FROM universities u
    JOIN countries c ON c.country_id = u.country_id
    WHERE u.university_id = ? AND u.is_active = 1
");
$stmt->bind_param("i", $uni_id);
$stmt->execute();
$uni = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$uni) { header("Location: universities.php"); exit; }

// ── Fetch programs with fees ──────────────────────────────────────────
$prog_res = $conn->prepare("
    SELECT p.*,
           MIN(tf.amount_local)        AS min_fee,
           MIN(tf.local_currency_code) AS fee_currency,
           tf.fee_period
    FROM programs p
    LEFT JOIN tuition_fees tf ON tf.program_id = p.program_id AND tf.fee_type = 'Tuition'
    WHERE p.university_id = ?
    GROUP BY p.program_id, p.university_id, p.program_name, p.degree_level,
             p.field_of_study, p.duration_months, p.language_of_instruction,
             p.description, p.created_at, tf.fee_period
    ORDER BY FIELD(p.degree_level,'Foundation','Diploma','Bachelor','Master','PhD'), p.program_name
");
$prog_res->bind_param("i", $uni_id);
$prog_res->execute();
$programs = $prog_res->get_result()->fetch_all(MYSQLI_ASSOC);
$prog_res->close();

// ── Fetch requirements & steps per program ────────────────────────────
$prog_ids = array_column($programs, 'program_id');
$requirements = [];
$steps = [];

if (!empty($prog_ids)) {
    $in = implode(',', array_map('intval', $prog_ids));

    $req_res = $conn->query("
        SELECT * FROM admission_requirements
        WHERE program_id IN ($in)
        ORDER BY program_id, is_mandatory DESC, requirement_type
    ");
    while ($r = $req_res->fetch_assoc()) $requirements[$r['program_id']][] = $r;

    $step_res = $conn->query("
        SELECT * FROM application_steps
        WHERE program_id IN ($in)
        ORDER BY program_id, step_number
    ");
    while ($s = $step_res->fetch_assoc()) $steps[$s['program_id']][] = $s;
}

// ── Fetch scholarships ────────────────────────────────────────────────
$sch_res = $conn->prepare("
    SELECT * FROM scholarships
    WHERE university_id = ?
    ORDER BY application_deadline ASC
");
$sch_res->bind_param("i", $uni_id);
$sch_res->execute();
$scholarships = $sch_res->get_result()->fetch_all(MYSQLI_ASSOC);
$sch_res->close();

// ── Fetch approved reviews ────────────────────────────────────────────
$rev_res = $conn->prepare("
    SELECT r.*, u.full_name
    FROM reviews r
    JOIN users u ON u.user_id = r.user_id
    WHERE r.university_id = ? AND r.status = 'Approved'
    ORDER BY r.created_at DESC
");
$rev_res->bind_param("i", $uni_id);
$rev_res->execute();
$reviews = $rev_res->get_result()->fetch_all(MYSQLI_ASSOC);
$rev_res->close();

// ── Stats ─────────────────────────────────────────────────────────────
$avg_rating = count($reviews) > 0
    ? round(array_sum(array_column($reviews, 'rating')) / count($reviews), 1)
    : null;
$program_count    = count($programs);
$scholarship_count = count($scholarships);
$review_count      = count($reviews);

// ── Handle review submission ──────────────────────────────────────────
$review_success = false;
$review_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!isset($_SESSION['user_id'])) {
        $review_error = 'You must be logged in to submit a review.';
    } elseif (!csrf_verify()) {
        $review_error = 'Security error: invalid request. Please refresh and try again.';
    } else {
        $rating  = (int)($_POST['rating']  ?? 0);
        $title   = trim($_POST['title']   ?? '');
        $comment = trim($_POST['comment'] ?? '');

        if ($rating < 1 || $rating > 5) {
            $review_error = 'Please select a rating between 1 and 5 stars.';
        } elseif ($comment === '') {
            $review_error = 'Please write a comment before submitting.';
        } else {
            // Check if user already reviewed this university
            $chk = $conn->prepare("SELECT review_id FROM reviews WHERE user_id = ? AND university_id = ?");
            $chk->bind_param("ii", $_SESSION['user_id'], $uni_id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $review_error = 'You have already submitted a review for this university.';
            } else {
                $ins = $conn->prepare("
                    INSERT INTO reviews (user_id, university_id, rating, title, comment, status)
                    VALUES (?, ?, ?, ?, ?, 'Pending')
                ");
                $ins->bind_param("iiiss", $_SESSION['user_id'], $uni_id, $rating, $title, $comment);
                if ($ins->execute()) {
                    $review_success = true;
                } else {
                    $review_error = 'Something went wrong. Please try again.';
                }
                $ins->close();
            }
            $chk->close();
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────
$flags = ['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$fallback_photo = 'https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=1600&q=85';

// Get image_url from DB and clean it up
$raw_image = trim($uni['image_url'] ?? '');

// FIX: Convert Windows backslashes to forward slashes
// e.g. assets\images\bangkok.jpg  →  assets/images/bangkok.jpg
$raw_image = str_replace('\\', '/', $raw_image);

if (!empty($raw_image)) {
    // If it's a full URL (http/https), upgrade Unsplash to high-res
    if (str_starts_with($raw_image, 'http')) {
        $hero_photo = $raw_image;
        if (str_contains($hero_photo, 'unsplash.com')) {
            $hero_photo = preg_replace('/w=\d+/', 'w=1600', $hero_photo);
            $hero_photo = preg_replace('/q=\d+/', 'q=90',   $hero_photo);
        }
    } else {
        // Local path — use as-is (e.g. assets/images/bangkok.jpg)
        $hero_photo = $raw_image;
    }
} else {
    // Nothing in DB — use fallback Unsplash photo
    $hero_photo = $fallback_photo;
}

$level_icons = [
    'Foundation' => '📗','Diploma'=>'📘','Bachelor'=>'🎓','Master'=>'🏅','PhD'=>'🔬'
];
$req_icons = ['Academic'=>'📋','English Test'=>'🌐','Document'=>'📄','Other'=>'ℹ️'];

function stars_html($rating, $size = 16) {
    $filled = $rating ? round($rating) : 0;
    $html = '';
    for ($i = 1; $i <= 5; $i++)
        $html .= '<span style="color:'.($i<=$filled?'#FBBF24':'#E5E7EB').';font-size:'.$size.'px">★</span>';
    return $html;
}
function currency_link($pref, $uni_id) {
    $currencies = ['MMK','THB','SGD','MYR','USD','EUR'];
    $html = '';
    foreach ($currencies as $c) {
        $active = $c === $pref ? 'style="background:#1A56DB;color:#fff;border-color:#1A56DB;"' : '';
        $html .= "<a href='university_detail.php?id=$uni_id&currency=$c' class='cur-pill' $active>$c</a>";
    }
    return $html;
}
$active_tab = $_GET['tab'] ?? 'overview';
$tabs = ['overview'=>'Overview','programs'=>'Programs & Fees','scholarships'=>'Scholarships','reviews'=>'Student Reviews','apply'=>'How to Apply'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($uni['name']) ?> — Veya</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F8FAFF;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
img{display:block;max-width:100%;}

/* ── NAV ── */
nav{
  position:sticky;top:0;z-index:200;
  background:rgba(255,255,255,.95);backdrop-filter:blur(16px);
  border-bottom:1px solid #E5E7EB;
  height:68px;padding:0 40px;
  display:flex;align-items:center;justify-content:space-between;gap:32px;
}
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
.hero{
  position:relative;height:480px;overflow:hidden;
  display:flex;align-items:flex-end;
}
.hero-bg{
  position:absolute;inset:0;
  background-size:cover;background-position:center;
}
.hero-bg.loaded{transform:scale(1.03);}
.hero-overlay{
  position:absolute;inset:0;
  background:linear-gradient(to top,rgba(10,22,40,.92) 0%,rgba(10,22,40,.5) 50%,rgba(10,22,40,.15) 100%);
}
.hero-content{
  position:relative;z-index:2;
  width:100%;max-width:1240px;margin:0 auto;
  padding:0 40px 40px;
}
.hero-breadcrumb{
  display:flex;align-items:center;gap:8px;
  font-size:12px;color:rgba(255,255,255,.5);margin-bottom:20px;
}
.hero-breadcrumb a{color:rgba(255,255,255,.5);transition:color .15s;}
.hero-breadcrumb a:hover{color:#fff;}
.hero-breadcrumb span{color:rgba(255,255,255,.25);}
.hero-top{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap;}
.hero-left{}
.hero-badges{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;}
.hero-badge{
  font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
  padding:5px 12px;border-radius:99px;
}
.badge-type{background:rgba(255,255,255,.15);backdrop-filter:blur(8px);color:#fff;border:1px solid rgba(255,255,255,.25);}
.badge-country{background:rgba(26,86,219,.3);color:#93C5FD;border:1px solid rgba(26,86,219,.4);}
.hero-name{
  font-family:'Instrument Serif',serif;font-weight:400;
  font-size:clamp(26px,4vw,44px);color:#fff;
  letter-spacing:-.02em;line-height:1.1;margin-bottom:6px;
}
.hero-sub{font-size:14px;color:rgba(255,255,255,.65);display:flex;align-items:center;gap:8px;}
.hero-sub svg{width:14px;height:14px;flex-shrink:0;}
.hero-stats{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;}
.hs{
  display:flex;flex-direction:column;align-items:center;
  background:rgba(255,255,255,.12);backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.18);
  border-radius:12px;padding:12px 20px;min-width:90px;text-align:center;
}
.hs-val{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.02em;line-height:1;}
.hs-label{font-size:10px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.55);margin-top:4px;}

/* Currency pills in hero */
.hero-right{display:flex;flex-direction:column;align-items:flex-end;gap:10px;}
.cur-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.cur-row-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.45);}
.cur-pill{
  font-size:12px;font-weight:700;padding:5px 13px;border-radius:99px;
  border:1px solid rgba(255,255,255,.25);color:rgba(255,255,255,.75);
  transition:all .15s;
}
.cur-pill:hover{border-color:rgba(255,255,255,.6);color:#fff;}
.hero-website{
  display:inline-flex;align-items:center;gap:6px;
  font-size:13px;font-weight:600;color:#fff;
  background:rgba(255,255,255,.12);backdrop-filter:blur(8px);
  border:1px solid rgba(255,255,255,.22);
  padding:9px 18px;border-radius:9px;
  transition:background .15s;
}
.hero-website:hover{background:rgba(255,255,255,.2);}
.hero-website svg{width:14px;height:14px;}

/* ── TAB NAV ── */
.tab-nav{
  background:#fff;border-bottom:1px solid #E5E7EB;
  position:sticky;top:68px;z-index:100;
}
.tab-nav-inner{
  max-width:1240px;margin:0 auto;
  display:flex;overflow-x:auto;padding:0 40px;
  scrollbar-width:none;
}
.tab-nav-inner::-webkit-scrollbar{display:none;}
.tab-btn{
  flex-shrink:0;padding:16px 20px;
  font-size:14px;font-weight:600;color:#6B7280;
  border-bottom:2px solid transparent;
  margin-bottom:-1px;cursor:pointer;
  transition:color .15s,border-color .15s;
  display:flex;align-items:center;gap:7px;
  background:none;border-top:none;border-left:none;border-right:none;
  font-family:'Plus Jakarta Sans',sans-serif;
}
.tab-btn:hover{color:#0A1628;}
.tab-btn.active{color:#1A56DB;border-bottom-color:#1A56DB;}
.tab-badge{
  font-size:10px;font-weight:700;
  background:#EFF6FF;color:#1A56DB;
  padding:1px 6px;border-radius:99px;
}
.tab-btn.active .tab-badge{background:#1A56DB;color:#fff;}

/* ── LAYOUT ── */
.page-body{max-width:1240px;margin:0 auto;padding:32px 40px;display:grid;grid-template-columns:1fr 340px;gap:28px;}
.main-col{}
.side-col{}

/* ── TAB CONTENT ── */
.tab-panel{display:none;}
.tab-panel.active{display:block;}

/* ── SECTION HEADERS ── */
.sec-title{
  font-family:'Instrument Serif',serif;font-weight:400;
  font-size:26px;letter-spacing:-.01em;color:#0A1628;
  margin-bottom:6px;
}
.sec-sub{font-size:14px;color:#6B7280;margin-bottom:24px;line-height:1.6;}
.sec-divider{height:1px;background:#F3F4F6;margin:32px 0;}

/* ── OVERVIEW ── */
.overview-desc{
  font-size:15px;color:#374151;line-height:1.8;
  margin-bottom:28px;
}
.key-facts{
  display:grid;grid-template-columns:repeat(3,1fr);gap:14px;
  margin-bottom:28px;
}
.kf{
  background:#fff;border:1px solid #E5E7EB;
  border-radius:12px;padding:18px;
  display:flex;align-items:center;gap:12px;
}
.kf-icon{font-size:22px;flex-shrink:0;}
.kf-label{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#9CA3AF;margin-bottom:3px;}
.kf-val{font-size:15px;font-weight:700;color:#0A1628;}

/* ── PROGRAMS ── */
.prog-list{display:flex;flex-direction:column;gap:16px;}
.prog-card{
  background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;
  transition:box-shadow .2s,border-color .2s;
}
.prog-card:hover{box-shadow:0 8px 24px rgba(10,22,40,.07);border-color:#BFDBFE;}
.prog-header{
  display:flex;align-items:flex-start;justify-content:space-between;
  padding:20px 20px 0;gap:12px;cursor:pointer;
}
.prog-header-left{flex:1;}
.prog-level-badge{
  font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
  padding:3px 9px;border-radius:99px;display:inline-block;margin-bottom:8px;
}
.level-Foundation{background:#FEF3C7;color:#92400E;}
.level-Diploma{background:#EDE9FE;color:#5B21B6;}
.level-Bachelor{background:#EFF6FF;color:#1E40AF;}
.level-Master{background:#F0FDF4;color:#166534;}
.level-PhD{background:#FFF7ED;color:#9A3412;}
.prog-name{font-size:16px;font-weight:700;color:#0A1628;line-height:1.3;margin-bottom:4px;}
.prog-meta{font-size:12px;color:#6B7280;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.prog-meta-item{display:flex;align-items:center;gap:4px;}
.prog-fee{text-align:right;flex-shrink:0;}
.prog-fee-label{font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#9CA3AF;margin-bottom:2px;}
.prog-fee-val{font-size:20px;font-weight:800;color:#1A56DB;line-height:1;}
.prog-fee-per{font-size:11px;color:#9CA3AF;}
.prog-toggle{
  font-size:18px;color:#9CA3AF;flex-shrink:0;
  margin-top:4px;transition:transform .25s;
}
.prog-card.open .prog-toggle{transform:rotate(180deg);}

.prog-body{display:none;padding:0 20px 20px;}
.prog-card.open .prog-body{display:block;}
.prog-desc{font-size:14px;color:#374151;line-height:1.7;margin:14px 0 18px;}

/* Requirements */
.req-list{display:flex;flex-direction:column;gap:8px;margin-bottom:18px;}
.req-item{
  display:flex;align-items:flex-start;gap:10px;
  background:#F9FAFB;border-radius:8px;padding:10px 12px;
}
.req-icon{font-size:16px;flex-shrink:0;margin-top:1px;}
.req-content{}
.req-type{font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#9CA3AF;margin-bottom:2px;}
.req-desc{font-size:13px;color:#374151;line-height:1.5;}
.req-tag{
  font-size:9px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
  background:#FEE2E2;color:#991B1B;
  padding:1px 5px;border-radius:3px;margin-left:5px;vertical-align:middle;
}

/* Application steps */
.steps-list{display:flex;flex-direction:column;gap:0;position:relative;padding-left:28px;}
.steps-list::before{
  content:"";position:absolute;left:10px;top:12px;bottom:12px;
  width:1.5px;background:#E5E7EB;
}
.step-item{position:relative;padding:0 0 18px;}
.step-item:last-child{padding-bottom:0;}
.step-num{
  position:absolute;left:-28px;top:0;
  width:20px;height:20px;border-radius:50%;
  background:#1A56DB;color:#fff;
  font-size:10px;font-weight:800;
  display:flex;align-items:center;justify-content:center;
}
.step-title{font-size:13px;font-weight:700;color:#0A1628;margin-bottom:3px;}
.step-desc{font-size:12px;color:#6B7280;line-height:1.55;}

/* ── SCHOLARSHIPS ── */
.sch-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.sch-card{
  background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:20px;
  transition:box-shadow .2s,border-color .2s;
  display:flex;flex-direction:column;
}
.sch-card:hover{box-shadow:0 8px 24px rgba(10,22,40,.07);border-color:#BBF7D0;}
.sch-icon{font-size:28px;margin-bottom:12px;}
.sch-name{font-size:15px;font-weight:700;color:#0A1628;line-height:1.3;margin-bottom:6px;}
.sch-desc{font-size:13px;color:#6B7280;line-height:1.6;margin-bottom:14px;flex:1;}
.sch-amount{
  font-size:18px;font-weight:800;color:#059669;
  margin-bottom:10px;
}
.sch-amount span{font-size:11px;font-weight:500;color:#9CA3AF;}
.sch-eligibility{
  font-size:12px;color:#374151;line-height:1.55;
  background:#F0FDF4;border-radius:8px;padding:10px;
  margin-bottom:12px;
}
.sch-eligibility strong{color:#166534;display:block;margin-bottom:3px;font-size:10px;text-transform:uppercase;letter-spacing:.06em;}
.sch-deadline{
  font-size:12px;font-weight:600;color:#EF4444;
  display:flex;align-items:center;gap:5px;
}
.sch-deadline.ok{color:#059669;}

/* ── REVIEWS ── */
.review-summary{
  display:flex;align-items:center;gap:28px;
  background:#fff;border:1px solid #E5E7EB;border-radius:14px;
  padding:24px;margin-bottom:24px;flex-wrap:wrap;
}
.rating-big{text-align:center;}
.rating-big-num{font-size:52px;font-weight:800;color:#0A1628;line-height:1;}
.rating-big-stars{margin:4px 0;}
.rating-big-count{font-size:13px;color:#9CA3AF;}
.rating-bars{flex:1;min-width:160px;}
.rbar{display:flex;align-items:center;gap:8px;margin-bottom:5px;}
.rbar-label{font-size:12px;color:#6B7280;width:10px;}
.rbar-track{flex:1;height:6px;background:#F3F4F6;border-radius:99px;overflow:hidden;}
.rbar-fill{height:100%;background:#FBBF24;border-radius:99px;}
.rbar-count{font-size:11px;color:#9CA3AF;width:16px;text-align:right;}

.review-list{display:flex;flex-direction:column;gap:16px;margin-bottom:32px;}
.rev-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:20px;}
.rev-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px;}
.rev-author{display:flex;align-items:center;gap:10px;}
.rev-avatar{
  width:38px;height:38px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:15px;font-weight:800;color:#fff;flex-shrink:0;
}
.rev-name{font-size:14px;font-weight:700;color:#0A1628;}
.rev-date{font-size:12px;color:#9CA3AF;}
.rev-title{font-size:15px;font-weight:700;color:#0A1628;margin-bottom:7px;}
.rev-comment{font-size:14px;color:#374151;line-height:1.7;}
.rev-empty{
  text-align:center;padding:48px;
  background:#fff;border:1px dashed #E5E7EB;border-radius:14px;
}

/* Write review form */
.review-form-wrap{
  background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:24px;
}
.review-form-title{font-size:17px;font-weight:700;color:#0A1628;margin-bottom:4px;}
.review-form-sub{font-size:13px;color:#6B7280;margin-bottom:20px;}

.star-picker{display:flex;gap:8px;margin-bottom:18px;}
.star-btn{
  font-size:28px;color:#E5E7EB;background:none;border:none;
  cursor:pointer;transition:color .1s,transform .1s;
  line-height:1;
}
.star-btn:hover,.star-btn.active{color:#FBBF24;transform:scale(1.2);}

.form-field{margin-bottom:16px;}
.form-label{display:block;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6B7280;margin-bottom:6px;}
.form-input,.form-textarea{
  width:100%;padding:11px 13px;
  font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;color:#0A1628;
  background:#F9FAFB;border:1px solid #E5E7EB;border-radius:9px;
  transition:border-color .15s,box-shadow .15s;
}
.form-input:focus,.form-textarea:focus{
  outline:none;border-color:#93C5FD;
  box-shadow:0 0 0 3px rgba(147,197,253,.2);background:#fff;
}
.form-textarea{resize:vertical;min-height:100px;line-height:1.6;}
.form-submit{
  width:100%;padding:13px;font-family:'Plus Jakarta Sans',sans-serif;
  font-size:14px;font-weight:700;color:#fff;background:#1A56DB;
  border:none;border-radius:9px;cursor:pointer;transition:background .15s;
}
.form-submit:hover{background:#1E40AF;}
.alert{padding:12px 14px;border-radius:9px;font-size:13px;margin-bottom:16px;}
.alert-success{background:#ECFDF5;color:#065F46;border:1px solid #A7F3D0;}
.alert-error{background:#FEF2F2;color:#991B1B;border:1px solid #FECACA;}
.login-prompt{
  text-align:center;padding:28px;
  background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;
}
.login-prompt p{font-size:14px;color:#1E40AF;margin-bottom:14px;font-weight:500;}
.login-prompt a{
  display:inline-block;padding:10px 24px;
  background:#1A56DB;color:#fff;font-weight:700;font-size:14px;border-radius:8px;
}

/* ── HOW TO APPLY ── */
.apply-intro{
  font-size:15px;color:#374151;line-height:1.8;margin-bottom:28px;
}
.apply-prog-select{margin-bottom:28px;}
.apply-prog-select label{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6B7280;display:block;margin-bottom:8px;}
.apply-sel{
  width:100%;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:600;color:#0A1628;
  background:#fff;border:1px solid #E5E7EB;padding:11px 14px;border-radius:9px;
  cursor:pointer;outline:none;transition:border-color .15s;
}
.apply-sel:focus{border-color:#93C5FD;}

.apply-steps-header{font-size:16px;font-weight:700;color:#0A1628;margin-bottom:16px;}
.apply-step{
  display:flex;gap:16px;padding:18px;
  background:#fff;border:1px solid #E5E7EB;border-radius:12px;
  margin-bottom:12px;
  transition:box-shadow .2s,border-color .2s;
}
.apply-step:hover{box-shadow:0 4px 16px rgba(10,22,40,.06);border-color:#BFDBFE;}
.apply-step-num{
  width:36px;height:36px;border-radius:10px;
  background:#1A56DB;color:#fff;
  font-size:15px;font-weight:800;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
}
.apply-step-body{}
.apply-step-title{font-size:15px;font-weight:700;color:#0A1628;margin-bottom:5px;}
.apply-step-desc{font-size:13px;color:#6B7280;line-height:1.65;}

/* ── SIDEBAR ── */
.sidebar-card{
  background:#fff;border:1px solid #E5E7EB;border-radius:14px;
  overflow:hidden;margin-bottom:16px;
}
.sidebar-card-head{
  padding:16px 18px;border-bottom:1px solid #F3F4F6;
  font-size:13px;font-weight:700;color:#0A1628;
  display:flex;align-items:center;gap:7px;
}
.sidebar-card-body{padding:18px;}
.quick-fact{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #F9FAFB;}
.quick-fact:last-child{border-bottom:none;}
.qf-label{font-size:13px;color:#6B7280;}
.qf-val{font-size:13px;font-weight:700;color:#0A1628;text-align:right;}
.sidebar-cta{
  background:linear-gradient(135deg,#0A1628,#1E3A5F);
  border-radius:14px;padding:22px;margin-bottom:16px;
}
.sidebar-cta h4{font-size:16px;font-weight:700;color:#fff;margin-bottom:8px;}
.sidebar-cta p{font-size:13px;color:rgba(255,255,255,.65);line-height:1.6;margin-bottom:16px;}
.sidebar-cta a{
  display:block;text-align:center;
  font-size:14px;font-weight:700;color:#0A1628;
  background:#fff;padding:10px;border-radius:9px;
  transition:background .15s;
}
.sidebar-cta a:hover{background:#F0F4FF;}
.nearby-uni{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #F9FAFB;}
.nearby-uni:last-child{border-bottom:none;}
.nearby-img{width:48px;height:36px;border-radius:6px;object-fit:cover;flex-shrink:0;}
.nearby-name{font-size:13px;font-weight:600;color:#0A1628;line-height:1.3;}
.nearby-loc{font-size:11px;color:#9CA3AF;}

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
.footer-nl-btn{background:#1A56DB;border:none;color:#fff;padding:0 14px;cursor:pointer;font-size:18px;}
.footer-bottom{max-width:1240px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;padding:20px 0;font-size:12px;color:rgba(255,255,255,.3);flex-wrap:wrap;gap:10px;}
.footer-bottom-links{display:flex;gap:20px;}
.footer-bottom-links a{color:rgba(255,255,255,.3);transition:color .15s;}
.footer-bottom-links a:hover{color:rgba(255,255,255,.6);}

/* Responsive */
@media(max-width:1024px){.page-body{grid-template-columns:1fr;}.side-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;}.footer-top{grid-template-columns:1fr 1fr;}}
@media(max-width:760px){.hero{height:420px;}.key-facts{grid-template-columns:1fr 1fr;}.sch-grid{grid-template-columns:1fr;}.hero-content{padding:0 20px 28px;}.hero-right{display:none;}.page-body{padding:20px;}.tab-nav-inner{padding:0 20px;}.footer-top{grid-template-columns:1fr;}.side-col{grid-template-columns:1fr;}}
@media(max-width:520px){.key-facts{grid-template-columns:1fr;}.hero-stats{gap:8px;}.hs{padding:10px 14px;}}
</style>
</head>
<body>

<!-- NAV -->
<?php $nav_active = 'universities'; include 'includes/nav.php'; ?>

<!-- HERO -->
<div class="hero">
  <?php
  // Decide how to output the URL safely:
  // - Local path  (assets/images/x.jpg) → output raw, no encoding needed
  // - Remote URL  (https://...)          → output raw inside CSS url() — HTML entity encoding breaks CSS
  $is_remote = str_starts_with($hero_photo, 'http');
  $safe_photo = $is_remote
      ? $hero_photo                              // remote: keep raw (& is fine inside CSS url())
      : htmlspecialchars($hero_photo, ENT_QUOTES, 'UTF-8'); // local: encode for safety
  ?>
  <div class="hero-bg" id="heroBg"
       style="background-image:url('<?= $safe_photo ?>')">
    <img src="<?= $safe_photo ?>"
         alt="<?= htmlspecialchars($uni['name']) ?>"
         style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block;z-index:0;opacity:0;"
         onerror="this.style.display='none'">
  </div>
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <div class="hero-breadcrumb">
      <a href="index.php">Home</a><span>/</span>
      <a href="universities.php">Universities</a><span>/</span>
      <span><?= htmlspecialchars($uni['name']) ?></span>
    </div>
    <div class="hero-top">
      <div class="hero-left">
        <div class="hero-badges">
          <span class="hero-badge badge-type"><?= htmlspecialchars($uni['university_type']) ?></span>
          <span class="hero-badge badge-country"><?= $flags[$uni['country_code']] ?? '' ?> <?= htmlspecialchars($uni['country_name']) ?></span>
          <?php if ($uni['established_year']): ?>
            <span class="hero-badge badge-type">Est. <?= $uni['established_year'] ?></span>
          <?php endif; ?>
        </div>
        <h1 class="hero-name"><?= htmlspecialchars($uni['name']) ?></h1>
        <div class="hero-sub">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 1 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <?= htmlspecialchars($uni['city']) ?>, <?= htmlspecialchars($uni['country_name']) ?>
        </div>
        <div class="hero-stats">
          <div class="hs">
            <div class="hs-val"><?= $program_count ?></div>
            <div class="hs-label">Programs</div>
          </div>
          <div class="hs">
            <div class="hs-val"><?= $scholarship_count ?></div>
            <div class="hs-label">Scholarships</div>
          </div>
          <div class="hs">
            <div class="hs-val"><?= $avg_rating ?? '—' ?></div>
            <div class="hs-label">Rating</div>
          </div>
          <div class="hs">
            <div class="hs-val"><?= $review_count ?></div>
            <div class="hs-label">Reviews</div>
          </div>
        </div>
      </div>
      <div class="hero-right">
        <div class="cur-row">
          <span class="cur-row-label">Fees in:</span>
          <?= currency_link($pref_currency, $uni_id) ?>
        </div>
        <!-- Save + Compare actions -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
          <button id="saveBtn"
                  onclick="toggleSaveDetail()"
                  style="display:flex;align-items:center;gap:6px;padding:9px 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;border-radius:9px;cursor:pointer;transition:all .2s;border:2px solid rgba(255,255,255,.4);background:<?= $is_saved?'rgba(239,68,68,.85)':'rgba(255,255,255,.15)' ?>;color:#fff;backdrop-filter:blur(8px);">
            <span id="saveIcon"><?= $is_saved?'❤️':'🤍' ?></span>
            <span id="saveLbl"><?= $is_saved?'Saved':'Save' ?></span>
          </button>
          <a href="compare.php?ids=<?= $uni_id ?>"
             style="display:flex;align-items:center;gap:6px;padding:9px 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;border-radius:9px;border:2px solid rgba(255,255,255,.4);background:rgba(255,255,255,.15);color:#fff;backdrop-filter:blur(8px);transition:all .2s;text-decoration:none;"
             onmouseover="this.style.background='rgba(255,255,255,.25)'"
             onmouseout="this.style.background='rgba(255,255,255,.15)'">
            ⚖️ Compare
          </a>
        </div>
        <?php if ($uni['website_url']): ?>
          <a href="<?= htmlspecialchars($uni['website_url']) ?>" target="_blank" rel="noopener" class="hero-website">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            Visit Official Website
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- TAB NAV -->
<div class="tab-nav">
  <div class="tab-nav-inner">
    <?php foreach ($tabs as $key => $label): ?>
      <button class="tab-btn <?= $active_tab === $key ? 'active' : '' ?>"
              onclick="switchTab('<?= $key ?>')">
        <?= $label ?>
        <?php if ($key === 'programs' && $program_count > 0): ?>
          <span class="tab-badge"><?= $program_count ?></span>
        <?php elseif ($key === 'scholarships' && $scholarship_count > 0): ?>
          <span class="tab-badge"><?= $scholarship_count ?></span>
        <?php elseif ($key === 'reviews' && $review_count > 0): ?>
          <span class="tab-badge"><?= $review_count ?></span>
        <?php endif; ?>
      </button>
    <?php endforeach; ?>
  </div>
</div>

<!-- PAGE BODY -->
<div class="page-body">

  <!-- MAIN COLUMN -->
  <div class="main-col">

    <!-- ══ OVERVIEW ══ -->
    <div class="tab-panel <?= $active_tab === 'overview' ? 'active' : '' ?>" id="tab-overview">
      <p class="overview-desc"><?= nl2br(htmlspecialchars($uni['description'] ?? 'No description available yet.')) ?></p>

      <div class="key-facts">
        <div class="kf">
          <span class="kf-icon">🏛️</span>
          <div><div class="kf-label">Type</div><div class="kf-val"><?= htmlspecialchars($uni['university_type']) ?></div></div>
        </div>
        <div class="kf">
          <span class="kf-icon">📍</span>
          <div><div class="kf-label">Location</div><div class="kf-val"><?= htmlspecialchars($uni['city'] ?? '—') ?></div></div>
        </div>
        <div class="kf">
          <span class="kf-icon">📅</span>
          <div><div class="kf-label">Established</div><div class="kf-val"><?= $uni['established_year'] ?? '—' ?></div></div>
        </div>
        <div class="kf">
          <span class="kf-icon">🌐</span>
          <div><div class="kf-label">Language</div><div class="kf-val">English</div></div>
        </div>
        <div class="kf">
          <span class="kf-icon">🎓</span>
          <div><div class="kf-label">Programs</div><div class="kf-val"><?= $program_count ?> offered</div></div>
        </div>
        <div class="kf">
          <span class="kf-icon">🏅</span>
          <div><div class="kf-label">Scholarships</div><div class="kf-val"><?= $scholarship_count ?> available</div></div>
        </div>
      </div>

      <?php if (!empty($programs)): ?>
      <div class="sec-divider"></div>
      <h2 class="sec-title">Programs at a Glance</h2>
      <p class="sec-sub">Click any program below for full details, fees, and entry requirements.</p>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <?php foreach ($programs as $p):
          $fee_str = '';
          if ($p['min_fee'] && $p['fee_currency']) {
            $conv = convert_fee((float)$p['min_fee'], $p['fee_currency'], $pref_currency, $exchange_rates);
            $fee_str = format_fee($conv ?? (float)$p['min_fee'], $conv ? $pref_currency : $p['fee_currency'], $currency_symbols);
          }
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:14px 16px;cursor:pointer;transition:border-color .15s;"
             onclick="switchTab('programs')" onmouseover="this.style.borderColor='#BFDBFE'" onmouseout="this.style.borderColor='#E5E7EB'">
          <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:18px;"><?= $level_icons[$p['degree_level']] ?? '🎓' ?></span>
            <div>
              <div style="font-size:14px;font-weight:600;color:#0A1628;"><?= htmlspecialchars($p['program_name']) ?></div>
              <div style="font-size:12px;color:#9CA3AF;"><?= htmlspecialchars($p['degree_level']) ?> · <?= htmlspecialchars($p['field_of_study'] ?? '') ?> · <?= round(($p['duration_months'] ?? 48)/12, 1) ?> yrs</div>
            </div>
          </div>
          <?php if ($fee_str): ?>
            <div style="text-align:right;flex-shrink:0;">
              <div style="font-size:16px;font-weight:800;color:#1A56DB;"><?= $fee_str ?></div>
              <div style="font-size:10px;color:#9CA3AF;">per year</div>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ PROGRAMS & FEES ══ -->
    <div class="tab-panel <?= $active_tab === 'programs' ? 'active' : '' ?>" id="tab-programs">
      <h2 class="sec-title">Programs &amp; Fees</h2>
      <p class="sec-sub">Fees shown in <strong><?= htmlspecialchars($pref_currency) ?></strong>. Use the currency selector in the hero to change.</p>

      <?php if (empty($programs)): ?>
        <div style="text-align:center;padding:60px;background:#fff;border:1px dashed #E5E7EB;border-radius:14px;">
          <div style="font-size:36px;margin-bottom:12px;">📭</div>
          <div style="font-size:16px;font-weight:600;color:#0A1628;">No programs listed yet</div>
        </div>
      <?php else: ?>
      <div class="prog-list">
        <?php foreach ($programs as $p):
          $fee_str = '—'; $fee_per = '';
          if ($p['min_fee'] && $p['fee_currency']) {
            $conv = convert_fee((float)$p['min_fee'], $p['fee_currency'], $pref_currency, $exchange_rates);
            $fee_str = format_fee($conv ?? (float)$p['min_fee'], $conv ? $pref_currency : $p['fee_currency'], $currency_symbols) ?? '—';
            $fee_per = $p['fee_period'] === 'Total Program' ? 'total' : ($p['fee_period'] === 'Per Semester' ? '/semester' : '/year');
          }
          $prog_reqs  = $requirements[$p['program_id']] ?? [];
          $prog_steps = $steps[$p['program_id']] ?? [];
        ?>
        <div class="prog-card" id="prog-<?= $p['program_id'] ?>">
          <div class="prog-header" onclick="toggleProg(<?= $p['program_id'] ?>)">
            <div class="prog-header-left">
              <span class="prog-level-badge level-<?= $p['degree_level'] ?>"><?= htmlspecialchars($p['degree_level']) ?></span>
              <div class="prog-name"><?= htmlspecialchars($p['program_name']) ?></div>
              <div class="prog-meta">
                <?php if ($p['field_of_study']): ?>
                  <span class="prog-meta-item">📚 <?= htmlspecialchars($p['field_of_study']) ?></span>
                <?php endif; ?>
                <?php if ($p['duration_months']): ?>
                  <span class="prog-meta-item">⏱️ <?= round($p['duration_months']/12, 1) ?> years</span>
                <?php endif; ?>
                <span class="prog-meta-item">🌐 <?= htmlspecialchars($p['language_of_instruction']) ?></span>
              </div>
            </div>
            <div class="prog-fee">
              <div class="prog-fee-label">Tuition</div>
              <div class="prog-fee-val"><?= $fee_str ?></div>
              <div class="prog-fee-per"><?= $fee_per ?></div>
            </div>
            <span class="prog-toggle">⌄</span>
          </div>

          <div class="prog-body">
            <?php if ($p['description']): ?>
              <p class="prog-desc"><?= htmlspecialchars($p['description']) ?></p>
            <?php endif; ?>

            <?php if (!empty($prog_reqs)): ?>
              <div style="font-size:13px;font-weight:700;color:#0A1628;margin-bottom:10px;">📋 Admission Requirements</div>
              <div class="req-list">
                <?php foreach ($prog_reqs as $r): ?>
                  <div class="req-item">
                    <span class="req-icon"><?= $req_icons[$r['requirement_type']] ?? 'ℹ️' ?></span>
                    <div class="req-content">
                      <div class="req-type">
                        <?= htmlspecialchars($r['requirement_type']) ?>
                        <?php if ($r['is_mandatory']): ?><span class="req-tag">Required</span><?php endif; ?>
                      </div>
                      <div class="req-desc"><?= htmlspecialchars($r['description']) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($prog_steps)): ?>
              <div style="font-size:13px;font-weight:700;color:#0A1628;margin-bottom:12px;margin-top:16px;">🪜 How to Apply</div>
              <div class="steps-list">
                <?php foreach ($prog_steps as $s): ?>
                  <div class="step-item">
                    <div class="step-num"><?= $s['step_number'] ?></div>
                    <div class="step-title"><?= htmlspecialchars($s['step_title']) ?></div>
                    <div class="step-desc"><?= htmlspecialchars($s['step_description']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ SCHOLARSHIPS ══ -->
    <div class="tab-panel <?= $active_tab === 'scholarships' ? 'active' : '' ?>" id="tab-scholarships">
      <h2 class="sec-title">Scholarships</h2>
      <p class="sec-sub">Financial assistance available to international students at <?= htmlspecialchars($uni['name']) ?>.</p>

      <?php if (empty($scholarships)): ?>
        <div style="text-align:center;padding:60px;background:#fff;border:1px dashed #E5E7EB;border-radius:14px;">
          <div style="font-size:36px;margin-bottom:12px;">🏅</div>
          <div style="font-size:16px;font-weight:600;color:#0A1628;">No scholarships listed yet</div>
        </div>
      <?php else: ?>
      <div class="sch-grid">
        <?php foreach ($scholarships as $s):
          $sch_fee = '';
          if ($s['amount_local'] && $s['local_currency_code']) {
            $conv = convert_fee((float)$s['amount_local'], $s['local_currency_code'], $pref_currency, $exchange_rates);
            $sch_fee = format_fee($conv ?? (float)$s['amount_local'], $conv ? $pref_currency : $s['local_currency_code'], $currency_symbols);
          }
          $deadline = $s['application_deadline'];
          $is_past  = $deadline && strtotime($deadline) < time();
        ?>
        <div class="sch-card">
          <!-- Name row -->
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px;">
            <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:0;">
              <div class="sch-icon">🏅</div>
              <div class="sch-name" style="flex:1;"><?= htmlspecialchars($s['name']) ?></div>
            </div>
          </div>

          <!-- Description -->
          <div class="sch-desc"><?= htmlspecialchars($s['description'] ?? '') ?></div>

          <!-- Award amount -->
          <?php if ($sch_fee): ?>
            <div class="sch-amount"><?= $sch_fee ?> <span>per year</span></div>
          <?php endif; ?>

          <!-- Award type + renewable badges -->
          <?php if (!empty($s['award_type']) || !empty($s['renewable'])): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0;">
              <?php if(!empty($s['award_type'])): ?>
                <span style="font-size:10px;font-weight:700;background:#EFF6FF;color:#1E40AF;padding:3px 8px;border-radius:99px;">
                  <?= htmlspecialchars($s['award_type']) ?>
                </span>
              <?php endif; ?>
              <?php if(!empty($s['renewable'])): ?>
                <span style="font-size:10px;font-weight:700;background:#F0FDF4;color:#065F46;padding:3px 8px;border-radius:99px;">
                  🔄 Renewable
                </span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <!-- Eligibility -->
          <?php if ($s['eligibility']): ?>
            <div class="sch-eligibility">
              <strong>Eligibility:</strong> <?= htmlspecialchars($s['eligibility']) ?>
            </div>
          <?php endif; ?>

          <!-- Deadline -->
          <?php if ($deadline): ?>
            <div class="sch-deadline <?= $is_past ? '' : 'ok' ?>">
              <?= $is_past ? '⚠️ Deadline passed:' : '📅 Apply by:' ?>
              <?= date('d M Y', strtotime($deadline)) ?>
            </div>
          <?php endif; ?>

          <!-- Action buttons: Apply + Save -->
          <?php $sch_is_saved = in_array((int)$s['scholarship_id'], $saved_sch_ids); ?>
          <div style="display:flex;align-items:center;gap:8px;margin-top:14px;flex-wrap:wrap;">

            <?php
              // Apply button: use application_url if set, else fall back to university website
              $apply_url  = !empty($s['application_url']) ? $s['application_url'] : ($uni['website_url'] ?? '');
              $apply_label = !empty($s['application_url']) ? '📝 Apply Now →' : '🌐 Visit University Website';
            ?>
            <?php if ($apply_url): ?>
              <a href="<?= htmlspecialchars($apply_url) ?>" target="_blank" rel="noopener"
                 style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:#059669;color:#fff;font-family:'Plus Jakarta Sans',sans-serif;font-size:12px;font-weight:700;border-radius:8px;text-decoration:none;transition:background .15s;"
                 onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                <?= $apply_label ?>
              </a>
            <?php endif; ?>

            <!-- Save / Unsave scholarship -->
            <button onclick="toggleSchSave(this,<?= $s['scholarship_id'] ?>)"
                    data-saved="<?= $sch_is_saved ? '1' : '0' ?>"
                    title="<?= $sch_is_saved ? 'Remove from saved' : 'Save scholarship' ?>"
                    style="display:inline-flex;align-items:center;gap:5px;padding:9px 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:12px;font-weight:700;border-radius:8px;cursor:pointer;transition:all .2s;border:1.5px solid <?= $sch_is_saved?'#EF4444':'#E5E7EB' ?>;background:<?= $sch_is_saved?'#FEE2E2':'#fff' ?>;color:<?= $sch_is_saved?'#EF4444':'#374151' ?>;">
              <?= $sch_is_saved ? '❤️ Saved' : '🤍 Save' ?>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ REVIEWS ══ -->
    <div class="tab-panel <?= $active_tab === 'reviews' ? 'active' : '' ?>" id="tab-reviews">
      <h2 class="sec-title">Student Reviews</h2>
      <p class="sec-sub">Honest experiences from students who studied at <?= htmlspecialchars($uni['name']) ?>.</p>

      <!-- Rating summary -->
      <?php if ($review_count > 0):
        $star_counts = array_fill(1, 5, 0);
        foreach ($reviews as $r) $star_counts[$r['rating']]++;
      ?>
      <div class="review-summary">
        <div class="rating-big">
          <div class="rating-big-num"><?= $avg_rating ?></div>
          <div class="rating-big-stars"><?= stars_html($avg_rating, 18) ?></div>
          <div class="rating-big-count"><?= $review_count ?> review<?= $review_count!=1?'s':'' ?></div>
        </div>
        <div class="rating-bars">
          <?php for ($star = 5; $star >= 1; $star--):
            $pct = $review_count > 0 ? round($star_counts[$star] / $review_count * 100) : 0;
          ?>
          <div class="rbar">
            <span class="rbar-label"><?= $star ?></span>
            <div class="rbar-track"><div class="rbar-fill" style="width:<?= $pct ?>%"></div></div>
            <span class="rbar-count"><?= $star_counts[$star] ?></span>
          </div>
          <?php endfor; ?>
        </div>
      </div>

      <div class="review-list">
        <?php
        $avatar_gradients = [
          'linear-gradient(135deg,#6366F1,#8B5CF6)',
          'linear-gradient(135deg,#059669,#10B981)',
          'linear-gradient(135deg,#D97706,#F59E0B)',
          'linear-gradient(135deg,#DC2626,#EF4444)',
          'linear-gradient(135deg,#0891B2,#06B6D4)',
        ];
        foreach ($reviews as $ri => $r):
          $grad = $avatar_gradients[$ri % count($avatar_gradients)];
          $initial = strtoupper($r['full_name'][0] ?? 'U');
        ?>
        <div class="rev-card">
          <div class="rev-top">
            <div class="rev-author">
              <div class="rev-avatar" style="background:<?= $grad ?>"><?= $initial ?></div>
              <div>
                <div class="rev-name"><?= htmlspecialchars($r['full_name']) ?></div>
                <div class="rev-date"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
              </div>
            </div>
            <div><?= stars_html($r['rating'], 15) ?></div>
          </div>
          <?php if ($r['title']): ?>
            <div class="rev-title"><?= htmlspecialchars($r['title']) ?></div>
          <?php endif; ?>
          <div class="rev-comment"><?= nl2br(htmlspecialchars($r['comment'] ?? '')) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="rev-empty">
        <div style="font-size:36px;margin-bottom:12px;">💬</div>
        <div style="font-size:16px;font-weight:600;color:#0A1628;margin-bottom:6px;">No reviews yet</div>
        <div style="font-size:14px;color:#6B7280;">Be the first to share your experience at this university.</div>
      </div>
      <?php endif; ?>

      <!-- Write a review -->
      <div class="review-form-wrap" id="write-review">
        <div class="review-form-title">✍️ Write a Review</div>
        <div class="review-form-sub">Share your experience to help other students make the right choice.</div>

        <?php if ($review_success): ?>
          <div class="alert alert-success">✅ Thank you! Your review has been submitted and is pending approval.</div>
        <?php endif; ?>
        <?php if ($review_error): ?>
          <div class="alert alert-error">⚠️ <?= htmlspecialchars($review_error) ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['user_id'])): ?>
        <form method="POST" action="university_detail.php?id=<?= $uni_id ?>&tab=reviews#write-review">
          <input type="hidden" name="submit_review" value="1">
          <?= csrf_field() ?>

          <div class="form-field">
            <label class="form-label">Your Rating</label>
            <div class="star-picker" id="starPicker">
              <?php for ($s = 1; $s <= 5; $s++): ?>
                <button type="button" class="star-btn" data-val="<?= $s ?>" onclick="setRating(<?= $s ?>)">★</button>
              <?php endfor; ?>
            </div>
            <input type="hidden" name="rating" id="ratingInput" value="0">
          </div>

          <div class="form-field">
            <label class="form-label" for="rev-title">Review Title (optional)</label>
            <input class="form-input" type="text" id="rev-title" name="title"
                   placeholder="e.g. Great campus experience" maxlength="150"
                   value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
          </div>

          <div class="form-field">
            <label class="form-label" for="rev-comment">Your Review</label>
            <textarea class="form-textarea" id="rev-comment" name="comment"
                      placeholder="Tell other students what you liked, what to watch out for, and any tips for applying…"><?= htmlspecialchars($_POST['comment'] ?? '') ?></textarea>
          </div>

          <button class="form-submit" type="submit">Submit Review</button>
        </form>
        <?php else: ?>
        <div class="login-prompt">
          <p>You need to be logged in to submit a review.</p>
          <a href="login.php?next=university_detail.php?id=<?= $uni_id ?>&tab=reviews">Log in to write a review →</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ HOW TO APPLY ══ -->
    <div class="tab-panel <?= $active_tab === 'apply' ? 'active' : '' ?>" id="tab-apply">
      <h2 class="sec-title">How to Apply</h2>
      <p class="apply-intro">
        Follow the steps below to apply to <?= htmlspecialchars($uni['name']) ?>.
        Each program may have slightly different requirements — select a program to see its specific steps.
      </p>

      <?php if (!empty($programs)): ?>
      <div class="apply-prog-select">
        <label>Select a Program</label>
        <select class="apply-sel" id="progSelect" onchange="showProgSteps(this.value)">
          <?php foreach ($programs as $p): ?>
            <option value="<?= $p['program_id'] ?>"><?= htmlspecialchars($p['program_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php foreach ($programs as $pi => $p):
        $prog_steps_here = $steps[$p['program_id']] ?? [];
      ?>
      <div id="steps-<?= $p['program_id'] ?>" style="<?= $pi > 0 ? 'display:none' : '' ?>">
        <div class="apply-steps-header">
          Steps to apply for <em><?= htmlspecialchars($p['program_name']) ?></em>
        </div>
        <?php if (empty($prog_steps_here)): ?>
          <div style="padding:24px;background:#F9FAFB;border-radius:12px;text-align:center;color:#9CA3AF;font-size:14px;">
            Application steps not yet listed. Please visit the <a href="<?= htmlspecialchars($uni['website_url'] ?? '#') ?>" target="_blank" style="color:#1A56DB;font-weight:600;">official website</a> for details.
          </div>
        <?php else: ?>
          <?php foreach ($prog_steps_here as $s): ?>
          <div class="apply-step">
            <div class="apply-step-num"><?= $s['step_number'] ?></div>
            <div class="apply-step-body">
              <div class="apply-step-title"><?= htmlspecialchars($s['step_title']) ?></div>
              <div class="apply-step-desc"><?= htmlspecialchars($s['step_description']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php else: ?>
        <div style="text-align:center;padding:48px;background:#F9FAFB;border-radius:14px;">
          <div style="font-size:14px;color:#9CA3AF;">No programs listed yet.</div>
        </div>
      <?php endif; ?>
    </div>

  </div><!-- /main-col -->

  <!-- SIDEBAR -->
  <aside class="side-col">

    <!-- Quick Facts -->
    <div class="sidebar-card">
      <div class="sidebar-card-head">📋 Quick Facts</div>
      <div class="sidebar-card-body">
        <div class="quick-fact"><span class="qf-label">Country</span><span class="qf-val"><?= htmlspecialchars($uni['country_name']) ?></span></div>
        <div class="quick-fact"><span class="qf-label">City</span><span class="qf-val"><?= htmlspecialchars($uni['city'] ?? '—') ?></span></div>
        <div class="quick-fact"><span class="qf-label">Type</span><span class="qf-val"><?= htmlspecialchars($uni['university_type']) ?></span></div>
        <div class="quick-fact"><span class="qf-label">Established</span><span class="qf-val"><?= $uni['established_year'] ?? '—' ?></span></div>
        <div class="quick-fact"><span class="qf-label">Language</span><span class="qf-val">English</span></div>
        <div class="quick-fact"><span class="qf-label">Currency</span><span class="qf-val"><?= htmlspecialchars($uni['currency_code']) ?></span></div>
        <?php if ($uni['website_url']): ?>
        <div class="quick-fact">
          <span class="qf-label">Website</span>
          <a href="<?= htmlspecialchars($uni['website_url']) ?>" target="_blank" rel="noopener"
             style="font-size:13px;font-weight:700;color:#1A56DB;">Visit →</a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Min Fee Card -->
    <?php
    $first_prog_with_fee = null;
    foreach ($programs as $p) {
        if ($p['min_fee'] && $p['fee_currency']) { $first_prog_with_fee = $p; break; }
    }
    if ($first_prog_with_fee):
      $conv = convert_fee((float)$first_prog_with_fee['min_fee'], $first_prog_with_fee['fee_currency'], $pref_currency, $exchange_rates);
      $fee_disp = format_fee($conv ?? (float)$first_prog_with_fee['min_fee'], $conv ? $pref_currency : $first_prog_with_fee['fee_currency'], $currency_symbols);
    ?>
    <div class="sidebar-card">
      <div class="sidebar-card-head">💱 Tuition Fees</div>
      <div class="sidebar-card-body" style="text-align:center;padding:20px;">
        <div style="font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;margin-bottom:6px;">Starting from</div>
        <div style="font-size:32px;font-weight:800;color:#1A56DB;line-height:1;margin-bottom:4px;"><?= $fee_disp ?></div>
        <div style="font-size:12px;color:#9CA3AF;margin-bottom:16px;">per year</div>
        <button onclick="switchTab('programs')"
                style="width:100%;padding:10px;background:#EFF6FF;color:#1A56DB;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;border:none;border-radius:8px;cursor:pointer;">
          View All Programs →
        </button>
      </div>
    </div>
    <?php endif; ?>

    <!-- CTA — only show to guests -->
    <?php if (!isset($_SESSION['user_id'])): ?>
    <div class="sidebar-cta">
      <h4>Ready to apply?</h4>
      <p>Create a free Veya account to save this university, track your application, and discover matched scholarships.</p>
      <a href="register.php">Get Started Free →</a>
    </div>
    <?php endif; ?>

    <!-- Official Links — only verified website shown.
         Instagram/Facebook removed: unverified data may show wrong accounts.
         Admin can add verified social links via admin panel in future. -->
    <?php if (!empty($uni['website_url'])): ?>
    <div class="sidebar-card">
      <div class="sidebar-card-head">🔗 Official Links</div>
      <div class="sidebar-card-body" style="display:flex;flex-direction:column;gap:8px;">
        <a href="<?= htmlspecialchars($uni['website_url']) ?>" target="_blank" rel="noopener"
           style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:9px;text-decoration:none;font-size:13px;font-weight:600;color:#374151;transition:all .15s;"
           onmouseover="this.style.borderColor='#1A56DB';this.style.color='#1A56DB'" onmouseout="this.style.borderColor='#E5E7EB';this.style.color='#374151'">
          🌐 <span>Official Website</span>
          <span style="margin-left:auto;font-size:11px;color:#9CA3AF;">↗</span>
        </a>
        <p style="font-size:11px;color:#9CA3AF;line-height:1.5;padding:4px 2px;">
          Visit the official website for the most up-to-date information on admissions, fees, and social media.
        </p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Scholarships preview -->
    <?php if ($scholarship_count > 0): ?>
    <div class="sidebar-card">
      <div class="sidebar-card-head">🏅 Scholarships Available</div>
      <div class="sidebar-card-body">
        <?php foreach (array_slice($scholarships, 0, 3) as $s): ?>
        <div style="padding:10px 0;border-bottom:1px solid #F9FAFB;">
          <div style="font-size:13px;font-weight:600;color:#0A1628;margin-bottom:3px;line-height:1.3;"><?= htmlspecialchars($s['name']) ?></div>
          <?php if ($s['application_deadline']): ?>
            <div style="font-size:11px;color:#EF4444;">Apply by <?= date('d M Y', strtotime($s['application_deadline'])) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($scholarship_count > 3): ?>
          <button onclick="switchTab('scholarships')"
                  style="width:100%;margin-top:12px;padding:9px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;color:#374151;cursor:pointer;">
            View all <?= $scholarship_count ?> scholarships →
          </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </aside>
</div>

<!-- FOOTER -->

<?php include 'includes/footer.php'; ?>


<script>
// ── Hero BG parallax load
const heroBg = document.getElementById('heroBg');
setTimeout(() => heroBg.classList.add('loaded'), 100);
window.addEventListener('scroll', () => {
  const s = window.scrollY;
  if (s < 600) heroBg.style.transform = `scale(1.03) translateY(${s * 0.25}px)`;
}, {passive: true});

// ── Tab switching
function switchTab(tabId) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + tabId)?.classList.add('active');
  document.querySelectorAll('.tab-btn').forEach(b => {
    if (b.getAttribute('onclick')?.includes(`'${tabId}'`)) b.classList.add('active');
  });
  // smooth scroll to tabs
  document.querySelector('.tab-nav').scrollIntoView({behavior:'smooth', block:'start'});
}

// ── Program accordion
function toggleProg(id) {
  const card = document.getElementById('prog-' + id);
  card.classList.toggle('open');
}

// ── Star rating picker
function setRating(val) {
  document.getElementById('ratingInput').value = val;
  document.querySelectorAll('.star-btn').forEach(btn => {
    btn.classList.toggle('active', parseInt(btn.dataset.val) <= val);
  });
}

// Hover preview on stars
document.querySelectorAll('.star-btn').forEach(btn => {
  btn.addEventListener('mouseenter', () => {
    const v = parseInt(btn.dataset.val);
    document.querySelectorAll('.star-btn').forEach(b =>
      b.style.color = parseInt(b.dataset.val) <= v ? '#FBBF24' : '#E5E7EB'
    );
  });
  btn.addEventListener('mouseleave', () => {
    const cur = parseInt(document.getElementById('ratingInput').value);
    document.querySelectorAll('.star-btn').forEach(b =>
      b.style.color = parseInt(b.dataset.val) <= cur ? '#FBBF24' : '#E5E7EB'
    );
  });
});

// ── Apply tab program selector
function showProgSteps(progId) {
  document.querySelectorAll('[id^="steps-"]').forEach(el => el.style.display = 'none');
  const target = document.getElementById('steps-' + progId);
  if (target) target.style.display = 'block';
}

// ── Open first program on page load if ?program=X
const urlParams = new URLSearchParams(window.location.search);
const progParam = urlParams.get('program');
if (progParam) {
  switchTab('programs');
  const card = document.getElementById('prog-' + progParam);
  if (card) {
    card.classList.add('open');
    setTimeout(() => card.scrollIntoView({behavior:'smooth', block:'center'}), 400);
  }
}

// ── Save / Unsave scholarship on detail page ───────────────────────────
function toggleSchSave(btn, id) {
    // Read state from data attribute — reliable regardless of button text
    const isSaved = btn.getAttribute('data-saved') === '1';
    const newSaved = !isSaved;

    // Update UI immediately
    btn.setAttribute('data-saved', newSaved ? '1' : '0');
    btn.textContent  = newSaved ? '❤️ Saved' : '🤍 Save';
    btn.title        = newSaved ? 'Remove from saved' : 'Save scholarship';
    btn.style.border      = `1.5px solid ${newSaved ? '#EF4444' : '#E5E7EB'}`;
    btn.style.background  = newSaved ? '#FEE2E2' : '#fff';
    btn.style.color       = newSaved ? '#EF4444' : '#374151';
    btn.style.transform   = 'scale(1.12)';
    setTimeout(() => btn.style.transform = '', 200);

    <?php if(isset($_SESSION['user_id'])): ?>
    fetch(`save_scholarship.php?id=${id}&action=${isSaved ? 'remove' : 'add'}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                // Revert on failure
                btn.setAttribute('data-saved', isSaved ? '1' : '0');
                btn.textContent = isSaved ? '❤️ Saved' : '🤍 Save';
                btn.style.border     = `1.5px solid ${isSaved ? '#EF4444' : '#E5E7EB'}`;
                btn.style.background = isSaved ? '#FEE2E2' : '#fff';
                btn.style.color      = isSaved ? '#EF4444' : '#374151';
            }
        })
        .catch(() => {
            // Revert on network error
            btn.setAttribute('data-saved', isSaved ? '1' : '0');
            btn.textContent = isSaved ? '❤️ Saved' : '🤍 Save';
            btn.style.border     = `1.5px solid ${isSaved ? '#EF4444' : '#E5E7EB'}`;
            btn.style.background = isSaved ? '#FEE2E2' : '#fff';
            btn.style.color      = isSaved ? '#EF4444' : '#374151';
        });
    <?php else: ?>
    // Not logged in — revert and redirect to login
    btn.setAttribute('data-saved', '0');
    btn.textContent = '🤍 Save';
    btn.style.border = '1.5px solid #E5E7EB';
    btn.style.background = '#fff';
    btn.style.color = '#374151';
    window.location = 'login.php?next=<?= urlencode('university_detail.php?id='.$uni_id.'&tab=scholarships') ?>';
    <?php endif; ?>
}
<?php if(isset($_SESSION['user_id'])): ?>
let _isSaved = <?= $is_saved ? 'true' : 'false' ?>;
function toggleSaveDetail() {
    const btn  = document.getElementById('saveBtn');
    const icon = document.getElementById('saveIcon');
    const lbl  = document.getElementById('saveLbl');
    const action = _isSaved ? 'remove' : 'add';
    _isSaved = !_isSaved;
    icon.textContent = _isSaved ? '❤️' : '🤍';
    lbl.textContent  = _isSaved ? 'Saved' : 'Save';
    btn.style.background = _isSaved ? 'rgba(239,68,68,.85)' : 'rgba(255,255,255,.15)';
    fetch(`save_university.php?id=<?= $uni_id ?>&action=${action}`)
        .catch(() => { _isSaved = !_isSaved; icon.textContent = _isSaved?'❤️':'🤍'; });
}
<?php else: ?>
function toggleSaveDetail() {
    window.location = 'login.php?next=<?= urlencode('university_detail.php?id='.$uni_id) ?>';
}
<?php endif; ?>
</script>
</body>
</html>