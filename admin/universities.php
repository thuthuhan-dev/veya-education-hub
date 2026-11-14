<?php
// admin/universities.php — Veya Admin Panel
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php"); exit;
}
require_once "../db_connect.php";

$msg = ''; $error = '';

// ── Handle actions ────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Toggle active status
if ($action === 'toggle' && isset($_GET['id'])) {
    $uid = (int)$_GET['id'];
    $conn->query("UPDATE universities SET is_active = NOT is_active WHERE university_id = $uid");
    header("Location: universities.php?msg=".urlencode("University status updated.")); exit;
}

// Hide (deactivate) university — programs and scholarships also hidden via is_active filter
if ($action === 'deactivate' && isset($_GET['id']) && ($_GET['csrf_token']??'')==csrf_token()) {
    $uid = (int)$_GET['id'];
    $s = $conn->prepare("UPDATE universities SET is_active=0 WHERE university_id=?");
    $s->bind_param("i",$uid); $s->execute(); $s->close();
    header("Location: universities.php?msg=".urlencode("University hidden. Programs and scholarships will no longer appear on the site.")); exit;
}

// Restore (reactivate) university
if ($action === 'activate' && isset($_GET['id']) && ($_GET['csrf_token']??'')==csrf_token()) {
    $uid = (int)$_GET['id'];
    $s = $conn->prepare("UPDATE universities SET is_active=1 WHERE university_id=?");
    $s->bind_param("i",$uid); $s->execute(); $s->close();
    header("Location: universities.php?msg=".urlencode("University restored and visible on site.")); exit;
}

// DELETE university permanently (also deletes programs, scholarships, reviews via FK cascade)
if ($action === 'delete' && isset($_GET['id']) && ($_GET['csrf_token']??'')==csrf_token()) {
    $uid = (int)$_GET['id'];
    // Delete related data first (in case FK not set to cascade)
    foreach (["DELETE FROM saved_universities WHERE university_id=?",
              "DELETE FROM reviews WHERE university_id=?",
              "DELETE FROM scholarships WHERE university_id=?",
              "DELETE FROM tuition_fees WHERE program_id IN (SELECT program_id FROM programs WHERE university_id=?)",
              "DELETE FROM programs WHERE university_id=?",
              "DELETE FROM universities WHERE university_id=?"] as $sql) {
        $s = $conn->prepare($sql); $s->bind_param("i",$uid); $s->execute(); $s->close();
    }
    header("Location: universities.php?msg=".urlencode("University and all related data permanently deleted.")); exit;
}

