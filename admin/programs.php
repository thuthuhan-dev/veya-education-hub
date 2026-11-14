<?php
// admin/programs.php — Veya Admin Panel
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php"); exit;
}
require_once "../db_connect.php";

$msg = ''; $error = '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Delete
if ($action === 'delete' && isset($_GET['id'])) {
    $pid = (int)$_GET['id'];
    $s = $conn->prepare("DELETE FROM programs WHERE program_id=?");
    $s->bind_param("i",$pid); $s->execute(); $s->close();
    header("Location: programs.php?msg=".urlencode("Program deleted.")); exit;
}

// Save
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['add','edit'])) {
    $pid       = (int)($_POST['program_id']             ?? 0);
    $uni_id    = (int)($_POST['university_id']          ?? 0);
    $prog_name = trim($_POST['program_name']            ?? '');
    $level     = trim($_POST['degree_level']            ?? '');
    $field     = trim($_POST['field_of_study']          ?? '');
    $duration  = (int)($_POST['duration_months']        ?? 0);
    $lang      = trim($_POST['language_of_instruction'] ?? 'English');
    $desc      = trim($_POST['description']             ?? '');
    $fee_amt   = $_POST['fee_amount'] !== '' ? (float)$_POST['fee_amount'] : null;
    $fee_cur   = trim($_POST['fee_currency']  ?? '');
    $fee_per   = trim($_POST['fee_period']    ?? 'Per Year');
    $fee_notes = trim($_POST['fee_notes']     ?? '');

    if ($prog_name==='' || $uni_id===0 || $level==='') {
        $error = 'Program name, university, and degree level are required.';
    } else {
        if ($action==='add') {
            $s = $conn->prepare("INSERT INTO programs (university_id,program_name,degree_level,field_of_study,duration_months,language_of_instruction,description) VALUES(?,?,?,?,?,?,?)");
            $s->bind_param("isssiis",$uni_id,$prog_name,$level,$field,$duration,$lang,$desc);
            $s->execute(); $pid = $conn->insert_id; $s->close();
        } else {
            $s = $conn->prepare("UPDATE programs SET university_id=?,program_name=?,degree_level=?,field_of_study=?,duration_months=?,language_of_instruction=?,description=? WHERE program_id=?");
            $s->bind_param("isssissi",$uni_id,$prog_name,$level,$field,$duration,$lang,$desc,$pid);
            $s->execute(); $s->close();
        }
        if ($fee_amt !== null && $fee_cur !== '') {
            $ex = $conn->prepare("SELECT fee_id FROM tuition_fees WHERE program_id=? AND fee_type='Tuition'");
            $ex->bind_param("i",$pid); $ex->execute();
            $fid = $ex->get_result()->fetch_assoc()['fee_id'] ?? null; $ex->close();
            if ($fid) {
                $fs = $conn->prepare("UPDATE tuition_fees SET amount_local=?,local_currency_code=?,fee_period=?,notes=? WHERE fee_id=?");
                $fs->bind_param("dsssi",$fee_amt,$fee_cur,$fee_per,$fee_notes,$fid);
            } else {
                $fs = $conn->prepare("INSERT INTO tuition_fees (program_id,fee_type,amount_local,local_currency_code,fee_period,notes) VALUES(?,'Tuition',?,?,?,?)");
                $fs->bind_param("idsss",$pid,$fee_amt,$fee_cur,$fee_per,$fee_notes);
            }
            $fs->execute(); $fs->close();
        }
        header("Location: programs.php?msg=".urlencode("Program ".($action==='add'?'added':'updated').".")); exit;
    }
}

$edit = null; $edit_fee = null;
if ($action==='edit' && isset($_GET['id'])) {
    $r = $conn->prepare("SELECT * FROM programs WHERE program_id=?");
    $edit_id = (int)$_GET['id']; $r->bind_param("i", $edit_id); $r->execute();
    $edit = $r->get_result()->fetch_assoc(); $r->close();
    if ($edit) {
        $rf = $conn->prepare("SELECT * FROM tuition_fees WHERE program_id=? AND fee_type='Tuition' LIMIT 1");
        $rf->bind_param("i",$edit['program_id']); $rf->execute();
        $edit_fee = $rf->get_result()->fetch_assoc(); $rf->close();
    }
}

