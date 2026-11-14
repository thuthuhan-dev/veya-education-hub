<?php
// admin/users.php — Veya Admin Panel
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php"); exit;
}
require_once "../db_connect.php";

$msg = ''; $error = '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Change role
if ($action === 'set_role' && isset($_GET['id'], $_GET['role'])) {
    $uid  = (int)$_GET['id'];
    $role = in_array($_GET['role'], ['Student','Admin']) ? $_GET['role'] : 'Student';
    if ($uid === (int)$_SESSION['user_id']) {
        $msg = 'You cannot change your own role.';
    } else {
        $s = $conn->prepare("UPDATE users SET role=? WHERE user_id=?");
        $s->bind_param("si",$role,$uid); $s->execute(); $s->close();
        header("Location: users.php?msg=".urlencode("User role updated to $role.")); exit;
    }
}

// Reset password
if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='reset_password') {
    $uid     = (int)($_POST['user_id']      ?? 0);
    $new_pwd = trim($_POST['new_password']  ?? '');
    if (strlen($new_pwd) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $hash = password_hash($new_pwd, PASSWORD_DEFAULT);
        $s = $conn->prepare("UPDATE users SET password_hash=? WHERE user_id=?");
        $s->bind_param("si",$hash,$uid); $s->execute(); $s->close();
        header("Location: users.php?msg=".urlencode("Password reset successfully.")); exit;
    }
}

// Toggle verified
if ($action === 'toggle_verify' && isset($_GET['id'])) {
    $uid = (int)$_GET['id'];
    $conn->query("UPDATE users SET is_verified = NOT is_verified WHERE user_id=$uid");
    header("Location: users.php?msg=".urlencode("User verification status updated.")); exit;
}

// Filters
$search     = trim($_GET['q']      ?? '');
$filter_role= trim($_GET['role']   ?? '');
$filter_cid = (int)($_GET['country']?? 0);
$page       = max(1,(int)($_GET['page']??1));
$per_page   = 15; $offset=($page-1)*$per_page;

$where=["1=1"]; $params=[]; $types="";
if($search!==''){
    $where[]="(u.full_name LIKE ? OR u.email LIKE ?)";
    $like="%$search%"; $params[]=$like; $params[]=$like; $types.="ss";
}
if($filter_role!==''){$where[]="u.role=?";$params[]=$filter_role;$types.="s";}
if($filter_cid>0){$where[]="u.home_country_id=?";$params[]=$filter_cid;$types.="i";}
$wsql="WHERE ".implode(" AND ",$where);

$cs=$conn->prepare("SELECT COUNT(*) FROM users u $wsql");
if($types)$cs->bind_param($types,...$params);$cs->execute();$total=$cs->get_result()->fetch_row()[0];$cs->close();
$total_pages=max(1,ceil($total/$per_page));

