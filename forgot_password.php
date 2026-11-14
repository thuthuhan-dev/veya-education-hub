<?php
// forgot_password.php — Veya Education Hub
session_start();
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit; }
require_once "db_connect.php";

$step    = 1; // 1=enter email, 2=show token link
$error   = '';
$success = '';
$reset_url = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check user exists
        $s = $conn->prepare("SELECT user_id, full_name FROM users WHERE email=?");
        $s->bind_param("s", $email); $s->execute();
        $user = $s->get_result()->fetch_assoc(); $s->close();

        // Always say "check your email" (security: don't reveal if email exists)
        if ($user) {
            // Delete any old tokens for this email
            $d = $conn->prepare("DELETE FROM password_resets WHERE email=?");
            $d->bind_param("s", $email); $d->execute(); $d->close();

            // Generate a secure token
            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            $i = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?,?,?)");
            $i->bind_param("sss", $email, $token, $expires_at); $i->execute(); $i->close();

            // Build reset URL
            $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            $path = dirname($_SERVER['PHP_SELF']);
            $reset_url = $base . $path . '/reset_password.php?token=' . $token;
        }

        $step = 2;
        $success = 'If that email address is registered, a reset link has been generated.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forgot Password — Veya</title>
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
h1{font-family:'Instrument Serif',serif;font-size:26px;font-weight:400;color:#0A1628;margin-bottom:6px;}
.sub{font-size:14px;color:#6B7280;line-height:1.6;margin-bottom:28px;}
label{display:block;font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#6B7280;margin-bottom:5px;}
input[type=email]{width:100%;padding:12px 14px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;color:#0A1628;background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:10px;outline:none;transition:all .15s;margin-bottom:16px;}
input[type=email]:focus{border-color:#93C5FD;background:#fff;box-shadow:0 0 0 3px rgba(147,197,253,.2);}
.btn{width:100%;padding:13px;font-family:'Plus Jakarta Sans',sans-serif;font-size:15px;font-weight:700;color:#fff;background:#1A56DB;border:none;border-radius:10px;cursor:pointer;transition:background .15s;}
.btn:hover{background:#1E40AF;}
.alert{padding:13px 16px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:20px;display:flex;gap:9px;align-items:flex-start;}
.alert-ok{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;}
.alert-err{background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;}
.back{display:flex;align-items:center;justify-content:center;gap:5px;margin-top:20px;font-size:13px;color:#6B7280;}
.back a{color:#1A56DB;font-weight:700;}
/* Dev notice box */
.dev-box{background:#FEF3C7;border:1px solid #FDE68A;border-radius:10px;padding:16px;margin-top:20px;}
.dev-title{font-size:12px;font-weight:800;color:#92400E;letter-spacing:.05em;text-transform:uppercase;margin-bottom:8px;}
.dev-link{word-break:break-all;font-size:12px;color:#1A56DB;background:#EFF6FF;padding:10px 12px;border-radius:7px;display:block;margin-top:6px;font-family:monospace;}
.dev-note{font-size:11px;color:#B45309;margin-top:8px;line-height:1.5;}
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
    <?php if ($step === 1): ?>
      <div class="icon-wrap">🔐</div>
      <h1>Forgot your password?</h1>
      <p class="sub">Enter your email address and we'll generate a reset link for you.</p>

      <?php if ($error): ?>
        <div class="alert alert-err">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <?= csrf_field() ?>
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email"
               placeholder="you@example.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
        <button type="submit" class="btn">Send Reset Link →</button>
      </form>

    <?php else: ?>
      <div class="icon-wrap">✅</div>
      <h1>Check your email</h1>
      <div class="alert alert-ok"><?= htmlspecialchars($success) ?></div>
      <p class="sub">If the email is registered, use the link below to set a new password. The link expires in <strong>1 hour</strong>.</p>

      <?php if ($reset_url): ?>
      <div class="dev-box">
        <div class="dev-title">🛠 Developer Mode — Reset Link</div>
        <p class="dev-note">On a live server this would be sent by email. Since you're running on XAMPP, copy and open this link:</p>
        <a href="<?= htmlspecialchars($reset_url) ?>" class="dev-link"><?= htmlspecialchars($reset_url) ?></a>
        <p class="dev-note">Remove this box when deploying to production.</p>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="back">
      <span>Remember it?</span>
      <a href="login.php">← Back to login</a>
    </div>
  </div>
</div>
</body>
</html>