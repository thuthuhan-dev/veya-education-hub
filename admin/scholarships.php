<?php
// admin/scholarships.php — Veya Admin Panel
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php"); exit;
}
require_once "../db_connect.php";

$msg = ''; $error = '';

// ── Handle actions ────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Delete
if ($action === 'delete' && isset($_GET['id'])) {
    $sid = (int)$_GET['id'];
    $s = $conn->prepare("DELETE FROM scholarships WHERE scholarship_id=?");
    $s->bind_param("i",$sid); $s->execute(); $s->close();
    header("Location: scholarships.php?msg=".urlencode("Scholarship deleted.")); exit;
}

// Save
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['add','edit'])) {
    $sid         = (int)($_POST['scholarship_id'] ?? 0);
    $uni_id      = (int)($_POST['university_id']  ?? 0);
    $name        = trim($_POST['name']             ?? '');
    $description = trim($_POST['description']      ?? '');
    $amount      = $_POST['amount_local'] !== '' ? (float)$_POST['amount_local'] : null;
    $currency    = trim($_POST['local_currency_code'] ?? '');
    $eligibility = trim($_POST['eligibility']      ?? '');
    $deadline    = trim($_POST['application_deadline'] ?? '') ?: null;
    $app_url     = trim($_POST['application_url']  ?? '') ?: null;
    $award_type  = trim($_POST['award_type']       ?? '') ?: null;
    $renewable   = isset($_POST['renewable']) ? 1 : 0;

    if ($name==='' || $uni_id===0) {
        $error = 'Scholarship name and university are required.';
    } else {
        if ($action==='add') {
            $s = $conn->prepare("INSERT INTO scholarships
                (university_id,name,description,amount_local,local_currency_code,
                 eligibility,application_deadline,application_url,award_type,renewable)
                VALUES(?,?,?,?,?,?,?,?,?,?)");
            $s->bind_param("issdsssssi",
                $uni_id,$name,$description,$amount,$currency,
                $eligibility,$deadline,$app_url,$award_type,$renewable);
        } else {
            $s = $conn->prepare("UPDATE scholarships SET
                university_id=?,name=?,description=?,amount_local=?,local_currency_code=?,
                eligibility=?,application_deadline=?,application_url=?,award_type=?,renewable=?
                WHERE scholarship_id=?");
            $s->bind_param("issdsssssii",
                $uni_id,$name,$description,$amount,$currency,
                $eligibility,$deadline,$app_url,$award_type,$renewable,$sid);
        }
        if ($s->execute()) {
            header("Location: scholarships.php?msg=".urlencode("Scholarship ".($action==='add'?'added':'updated').".")); exit;
        }
        $error = 'Database error: '.$conn->error;
        $s->close();
    }
}

// Fetch for edit
$edit = null;
if ($action==='edit' && isset($_GET['id'])) {
    $r = $conn->prepare("SELECT * FROM scholarships WHERE scholarship_id=?");
    $edit_id = (int)$_GET['id']; $r->bind_param("i", $edit_id); $r->execute();
    $edit = $r->get_result()->fetch_assoc(); $r->close();
}

// ── Lists ─────────────────────────────────────────────────────────────
$universities_list = $conn->query("SELECT u.university_id,u.name,c.currency_code FROM universities u JOIN countries c ON c.country_id=u.country_id WHERE u.is_active=1 ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);
$currencies_list   = $conn->query("SELECT currency_code FROM currencies ORDER BY currency_code")->fetch_all(MYSQLI_ASSOC);
$countries_list    = $conn->query("SELECT c.country_id,c.country_name,c.country_code FROM countries c WHERE c.is_active=1 AND c.has_universities=1
    ORDER BY c.country_name")->fetch_all(MYSQLI_ASSOC);

$search     = trim($_GET['q'] ?? '');
$filter_cid = (int)($_GET['country'] ?? 0);
$filter_status = $_GET['status'] ?? ''; // open|closed
$page     = max(1,(int)($_GET['page']??1));
$per_page = 15; $offset = ($page-1)*$per_page;

$where = ["1=1"]; $params=[]; $types="";
if ($search!=='') {
    $where[]="(s.name LIKE ? OR u.name LIKE ? OR s.eligibility LIKE ?)";
    $like="%$search%"; $params[]=$like; $params[]=$like; $params[]=$like; $types.="sss";
}
if ($filter_cid>0) { $where[]="c.country_id=?"; $params[]=$filter_cid; $types.="i"; }
if ($filter_status==='open')   $where[]="(s.application_deadline>=CURDATE() OR s.application_deadline IS NULL)";
if ($filter_status==='closed') $where[]="s.application_deadline<CURDATE()";
$wsql = "WHERE ".implode(" AND ",$where);

$cs = $conn->prepare("SELECT COUNT(*) FROM scholarships s JOIN universities u ON u.university_id=s.university_id JOIN countries c ON c.country_id=u.country_id $wsql");
if ($types) $cs->bind_param($types,...$params);
$cs->execute(); $total=$cs->get_result()->fetch_row()[0]; $cs->close();
$total_pages = max(1,ceil($total/$per_page));

$stmt = $conn->prepare("
    SELECT s.*,u.name AS uni_name,c.country_name,c.country_code
    FROM scholarships s
    JOIN universities u ON u.university_id=s.university_id
    JOIN countries c ON c.country_id=u.country_id
    $wsql
    ORDER BY s.application_deadline ASC, s.name ASC
    LIMIT ? OFFSET ?
");
$ap=[...$params,$per_page,$offset]; $at=$types."ii";
if ($at) $stmt->bind_param($at,...$ap);
$stmt->execute();
$scholarships = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flags=['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$admin_name=explode(' ',$_SESSION['full_name'])[0];
$ac=['linear-gradient(135deg,#6366F1,#8B5CF6)','linear-gradient(135deg,#DC2626,#EF4444)'];
$avatar_color=$ac[$_SESSION['user_id']%count($ac)];
$initial=strtoupper($_SESSION['full_name'][0]??'A');
$msg = $_GET['msg'] ?? $msg;
$showing_form = in_array($action,['add','edit']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Scholarships — Veya Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F1F5F9;display:flex;min-height:100vh;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
.sidebar{width:240px;background:#0A1628;display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;overflow-y:auto;}
.sb-logo{padding:24px 20px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px;}
.sb-logo img{height:26px;filter:brightness(0) invert(1);opacity:.8;}
.sb-logo-text{font-size:17px;font-weight:800;color:#fff;}
.sb-badge{font-size:9px;font-weight:700;background:#EF4444;color:#fff;padding:2px 6px;border-radius:4px;text-transform:uppercase;margin-left:4px;}
.sb-section{padding:16px 12px 8px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.3);}
.sb-item{display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:9px;margin:2px 8px;font-size:13px;font-weight:600;color:rgba(255,255,255,.6);transition:all .15s;}
.sb-item:hover{background:rgba(255,255,255,.08);color:#fff;}
.sb-item.active{background:rgba(26,86,219,.4);color:#fff;}
.sb-item .icon{font-size:16px;width:20px;text-align:center;}
.sb-divider{height:1px;background:rgba(255,255,255,.07);margin:10px 16px;}
.sb-user{margin-top:auto;padding:16px;border-top:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px;}
.sb-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;}
.sb-user-name{font-size:13px;font-weight:700;color:#fff;}
.sb-user-role{font-size:10px;color:rgba(255,255,255,.4);}
.sb-logout{margin-left:auto;font-size:18px;color:rgba(255,255,255,.3);transition:color .15s;}
.sb-logout:hover{color:#EF4444;}
.main{margin-left:240px;flex:1;}
.topbar{background:#fff;border-bottom:1px solid #E5E7EB;padding:0 32px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:18px;font-weight:800;color:#0A1628;}
.btn-add{display:flex;align-items:center;gap:7px;padding:9px 20px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#059669;border:none;border-radius:9px;cursor:pointer;transition:background .15s;}
.btn-add:hover{background:#047857;}
.content{padding:28px 32px;}
.alert{padding:12px 16px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:20px;}
.alert-ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;}
.alert-err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}
/* FORM */
.form-wrap{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:28px;margin-bottom:24px;}
.form-title{font-size:17px;font-weight:800;color:#0A1628;margin-bottom:3px;}
.form-sub{font-size:13px;color:#9CA3AF;margin-bottom:22px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.field{margin-bottom:0;}
.field.full{grid-column:1/-1;}
.field.third{grid-column:span 1;}
label{display:block;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280;margin-bottom:5px;}
input,select,textarea{width:100%;padding:10px 12px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;color:#0A1628;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:9px;outline:none;transition:border-color .15s;}
input:focus,select:focus,textarea:focus{border-color:#93C5FD;background:#fff;box-shadow:0 0 0 3px rgba(147,197,253,.2);}
textarea{resize:vertical;min-height:80px;line-height:1.6;}
.form-actions{display:flex;gap:10px;grid-column:1/-1;margin-top:6px;}
.btn-save{padding:11px 26px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;color:#fff;background:#059669;border:none;border-radius:9px;cursor:pointer;}
.btn-save:hover{background:#047857;}
.btn-cancel{padding:11px 18px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:600;color:#374151;background:#F3F4F6;border:none;border-radius:9px;cursor:pointer;}
/* TABLE */
.filters{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap;align-items:center;}
.fi{font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;color:#374151;background:#fff;border:1px solid #E5E7EB;padding:9px 12px;border-radius:9px;outline:none;cursor:pointer;}
.fi-search{flex:1;min-width:180px;}
.fi-search::placeholder{color:#9CA3AF;font-weight:400;}
.fi-btn{padding:9px 18px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#374151;border:none;border-radius:9px;cursor:pointer;}
.table-wrap{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;margin-bottom:20px;}
table{width:100%;border-collapse:collapse;}
thead th{padding:11px 16px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;background:#F9FAFB;border-bottom:1px solid #E5E7EB;}
tbody td{padding:12px 16px;font-size:13px;color:#374151;border-bottom:1px solid #F9FAFB;vertical-align:top;}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:#FAFBFF;}
.sch-name{font-size:14px;font-weight:700;color:#0A1628;margin-bottom:2px;}
.sch-uni{font-size:11px;color:#9CA3AF;}
.dl-ok{color:#059669;font-weight:600;font-size:12px;}
.dl-soon{color:#D97706;font-weight:600;font-size:12px;}
.dl-past{color:#9CA3AF;font-size:12px;}
.act-btn{font-size:12px;font-weight:700;padding:5px 11px;border-radius:7px;border:none;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px;margin-right:4px;}
.act-edit{color:#1A56DB;background:#EFF6FF;}
.act-edit:hover{background:#DBEAFE;}
.act-del{color:#EF4444;background:#FEF2F2;}
.act-del:hover{background:#EF4444;color:#fff;}
.pagination{display:flex;justify-content:center;gap:5px;margin-top:4px;}
.pg-btn{min-width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #E5E7EB;color:#374151;background:#fff;transition:all .15s;}
.pg-btn:hover:not(.disabled):not(.active){border-color:#059669;color:#059669;}
.pg-btn.active{background:#059669;border-color:#059669;color:#fff;}
.pg-btn.disabled{opacity:.35;pointer-events:none;}
</style>
</head>
<body>
<?php $admin_active = "scholarships"; include "includes/sidebar.php"; ?>

<div class="main">
  <div class="topbar">
    <div class="topbar-title">🏅 Scholarships</div>
    <div class="topbar-right"><a href="scholarships.php?action=add" class="btn-add">➕ Add Scholarship</a></div>
  </div>
  <div class="content">
    <?php if($msg):   ?><div class="alert alert-ok">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- FORM -->
    <?php if($showing_form): ?>
    <div class="form-wrap">
      <div class="form-title"><?= $action==='add'?'➕ Add Scholarship':'✏️ Edit Scholarship' ?></div>
      <div class="form-sub">Fill in the scholarship details below.</div>
      <form method="POST">
        <input type="hidden" name="action" value="<?= $action ?>">
        <?php if($action==='edit'): ?><input type="hidden" name="scholarship_id" value="<?= $edit['scholarship_id'] ?>"><?php endif; ?>
        <div class="form-grid">
          <div class="field full">
            <label>Scholarship Name *</label>
            <input type="text" name="name" value="<?= htmlspecialchars($edit['name']??'') ?>" placeholder="e.g. NUS ASEAN Undergraduate Scholarship" required>
          </div>
          <div class="field">
            <label>University *</label>
            <select name="university_id" required>
              <option value="">— Select university —</option>
              <?php foreach($universities_list as $u): ?>
                <option value="<?=$u['university_id']?>" <?=($edit['university_id']??0)==$u['university_id']?'selected':''?>>
                  <?= htmlspecialchars($u['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Application Deadline</label>
            <input type="date" name="application_deadline" value="<?= $edit['application_deadline']??'' ?>">
          </div>
          <div class="field">
            <label>Scholarship Amount</label>
            <input type="number" name="amount_local" value="<?= $edit['amount_local']??'' ?>" placeholder="e.g. 74000" step="0.01" min="0">
          </div>
          <div class="field">
            <label>Currency</label>
            <select name="local_currency_code">
              <option value="">— Select currency —</option>
              <?php foreach($currencies_list as $c): ?>
                <option value="<?=$c['currency_code']?>" <?=($edit['local_currency_code']??'')===$c['currency_code']?'selected':''?>>
                  <?= $c['currency_code'] ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field full">
            <label>Description</label>
            <textarea name="description" placeholder="Describe what the scholarship covers and who it's for…"><?= htmlspecialchars($edit['description']??'') ?></textarea>
          </div>
          <div class="field full">
            <label>Eligibility Requirements</label>
            <textarea name="eligibility" placeholder="Who can apply? GPA requirements, nationality, etc…"><?= htmlspecialchars($edit['eligibility']??'') ?></textarea>
          </div>

          <!-- NEW FIELDS -->
          <div class="field full">
            <label>Application URL <span style="font-size:11px;color:#9CA3AF;font-weight:400;">— Link directly to the scholarship application page</span></label>
            <input type="url" name="application_url"
                   value="<?= htmlspecialchars($edit['application_url']??'') ?>"
                   placeholder="https://university.edu/scholarship/apply">
          </div>
          <div class="field">
            <label>Award Type</label>
            <select name="award_type">
              <option value="">— Select —</option>
              <?php foreach(['Full','Partial','Tuition Only','Living Allowance'] as $at): ?>
                <option value="<?=$at?>" <?=($edit['award_type']??'')===$at?'selected':''?>><?=$at?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="display:flex;align-items:center;gap:10px;padding-top:28px;">
            <input type="checkbox" name="renewable" id="cb_renewable" value="1"
                   <?= !empty($edit['renewable']) ? 'checked' : '' ?>
                   style="width:18px;height:18px;accent-color:#1A56DB;cursor:pointer;">
            <label for="cb_renewable" style="margin:0;cursor:pointer;">
              Renewable
              <span style="font-size:11px;color:#9CA3AF;font-weight:400;display:block;">
                Scholarship can be renewed each year
              </span>
            </label>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn-save">💾 <?= $action==='add'?'Add Scholarship':'Save Changes' ?></button>
            <a href="scholarships.php" class="btn-cancel">Cancel</a>
          </div>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- FILTERS -->
    <form method="GET" style="display:contents;">
      <div class="filters">
        <input class="fi fi-search" type="text" name="q" placeholder="Search scholarships…" value="<?= htmlspecialchars($search) ?>">
        <select class="fi" name="country" onchange="this.form.submit()">
          <option value="">All Countries</option>
          <?php foreach($countries_list as $c): ?>
            <option value="<?=$c['country_id']?>" <?=$filter_cid==$c['country_id']?'selected':''?>>
              <?=$flags[$c['country_code']]??''?> <?=htmlspecialchars($c['country_name'])?>
            </option>
          <?php endforeach; ?>
        </select>
        <select class="fi" name="status" onchange="this.form.submit()">
          <option value="">All Deadlines</option>
          <option value="open"   <?=$filter_status==='open'  ?'selected':''?>>✅ Open</option>
          <option value="closed" <?=$filter_status==='closed'?'selected':''?>>❌ Closed</option>
        </select>
        <button class="fi-btn" type="submit">Search</button>
        <span style="font-size:13px;color:#9CA3AF;margin-left:auto;"><?= $total ?> scholarships</span>
      </div>
    </form>

    <!-- TABLE -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Scholarship Name</th>
            <th>University</th>
            <th>Amount</th>
            <th>Deadline</th>
            <th>Eligibility</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($scholarships)): ?>
            <tr><td colspan="6" style="text-align:center;padding:40px;color:#9CA3AF;">No scholarships found.</td></tr>
          <?php else: foreach($scholarships as $s):
            $dl = $s['application_deadline'];
            $is_past = $dl && strtotime($dl) < time();
            $days_left = $dl ? ceil((strtotime($dl)-time())/86400) : null;
          ?>
          <tr>
            <td>
              <div class="sch-name"><?= htmlspecialchars($s['name']) ?></div>
            </td>
            <td>
              <div style="font-size:13px;font-weight:600;color:#0A1628;"><?= htmlspecialchars(mb_strimwidth($s['uni_name'],0,30,'…')) ?></div>
              <div style="font-size:11px;color:#9CA3AF;"><?= $flags[$s['country_code']]??'' ?> <?= htmlspecialchars($s['country_name']) ?></div>
            </td>
            <td>
              <?php if($s['amount_local']): ?>
                <div style="font-size:14px;font-weight:800;color:#059669;">
                  <?= number_format((float)$s['amount_local'],0) ?>
                </div>
                <div style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars($s['local_currency_code']) ?>/year</div>
              <?php else: ?><span style="color:#D1D5DB;">—</span><?php endif; ?>
            </td>
            <td>
              <?php if(!$dl): ?>
                <span class="dl-ok">Open (no deadline)</span>
              <?php elseif($is_past): ?>
                <div class="dl-past">❌ <?= date('d M Y',strtotime($dl)) ?></div>
                <div style="font-size:11px;color:#9CA3AF;">Closed</div>
              <?php elseif($days_left <= 30): ?>
                <div class="dl-soon">⚠️ <?= date('d M Y',strtotime($dl)) ?></div>
                <div style="font-size:11px;color:#D97706;"><?= $days_left ?> days left</div>
              <?php else: ?>
                <div class="dl-ok">📅 <?= date('d M Y',strtotime($dl)) ?></div>
                <div style="font-size:11px;color:#9CA3AF;"><?= $days_left ?> days left</div>
              <?php endif; ?>
            </td>
            <td style="max-width:220px;">
              <div style="font-size:12px;color:#374151;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                <?= htmlspecialchars($s['eligibility']??'—') ?>
              </div>
            </td>
            <td style="white-space:nowrap;">
              <a href="scholarships.php?action=edit&id=<?=$s['scholarship_id']?>" class="act-btn act-edit">✏️ Edit</a>
              <a href="scholarships.php?action=delete&id=<?=$s['scholarship_id']?>"
                 class="act-btn act-del"
                 onclick="return confirm('Delete this scholarship permanently?')">🗑️ Delete</a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- PAGINATION -->
    <?php if($total_pages>1): ?>
    <nav class="pagination">
      <a href="?<?=http_build_query(array_filter(['q'=>$search,'country'=>$filter_cid,'status'=>$filter_status,'page'=>$page-1]))?>" class="pg-btn <?=$page<=1?'disabled':''?>">‹</a>
      <?php for($p=1;$p<=$total_pages;$p++):
        $qs=http_build_query(array_filter(['q'=>$search,'country'=>$filter_cid,'status'=>$filter_status,'page'=>$p]));
        if($p===1||$p===$total_pages||abs($p-$page)<=1):
          echo "<a href='?$qs' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        elseif(abs($p-$page)===2): echo "<span style='color:#D1D5DB;padding:0 4px;'>…</span>";
        endif;
      endfor; ?>
      <a href="?<?=http_build_query(array_filter(['q'=>$search,'country'=>$filter_cid,'status'=>$filter_status,'page'=>$page+1]))?>" class="pg-btn <?=$page>=$total_pages?'disabled':''?>">›</a>
    </nav>
    <?php endif; ?>
  </div>
</div>
</body>
</html>