$stmt=$conn->prepare("
    SELECT u.*,c.country_name,
           COUNT(DISTINCT su.university_id) AS saved_count,
           COUNT(DISTINCT r.review_id)      AS review_count
    FROM users u
    LEFT JOIN countries c  ON c.country_id=u.home_country_id
    LEFT JOIN saved_universities su ON su.user_id=u.user_id
    LEFT JOIN reviews r ON r.user_id=u.user_id
    $wsql
    GROUP BY u.user_id,u.full_name,u.email,u.password_hash,u.role,
             u.home_country_id,u.preferred_currency_code,u.is_verified,
             u.created_at,c.country_name
    ORDER BY u.created_at DESC
    LIMIT ? OFFSET ?
");
$ap=[...$params,$per_page,$offset];$at=$types."ii";
if($at)$stmt->bind_param($at,...$ap);
$stmt->execute();$users=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

// Stats
$rstats=$conn->query("SELECT role,COUNT(*) cnt FROM users GROUP BY role")->fetch_all(MYSQLI_ASSOC);
$role_counts=['Student'=>0,'Admin'=>0];
foreach($rstats as $r)$role_counts[$r['role']]=(int)$r['cnt'];

$ctry_list=$conn->query("SELECT country_id,country_name FROM countries WHERE is_active=1 ORDER BY country_name")->fetch_all(MYSQLI_ASSOC);

$flags=['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$admin_name=explode(' ',$_SESSION['full_name'])[0];
$ac=['linear-gradient(135deg,#6366F1,#8B5CF6)','linear-gradient(135deg,#DC2626,#EF4444)'];
$avatar_color=$ac[$_SESSION['user_id']%count($ac)];
$initial=strtoupper($_SESSION['full_name'][0]??'A');
$msg=$_GET['msg']??$msg;
$av_colors=['#6366F1','#059669','#D97706','#DC2626','#0891B2','#7C3AED'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Users — Veya Admin</title>
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
.topbar-stats{display:flex;gap:16px;}
.ts{font-size:13px;color:#6B7280;font-weight:500;}
.ts strong{color:#0A1628;font-weight:800;}
.content{padding:28px 32px;}
.alert{padding:12px 16px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:20px;}
.alert-ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;}
.alert-err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}

/* ROLE TABS */
.role-tabs{display:flex;gap:8px;margin-bottom:20px;}
.rtab{padding:8px 18px;border-radius:9px;font-size:13px;font-weight:700;border:1px solid #E5E7EB;background:#fff;color:#6B7280;transition:all .15s;display:flex;align-items:center;gap:6px;}
.rtab:hover{border-color:#1A56DB;color:#1A56DB;}
.rtab.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}
.rtab .n{font-size:11px;padding:1px 7px;border-radius:99px;background:rgba(0,0,0,.1);}
.rtab.active .n{background:rgba(255,255,255,.25);}

/* FILTERS */
.filters{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap;align-items:center;}
.fi{font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;color:#374151;background:#fff;border:1px solid #E5E7EB;padding:9px 12px;border-radius:9px;outline:none;cursor:pointer;}
.fi-search{flex:1;min-width:180px;}
.fi-search::placeholder{color:#9CA3AF;font-weight:400;}
.fi-btn{padding:9px 18px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#374151;border:none;border-radius:9px;cursor:pointer;}

/* TABLE */
.table-wrap{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;margin-bottom:20px;}
table{width:100%;border-collapse:collapse;}
thead th{padding:11px 16px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;background:#F9FAFB;border-bottom:1px solid #E5E7EB;}
tbody td{padding:13px 16px;font-size:13px;color:#374151;border-bottom:1px solid #F9FAFB;vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:#FAFBFF;}
.u-av{width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;margin-right:10px;vertical-align:middle;}
.u-name{font-size:14px;font-weight:700;color:#0A1628;display:inline;vertical-align:middle;}
.u-email{font-size:11px;color:#9CA3AF;display:block;margin-top:1px;}
.role-badge{font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;letter-spacing:.05em;}
.role-Student{background:#EFF6FF;color:#1E40AF;}
.role-Admin{background:#FEF3C7;color:#92400E;}
.verify-badge{font-size:10px;font-weight:700;padding:3px 8px;border-radius:99px;}
.v-yes{background:#ECFDF5;color:#065F46;}
.v-no{background:#FEF2F2;color:#991B1B;}

/* ACTION BUTTONS */
.act-wrap{display:flex;flex-wrap:wrap;gap:5px;}
.act-btn{font-size:11px;font-weight:700;padding:5px 10px;border-radius:6px;border:none;cursor:pointer;transition:all .15s;display:inline-block;text-align:center;}
.act-role-a{color:#92400E;background:#FEF3C7;}
.act-role-a:hover{background:#F59E0B;color:#fff;}
.act-role-s{color:#1E40AF;background:#EFF6FF;}
.act-role-s:hover{background:#1A56DB;color:#fff;}
.act-verify{color:#065F46;background:#ECFDF5;}
.act-verify:hover{background:#059669;color:#fff;}
.act-unverify{color:#991B1B;background:#FEF2F2;}
.act-unverify:hover{background:#EF4444;color:#fff;}
.act-pwd{color:#374151;background:#F3F4F6;}
.act-pwd:hover{background:#E5E7EB;}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;padding:28px;width:400px;max-width:90vw;}
.modal-title{font-size:16px;font-weight:800;color:#0A1628;margin-bottom:6px;}
.modal-sub{font-size:13px;color:#9CA3AF;margin-bottom:20px;}
.modal input{width:100%;padding:11px 13px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;color:#0A1628;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:9px;outline:none;margin-bottom:14px;}
.modal input:focus{border-color:#93C5FD;background:#fff;}
.modal-actions{display:flex;gap:10px;}
.modal-save{flex:1;padding:11px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;color:#fff;background:#1A56DB;border:none;border-radius:9px;cursor:pointer;}
.modal-cancel{padding:11px 18px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:600;color:#374151;background:#F3F4F6;border:none;border-radius:9px;cursor:pointer;}

/* PAGINATION */
.pagination{display:flex;justify-content:center;gap:5px;}
.pg-btn{min-width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #E5E7EB;color:#374151;background:#fff;transition:all .15s;}
.pg-btn:hover:not(.disabled):not(.active){border-color:#1A56DB;color:#1A56DB;}
.pg-btn.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}
.pg-btn.disabled{opacity:.35;pointer-events:none;}
</style>
</head>
<body>

<!-- SIDEBAR -->
<?php $admin_active = "users"; include "includes/sidebar.php"; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">👥 Users</div>
    <div class="topbar-right"><div class="topbar-stats" style="display:flex;gap:10px;">
      <span class="ts"><strong><?= $total ?></strong> total</span>
      <span class="ts"><strong><?= $role_counts['Student'] ?></strong> students</span>
      <span class="ts"><strong><?= $role_counts['Admin'] ?></strong> admins</span>
    </div></div>
  </div>

  <div class="content">
    <?php if($msg):   ?><div class="alert alert-ok">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Role tabs -->
    <div class="role-tabs">
      <?php foreach([''=> 'All Users','Student'=>'🎓 Students','Admin'=>'⚙️ Admins'] as $val=>$lbl):
        $cnt = $val==='' ? ($role_counts['Student']+$role_counts['Admin']) : ($role_counts[$val]??0);
      ?>
      <a href="?<?=http_build_query(array_filter(['role'=>$val,'q'=>$search,'country'=>$filter_cid,'page'=>1]))?>"
         class="rtab <?= $filter_role===$val?'active':'' ?>">
        <?=$lbl?><span class="n"><?=$cnt?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="GET" style="display:contents;">
      <?php if($filter_role): ?><input type="hidden" name="role" value="<?= htmlspecialchars($filter_role) ?>"><?php endif; ?>
      <div class="filters">
        <input class="fi fi-search" type="text" name="q" placeholder="Search name or email…" value="<?= htmlspecialchars($search) ?>">
        <select class="fi" name="country" onchange="this.form.submit()">
          <option value="">All Home Countries</option>
          <?php foreach($ctry_list as $c): ?>
            <option value="<?=$c['country_id']?>" <?=$filter_cid==$c['country_id']?'selected':''?>><?=htmlspecialchars($c['country_name'])?></option>
          <?php endforeach; ?>
        </select>
        <button class="fi-btn" type="submit">Search</button>
        <?php if($search||$filter_cid): ?><a href="users.php?role=<?=htmlspecialchars($filter_role)?>" style="font-size:13px;color:#EF4444;font-weight:600;">Clear</a><?php endif; ?>
      </div>
    </form>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>User</th>
            <th>Role</th>
            <th>Home Country</th>
            <th>Currency</th>
            <th>Saved</th>
            <th>Reviews</th>
            <th>Verified</th>
            <th>Joined</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($users)): ?>
            <tr><td colspan="9" style="text-align:center;padding:40px;color:#9CA3AF;">No users found.</td></tr>
          <?php else: foreach($users as $i=>$u):
            $ucolor = $av_colors[$u['user_id']%count($av_colors)];
            $uinit  = strtoupper($u['full_name'][0]??'U');
            $is_me  = $u['user_id'] == (int)$_SESSION['user_id'];
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;">
                <span class="u-av" style="background:<?=$ucolor?>"><?=$uinit?></span>
                <div>
                  <span class="u-name"><?= htmlspecialchars($u['full_name']) ?> <?= $is_me?'<span style="font-size:10px;background:#EFF6FF;color:#1A56DB;padding:1px 6px;border-radius:4px;font-weight:700;">You</span>':'' ?></span>
                  <span class="u-email"><?= htmlspecialchars($u['email']) ?></span>
                </div>
              </div>
            </td>
            <td><span class="role-badge role-<?=$u['role']?>"><?=$u['role']?></span></td>
            <td><?= htmlspecialchars($u['country_name']??'—') ?></td>
            <td><span style="font-size:13px;font-weight:700;color:#374151;"><?= htmlspecialchars($u['preferred_currency_code']) ?></span></td>
            <td><span style="font-size:14px;font-weight:700;color:#0A1628;"><?=$u['saved_count']?></span></td>
            <td><span style="font-size:14px;font-weight:700;color:#0A1628;"><?=$u['review_count']?></span></td>
            <td>
              <span class="verify-badge <?=$u['is_verified']?'v-yes':'v-no'?>">
                <?=$u['is_verified']?'✅ Yes':'❌ No'?>
              </span>
            </td>
            <td style="font-size:12px;color:#9CA3AF;"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td>
              <div class="act-wrap">
                <?php if(!$is_me): ?>
                  <?php if($u['role']==='Student'): ?>
                    <a href="users.php?action=set_role&id=<?=$u['user_id']?>&role=Admin"
                       class="act-btn act-role-a"
                       onclick="return confirm('Make <?=htmlspecialchars($u['full_name'])?> an Admin?')">⚙️ Make Admin</a>
                  <?php else: ?>
                    <a href="users.php?action=set_role&id=<?=$u['user_id']?>&role=Student"
                       class="act-btn act-role-s"
                       onclick="return confirm('Change to Student role?')">🎓 Make Student</a>
                  <?php endif; ?>
                  <a href="users.php?action=toggle_verify&id=<?=$u['user_id']?>"
                     class="act-btn <?=$u['is_verified']?'act-unverify':'act-verify'?>">
                    <?=$u['is_verified']?'🔒 Unverify':'✅ Verify'?>
                  </a>
                <?php endif; ?>
                <button type="button"
                        onclick="openPwdModal(<?=$u['user_id']?>,'<?=htmlspecialchars(addslashes($u['full_name']))?>')"
                        class="act-btn act-pwd">🔑 Reset Pwd</button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if($total_pages>1): ?>
    <nav class="pagination">
      <a href="?<?=http_build_query(array_filter(['q'=>$search,'role'=>$filter_role,'country'=>$filter_cid,'page'=>$page-1]))?>" class="pg-btn <?=$page<=1?'disabled':''?>">‹</a>
      <?php for($p=1;$p<=$total_pages;$p++):
        $qs=http_build_query(array_filter(['q'=>$search,'role'=>$filter_role,'country'=>$filter_cid,'page'=>$p]));
        if($p===1||$p===$total_pages||abs($p-$page)<=1): echo "<a href='?$qs' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        elseif(abs($p-$page)===2): echo "<span style='color:#D1D5DB;padding:0 4px;'>…</span>";
        endif;
      endfor; ?>
      <a href="?<?=http_build_query(array_filter(['q'=>$search,'role'=>$filter_role,'country'=>$filter_cid,'page'=>$page+1]))?>" class="pg-btn <?=$page>=$total_pages?'disabled':''?>">›</a>
    </nav>
    <?php endif; ?>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="pwdModal">
  <div class="modal">
    <div class="modal-title">🔑 Reset Password</div>
    <div class="modal-sub" id="pwdModalSub">Set a new password for this user.</div>
    <form method="POST" action="users.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="pwdUserId">
      <input type="password" name="new_password" placeholder="New password (min 8 characters)" required minlength="8">
      <div class="modal-actions">
        <button type="submit" class="modal-save">Reset Password</button>
        <button type="button" class="modal-cancel" onclick="closePwdModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openPwdModal(uid, name) {
    document.getElementById('pwdUserId').value = uid;
    document.getElementById('pwdModalSub').textContent = 'Set a new password for ' + name + '.';
    document.getElementById('pwdModal').classList.add('open');
}
function closePwdModal() {
    document.getElementById('pwdModal').classList.remove('open');
}
document.getElementById('pwdModal').addEventListener('click', function(e) {
    if (e.target === this) closePwdModal();
});
</script>
</body>
</html>