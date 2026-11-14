<?php
// profile.php — Veya Education Hub
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?next=profile.php"); exit;
}
require_once "db_connect.php";
$user_id = (int)$_SESSION['user_id'];
$success = ''; $error = '';

if ($_SESSION['csrf_token'] ?? '' === '') csrf_token(); // ensure token exists
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check on all profile form submissions
    if (!csrf_verify()) {
        die('<p style="font-family:sans-serif;padding:40px;color:#DC2626;">Security error: invalid request. Please go back and try again.</p>');
    }
    $act = $_POST['action'] ?? '';

    if ($act === 'update_currency') {
        $cur = trim($_POST['currency'] ?? '');
        if (in_array($cur, ['MMK','THB','SGD','MYR','USD','EUR'])) {
            $s = $conn->prepare("UPDATE users SET preferred_currency_code=? WHERE user_id=?");
            $s->bind_param("si",$cur,$user_id); $s->execute(); $s->close();
            $_SESSION['preferred_currency_code'] = $cur;
            header("Location: dashboard.php"); exit;
        }
    }

    if ($act === 'update_profile') {
        $name    = trim($_POST['full_name'] ?? '');
        $cid     = (int)($_POST['home_country_id'] ?? 0) ?: null;
        $cur     = trim($_POST['preferred_currency_code'] ?? 'MMK');
        if ($name === '') { $error = 'Full name cannot be empty.'; }
        elseif (!in_array($cur, ['MMK','THB','SGD','MYR','USD','EUR'])) { $error = 'Invalid currency.'; }
        else {
            $s = $conn->prepare("UPDATE users SET full_name=?,home_country_id=?,preferred_currency_code=? WHERE user_id=?");
            $s->bind_param("sisi",$name,$cid,$cur,$user_id);
            if ($s->execute()) { $_SESSION['full_name']=$name; $_SESSION['preferred_currency_code']=$cur; $success='Profile updated!'; }
            else { $error='Update failed. Please try again.'; }
            $s->close();
        }
    }

    if ($act === 'change_password') {
        $cur_pwd = $_POST['current_password'] ?? '';
        $new_pwd = $_POST['new_password']     ?? '';
        $cnf_pwd = $_POST['confirm_password'] ?? '';
        if ($cur_pwd === '')       { $error = 'Current password is required.'; }
        elseif (strlen($new_pwd)<8){ $error = 'New password must be at least 8 characters.'; }
        elseif ($new_pwd!==$cnf_pwd){ $error = 'New passwords do not match.'; }
        else {
            $s = $conn->prepare("SELECT password_hash FROM users WHERE user_id=?");
            $s->bind_param("i",$user_id); $s->execute();
            $hash = $s->get_result()->fetch_assoc()['password_hash']; $s->close();
            if (!password_verify($cur_pwd,$hash)) { $error = 'Current password is incorrect.'; }
            else {
                $nh = password_hash($new_pwd, PASSWORD_DEFAULT);
                $s = $conn->prepare("UPDATE users SET password_hash=? WHERE user_id=?");
                $s->bind_param("si",$nh,$user_id);
                if ($s->execute()) $success = 'Password changed successfully!';
                else $error = 'Failed to update password.';
                $s->close();
            }
        }
    }
}

// Fresh user data
$s = $conn->prepare("SELECT u.*,c.country_name FROM users u LEFT JOIN countries c ON c.country_id=u.home_country_id WHERE u.user_id=?");
$s->bind_param("i",$user_id); $s->execute();
$user = $s->get_result()->fetch_assoc(); $s->close();

$countries = $conn->query("SELECT country_id,country_name FROM countries WHERE is_active=1 ORDER BY country_name");
$pref_cur  = $user['preferred_currency_code'] ?? 'MMK';

