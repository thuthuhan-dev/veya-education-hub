<?php
// reset_password.php — Veya Education Hub
session_start();
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit; }
require_once "db_connect.php";

$token   = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error   = '';
$success = '';
$valid   = false;
$email   = '';

// Validate token
if ($token !== '') {
    $s = $conn->prepare("
        SELECT email FROM password_resets
        WHERE token=? AND used=0 AND expires_at > NOW()
        LIMIT 1
    ");
    $s->bind_param("s", $token); $s->execute();
    $row = $s->get_result()->fetch_assoc(); $s->close();
    if ($row) {
        $valid = true;
        $email = $row['email'];
    }
}

if (!$valid && $token !== '') {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

// Handle password update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $new_pwd = $_POST['new_password']     ?? '';
    $cnf_pwd = $_POST['confirm_password'] ?? '';

    if (strlen($new_pwd) < 8)    $error = 'Password must be at least 8 characters.';
    elseif ($new_pwd !== $cnf_pwd) $error = 'Passwords do not match.';
    else {
        $hash = password_hash($new_pwd, PASSWORD_DEFAULT);

        // Update password
        $s = $conn->prepare("UPDATE users SET password_hash=? WHERE email=?");
        $s->bind_param("ss", $hash, $email); $s->execute(); $s->close();

        // Mark token used
        $s = $conn->prepare("UPDATE password_resets SET used=1 WHERE token=?");
        $s->bind_param("s", $token); $s->execute(); $s->close();

        $success = 'Password updated successfully! You can now log in.';
        $valid   = false; // hide the form
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reset Password — Veya</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#F8FAFF;min-height:100vh;display:flex;flex-direction:column;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}
.top-bar{padding:20px 40px;display:flex;align-items:center;}
.logo{display:flex;align-items:center;gap:8px;font-size:18px;font-weight:800;color:#0A1628;letter-spacing:-.02em;}
.logo img{height:26px;}
.center{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px;}
.card{background:#fff;border:1px solid #E5E7EB;border-radius:20px;padding:40px;width:100%;max-width:440px;box-shadow:0 4px 24px rgba(10,22,40,.06);}
.icon-wrap{width:56px;height:56px;border-radius:14px;background:#EFF6FF;display:flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:20px;}
.icon-wrap.success{background:#ECFDF5;}
.icon-wrap.error{background:#FEF2F2;}
h1{font-family:'Instrument Serif',serif;font-size:26px;font-weight:400;color:#0A1628;margin-bottom:6px;}
.sub{font-size:14px;color:#6B7280;line-height:1.6;margin-bottom:28px;}
label{display:block;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280;margin-bottom:5px;}
.field{margin-bottom:16px;}
input[type=password]{width:100%;padding:12px 14px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;color:#0A1628;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:10px;outline:none;transition:all .15s;}
input:focus{border-color:#93C5FD;background:#fff;box-shadow:0 0 0 3px rgba(147,197,253,.2);}
.btn{width:100%;padding:13px;font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:700;color:#fff;background:#1A56DB;border:none;border-radius:10px;cursor:pointer;transition:background .15s;}
.btn:hover{background:#1E40AF;}
.btn-green{background:#059669;}
.btn-green:hover{background:#047857;}
.alert{padding:13px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:20px;display:flex;gap:9px;align-items:flex-start;}
.alert-ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;}
.alert-err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}
.back{display:flex;align-items:center;justify-content:center;gap:5px;margin-top:20px;font-size:13px;color:#6B7280;}
.back a{color:#1A56DB;font-weight:700;}
.pwd-bar{height:4px;border-radius:99px;background:#F3F4F6;overflow:hidden;margin:8px 0 4px;}
.pwd-fill{height:100%;border-radius:99px;transition:width .3s,background .3s;width:0%;}
.pwd-hint{font-size:11px;color:#9CA3AF;}
</style>
</head>
<body>
<div class="top-bar">
  <a href="index.php" class="logo">
    <img src="assets/veya-logo.png" alt="Veya" onerror="this.style.display='none'">
    Veya
  </a>
</div>

<div class="center">
  <div class="card">

    <?php if ($success): ?>
      <div class="icon-wrap success">✅</div>
      <h1>Password updated!</h1>
      <div class="alert alert-ok"><?= htmlspecialchars($success) ?></div>
      <a href="login.php" class="btn btn-green" style="display:block;text-align:center;padding:13px;">→ Go to Login</a>

    <?php elseif (!$valid): ?>
      <div class="icon-wrap error">❌</div>
      <h1>Link expired</h1>
      <?php if ($error): ?>
        <div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <p class="sub">Reset links expire after 1 hour and can only be used once. Request a new link below.</p>
      <a href="forgot_password.php" class="btn" style="display:block;text-align:center;padding:13px;">Request New Link</a>

    <?php else: ?>
      <div class="icon-wrap">🔑</div>
      <h1>Set new password</h1>
      <p class="sub">Resetting password for <strong><?= htmlspecialchars($email) ?></strong>. Choose a strong password.</p>

      <?php if ($error): ?>
        <div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="field">
          <label>New Password</label>
          <input type="password" name="new_password" id="npwd"
                 placeholder="At least 8 characters"
                 oninput="checkStr(this.value)" required minlength="8" autofocus>
          <div class="pwd-bar"><div class="pwd-fill" id="pf"></div></div>
          <div class="pwd-hint" id="ph">Enter a new password</div>
        </div>

        <div class="field">
          <label>Confirm New Password</label>
          <input type="password" name="confirm_password"
                 placeholder="Repeat new password" required minlength="8">
        </div>

        <button type="submit" class="btn">Set New Password →</button>
      </form>
    <?php endif; ?>

    <div class="back">
      <a href="login.php">← Back to login</a>
    </div>
  </div>
</div>

<script>
function checkStr(v) {
    let s = 0;
    if (v.length >= 8) s++; if (v.length >= 12) s++;
    if (/[A-Z]/.test(v)) s++; if (/[0-9]/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    const l = [{w:'0%',bg:'#E5E7EB',t:'Too short'},{w:'20%',bg:'#EF4444',t:'Weak'},
               {w:'40%',bg:'#F59E0B',t:'Fair'},{w:'65%',bg:'#3B82F6',t:'Good'},
               {w:'85%',bg:'#10B981',t:'Strong'},{w:'100%',bg:'#059669',t:'Very strong'}];
    const r = l[Math.min(s, 5)];
    const pf = document.getElementById('pf');
    pf.style.width = r.w; pf.style.background = r.bg;
    const ph = document.getElementById('ph');
    ph.textContent = r.t; ph.style.color = r.bg;
}
</script>
</body>
</html>