// Save (add or edit)
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['add','edit'])) {
    $uid        = (int)($_POST['university_id'] ?? 0);
    $country_id = (int)($_POST['country_id']    ?? 0);
    $name       = trim($_POST['name']            ?? '');
    $city       = trim($_POST['city']            ?? '');
    $website    = trim($_POST['website_url']     ?? '');
    $image_url  = trim($_POST['image_url']       ?? '');
    $description= trim($_POST['description']     ?? '');
    $est_year   = (int)($_POST['established_year']?? 0) ?: null;
    $type       = $_POST['university_type']      ?? 'Private';
    $is_active  = isset($_POST['is_active']) ? 1 : 0;
    // Extended fields
    $world_rank  = (int)($_POST['world_ranking']  ??0)?:null;
    $nat_rank    = (int)($_POST['national_ranking']??0)?:null;
    $accept_rate = $_POST['acceptance_rate'] !== '' ? (float)$_POST['acceptance_rate'] : null;
    $students    = (int)($_POST['total_students']  ??0)?:null;
    $intl_pct    = $_POST['international_pct'] !== '' ? (float)$_POST['international_pct'] : null;
    $sf_ratio    = trim($_POST['student_faculty_ratio'] ?? '');
    $campus_size = trim($_POST['campus_size']      ?? '');
    $research    = $_POST['research_output']       ?? null;
    $accred      = trim($_POST['accreditations']   ?? '');
    $notable     = trim($_POST['notable_programs'] ?? '');
    $facilities  = trim($_POST['facilities']       ?? '');

    if ($name==='' || $country_id===0) {
        $error = 'University name and country are required.';
    } else {
        if ($action === 'add') {
            $s = $conn->prepare("
                INSERT INTO universities
                (country_id,name,city,website_url,image_url,description,established_year,
                 university_type,is_active,world_ranking,national_ranking,acceptance_rate,
                 total_students,international_pct,student_faculty_ratio,campus_size,
                 research_output,accreditations,notable_programs,facilities)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $s->bind_param("isssssisiiididssssss",
                $country_id,$name,$city,$website,$image_url,$description,$est_year,
                $type,$is_active,$world_rank,$nat_rank,$accept_rate,
                $students,$intl_pct,$sf_ratio,$campus_size,
                $research,$accred,$notable,$facilities);
        } else {
            $s = $conn->prepare("
                UPDATE universities SET
                country_id=?,name=?,city=?,website_url=?,image_url=?,description=?,
                established_year=?,university_type=?,is_active=?,
                world_ranking=?,national_ranking=?,acceptance_rate=?,
                total_students=?,international_pct=?,student_faculty_ratio=?,
                campus_size=?,research_output=?,accreditations=?,
                notable_programs=?,facilities=?
                WHERE university_id=?
            ");
            $s->bind_param("isssssisiiididssssssi",
                $country_id,$name,$city,$website,$image_url,$description,$est_year,
                $type,$is_active,$world_rank,$nat_rank,$accept_rate,
                $students,$intl_pct,$sf_ratio,$campus_size,
                $research,$accred,$notable,$facilities,$uid);
        }
        if ($s->execute()) {
            $new_id = $action==='add' ? $conn->insert_id : $uid;
            header("Location: universities.php?msg=".urlencode("University ".($action==='add'?'added':'updated')." successfully.")); exit;
        }
        $error = 'Database error: '.$conn->error;
        $s->close();
    }
}

// ── Fetch uni for edit ────────────────────────────────────────────────
$edit_uni = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $uid = (int)$_GET['id'];
    $r = $conn->prepare("SELECT * FROM universities WHERE university_id=?");
    $r->bind_param("i",$uid); $r->execute();
    $edit_uni = $r->get_result()->fetch_assoc();
    $r->close();
}

// ── Countries ─────────────────────────────────────────────────────────
$countries = $conn->query("SELECT c.country_id,c.country_name,c.country_code FROM countries c WHERE c.is_active=1 AND c.has_universities=1
    ORDER BY c.country_name")->fetch_all(MYSQLI_ASSOC);

// ── List ──────────────────────────────────────────────────────────────
$search      = trim($_GET['q']        ?? '');
$filter_cid  = (int)($_GET['country'] ?? 0);
$filter_type = $_GET['type']           ?? '';
$page        = max(1,(int)($_GET['page']??1));
$per_page    = 15; $offset = ($page-1)*$per_page;

$where = ["1=1"]; $params=[]; $types="";
if ($search!=='') {
    $where[]="(u.name LIKE ? OR u.city LIKE ?)";
    $like="%$search%"; $params[]=$like; $params[]=$like; $types.="ss";
}
if ($filter_cid>0)  { $where[]="u.country_id=?";       $params[]=$filter_cid;  $types.="i"; }
if ($filter_type!=='') { $where[]="u.university_type=?";$params[]=$filter_type; $types.="s"; }
$where_sql = "WHERE ".implode(" AND ",$where);

$cs = $conn->prepare("SELECT COUNT(*) FROM universities u $where_sql");
if ($types) $cs->bind_param($types,...$params);
$cs->execute();
$total = $cs->get_result()->fetch_row()[0];
$total_pages = max(1,ceil($total/$per_page));
$cs->close();

$stmt = $conn->prepare("
    SELECT u.*,c.country_name,c.country_code,
           COUNT(DISTINCT p.program_id) AS prog_count,
           COUNT(DISTINCT s.scholarship_id) AS sch_count,
           AVG(r.rating) AS avg_rating
    FROM universities u
    JOIN countries c ON c.country_id=u.country_id
    LEFT JOIN programs p ON p.university_id=u.university_id
    LEFT JOIN scholarships s ON s.university_id=u.university_id
    LEFT JOIN reviews r ON r.university_id=u.university_id AND r.status='Approved'
    $where_sql
    GROUP BY u.university_id,u.name,u.city,u.university_type,u.image_url,
             u.website_url,u.established_year,u.description,u.is_active,
             u.world_ranking,u.national_ranking,u.acceptance_rate,u.total_students,
             u.international_pct,u.student_faculty_ratio,u.campus_size,
             u.research_output,u.accreditations,u.notable_programs,u.facilities,
             u.country_id,u.created_at,u.updated_at,
             c.country_name,c.country_code
    ORDER BY c.country_name, u.name
    LIMIT ? OFFSET ?
");
$ap=[...$params,$per_page,$offset]; $at=$types."ii";
if ($at) $stmt->bind_param($at,...$ap);
$stmt->execute();
$universities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flags=['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$fallback='https://images.unsplash.com/photo-1562774053-701939374585?auto=format&fit=crop&w=80&q=60';
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
<title>Universities — Veya Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F1F5F9;display:flex;min-height:100vh;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
.sidebar{width:240px;background:#0A1628;display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;overflow-y:auto;}
.sb-logo{padding:24px 20px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px;}
.sb-logo img{height:26px;filter:brightness(0) invert(1);opacity:.8;}
.sb-logo-text{font-size:17px;font-weight:800;color:#fff;}
.sb-badge{font-size:9px;font-weight:700;background:#EF4444;color:#fff;padding:2px 6px;border-radius:4px;letter-spacing:.06em;text-transform:uppercase;margin-left:4px;}
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
.topbar{background:#fff;border-bottom:1px solid #E5E7EB;padding:0 32px;height:64px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:18px;font-weight:800;color:#0A1628;}
.btn-add{display:flex;align-items:center;gap:7px;padding:9px 20px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#1A56DB;border:none;border-radius:9px;cursor:pointer;transition:background .15s;}
.btn-add:hover{background:#1E40AF;}
.content{padding:28px 32px;}
.alert{padding:12px 16px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:20px;}
.alert-ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;}
.alert-err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}

/* FILTERS */
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center;}
.fi{font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;color:#374151;background:#fff;border:1px solid #E5E7EB;padding:9px 13px;border-radius:9px;outline:none;cursor:pointer;transition:border-color .15s;}
.fi:focus{border-color:#93C5FD;}
.fi-search{flex:1;min-width:180px;}
.fi-search::placeholder{color:#9CA3AF;font-weight:400;}
.fi-btn{padding:9px 20px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#374151;border:none;border-radius:9px;cursor:pointer;}
.fi-btn:hover{background:#1F2937;}
.result-info{font-size:13px;color:#9CA3AF;margin-left:auto;}

/* TABLE */
.table-wrap{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;margin-bottom:20px;}
table{width:100%;border-collapse:collapse;}
thead th{padding:11px 16px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;background:#F9FAFB;border-bottom:1px solid #E5E7EB;}
tbody td{padding:13px 16px;font-size:13px;color:#374151;border-bottom:1px solid #F9FAFB;vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:#FAFBFF;}
.uni-thumb{width:44px;height:36px;border-radius:6px;object-fit:cover;flex-shrink:0;background:#F3F4F6;}
.uni-name{font-size:14px;font-weight:700;color:#0A1628;}
.uni-city{font-size:11px;color:#9CA3AF;}
.badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;letter-spacing:.04em;}
.badge-public{background:#ECFDF5;color:#065F46;}
.badge-private{background:#EFF6FF;color:#1E40AF;}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px;}
.dot-on{background:#22C55E;}
.dot-off{background:#E5E7EB;}
.act-btn{font-size:12px;font-weight:700;padding:5px 11px;border-radius:7px;border:none;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:4px;}
.act-edit{color:#1A56DB;background:#EFF6FF;}
.act-edit:hover{background:#DBEAFE;}
.act-toggle-on{color:#EF4444;background:#FEF2F2;}
.act-toggle-on:hover{background:#EF4444;color:#fff;}
.act-toggle-off{color:#059669;background:#ECFDF5;}
.act-toggle-off:hover{background:#059669;color:#fff;}

/* PAGINATION */
.pagination{display:flex;justify-content:center;gap:5px;margin-top:4px;}
.pg-btn{min-width:36px;height:36px;padding:0 10px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #E5E7EB;color:#374151;background:#fff;transition:all .15s;}
.pg-btn:hover:not(.disabled):not(.active){border-color:#1A56DB;color:#1A56DB;}
.pg-btn.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}
.pg-btn.disabled{opacity:.35;pointer-events:none;}

/* FORM */
.form-wrap{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:28px;margin-bottom:24px;}
.form-title{font-size:18px;font-weight:800;color:#0A1628;margin-bottom:4px;}
.form-sub{font-size:13px;color:#9CA3AF;margin-bottom:24px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;}
.field{margin-bottom:16px;}
.field.full{grid-column:1/-1;}
label{display:block;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280;margin-bottom:5px;}
input[type=text],input[type=url],input[type=number],select,textarea{width:100%;padding:10px 12px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;color:#0A1628;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:9px;transition:border-color .15s,box-shadow .15s;outline:none;}
input:focus,select:focus,textarea:focus{border-color:#93C5FD;background:#fff;box-shadow:0 0 0 3px rgba(147,197,253,.2);}
textarea{resize:vertical;min-height:90px;line-height:1.6;}
.form-section{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;padding-bottom:10px;border-bottom:1px solid #F3F4F6;margin-bottom:16px;margin-top:10px;grid-column:1/-1;}
.form-actions{display:flex;gap:10px;align-items:center;margin-top:8px;grid-column:1/-1;}
.btn-save{padding:11px 28px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;color:#fff;background:#1A56DB;border:none;border-radius:9px;cursor:pointer;transition:background .15s;}
.btn-save:hover{background:#1E40AF;}
.btn-cancel{padding:11px 20px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:600;color:#374151;background:#F3F4F6;border:none;border-radius:9px;cursor:pointer;transition:background .15s;}
.btn-cancel:hover{background:#E5E7EB;}
.toggle-wrap{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:9px;}
.toggle-label{font-size:14px;color:#374151;font-weight:500;}
</style>
</head>
<body>

<!-- SIDEBAR -->
<?php $admin_active = "universities"; include "includes/sidebar.php"; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">🏫 Universities</div>
    <div class="topbar-right"><a href="universities.php?action=add" class="btn-add">➕ Add University</a></div>
  </div>
  <div class="content">

    <?php if($msg):   ?><div class="alert alert-ok">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- ADD / EDIT FORM -->
    <?php if($showing_form): ?>
    <div class="form-wrap">
      <div class="form-title"><?= $action==='add' ? '➕ Add New University' : '✏️ Edit University' ?></div>
      <div class="form-sub"><?= $action==='add' ? 'Fill in the details to add a new university to Veya.' : 'Update the information for '.htmlspecialchars($edit_uni['name']??''). '.' ?></div>
      <form method="POST" action="universities.php">
        <input type="hidden" name="action" value="<?= $action ?>">
        <?php if($action==='edit'): ?><input type="hidden" name="university_id" value="<?= $edit_uni['university_id'] ?>"><?php endif; ?>
        <div class="form-grid">
          <div class="form-section">📋 Basic Information</div>
          <div class="field">
            <label>University Name *</label>
            <input type="text" name="name" value="<?= htmlspecialchars($edit_uni['name']??'') ?>" placeholder="e.g. Chulalongkorn University" required>
          </div>
          <div class="field">
            <label>Country *</label>
            <select name="country_id" required>
              <option value="">— Select country —</option>
              <?php foreach($countries as $c): ?>
                <option value="<?=$c['country_id']?>" <?=($edit_uni['country_id']??0)==$c['country_id']?'selected':''?>>
                  <?=$flags[$c['country_code']]??''?> <?=htmlspecialchars($c['country_name'])?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>City</label>
            <input type="text" name="city" value="<?= htmlspecialchars($edit_uni['city']??'') ?>" placeholder="e.g. Bangkok">
          </div>
          <div class="field">
            <label>Established Year</label>
            <input type="number" name="established_year" value="<?= $edit_uni['established_year']??'' ?>" placeholder="e.g. 1917" min="1800" max="2030">
          </div>
          <div class="field">
            <label>University Type</label>
            <select name="university_type">
              <option value="Public"   <?=($edit_uni['university_type']??'')==='Public'  ?'selected':''?>>🏛️ Public</option>
              <option value="Private"  <?=($edit_uni['university_type']??'')==='Private' ?'selected':''?>>🏫 Private</option>
            </select>
          </div>
          <div class="field">
            <label>Status</label>
            <div class="toggle-wrap">
              <input type="checkbox" name="is_active" id="is_active" value="1" <?=($edit_uni['is_active']??1)?'checked':''?> style="width:auto;">
              <label for="is_active" class="toggle-label" style="text-transform:none;letter-spacing:0;font-size:14px;margin-bottom:0;">Active (visible on site)</label>
            </div>
          </div>
          <div class="field full">
            <label>Website URL</label>
            <input type="url" name="website_url" value="<?= htmlspecialchars($edit_uni['website_url']??'') ?>" placeholder="https://www.university.edu">
          </div>
          <div class="field full">
            <label>Image URL (Unsplash or local path)</label>
            <input type="text" name="image_url" value="<?= htmlspecialchars($edit_uni['image_url']??'') ?>" placeholder="https://images.unsplash.com/... or assets/images/uni.jpg">
          </div>
          <div class="field full">
            <label>Description</label>
            <textarea name="description" placeholder="Write a compelling 2-3 sentence description of the university…"><?= htmlspecialchars($edit_uni['description']??'') ?></textarea>
          </div>

          <div class="form-section">🏆 Rankings & Stats</div>
          <div class="field">
            <label>World Ranking (QS)</label>
            <input type="number" name="world_ranking" value="<?= $edit_uni['world_ranking']??'' ?>" placeholder="e.g. 8">
          </div>
          <div class="field">
            <label>National Ranking</label>
            <input type="number" name="national_ranking" value="<?= $edit_uni['national_ranking']??'' ?>" placeholder="e.g. 1">
          </div>
          <div class="field">
            <label>Acceptance Rate (%)</label>
            <input type="number" name="acceptance_rate" value="<?= $edit_uni['acceptance_rate']??'' ?>" placeholder="e.g. 18.5" step="0.1" min="0" max="100">
          </div>
          <div class="field">
            <label>Total Students</label>
            <input type="number" name="total_students" value="<?= $edit_uni['total_students']??'' ?>" placeholder="e.g. 40000">
          </div>
          <div class="field">
            <label>International Students (%)</label>
            <input type="number" name="international_pct" value="<?= $edit_uni['international_pct']??'' ?>" placeholder="e.g. 35.0" step="0.1">
          </div>
          <div class="field">
            <label>Student:Faculty Ratio</label>
            <input type="text" name="student_faculty_ratio" value="<?= htmlspecialchars($edit_uni['student_faculty_ratio']??'') ?>" placeholder="e.g. 9:1">
          </div>
          <div class="field">
            <label>Campus Size</label>
            <input type="text" name="campus_size" value="<?= htmlspecialchars($edit_uni['campus_size']??'') ?>" placeholder="e.g. 150 acres">
          </div>
          <div class="field">
            <label>Research Output</label>
            <select name="research_output">
              <option value="">— Not set —</option>
              <?php foreach(['Very High','High','Medium','Low'] as $ro): ?>
                <option value="<?=$ro?>" <?=($edit_uni['research_output']??'')===$ro?'selected':''?>><?=$ro?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field full">
            <label>Accreditations (comma-separated)</label>
            <input type="text" name="accreditations" value="<?= htmlspecialchars($edit_uni['accreditations']??'') ?>" placeholder="AACSB, ABET, EQUIS">
          </div>
          <div class="field full">
            <label>Notable Programs (comma-separated)</label>
            <input type="text" name="notable_programs" value="<?= htmlspecialchars($edit_uni['notable_programs']??'') ?>" placeholder="Computing, Law, Medicine, Engineering">
          </div>
          <div class="field full">
            <label>Facilities (comma-separated)</label>
            <input type="text" name="facilities" value="<?= htmlspecialchars($edit_uni['facilities']??'') ?>" placeholder="Library, Sports Complex, Hospital, Research Labs">
          </div>

          <div class="form-actions">
            <button type="submit" class="btn-save">💾 <?= $action==='add'?'Add University':'Save Changes' ?></button>
            <a href="universities.php" class="btn-cancel">Cancel</a>
          </div>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <!-- FILTERS & TABLE -->
    <form method="GET" action="universities.php" style="display:contents;">
      <div class="filters">
        <input class="fi fi-search" type="text" name="q" placeholder="Search name or city…" value="<?= htmlspecialchars($search) ?>">
        <select class="fi" name="country" onchange="this.form.submit()">
          <option value="">All Countries</option>
          <?php foreach($countries as $c): ?>
            <option value="<?=$c['country_id']?>" <?=$filter_cid==$c['country_id']?'selected':''?>>
              <?=$flags[$c['country_code']]??''?> <?=htmlspecialchars($c['country_name'])?>
            </option>
          <?php endforeach; ?>
        </select>
        <select class="fi" name="type" onchange="this.form.submit()">
          <option value="">All Types</option>
          <option value="Public"  <?=$filter_type==='Public' ?'selected':''?>>🏛️ Public</option>
          <option value="Private" <?=$filter_type==='Private'?'selected':''?>>🏫 Private</option>
        </select>
        <button class="fi-btn" type="submit">Search</button>
        <?php if($search||$filter_cid||$filter_type): ?><a href="universities.php" style="font-size:13px;color:#EF4444;font-weight:600;">Clear</a><?php endif; ?>
        <span class="result-info"><?= $total ?> universities</span>
      </div>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:44px;"></th>
            <th>University</th>
            <th>Country</th>
            <th>Type</th>
            <th>Ranking</th>
            <th>Programs</th>
            <th>Scholarships</th>
            <th>Rating</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($universities)): ?>
            <tr><td colspan="10" style="text-align:center;padding:40px;color:#9CA3AF;">No universities found.</td></tr>
          <?php else: foreach($universities as $u):
            $img = str_replace('\\','/',trim($u['image_url']??''));
            $thumb = !empty($img) ? $img : $fallback;
            $rating = $u['avg_rating'] ? round((float)$u['avg_rating'],1) : null;
          ?>
          <tr>
            <td><img class="uni-thumb" src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy" onerror="this.src='<?=$fallback?>'"></td>
            <td>
              <div class="uni-name"><?= htmlspecialchars($u['name']) ?></div>
              <div class="uni-city">📍 <?= htmlspecialchars($u['city']??'—') ?> · Est. <?= $u['established_year']??'—' ?></div>
            </td>
            <td><?= $flags[$u['country_code']]??'' ?> <?= htmlspecialchars($u['country_name']) ?></td>
            <td><span class="badge badge-<?= strtolower($u['university_type']) ?>"><?= $u['university_type'] ?></span></td>
            <td><?= $u['world_ranking'] ? '#'.$u['world_ranking'] : '<span style="color:#D1D5DB;">—</span>' ?></td>
            <td><?= $u['prog_count'] ?></td>
            <td><?= $u['sch_count'] ?></td>
            <td><?= $rating ? '⭐ '.$rating : '<span style="color:#D1D5DB;">—</span>' ?></td>
            <td>
              <span class="status-dot <?= $u['is_active']?'dot-on':'dot-off' ?>"></span>
              <?= $u['is_active'] ? 'Active' : 'Hidden' ?>
            </td>
            <td style="white-space:nowrap;">
              <a href="universities.php?action=edit&id=<?=$u['university_id']?>" class="act-btn act-edit">✏️ Edit</a>
              <?php if($u['is_active']): ?>
              <a href="universities.php?action=deactivate&id=<?=$u['university_id']?>&csrf_token=<?=csrf_token()?>"
                 class="act-btn act-toggle-on"
                 onclick="return confirm('Hide this university? It will disappear from all pages.')">
                🙈 Hide
              </a>
              <?php else: ?>
              <a href="universities.php?action=activate&id=<?=$u['university_id']?>&csrf_token=<?=csrf_token()?>"
                 class="act-btn act-toggle-off"
                 onclick="return confirm('Restore this university? It will be visible again.')">
                👁️ Show
              </a>
              <?php endif; ?>
              <a href="universities.php?action=delete&id=<?=$u['university_id']?>&csrf_token=<?=csrf_token()?>"
                 class="act-btn"
                 style="background:#FEF2F2;color:#EF4444;border:1px solid #FCA5A5;"
                 onclick="return confirm('⚠️ PERMANENTLY DELETE this university and ALL its programs, scholarships, and reviews? This cannot be undone!')">
                🗑️ Delete
              </a>
              <a href="../university_detail.php?id=<?=$u['university_id']?>" target="_blank"
                 style="font-size:12px;color:#9CA3AF;font-weight:600;padding:5px 8px;">View →</a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- PAGINATION -->
    <?php if($total_pages>1): ?>
    <nav class="pagination">
      <a href="?<?=http_build_query(array_filter(['q'=>$search,'country'=>$filter_cid,'type'=>$filter_type,'page'=>$page-1]))?>" class="pg-btn <?=$page<=1?'disabled':''?>">‹</a>
      <?php for($p=1;$p<=$total_pages;$p++):
        $qs=http_build_query(array_filter(['q'=>$search,'country'=>$filter_cid,'type'=>$filter_type,'page'=>$p]));
        if($p===1||$p===$total_pages||abs($p-$page)<=1):
          echo "<a href='?$qs' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        elseif(abs($p-$page)===2): echo "<span style='color:#D1D5DB;padding:0 4px;'>…</span>";
        endif;
      endfor; ?>
      <a href="?<?=http_build_query(array_filter(['q'=>$search,'country'=>$filter_cid,'type'=>$filter_type,'page'=>$page+1]))?>" class="pg-btn <?=$page>=$total_pages?'disabled':''?>">›</a>
    </nav>
    <?php endif; ?>

  </div>
</div>
</body>
</html>