$unis_list = $conn->query("SELECT u.university_id,u.name FROM universities u WHERE u.is_active=1 ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);
$cur_list  = $conn->query("SELECT currency_code FROM currencies ORDER BY currency_code")->fetch_all(MYSQLI_ASSOC);
$ctry_list = $conn->query("SELECT c.country_id,c.country_name,c.country_code FROM countries c WHERE c.is_active=1 AND c.has_universities=1
    ORDER BY c.country_name")->fetch_all(MYSQLI_ASSOC);

$search=trim($_GET['q']??'');
$filter_cid=(int)($_GET['country']??0);
$filter_lvl=trim($_GET['level']??'');
$page=max(1,(int)($_GET['page']??1));$per_page=15;$offset=($page-1)*$per_page;

$where=["1=1"];$params=[];$types="";
if($search!==''){$where[]="(p.program_name LIKE ? OR p.field_of_study LIKE ?)";$like="%$search%";$params[]=$like;$params[]=$like;$types.="ss";}
if($filter_cid>0){$where[]="c.country_id=?";$params[]=$filter_cid;$types.="i";}
if($filter_lvl!==''){$where[]="p.degree_level=?";$params[]=$filter_lvl;$types.="s";}
$wsql="WHERE ".implode(" AND ",$where);

$cs=$conn->prepare("SELECT COUNT(*) FROM programs p JOIN universities u ON u.university_id=p.university_id JOIN countries c ON c.country_id=u.country_id $wsql");
if($types)$cs->bind_param($types,...$params);$cs->execute();$total=$cs->get_result()->fetch_row()[0];$cs->close();
$total_pages=max(1,ceil($total/$per_page));

$stmt=$conn->prepare("SELECT p.*,u.name AS uni_name,c.country_name,c.country_code,tf.amount_local,tf.local_currency_code,tf.fee_period FROM programs p JOIN universities u ON u.university_id=p.university_id JOIN countries c ON c.country_id=u.country_id LEFT JOIN tuition_fees tf ON tf.program_id=p.program_id AND tf.fee_type='Tuition' $wsql ORDER BY c.country_name,u.name,p.degree_level,p.program_name LIMIT ? OFFSET ?");
$ap=[...$params,$per_page,$offset];$at=$types."ii";if($at)$stmt->bind_param($at,...$ap);
$stmt->execute();$programs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

$flags=['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$lc=['Foundation'=>['#92400E','#FEF3C7'],'Diploma'=>['#5B21B6','#EDE9FE'],'Bachelor'=>['#1E40AF','#EFF6FF'],'Master'=>['#065F46','#ECFDF5'],'PhD'=>['#9A3412','#FFF7ED']];
$admin_name=explode(' ',$_SESSION['full_name'])[0];
$ac=['linear-gradient(135deg,#6366F1,#8B5CF6)','linear-gradient(135deg,#DC2626,#EF4444)'];
$avatar_color=$ac[$_SESSION['user_id']%count($ac)];$initial=strtoupper($_SESSION['full_name'][0]??'A');
$msg=$_GET['msg']??$msg;$showing_form=in_array($action,['add','edit']);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Programs — Veya Admin</title>
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
.btn-add{display:flex;align-items:center;gap:7px;padding:9px 20px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#7C3AED;border:none;border-radius:9px;cursor:pointer;transition:background .15s;}
.btn-add:hover{background:#6D28D9;}
.content{padding:28px 32px;}
.alert{padding:12px 16px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:20px;}
.alert-ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;}
.alert-err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}
.form-wrap{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:28px;margin-bottom:24px;}
.form-title{font-size:17px;font-weight:800;color:#0A1628;margin-bottom:3px;}
.form-sub{font-size:13px;color:#9CA3AF;margin-bottom:20px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.field.full{grid-column:1/-1;}
.form-sec{grid-column:1/-1;font-size:11px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:#9CA3AF;padding-top:10px;border-top:1px solid #F3F4F6;margin-top:4px;}
label{display:block;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280;margin-bottom:5px;}
input,select,textarea{width:100%;padding:10px 12px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;color:#0A1628;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:9px;outline:none;transition:border-color .15s;}
input:focus,select:focus,textarea:focus{border-color:#93C5FD;background:#fff;box-shadow:0 0 0 3px rgba(147,197,253,.2);}
textarea{resize:vertical;min-height:70px;line-height:1.6;}
.form-actions{display:flex;gap:10px;grid-column:1/-1;margin-top:4px;}
.btn-save{padding:11px 26px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;color:#fff;background:#7C3AED;border:none;border-radius:9px;cursor:pointer;}
.btn-save:hover{background:#6D28D9;}
.btn-cancel{padding:11px 18px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:600;color:#374151;background:#F3F4F6;border:none;border-radius:9px;cursor:pointer;}
.filters{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap;align-items:center;}
.fi{font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;color:#374151;background:#fff;border:1px solid #E5E7EB;padding:9px 12px;border-radius:9px;outline:none;cursor:pointer;}
.fi-search{flex:1;min-width:160px;}
.fi-search::placeholder{color:#9CA3AF;font-weight:400;}
.fi-btn{padding:9px 18px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#374151;border:none;border-radius:9px;cursor:pointer;}
.table-wrap{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;margin-bottom:20px;}
table{width:100%;border-collapse:collapse;}
thead th{padding:11px 16px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;background:#F9FAFB;border-bottom:1px solid #E5E7EB;}
tbody td{padding:12px 16px;font-size:13px;color:#374151;border-bottom:1px solid #F9FAFB;vertical-align:top;}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:#FAFBFF;}
.lvl-badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;}
.act-btn{font-size:12px;font-weight:700;padding:5px 11px;border-radius:7px;border:none;cursor:pointer;transition:all .15s;margin-right:4px;display:inline-block;}
.act-edit{color:#1A56DB;background:#EFF6FF;}
.act-edit:hover{background:#DBEAFE;}
.act-del{color:#EF4444;background:#FEF2F2;}
.act-del:hover{background:#EF4444;color:#fff;}
.pagination{display:flex;justify-content:center;gap:5px;margin-top:4px;}
.pg-btn{min-width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #E5E7EB;color:#374151;background:#fff;transition:all .15s;}
.pg-btn:hover:not(.disabled):not(.active){border-color:#7C3AED;color:#7C3AED;}
.pg-btn.active{background:#7C3AED;border-color:#7C3AED;color:#fff;}
.pg-btn.disabled{opacity:.35;pointer-events:none;}
</style>
</head>
<body>
<?php $admin_active = "programs"; include "includes/sidebar.php"; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title">📚 Programs &amp; Fees</div>
    <div class="topbar-right"><a href="programs.php?action=add" class="btn-add">➕ Add Program</a></div>
  </div>
  <div class="content">
    <?php if($msg):   ?><div class="alert alert-ok">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if($showing_form): ?>
    <div class="form-wrap">
      <div class="form-title"><?= $action==='add'?'➕ Add Program':'✏️ Edit Program' ?></div>
      <div class="form-sub">Program details + tuition fee.</div>
      <form method="POST">
        <input type="hidden" name="action" value="<?= $action ?>">
        <?php if($action==='edit'): ?><input type="hidden" name="program_id" value="<?= $edit['program_id'] ?>"><?php endif; ?>
        <div class="form-grid">
          <div class="field full"><label>Program Name *</label><input type="text" name="program_name" value="<?= htmlspecialchars($edit['program_name']??'') ?>" placeholder="e.g. Bachelor of Computing" required></div>
          <div class="field"><label>University *</label>
            <select name="university_id" required>
              <option value="">— Select university —</option>
              <?php foreach($unis_list as $u): ?>
                <option value="<?=$u['university_id']?>" <?=($edit['university_id']??0)==$u['university_id']?'selected':''?>><?= htmlspecialchars($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Degree Level *</label>
            <select name="degree_level" required>
              <option value="">— Select —</option>
              <?php foreach(['Foundation','Diploma','Bachelor','Master','PhD'] as $l): ?>
                <option value="<?=$l?>" <?=($edit['degree_level']??'')===$l?'selected':''?>><?=$l?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Field of Study</label>
            <select name="field_of_study">
              <option value="">— Select —</option>
              <?php foreach(['Business','Computing','Engineering','Health Sciences','Law','Economics','Arts','Architecture','Environmental','Agriculture','Science','Tourism','Psychology','Social Sciences'] as $f): ?>
                <option value="<?=$f?>" <?=($edit['field_of_study']??'')===$f?'selected':''?>><?=$f?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Duration (months)</label><input type="number" name="duration_months" value="<?= $edit['duration_months']??'' ?>" placeholder="e.g. 48" min="6" max="120"></div>
          <div class="field"><label>Language</label>
            <select name="language_of_instruction">
              <?php foreach(['English','Thai','Malay','Bilingual'] as $lang): ?>
                <option value="<?=$lang?>" <?=($edit['language_of_instruction']??'English')===$lang?'selected':''?>><?=$lang?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field full"><label>Description</label><textarea name="description" placeholder="Brief program description…"><?= htmlspecialchars($edit['description']??'') ?></textarea></div>
          <div class="form-sec">💱 Tuition Fee</div>
          <div class="field"><label>Amount</label><input type="number" name="fee_amount" value="<?= $edit_fee['amount_local']??'' ?>" placeholder="e.g. 37000" step="0.01" min="0"></div>
          <div class="field"><label>Currency</label>
            <select name="fee_currency">
              <option value="">— Select —</option>
              <?php foreach($cur_list as $c): ?>
                <option value="<?=$c['currency_code']?>" <?=($edit_fee['local_currency_code']??'')===$c['currency_code']?'selected':''?>><?=$c['currency_code']?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Fee Period</label>
            <select name="fee_period">
              <?php foreach(['Per Year','Per Semester','Total Program'] as $fp): ?>
                <option value="<?=$fp?>" <?=($edit_fee['fee_period']??'Per Year')===$fp?'selected':''?>><?=$fp?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Fee Notes</label><input type="text" name="fee_notes" value="<?= htmlspecialchars($edit_fee['notes']??'') ?>" placeholder="e.g. International student rate"></div>
          <div class="form-actions">
            <button type="submit" class="btn-save">💾 <?= $action==='add'?'Add Program':'Save Changes' ?></button>
            <a href="programs.php" class="btn-cancel">Cancel</a>
          </div>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <form method="GET" style="display:contents;">
      <div class="filters">
        <input class="fi fi-search" type="text" name="q" placeholder="Search program name or field…" value="<?= htmlspecialchars($search) ?>">
        <select class="fi" name="country" onchange="this.form.submit()">
          <option value="">All Countries</option>
          <?php foreach($ctry_list as $c): ?>
            <option value="<?=$c['country_id']?>" <?=$filter_cid==$c['country_id']?'selected':''?>><?=$flags[$c['country_code']]??''?> <?=htmlspecialchars($c['country_name'])?></option>
          <?php endforeach; ?>
        </select>
        <select class="fi" name="level" onchange="this.form.submit()">
          <option value="">All Levels</option>
          <?php foreach(['Foundation','Diploma','Bachelor','Master','PhD'] as $l): ?>
            <option value="<?=$l?>" <?=$filter_lvl===$l?'selected':''?>><?=$l?></option>
          <?php endforeach; ?>
        </select>
        <button class="fi-btn" type="submit">Search</button>
        <span style="font-size:13px;color:#9CA3AF;margin-left:auto;"><?= $total ?> programs</span>
      </div>
    </form>

    <div class="table-wrap">
      <table>
        <thead><tr><th>Program Name</th><th>University</th><th>Level</th><th>Field</th><th>Duration</th><th>Tuition Fee</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if(empty($programs)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:#9CA3AF;">No programs found.</td></tr>
          <?php else: foreach($programs as $p):
            $c = $lc[$p['degree_level']] ?? ['#374151','#F3F4F6'];
          ?>
          <tr>
            <td><div style="font-size:13px;font-weight:700;color:#0A1628;margin-bottom:2px;"><?= htmlspecialchars(mb_strimwidth($p['program_name'],0,50,'…')) ?></div><div style="font-size:11px;color:#9CA3AF;"><?= htmlspecialchars($p['language_of_instruction']) ?></div></td>
            <td><div style="font-size:13px;font-weight:600;color:#0A1628;"><?= htmlspecialchars(mb_strimwidth($p['uni_name'],0,28,'…')) ?></div><div style="font-size:11px;color:#9CA3AF;"><?= $flags[$p['country_code']]??'' ?> <?= htmlspecialchars($p['country_name']) ?></div></td>
            <td><span class="lvl-badge" style="color:<?=$c[0]?>;background:<?=$c[1]?>"><?= $p['degree_level'] ?></span></td>
            <td style="font-size:12px;color:#6B7280;"><?= htmlspecialchars($p['field_of_study']??'—') ?></td>
            <td><?= $p['duration_months'] ? round($p['duration_months']/12,1).' yrs' : '—' ?></td>
            <td><?php if($p['amount_local']): ?><div style="font-size:14px;font-weight:800;color:#1A56DB;"><?= number_format((float)$p['amount_local'],0) ?></div><div style="font-size:10px;color:#9CA3AF;"><?= htmlspecialchars($p['local_currency_code']) ?>/yr</div><?php else: ?>—<?php endif; ?></td>
            <td><a href="programs.php?action=edit&id=<?=$p['program_id']?>" class="act-btn act-edit">✏️ Edit</a><a href="programs.php?action=delete&id=<?=$p['program_id']?>" class="act-btn act-del" onclick="return confirm('Delete this program?')">🗑️</a></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if($total_pages>1): ?>
    <nav class="pagination">
      <a href="?<?=http_build_query(array_filter(['q'=>$search,'country'=>$filter_cid,'level'=>$filter_lvl,'page'=>$page-1]))?>" class="pg-btn <?=$page<=1?'disabled':''?>">‹</a>
      <?php for($p=1;$p<=$total_pages;$p++):
        $qs=http_build_query(array_filter(['q'=>$search,'country'=>$filter_cid,'level'=>$filter_lvl,'page'=>$p]));
        if($p===1||$p===$total_pages||abs($p-$page)<=1):echo "<a href='?$qs' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        elseif(abs($p-$page)===2):echo "<span style='color:#D1D5DB;padding:0 4px;'>…</span>";
        endif;
      endfor; ?>
      <a href="?<?=http_build_query(array_filter(['q'=>$search,'country'=>$filter_cid,'level'=>$filter_lvl,'page'=>$page+1]))?>" class="pg-btn <?=$page>=$total_pages?'disabled':''?>">›</a>
    </nav>
    <?php endif; ?>
  </div>
</div>
</body>
</html>