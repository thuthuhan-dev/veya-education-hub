<?php
// programs.php — Veya Education Hub
session_start();
require_once "db_connect.php";

$pref_currency = $_GET['currency'] ?? $_SESSION['preferred_currency_code'] ?? 'MMK';
if (!in_array($pref_currency, ['MMK','THB','SGD','MYR','USD','EUR'])) $pref_currency = 'MMK';

$rates   = load_exchange_rates($conn);
$symbols = load_currency_symbols($conn);

// ── Filters ───────────────────────────────────────────────────────────
$search      = trim($_GET['q']       ?? '');
$filter_cid  = (int)($_GET['country']  ?? 0);
$filter_lvl  = trim($_GET['level']     ?? '');
$filter_field= trim($_GET['field']     ?? '');
$filter_lang = trim($_GET['lang']      ?? '');
$page        = max(1,(int)($_GET['page']??1));
$per_page    = 12; $offset = ($page-1)*$per_page;

$where = ["u.is_active=1"]; $params=[]; $types="";
if ($search!=='') {
    $where[]="(p.program_name LIKE ? OR p.description LIKE ? OR u.name LIKE ?)";
    $like="%$search%"; $params[]=$like;$params[]=$like;$params[]=$like;$types.="sss";
}
if ($filter_cid>0)   { $where[]="c.country_id=?";     $params[]=$filter_cid; $types.="i"; }
if ($filter_lvl!=='') { $where[]="p.degree_level=?";  $params[]=$filter_lvl; $types.="s"; }
if ($filter_field!==''){ $where[]="p.field_of_study=?";$params[]=$filter_field;$types.="s";}
if ($filter_lang!=='') { $where[]="p.language_of_instruction=?";$params[]=$filter_lang;$types.="s";}
$wsql = "WHERE ".implode(" AND ",$where);

// Count
$cs=$conn->prepare("SELECT COUNT(*) FROM programs p JOIN universities u ON u.university_id=p.university_id JOIN countries c ON c.country_id=u.country_id $wsql");
if ($types) $cs->bind_param($types,...$params);
$cs->execute(); $total=$cs->get_result()->fetch_row()[0]; $cs->close();
$total_pages=max(1,ceil($total/$per_page));