$av_colors=['#6366F1','#059669','#D97706','#DC2626','#0891B2','#7C3AED'];
$avatar_color=$av_colors[$user_id%count($av_colors)];
$initial=strtoupper($user['full_name'][0]??'U');
$nav_active='dashboard'; // highlight Dashboard in nav since Profile is sub-page
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Profile — Veya</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F8FAFF;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
/* HEADER */
.ph{background:linear-gradient(135deg,#0A1628,#1E3A5F);padding:36px 40px;position:relative;overflow:hidden;}
.ph::before{content:"";position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle,rgba(26,86,219,.25),transparent 70%);}
.ph-in{max-width:760px;margin:0 auto;position:relative;z-index:2;}
.ph-bc{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:14px;display:flex;gap:7px;}
.ph-bc a{color:rgba(255,255,255,.4);transition:color .15s;}
.ph-bc a:hover{color:#fff;}
.ph-bc span{color:rgba(255,255,255,.2);}
.ph-row{display:flex;align-items:center;gap:14px;}
.ph-av{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff;border:3px solid rgba(255,255,255,.3);}
.ph-name{font-family:'Instrument Serif',serif;font-size:24px;color:#fff;font-weight:400;margin-bottom:2px;}
.ph-email{font-size:13px;color:rgba(255,255,255,.5);}
/* WRAP */
.wrap{max-width:760px;margin:0 auto;padding:28px 40px 60px;}
/* ALERT */
.alert{padding:13px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:20px;display:flex;align-items:center;gap:9px;}
.alert-ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;}
.alert-err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}
/* CARDS */
.pcard{background:#fff;border:1px solid #E5E7EB;border-radius:14px;margin-bottom:20px;overflow:hidden;}
.pcard-head{padding:18px 24px 0;border-bottom:1px solid #F3F4F6;display:flex;align-items:center;gap:10px;padding-bottom:14px;}
.pcard-icon{font-size:20px;}
.pcard-title{font-size:15px;font-weight:800;color:#0A1628;}
.pcard-sub{font-size:12px;color:#9CA3AF;margin-top:1px;}
.pcard-body{padding:20px 24px 24px;}
/* FIELDS */
.fg{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.fg.full{grid-template-columns:1fr;}
.field{margin-bottom:0;}
.field.full{grid-column:1/-1;}
label{display:block;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280;margin-bottom:5px;}
input[type=text],input[type=email],input[type=password],select{width:100%;padding:11px 13px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;color:#0A1628;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:9px;outline:none;transition:border-color .15s,box-shadow .15s;}
input:focus,select:focus{border-color:#93C5FD;background:#fff;box-shadow:0 0 0 3px rgba(147,197,253,.2);}
input:disabled{opacity:.5;cursor:not-allowed;background:#F3F4F6;}
.btn-save{margin-top:16px;display:inline-flex;align-items:center;gap:7px;padding:11px 26px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;color:#fff;background:#1A56DB;border:none;border-radius:9px;cursor:pointer;transition:background .15s,transform .1s;}
.btn-save:hover{background:#1E40AF;transform:translateY(-1px);}
.btn-save.green{background:#059669;}
.btn-save.green:hover{background:#047857;}
/* PASSWORD STRENGTH */
.pwd-bar{height:4px;border-radius:99px;background:#F3F4F6;overflow:hidden;margin:8px 0 4px;}
.pwd-fill{height:100%;border-radius:99px;transition:width .3s,background .3s;width:0%;}
.pwd-hint{font-size:11px;color:#9CA3AF;}
/* CURRENCY GRID */
.cur-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:4px;}
.cur-opt{text-align:center;padding:10px 4px;border:1.5px solid #E5E7EB;border-radius:9px;font-family:'Plus Jakarta Sans',sans-serif;font-size:12px;font-weight:700;color:#374151;cursor:pointer;background:#F9FAFB;transition:all .15s;}
.cur-opt:hover{border-color:#1A56DB;color:#1A56DB;background:#EFF6FF;}
.cur-opt.sel{background:#1A56DB;border-color:#1A56DB;color:#fff;}
/* INFO GRID */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.info-item{background:#F9FAFB;border-radius:9px;padding:12px;}
.info-lbl{font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#9CA3AF;margin-bottom:3px;}
.info-val{font-size:14px;font-weight:700;color:#0A1628;}
@media(max-width:640px){.fg{grid-template-columns:1fr;}.wrap{padding:20px;}.ph{padding:28px 20px;}.info-grid{grid-template-columns:1fr;}.cur-grid{grid-template-columns:repeat(2,1fr);}}
</style>
</head>
<body>
<?php $nav_active = 'dashboard'; include 'includes/nav.php'; ?>

<div class="ph">
  <div class="ph-in">
    <div class="ph-bc"><a href="index.php">Home</a><span>/</span><a href="dashboard.php">Dashboard</a><span>/</span><span>Profile</span></div>
    <div class="ph-row">
      <div class="ph-av" style="background:<?= $avatar_color ?>"><?= $initial ?></div>
      <div>
        <div class="ph-name"><?= htmlspecialchars($user['full_name']) ?></div>
        <div class="ph-email"><?= htmlspecialchars($user['email']) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="wrap">
  <?php if($success): ?><div class="alert alert-ok">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if($error):   ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- ── 1. PERSONAL INFO ── -->
  <div class="pcard">
    <div class="pcard-head">
      <span class="pcard-icon">👤</span>
      <div><div class="pcard-title">Personal Information</div><div class="pcard-sub">Update your name, home country, and preferred currency</div></div>
    </div>
    <div class="pcard-body">
      <form method="POST">
        <input type="hidden" name="action" value="update_profile">
        <?= csrf_field() ?>
        <div class="fg">
          <div class="field full">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required placeholder="Your full name">
          </div>
          <div class="field full">
            <label>Email Address (cannot be changed)</label>
            <input type="email" value="<?= htmlspecialchars($user['email']) ?>" disabled>
          </div>
          <div class="field">
            <label>Home Country</label>
            <select name="home_country_id">
              <option value="">— Not set —</option>
              <?php while($c=$countries->fetch_assoc()): ?>
                <option value="<?=$c['country_id']?>" <?=$user['home_country_id']==$c['country_id']?'selected':''?>>
                  <?= htmlspecialchars($c['country_name']) ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="field">
            <label>Preferred Currency</label>
            <select name="preferred_currency_code">
              <?php foreach(['MMK'=>'Myanmar Kyat','THB'=>'Thai Baht','SGD'=>'Singapore Dollar','MYR'=>'Malaysian Ringgit','USD'=>'US Dollar','EUR'=>'Euro'] as $code=>$name): ?>
                <option value="<?=$code?>" <?=$pref_cur===$code?'selected':''?>><?=$code?> — <?=$name?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button type="submit" class="btn-save">💾 Save Profile</button>
      </form>
    </div>
  </div>

  <!-- ── 2. CHANGE PASSWORD ── -->
  <div class="pcard">
    <div class="pcard-head">
      <span class="pcard-icon">🔒</span>
      <div><div class="pcard-title">Change Password</div><div class="pcard-sub">Use at least 8 characters — mix letters, numbers, and symbols</div></div>
    </div>
    <div class="pcard-body">
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <?= csrf_field() ?>
        <div class="fg">
          <div class="field full">
            <label>Current Password</label>
            <input type="password" name="current_password" placeholder="Enter your current password" required>
          </div>
          <div class="field">
            <label>New Password</label>
            <input type="password" name="new_password" id="npwd" placeholder="Min. 8 characters" oninput="checkStr(this.value)" required minlength="8">
            <div class="pwd-bar"><div class="pwd-fill" id="pf"></div></div>
            <div class="pwd-hint" id="ph">Enter a new password above</div>
          </div>
          <div class="field">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" placeholder="Repeat new password" required minlength="8">
          </div>
        </div>
        <button type="submit" class="btn-save">🔒 Update Password</button>
      </form>
    </div>
  </div>

  <!-- ── 3. CURRENCY QUICK SET ── -->
  <div class="pcard">
    <div class="pcard-head">
      <span class="pcard-icon">💱</span>
      <div><div class="pcard-title">Fee Display Currency</div><div class="pcard-sub">All university fees will be shown in your chosen currency</div></div>
    </div>
    <div class="pcard-body">
      <form method="POST" action="profile.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_currency">
        <input type="hidden" name="currency" id="cur-val" value="<?= htmlspecialchars($pref_cur) ?>">
        <div class="cur-grid">
          <?php foreach(['MMK'=>'Myanmar Kyat','THB'=>'Thai Baht','SGD'=>'S$ Dollar','MYR'=>'Ringgit','USD'=>'US Dollar','EUR'=>'Euro'] as $code=>$name): ?>
            <button type="button" onclick="pickCur('<?=$code?>',this)"
                    class="cur-opt <?=$pref_cur===$code?'sel':''?>">
              <div style="font-size:14px;margin-bottom:2px;"><?=$code?></div>
              <div style="font-size:9px;opacity:.65;font-weight:500;"><?=$name?></div>
            </button>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn-save green" style="margin-top:12px;">💾 Save Currency</button>
      </form>
    </div>
  </div>

  <!-- ── 4. ACCOUNT INFO ── -->
  <div class="pcard">
    <div class="pcard-head">
      <span class="pcard-icon">ℹ️</span>
      <div><div class="pcard-title">Account Information</div><div class="pcard-sub">Read-only overview of your Veya account</div></div>
    </div>
    <div class="pcard-body">
      <div class="info-grid">
        <?php foreach([
          'Role'        => ucfirst(strtolower($user['role'])),
          'Email'       => $user['email'],
          'Member Since'=> date('d M Y', strtotime($user['created_at'] ?? 'now')),
          'Home Country'=> $user['country_name'] ?? 'Not set',
          'Currency'    => $pref_cur,
          'Verified'    => $user['is_verified'] ? '✅ Verified' : '⏳ Pending',
        ] as $lbl=>$val): ?>
        <div class="info-item">
          <div class="info-lbl"><?=$lbl?></div>
          <div class="info-val"><?= htmlspecialchars($val) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>

<script>
function pickCur(code,btn){
  document.querySelectorAll('.cur-opt').forEach(b=>b.classList.remove('sel'));
  btn.classList.add('sel');
  document.getElementById('cur-val').value=code;
}
function checkStr(v){
  let s=0;
  if(v.length>=8)s++;if(v.length>=12)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
  const l=[{w:'0%',bg:'#E5E7EB',t:'Too short'},{w:'20%',bg:'#EF4444',t:'Weak'},{w:'40%',bg:'#F59E0B',t:'Fair'},{w:'65%',bg:'#3B82F6',t:'Good'},{w:'85%',bg:'#10B981',t:'Strong'},{w:'100%',bg:'#059669',t:'Very strong'}];
  const r=l[Math.min(s,5)];
  const pf=document.getElementById('pf');pf.style.width=r.w;pf.style.background=r.bg;
  const ph=document.getElementById('ph');ph.textContent=r.t;ph.style.color=r.bg;
}
</script>
</body>
</html>