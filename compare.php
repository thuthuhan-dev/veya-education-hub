<?php
// compare.php — Veya Education Hub
session_start();
require_once "db_connect.php";

$pref_currency = $_GET['currency'] ?? $_SESSION['preferred_currency_code'] ?? 'MMK';
if (!in_array($pref_currency, ['MMK','THB','SGD','MYR','USD','EUR'])) $pref_currency = 'MMK';

$rates   = load_exchange_rates($conn);
$symbols = load_currency_symbols($conn);

$raw_ids = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
$ids     = array_values(array_unique(array_slice($raw_ids, 0, 3)));

$universities = [];
if (!empty($ids)) {
    $in  = implode(',', $ids);
    $res = $conn->query("
        SELECT u.*,
               c.country_name, c.country_code, c.currency_code,
               ROUND(AVG(r.rating),1)            AS avg_rating,
               COUNT(DISTINCT r.review_id)       AS review_count,
               COUNT(DISTINCT p.program_id)      AS program_count,
               COUNT(DISTINCT s.scholarship_id)  AS sch_count,
               MIN(tf.amount_local)              AS min_fee,
               MIN(tf.local_currency_code)       AS fee_currency
        FROM universities u
        JOIN countries c ON c.country_id = u.country_id
        LEFT JOIN reviews r ON r.university_id = u.university_id AND r.status='Approved'
        LEFT JOIN programs p ON p.university_id = u.university_id
        LEFT JOIN scholarships s ON s.university_id = u.university_id
        LEFT JOIN tuition_fees tf ON tf.program_id = p.program_id AND tf.fee_type='Tuition'
        WHERE u.university_id IN ($in) AND u.is_active=1
        GROUP BY u.university_id, c.country_name, c.country_code, c.currency_code
    ");
    $map = [];
    while ($row = $res->fetch_assoc()) $map[$row['university_id']] = $row;
    foreach ($ids as $id) if (isset($map[$id])) $universities[] = $map[$id];
}

$all_unis = $conn->query("SELECT university_id, name FROM universities WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$flags    = ['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$fallback = 'https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=600&q=80';
$count    = count($universities);

function safe_img_c($raw){
    global $fallback;
    $img = trim($raw ?? '');
    if (empty($img)) return $fallback;
    $img = str_replace('\\','/',$img);
    return str_starts_with($img,'http') ? $img : htmlspecialchars($img,ENT_QUOTES,'UTF-8');
}

// Build structured comparison data grouped by category
function make_sections($universities, $pref_currency, $rates, $symbols) {
    global $flags;
    $count = count($universities);

    // Helper to get values array
    $vals = fn($key) => array_column($universities, $key);
    $fmt_rank = fn($u) => $u['world_ranking'] ? '#'.$u['world_ranking'] : null;
    $fmt_nat  = fn($u) => $u['national_ranking'] ? '#'.$u['national_ranking'] : null;
    $fmt_fee  = function($u) use ($pref_currency,$rates,$symbols) {
        if (!$u['min_fee'] || !$u['fee_currency']) return null;
        $conv = convert_fee((float)$u['min_fee'],$u['fee_currency'],$pref_currency,$rates);
        if (!$conv) return null;
        return format_fee($conv, $pref_currency, $symbols).'/yr';
    };

    return [
        ['label'=>'🎓 Overview', 'rows'=>[
            ['icon'=>'🏛️','label'=>'Type',             'vals'=>array_map(fn($u)=>$u['university_type'],$universities)],
            ['icon'=>'📍','label'=>'City',              'vals'=>array_map(fn($u)=>$u['city'],$universities)],
            ['icon'=>'📅','label'=>'Established',       'vals'=>array_map(fn($u)=>$u['established_year'],$universities)],
            ['icon'=>'🔬','label'=>'Research Output',   'vals'=>array_map(fn($u)=>$u['research_output'],$universities)],
        ]],
        ['label'=>'🏆 Rankings', 'rows'=>[
            ['icon'=>'🌐','label'=>'QS World Ranking',  'vals'=>array_map($fmt_rank,$universities), 'best'=>'low_num'],
            ['icon'=>'🏳️','label'=>'National Ranking',  'vals'=>array_map($fmt_nat,$universities),  'best'=>'low_num'],
        ]],
        ['label'=>'👥 Students & Campus', 'rows'=>[
            ['icon'=>'👥','label'=>'Total Students',    'vals'=>array_map(fn($u)=>$u['total_students']?number_format($u['total_students']):null,$universities), 'best'=>'high_num'],
            ['icon'=>'🌏','label'=>'International %',   'vals'=>array_map(fn($u)=>$u['international_pct']?$u['international_pct'].'%':null,$universities)],
            ['icon'=>'📐','label'=>'Student:Faculty',   'vals'=>array_map(fn($u)=>$u['student_faculty_ratio'],$universities)],
            ['icon'=>'🏫','label'=>'Campus Size',       'vals'=>array_map(fn($u)=>$u['campus_size'],$universities)],
            ['icon'=>'📊','label'=>'Acceptance Rate',   'vals'=>array_map(fn($u)=>$u['acceptance_rate']?$u['acceptance_rate'].'%':null,$universities), 'best'=>'high_num'],
        ]],
        ['label'=>'💰 Fees & Programs', 'rows'=>[
            ['icon'=>'💰','label'=>'Min Tuition ('.$pref_currency.')', 'vals'=>array_map($fmt_fee,$universities), 'best'=>'low_str'],
            ['icon'=>'📚','label'=>'Programs Available','vals'=>array_map(fn($u)=>$u['program_count'],$universities), 'best'=>'high_num'],
            ['icon'=>'🏅','label'=>'Scholarships',      'vals'=>array_map(fn($u)=>$u['sch_count'],$universities), 'best'=>'high_num'],
        ]],
        ['label'=>'⭐ Reputation', 'rows'=>[
            ['icon'=>'⭐','label'=>'Student Rating',    'vals'=>array_map(fn($u)=>$u['avg_rating']?$u['avg_rating'].' / 5.0':null,$universities), 'best'=>'high_num'],
            ['icon'=>'💬','label'=>'Reviews',           'vals'=>array_map(fn($u)=>$u['review_count'],$universities)],
        ]],
    ];
}

$sections = $universities ? make_sections($universities, $pref_currency, $rates, $symbols) : [];

// All-same detection: returns true if all non-null values are identical
function all_same($vals) {
    $non_null = array_filter($vals, fn($v) => $v !== null && $v !== '');
    if (count($non_null) <= 1) return false;
    return count(array_unique($non_null)) === 1;
}

$nav_active = 'compare';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Compare Universities — Veya</title>
<?php include 'includes/nav.php'; ?>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F1F5F9;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}

/* HERO */
.ph{background:linear-gradient(135deg,#0A1628,#1E3A5F);padding:36px 40px;position:relative;overflow:hidden;}
.ph::before{content:"";position:absolute;top:-60px;right:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(26,86,219,.25),transparent 70%);}
.ph-in{max-width:1200px;margin:0 auto;position:relative;z-index:2;}
.ph-bc{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:12px;display:flex;gap:7px;}
.ph-bc a{color:rgba(255,255,255,.4);}
.ph-bc a:hover{color:#fff;}
.ph-bc span{color:rgba(255,255,255,.2);}
.ph-row{display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:16px;}
.ph-title{font-family:'Instrument Serif',serif;font-size:clamp(22px,3vw,34px);color:#fff;font-weight:400;margin-bottom:4px;}
.ph-sub{font-size:13px;color:rgba(255,255,255,.55);}
.cur-row{display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.cur-lbl{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:rgba(255,255,255,.4);}
.cur-pill{font-size:12px;font-weight:700;padding:5px 13px;border-radius:99px;border:1px solid rgba(255,255,255,.22);color:rgba(255,255,255,.6);transition:all .15s;}
.cur-pill:hover{border-color:rgba(255,255,255,.5);color:#fff;}
.cur-pill.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}

/* CONTROLS BAR */
.controls{background:#fff;border-bottom:1px solid #E5E7EB;padding:12px 40px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.add-sel{font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;color:#374151;background:#F9FAFB;border:1.5px solid #E5E7EB;padding:9px 13px;border-radius:9px;outline:none;cursor:pointer;min-width:220px;flex:1;max-width:340px;}
.add-sel:focus{border-color:#93C5FD;}
.add-btn{padding:9px 20px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#1A56DB;border:none;border-radius:9px;cursor:pointer;transition:background .15s;white-space:nowrap;}
.add-btn:hover{background:#1E40AF;}
.diff-toggle{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:#374151;cursor:pointer;user-select:none;margin-left:auto;}
.diff-toggle input{width:16px;height:16px;accent-color:#1A56DB;cursor:pointer;}
.back-link{font-size:13px;font-weight:600;color:#6B7280;display:flex;align-items:center;gap:5px;}
.back-link:hover{color:#0A1628;}

/* MAIN */
.main{max-width:1200px;margin:0 auto;padding:28px 40px;}

/* EMPTY STATE */
.empty{text-align:center;padding:80px 20px;background:#fff;border:1px dashed #E5E7EB;border-radius:16px;}

/* COMPARE TABLE */
.compare-wrap{overflow-x:auto;border-radius:16px;box-shadow:0 2px 16px rgba(10,22,40,.07);}
.compare-table{width:100%;min-width:560px;border-collapse:collapse;}

/* STICKY HEADER ROW */
.uni-header-row{position:sticky;top:68px;z-index:40;background:#fff;}
.uni-header-row td{padding:0;border-bottom:2px solid #E5E7EB;}
.uni-header-row td:first-child{width:200px;min-width:160px;background:#F9FAFB;border-right:1px solid #E5E7EB;}
.uni-card-cell{padding:0;}
.uni-card{position:relative;overflow:hidden;}
.uni-card-img{height:130px;overflow:hidden;position:relative;}
.uni-card-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s;}
.uni-card-img::after{content:"";position:absolute;inset:0;background:linear-gradient(to top,rgba(10,22,40,.65),transparent 50%);}
.uni-remove{position:absolute;top:10px;right:10px;width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.85);border:none;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;z-index:2;transition:all .15s;}
.uni-remove:hover{background:#FEF2F2;color:#EF4444;}
.uni-card-body{padding:12px 16px;}
.uni-flag{font-size:11px;font-weight:700;color:#9CA3AF;margin-bottom:3px;}
.uni-name{font-size:13px;font-weight:800;color:#0A1628;line-height:1.3;margin-bottom:6px;}
.uni-rating{display:flex;align-items:center;gap:4px;margin-bottom:8px;}
.uni-stars span{color:#FBBF24;font-size:11px;}
.uni-stars .off{color:#E5E7EB;}
.uni-rating-num{font-size:11px;color:#9CA3AF;}
.uni-actions{display:flex;gap:6px;}
.uni-view{flex:1;text-align:center;font-size:11px;font-weight:700;color:#fff;background:#1A56DB;padding:6px 10px;border-radius:7px;transition:background .15s;}
.uni-view:hover{background:#1E40AF;}
.uni-web{font-size:11px;font-weight:600;color:#374151;background:#F3F4F6;padding:6px 10px;border-radius:7px;transition:background .15s;}
.uni-web:hover{background:#E5E7EB;}

/* SECTION HEADER ROW */
.sec-row td{
    padding:10px 16px;
    font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;
    background:#0A1628;color:#fff;
}
.sec-row td:first-child{border-right:1px solid rgba(255,255,255,.08);}

/* DATA ROWS */
.data-row td{
    padding:13px 16px;font-size:13px;color:#374151;
    border-bottom:1px solid #F1F5F9;vertical-align:middle;
}
.data-row:nth-child(even) td{background:#FAFBFF;}
.data-row:nth-child(odd) td{background:#fff;}
.data-row td:first-child{
    background:#F9FAFB!important;border-right:1px solid #E5E7EB;
    font-size:12px;font-weight:700;color:#374151;
    display:flex;align-items:center;gap:7px;
}
.row-icon{font-size:14px;width:20px;text-align:center;flex-shrink:0;}
.cell-val{font-size:13px;font-weight:500;}
.cell-best{font-weight:700;color:#065F46;background:#ECFDF5!important;border-radius:6px;padding:4px 10px;display:inline-block;}
.cell-null{color:#D1D5DB;font-size:13px;}
.cell-same{color:#9CA3AF;font-size:13px;}

/* HIDE SAME ROWS */
.same-row.hide-same{display:none;}

/* Footer */
footer{background:#0A1628;margin-top:48px;padding:22px 40px;}
.ft{max-width:1200px;margin:0 auto;display:flex;justify-content:space-between;font-size:12px;color:rgba(255,255,255,.3);flex-wrap:wrap;gap:8px;align-items:center;}
.ft a{color:rgba(255,255,255,.3);margin-left:14px;}
.ft a:hover{color:rgba(255,255,255,.7);}

@media(max-width:900px){
  .main,.controls,.ph{padding-left:16px;padding-right:16px;}
  /* Keep label column but make it compact */
  .uni-header-row td:first-child{width:90px;min-width:80px;padding:10px 8px;}
  .data-row td:first-child{width:90px;min-width:80px;padding:10px 8px;font-size:11px;gap:4px;}
  .row-icon{display:none;}
  /* University cards: smaller on mobile */
  .uni-card-img{height:90px;}
  .uni-card-body{padding:8px 10px;}
  .uni-name{font-size:12px;}
  .uni-rating{display:none;}
  /* Section header rows */
  .sec-row td{padding:8px 10px;font-size:10px;}
  /* Data cells */
  .data-row td{padding:10px 8px;font-size:12px;}
  .cell-best{padding:3px 7px;}
  /* Controls bar */
  .controls{flex-wrap:wrap;gap:8px;padding:10px 16px;}
  .add-sel{min-width:160px;}
  .diff-toggle{margin-left:0;}
}
</style>
</head>
<body>

<div class="ph">
  <div class="ph-in">
    <div class="ph-bc"><a href="index.php">Home</a><span>/</span><a href="universities.php">Universities</a><span>/</span><span>Compare</span></div>
    <div class="ph-row">
      <div>
        <div class="ph-title">Compare Universities</div>
        <div class="ph-sub">Side-by-side comparison · best values highlighted in green</div>
      </div>
      <div class="cur-row">
        <span class="cur-lbl">Fees in:</span>
        <?php foreach(['MMK','THB','SGD','MYR','USD','EUR'] as $c): ?>
          <a href="compare.php?ids=<?= implode(',',$ids) ?>&currency=<?= $c ?>"
             class="cur-pill <?= $pref_currency===$c?'active':'' ?>"><?= $c ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- CONTROLS -->
<div class="controls">
  <select class="add-sel" id="addSel">
    <option value="">➕ Add a university to compare…</option>
    <?php foreach($all_unis as $u): if(!in_array($u['university_id'],$ids)): ?>
      <option value="<?= $u['university_id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
    <?php endif; endforeach; ?>
  </select>
  <button class="add-btn" onclick="addUni()">Add</button>
  <a href="universities.php" class="back-link">← Back to Browse</a>
  <?php if($count >= 2): ?>
  <label class="diff-toggle" style="margin-left:auto;">
    <input type="checkbox" id="diffToggle" onchange="toggleDiff(this.checked)">
    Show differences only
  </label>
  <?php endif; ?>
</div>

<div class="main">
  <?php if(empty($universities)): ?>
  <div class="empty">
    <div style="font-size:52px;margin-bottom:14px;">⚖️</div>
    <div style="font-size:20px;font-weight:800;color:#0A1628;margin-bottom:8px;">Nothing to compare yet</div>
    <div style="font-size:14px;color:#6B7280;margin-bottom:28px;max-width:380px;margin-left:auto;margin-right:auto;line-height:1.7;">Browse universities and click <strong>Compare</strong> on any card to start comparing up to 3 universities side by side.</div>
    <a href="universities.php" style="display:inline-block;padding:12px 28px;background:#1A56DB;color:#fff;font-weight:700;border-radius:10px;font-size:14px;">Browse Universities →</a>
  </div>

  <?php else: ?>
  <div class="compare-wrap">
  <table class="compare-table">

    <!-- STICKY HEADER: University cards -->
    <thead>
    <tr class="uni-header-row">
      <td style="padding:16px;vertical-align:middle;">
        <div style="font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;">Comparing</div>
        <div style="font-size:20px;font-weight:800;color:#0A1628;margin-top:2px;"><?= $count ?> <?= $count===1?'University':'Universities' ?></div>
      </td>
      <?php foreach($universities as $u):
        $img    = safe_img_c($u['image_url']);
        $rating = $u['avg_rating'] ? (float)$u['avg_rating'] : null;
        $filled = $rating ? round($rating) : 0;
      ?>
      <td class="uni-card-cell">
        <div class="uni-card">
          <div class="uni-card-img">
            <img src="<?= $img ?>" alt="<?= htmlspecialchars($u['name']) ?>" loading="lazy">
            <button class="uni-remove" onclick="removeUni(<?= $u['university_id'] ?>)" title="Remove">✕</button>
          </div>
          <div class="uni-card-body">
            <div class="uni-flag"><?= $flags[$u['country_code']]??'' ?> <?= htmlspecialchars($u['country_name']) ?></div>
            <div class="uni-name"><?= htmlspecialchars($u['name']) ?></div>
            <div class="uni-rating">
              <div class="uni-stars">
                <?php for($s=1;$s<=5;$s++): ?><span class="<?= $s<=$filled?'':'off' ?>">★</span><?php endfor; ?>
              </div>
              <span class="uni-rating-num"><?= $rating ? "$rating ({$u['review_count']})" : 'No reviews' ?></span>
            </div>
            <div class="uni-actions">
              <a href="university_detail.php?id=<?= $u['university_id'] ?>" class="uni-view">View Details</a>
              <?php if($u['website_url']): ?>
              <a href="<?= htmlspecialchars($u['website_url']) ?>" target="_blank" class="uni-web">🌐</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </td>
      <?php endforeach; ?>
    </tr>
    </thead>

    <!-- SECTIONS & DATA ROWS -->
    <tbody>
    <?php foreach($sections as $section): ?>

      <!-- Section header -->
      <tr class="sec-row">
        <td colspan="<?= $count+1 ?>"><?= $section['label'] ?></td>
      </tr>

      <?php foreach($section['rows'] as $row):
        $values   = $row['vals'];
        $is_same  = all_same($values);
        $best_key = $row['best'] ?? null;

        // Find best value index
        $best_idx = null;
        if ($best_key && $count > 1) {
            $nums = [];
            foreach ($values as $i => $v) {
                if ($v === null) continue;
                // Extract numeric part
                preg_match('/[\d,]+\.?\d*/', str_replace(',','',$v), $m);
                if ($m) $nums[$i] = (float)$m[0];
            }
            if ($nums) {
                if (str_contains($best_key,'high')) { arsort($nums); }
                else { asort($nums); }
                $best_idx = array_key_first($nums);
            }
        }
      ?>
      <tr class="data-row <?= $is_same?'same-row':'' ?>">
        <td><span class="row-icon"><?= $row['icon'] ?></span><?= $row['label'] ?></td>
        <?php foreach($values as $i => $v): ?>
        <td>
          <?php if($v === null || $v === ''): ?>
            <span class="cell-null">—</span>
          <?php elseif($is_same): ?>
            <span class="cell-same"><?= htmlspecialchars((string)$v) ?></span>
          <?php elseif($i === $best_idx): ?>
            <span class="cell-best"><?= htmlspecialchars((string)$v) ?></span>
          <?php else: ?>
            <span class="cell-val"><?= htmlspecialchars((string)$v) ?></span>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>

    <?php endforeach; ?>

    <!-- PROGRAMS FROM DB (live data, synced with university detail page) -->
    <?php
    // Fetch actual programs from the programs table for all compared universities
    $prog_in  = implode(',', array_map(fn($u)=>(int)$u['university_id'], $universities));
    $prog_map = [];
    if ($prog_in) {
        $pr = $conn->query("
            SELECT p.university_id, p.program_name, p.degree_level
            FROM programs p
            WHERE p.university_id IN ($prog_in)
            ORDER BY p.degree_level, p.program_name
        ");
        while ($r = $pr->fetch_assoc())
            $prog_map[$r['university_id']][] = $r;
    }
    $level_colors=['Foundation'=>'#92400E,#FEF3C7','Diploma'=>'#5B21B6,#EDE9FE','Bachelor'=>'#1E40AF,#EFF6FF','Master'=>'#065F46,#ECFDF5','PhD'=>'#9A3412,#FFF7ED'];
    ?>
    <tr class="sec-row"><td colspan="<?= $count+1 ?>">📚 Programs (from database)</td></tr>
    <tr class="data-row">
      <td><span class="row-icon">⭐</span>All Programs</td>
      <?php foreach($universities as $u): ?>
      <td>
        <?php $progs = $prog_map[$u['university_id']] ?? []; ?>
        <?php if($progs): ?>
          <div style="display:flex;flex-wrap:wrap;gap:4px;max-height:120px;overflow-y:auto;">
            <?php foreach($progs as $p):
              $lc = explode(',', $level_colors[$p['degree_level']] ?? '#374151,#F3F4F6');
            ?>
              <span style="font-size:10px;font-weight:600;color:<?=$lc[0]?>;background:<?=$lc[1]?>;padding:3px 7px;border-radius:5px;white-space:nowrap;"><?= htmlspecialchars($p['degree_level'][0]) ?>: <?= htmlspecialchars(mb_strimwidth($p['program_name'],0,28,'…')) ?></span>
            <?php endforeach; ?>
          </div>
        <?php else: ?><span class="cell-null">—</span><?php endif; ?>
      </td>
      <?php endforeach; ?>
    </tr>

    <!-- FACILITIES ROW -->
    <tr class="sec-row"><td colspan="<?= $count+1 ?>">🏟️ Facilities</td></tr>
    <tr class="data-row">
      <td><span class="row-icon">🏗️</span>Campus</td>
      <?php foreach($universities as $u): ?>
      <td>
        <?php if($u['facilities']): ?>
          <div style="display:flex;flex-wrap:wrap;gap:4px;">
            <?php foreach(array_slice(explode(',',$u['facilities']),0,4) as $f): ?>
              <span style="font-size:10px;font-weight:600;background:#EFF6FF;color:#1E40AF;padding:3px 7px;border-radius:5px;"><?= trim(htmlspecialchars($f)) ?></span>
            <?php endforeach; ?>
          </div>
        <?php else: ?><span class="cell-null">—</span><?php endif; ?>
      </td>
      <?php endforeach; ?>
    </tr>

    <!-- ACCREDITATIONS ROW -->
    <tr class="sec-row"><td colspan="<?= $count+1 ?>">✅ Accreditations</td></tr>
    <tr class="data-row" style="border-radius:0 0 16px 16px;">
      <td><span class="row-icon">🏅</span>Bodies</td>
      <?php foreach($universities as $u): ?>
      <td>
        <?php if($u['accreditations']): ?>
          <div style="display:flex;flex-wrap:wrap;gap:4px;">
            <?php foreach(explode(',',$u['accreditations']) as $acc): ?>
              <span style="font-size:10px;font-weight:600;background:#ECFDF5;color:#065F46;padding:3px 7px;border-radius:5px;"><?= trim(htmlspecialchars($acc)) ?></span>
            <?php endforeach; ?>
          </div>
        <?php else: ?><span class="cell-null">—</span><?php endif; ?>
      </td>
      <?php endforeach; ?>
    </tr>
    </tbody>

  </table>
  </div>
  <?php endif; ?>
</div>


<?php include 'includes/footer.php'; ?>


<script>
const currentIds = <?= json_encode($ids) ?>;
const curCurrency = '<?= htmlspecialchars($pref_currency) ?>';

function buildUrl(ids) {
    return 'compare.php?ids=' + ids.join(',') + '&currency=' + curCurrency;
}
function addUni() {
    const id = parseInt(document.getElementById('addSel').value);
    if (!id) return;
    if (currentIds.length >= 3) { alert('Max 3 universities. Remove one first.'); return; }
    window.location.href = buildUrl([...currentIds, id]);
}
function removeUni(id) {
    const newIds = currentIds.filter(i => i !== id);
    window.location.href = newIds.length ? buildUrl(newIds) : 'compare.php';
}
function toggleDiff(showDiff) {
    document.querySelectorAll('.same-row').forEach(row => {
        row.classList.toggle('hide-same', showDiff);
    });
}
// Enter key on select
document.getElementById('addSel')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') addUni();
});
</script>
</body>
</html>