// Fetch
$stmt=$conn->prepare("
    SELECT p.*,
           u.name AS uni_name,u.image_url,u.world_ranking,u.website_url,
           c.country_name,c.country_code,
           tf.amount_local,tf.local_currency_code,tf.fee_period
    FROM programs p
    JOIN universities u ON u.university_id=p.university_id
    JOIN countries c ON c.country_id=u.country_id
    LEFT JOIN tuition_fees tf ON tf.program_id=p.program_id AND tf.fee_type='Tuition'
    $wsql
    ORDER BY c.country_name,p.degree_level,u.name,p.program_name
    LIMIT ? OFFSET ?
");
$ap=[...$params,$per_page,$offset];$at=$types."ii";
if($at)$stmt->bind_param($at,...$ap);
$stmt->execute();$programs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

// Filter options
$countries_list = $conn->query("SELECT c.country_id,c.country_name,c.country_code FROM countries c JOIN universities u ON u.country_id=c.country_id WHERE c.is_active=1 GROUP BY c.country_id ORDER BY c.country_name")->fetch_all(MYSQLI_ASSOC);
$fields_list    = $conn->query("SELECT DISTINCT field_of_study FROM programs WHERE field_of_study IS NOT NULL ORDER BY field_of_study")->fetch_all(MYSQLI_ASSOC);

$level_colors=['Foundation'=>['#92400E','#FEF3C7'],'Diploma'=>['#5B21B6','#EDE9FE'],'Bachelor'=>['#1E40AF','#EFF6FF'],'Master'=>['#065F46','#ECFDF5'],'PhD'=>['#9A3412','#FFF7ED']];
$flags=['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$fallback='https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=400&q=70';

function qs_programs($overrides=[]){
    global $search,$filter_cid,$filter_lvl,$filter_field,$filter_lang,$pref_currency;
    $p=array_filter(['q'=>$search,'country'=>$filter_cid?:null,'level'=>$filter_lvl,'field'=>$filter_field,'lang'=>$filter_lang,'currency'=>$pref_currency]);
    return http_build_query(array_merge($p,$overrides));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Browse Programs — Veya</title>
<?php $nav_active='universities'; include 'includes/nav.php'; ?>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F8FAFF;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
/* HERO */
.hero{background:linear-gradient(135deg,#1E3A5F,#0A1628);padding:52px 40px 48px;position:relative;overflow:hidden;}
.hero::before{content:"";position:absolute;top:-80px;right:-80px;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(124,58,237,.3),transparent 70%);}
.hero-in{max-width:1100px;margin:0 auto;position:relative;z-index:2;}
.hero-bc{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:14px;display:flex;gap:7px;}
.hero-bc a{color:rgba(255,255,255,.4);transition:color .15s;}
.hero-bc a:hover{color:#fff;}
.hero-bc span{color:rgba(255,255,255,.2);}
.hero-title{font-family:'Instrument Serif',serif;font-size:clamp(26px,4vw,42px);color:#fff;font-weight:400;margin-bottom:8px;}
.hero-sub{font-size:15px;color:rgba(255,255,255,.6);margin-bottom:28px;max-width:540px;line-height:1.6;}
.hero-search{display:flex;gap:10px;max-width:520px;}
.hero-search input{flex:1;padding:13px 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;background:rgba(255,255,255,.12);border:1.5px solid rgba(255,255,255,.2);border-radius:10px;color:#fff;outline:none;transition:all .15s;}
.hero-search input::placeholder{color:rgba(255,255,255,.4);}
.hero-search input:focus{background:rgba(255,255,255,.18);border-color:rgba(255,255,255,.4);}
.hero-search button{padding:13px 22px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;color:#fff;background:#7C3AED;border:none;border-radius:10px;cursor:pointer;transition:background .15s;white-space:nowrap;}
.hero-search button:hover{background:#6D28D9;}
.hero-stats{display:flex;gap:24px;margin-top:24px;flex-wrap:wrap;}
.hs{font-size:13px;color:rgba(255,255,255,.55);display:flex;align-items:center;gap:6px;}
.hs strong{color:#fff;font-size:16px;font-weight:800;}
/* CURRENCY PILLS */
.cur-bar{background:#fff;border-bottom:1px solid #E5E7EB;padding:10px 40px;display:flex;align-items:center;gap:10px;overflow-x:auto;}
.cur-label{font-size:12px;font-weight:700;color:#9CA3AF;white-space:nowrap;}
.cur-pill{font-size:12px;font-weight:700;padding:5px 14px;border-radius:99px;border:1.5px solid #E5E7EB;color:#6B7280;transition:all .15s;white-space:nowrap;}
.cur-pill:hover{border-color:#7C3AED;color:#7C3AED;}
.cur-pill.active{background:#7C3AED;border-color:#7C3AED;color:#fff;}
/* LAYOUT */
.layout{max-width:1100px;margin:0 auto;padding:28px 40px;display:grid;grid-template-columns:220px 1fr;gap:24px;align-items:start;}
/* SIDEBAR */
.filters-sidebar{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;position:sticky;top:84px;}
.fs-head{padding:14px 16px;border-bottom:1px solid #F3F4F6;font-size:13px;font-weight:800;color:#0A1628;display:flex;justify-content:space-between;align-items:center;}
.fs-head a{font-size:11px;font-weight:600;color:#EF4444;}
.fs-section{padding:14px 16px;border-bottom:1px solid #F3F4F6;}
.fs-section:last-child{border-bottom:none;}
.fs-label{font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:#9CA3AF;margin-bottom:8px;}
.fs-opt{display:block;padding:7px 10px;border-radius:7px;font-size:13px;font-weight:600;color:#6B7280;transition:all .12s;cursor:pointer;}
.fs-opt:hover{background:#F3F4F6;color:#0A1628;}
.fs-opt.active{background:#EDE9FE;color:#7C3AED;}
.fs-opt .n{float:right;font-size:11px;color:#9CA3AF;}
/* RESULTS */
.results-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;}
.results-count{font-size:14px;color:#6B7280;font-weight:500;}
.results-count strong{font-size:17px;font-weight:800;color:#0A1628;}
.prog-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
/* PROGRAM CARD */
.pcard{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;transition:all .2s;display:flex;flex-direction:column;}
.pcard:hover{box-shadow:0 8px 28px rgba(10,22,40,.08);transform:translateY(-3px);border-color:#E0E7FF;}
.pc-img{height:100px;position:relative;overflow:hidden;flex-shrink:0;}
.pc-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s;}
.pcard:hover .pc-img img{transform:scale(1.05);}
.pc-overlay{position:absolute;inset:0;background:linear-gradient(to top,rgba(10,22,40,.75),rgba(10,22,40,.2));}
.pc-level{position:absolute;top:10px;left:10px;font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;}
.pc-flag{position:absolute;top:10px;right:10px;font-size:18px;}
.pc-body{padding:14px;flex:1;display:flex;flex-direction:column;}
.pc-field{font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#9CA3AF;margin-bottom:5px;}
.pc-name{font-size:14px;font-weight:800;color:#0A1628;line-height:1.3;margin-bottom:6px;}
.pc-uni{font-size:12px;color:#6B7280;font-weight:600;margin-bottom:10px;display:flex;align-items:center;gap:5px;}
.pc-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;}
.pc-tag{font-size:10px;font-weight:600;background:#F3F4F6;color:#374151;padding:3px 8px;border-radius:5px;}
.pc-footer{display:flex;align-items:center;justify-content:space-between;margin-top:auto;padding-top:10px;border-top:1px solid #F3F4F6;}
.pc-fee{font-size:15px;font-weight:800;color:#7C3AED;}
.pc-fee-sub{font-size:10px;color:#9CA3AF;margin-top:1px;}
.pc-btn{font-size:12px;font-weight:700;color:#fff;background:#1A56DB;padding:7px 14px;border-radius:7px;transition:background .15s;}
.pc-btn:hover{background:#1E40AF;}
/* PAGINATION */
.pagination{display:flex;justify-content:center;gap:5px;margin-top:24px;}
.pg-btn{min-width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #E5E7EB;color:#374151;background:#fff;transition:all .15s;}
.pg-btn:hover:not(.disabled):not(.active){border-color:#7C3AED;color:#7C3AED;}
.pg-btn.active{background:#7C3AED;border-color:#7C3AED;color:#fff;}
.pg-btn.disabled{opacity:.35;pointer-events:none;}
/* Empty */
.empty-state{text-align:center;padding:60px;background:#fff;border:1px dashed #E5E7EB;border-radius:14px;grid-column:1/-1;}
/* Footer */
footer{background:#0A1628;margin-top:60px;padding:24px 40px;}
.ft{max-width:1100px;margin:0 auto;display:flex;justify-content:space-between;font-size:12px;color:rgba(255,255,255,.3);flex-wrap:wrap;gap:8px;align-items:center;}
.ft a{color:rgba(255,255,255,.3);margin-left:14px;transition:color .15s;}
.ft a:hover{color:rgba(255,255,255,.7);}
@media(max-width:900px){.layout{grid-template-columns:1fr;}.filters-sidebar{position:static;}.prog-grid{grid-template-columns:1fr;}}
@media(max-width:640px){.hero,.layout{padding-left:20px;padding-right:20px;}.cur-bar{padding-left:20px;}}
</style>
</head>
<body>

<div class="hero">
  <div class="hero-in">
    <div class="hero-bc"><a href="index.php">Home</a><span>/</span><span>Programs</span></div>
    <h1 class="hero-title">Browse All Programs</h1>
    <p class="hero-sub">Find the right degree — filter by level, field, country, and see fees in your currency.</p>
    <form method="GET" action="programs.php#results" class="hero-search">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search program name, university…">
      <?php if($filter_cid): ?><input type="hidden" name="country" value="<?=$filter_cid?>"><?php endif; ?>
      <?php if($filter_lvl): ?><input type="hidden" name="level"   value="<?=htmlspecialchars($filter_lvl)?>"><?php endif; ?>
      <input type="hidden" name="currency" value="<?= htmlspecialchars($pref_currency) ?>">
      <button type="submit">Search</button>
    </form>
    <div class="hero-stats">
      <div class="hs"><strong><?= $total ?></strong> programs found</div>
      <div class="hs"><strong>3</strong> countries</div>
      <div class="hs"><strong>5</strong> degree levels</div>
    </div>
  </div>
</div>

<!-- Currency bar -->
<div class="cur-bar">
  <span class="cur-label">Fees in:</span>
  <?php foreach(['MMK','THB','SGD','MYR','USD','EUR'] as $c): ?>
    <a href="programs.php?<?= qs_programs(['currency'=>$c,'page'=>1]) ?>"
       class="cur-pill <?= $pref_currency===$c?'active':'' ?>"><?= $c ?></a>
  <?php endforeach; ?>
</div>

<div class="layout">
  <!-- Sidebar filters -->
  <aside class="filters-sidebar">
    <div class="fs-head">
      Filters
      <?php if($filter_cid||$filter_lvl||$filter_field||$filter_lang): ?>
        <a href="programs.php?currency=<?= htmlspecialchars($pref_currency) ?>">Clear all</a>
      <?php endif; ?>
    </div>

    <!-- Level -->
    <div class="fs-section">
      <div class="fs-label">Degree Level</div>
      <?php foreach(['Foundation','Diploma','Bachelor','Master','PhD'] as $lvl): ?>
        <a href="programs.php?<?= qs_programs(['level'=>$filter_lvl===$lvl?'':$lvl,'page'=>1]) ?>"
           class="fs-opt <?= $filter_lvl===$lvl?'active':'' ?>">
          <?= $lvl ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Country -->
    <div class="fs-section">
      <div class="fs-label">Country</div>
      <?php foreach($countries_list as $c): ?>
        <a href="programs.php?<?= qs_programs(['country'=>$filter_cid==$c['country_id']?0:$c['country_id'],'page'=>1]) ?>"
           class="fs-opt <?= $filter_cid==$c['country_id']?'active':'' ?>">
          <?= $flags[$c['country_code']]??'' ?> <?= htmlspecialchars($c['country_name']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Field of Study -->
    <div class="fs-section">
      <div class="fs-label">Field of Study</div>
      <?php foreach($fields_list as $f): if(!$f['field_of_study']) continue; ?>
        <a href="programs.php?<?= qs_programs(['field'=>$filter_field===$f['field_of_study']?'':$f['field_of_study'],'page'=>1]) ?>"
           class="fs-opt <?= $filter_field===$f['field_of_study']?'active':'' ?>">
          <?= htmlspecialchars($f['field_of_study']) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Language -->
    <div class="fs-section">
      <div class="fs-label">Language</div>
      <?php foreach(['English','Thai','Malay','Bilingual'] as $lang): ?>
        <a href="programs.php?<?= qs_programs(['lang'=>$filter_lang===$lang?'':$lang,'page'=>1]) ?>"
           class="fs-opt <?= $filter_lang===$lang?'active':'' ?>">
          <?= $lang ?>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>

  <!-- Results -->
  <div>
    <div id="results" class="results-head">
      <div class="results-count">
        <strong><?= number_format($total) ?></strong> programs found
        <?php if($filter_lvl||$filter_field||$filter_cid): ?>
          <span style="font-size:12px;color:#9CA3AF;"> — filtered</span>
        <?php endif; ?>
      </div>
      <a href="universities.php" style="font-size:13px;font-weight:600;color:#6B7280;">Browse Universities →</a>
    </div>

    <div class="prog-grid">
      <?php if(empty($programs)): ?>
        <div class="empty-state">
          <div style="font-size:36px;margin-bottom:10px;">📚</div>
          <div style="font-size:17px;font-weight:700;color:#0A1628;margin-bottom:6px;">No programs found</div>
          <div style="font-size:13px;color:#9CA3AF;margin-bottom:20px;">Try adjusting your filters or search terms.</div>
          <a href="programs.php" style="font-size:13px;font-weight:700;color:#7C3AED;">Clear filters</a>
        </div>
      <?php else: foreach($programs as $p):
        $img   = safe_img($p['image_url']??'');
        $lc    = $level_colors[$p['degree_level']] ?? ['#374151','#F3F4F6'];
        $fee_v = convert_fee((float)($p['amount_local']??0), $p['local_currency_code']??'MMK', $pref_currency, $rates);
        $fee_f = $fee_v ? format_fee($fee_v, $pref_currency, $symbols) : null;
        $dur   = $p['duration_months'] ? round($p['duration_months']/12,1).' yrs' : null;
      ?>
      <div class="pcard">
        <div class="pc-img">
          <img src="<?= $img ?>" alt="<?= htmlspecialchars($p['uni_name']) ?>" loading="lazy">
          <div class="pc-overlay"></div>
          <span class="pc-level" style="color:<?=$lc[0]?>;background:<?=$lc[1]?>"><?= $p['degree_level'] ?></span>
          <span class="pc-flag"><?= $flags[$p['country_code']]??'' ?></span>
        </div>
        <div class="pc-body">
          <?php if($p['field_of_study']): ?>
            <div class="pc-field"><?= htmlspecialchars($p['field_of_study']) ?></div>
          <?php endif; ?>
          <div class="pc-name"><?= htmlspecialchars($p['program_name']) ?></div>
          <div class="pc-uni">🏫 <?= htmlspecialchars($p['uni_name']) ?></div>
          <div class="pc-meta">
            <?php if($dur): ?><span class="pc-tag">⏱ <?= $dur ?></span><?php endif; ?>
            <span class="pc-tag">🌐 <?= htmlspecialchars($p['language_of_instruction']) ?></span>
            <?php if($p['world_ranking']): ?><span class="pc-tag">🏆 #<?= $p['world_ranking'] ?> World</span><?php endif; ?>
          </div>
          <div class="pc-footer">
            <div>
              <?php if($fee_f): ?>
                <div class="pc-fee"><?= $fee_f ?>/yr</div>
                <div class="pc-fee-sub"><?= htmlspecialchars($pref_currency) ?> · approx.</div>
              <?php else: ?>
                <div style="font-size:13px;color:#9CA3AF;">Fee on request</div>
              <?php endif; ?>
            </div>
            <a href="university_detail.php?id=<?= $p['university_id'] ?>&tab=programs"
               class="pc-btn">View →</a>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Pagination -->
    <?php if($total_pages>1): ?>
    <nav class="pagination">
      <a href="programs.php?<?= qs_programs(['page'=>$page-1]) ?>#results" class="pg-btn <?=$page<=1?'disabled':''?>">‹</a>
      <?php for($p=1;$p<=$total_pages;$p++):
        if($p===1||$p===$total_pages||abs($p-$page)<=1):
          echo "<a href='programs.php?".qs_programs(['page'=>$p])."#results' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        elseif(abs($p-$page)===2): echo "<span style='color:#D1D5DB;padding:0 4px;'>…</span>";
        endif;
      endfor; ?>
      <a href="programs.php?<?= qs_programs(['page'=>$page+1]) ?>#results" class="pg-btn <?=$page>=$total_pages?'disabled':''?>">›</a>
    </nav>
    <?php endif; ?>
  </div>
</div>

<footer>
  <div class="ft">
    <span>© <?= date('Y') ?> Veya Education Hub</span>
    <div><a href="index.php">Home</a><a href="universities.php">Universities</a><a href="scholarships.php">Scholarships</a><a href="compare.php">Compare</a></div>
  </div>
</footer>

<script>
if(location.hash==='#results'&&document.getElementById('results')){
    setTimeout(()=>document.getElementById('results').scrollIntoView({behavior:'smooth',block:'start'}),100);
}
</script>
</body>
</html>