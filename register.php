<?php
session_start();
// Redirect if already logged in
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit; }
require_once "db_connect.php";
$errors = [];
$success = false;
$countries = $conn->query("SELECT country_id, country_name FROM countries WHERE is_active=1 ORDER BY country_name");
$currencies = $conn->query("SELECT currency_code, currency_name FROM currencies ORDER BY currency_code");
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST["full_name"] ?? "");
    $email     = trim($_POST["email"] ?? "");
    $password  = $_POST["password"] ?? "";
    $confirm   = $_POST["confirm_password"] ?? "";
    $home_country_id = !empty($_POST["home_country_id"]) ? (int)$_POST["home_country_id"] : null;
    $preferred_currency_code = trim($_POST["preferred_currency_code"] ?? "MMK");
    if ($full_name === "") $errors[] = "Full name is required.";
    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "A valid email address is required.";
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    if ($password !== $confirm) $errors[] = "Passwords do not match.";
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email); $stmt->execute(); $stmt->store_result();
        if ($stmt->num_rows > 0) $errors[] = "An account with this email already exists.";
        $stmt->close();
    }
    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = "Student";
        $stmt = $conn->prepare("INSERT INTO users (full_name, email, password_hash, home_country_id, preferred_currency_code, role) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("sssiss", $full_name, $email, $hash, $home_country_id, $preferred_currency_code, $role);
        if ($stmt->execute()) {
            // Auto-login immediately after registration
            $new_id = $conn->insert_id;
            session_regenerate_id(true);
            $_SESSION['user_id']               = $new_id;
            $_SESSION['full_name']             = $full_name;
            $_SESSION['role']                  = 'Student';
            $_SESSION['preferred_currency_code'] = $preferred_currency_code;
            $stmt->close();
            header("Location: dashboard.php?welcome=1");
            exit;
        } else {
            $errors[] = "Something went wrong. Please try again.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Register — Veya</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,ital,wght@9..144,0,300;9..144,1,300;9..144,0,500&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html,body{min-height:100vh;}
body{
  font-family:'Inter',sans-serif;
  background:#14213D;
  color:#FAF7F1;
  display:grid;
  grid-template-columns:1fr 1fr;
  min-height:100vh;
}
/* ── HERO ── */
.hero{
  position:relative;
  display:flex;
  flex-direction:column;
  justify-content:space-between;
  padding:52px 56px;
  overflow:hidden;
  background:#14213D;
}
.hero-wm{
  position:absolute;inset:0;
  display:flex;align-items:center;justify-content:center;
  pointer-events:none;
}
.hero-wm img{width:76%;opacity:0.055;filter:brightness(0) invert(1);}
.hero::after{
  content:"";position:absolute;bottom:-100px;right:-80px;
  width:360px;height:360px;border-radius:50%;
  background:#1A56DB;opacity:0.09;pointer-events:none;
}
.logo-row{
  position:relative;z-index:2;
  display:flex;align-items:center;gap:12px;
}
.logo-row img{height:44px;filter:brightness(0) invert(1);}
.wordmark strong{
  display:block;font-family:'Fraunces',serif;
  font-weight:500;font-size:22px;letter-spacing:.03em;
}
.wordmark span{
  display:block;font-family:'IBM Plex Mono',monospace;
  font-size:10px;letter-spacing:.12em;color:#1A56DB;text-transform:uppercase;
}
.hero-foot{position:relative;z-index:2;}
.hero-headline{
  font-family:'Fraunces',serif;font-weight:300;font-style:italic;
  font-size:46px;line-height:1.1;margin-bottom:28px;letter-spacing:-.01em;
}
.hero-headline em{font-style:normal;color:#1A56DB;}
.dests{display:flex;gap:10px;flex-wrap:wrap;}
.dest{
  font-family:'IBM Plex Mono',monospace;font-size:11px;
  letter-spacing:.07em;color:rgba(250,247,241,.6);
  border:1px solid rgba(250,247,241,.18);border-radius:2px;
  padding:6px 10px;display:flex;align-items:center;gap:7px;
}
.dest b{
  width:6px;height:6px;border-radius:50%;
  background:#1A56DB;opacity:.8;display:block;
}
/* ── FORM SIDE ── */
.form-side{
  background:#FAF7F1;color:#14213D;
  display:flex;flex-direction:column;justify-content:center;
  padding:60px 64px;
}
.form-head{margin-bottom:36px;}
.form-head h1{
  font-family:'Fraunces',serif;font-weight:500;
  font-size:28px;margin-bottom:8px;
}
.form-head p{font-size:14px;color:#6B7280;line-height:1.6;}
.notice{
  border-radius:4px;padding:12px 14px;font-size:13px;
  margin-bottom:24px;line-height:1.5;border:1px solid;
}
.ok{background:#EBF5EE;color:#2E7D50;border-color:#A7D4B6;}
.er{background:#FAEDEA;color:#8B2A1F;border-color:#E5A89D;}
.er ul{margin:0;padding-left:18px;}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px 20px;}
.full{grid-column:1/-1;}
label{
  display:block;font-size:11px;font-weight:600;
  letter-spacing:.07em;text-transform:uppercase;
  color:#6B7280;margin-bottom:7px;
}
input,select{
  width:100%;padding:11px 13px;
  font-family:'Inter',sans-serif;font-size:14px;color:#14213D;
  background:#fff;border:1px solid #DDD8CF;border-radius:3px;
  transition:border-color .15s,box-shadow .15s;
}
input:focus,select:focus{
  outline:none;border-color:#1A56DB;
  box-shadow:0 0 0 3px rgba(217,118,44,.15);
}
.divider{
  grid-column:1/-1;display:flex;align-items:center;gap:12px;margin-top:2px;
}
.divider::before,.divider::after{content:"";flex:1;height:1px;background:#E4DFD3;}
.divider span{
  font-family:'IBM Plex Mono',monospace;font-size:10px;
  letter-spacing:.1em;color:#9CA3AF;text-transform:uppercase;white-space:nowrap;
}
.full button{
  width:100%;padding:14px;font-family:'Inter',sans-serif;
  font-size:14px;font-weight:600;color:#FAF7F1;background:#14213D;
  border:none;border-radius:3px;cursor:pointer;letter-spacing:.02em;
  transition:background .15s,transform .1s;margin-top:4px;
}
.full button:hover{background:#24365C;}
.full button:active{transform:translateY(1px);}
.login-hint{
  grid-column:1/-1;text-align:center;
  font-size:13px;color:#9CA3AF;
}
.login-hint a{color:#1A56DB;text-decoration:none;font-weight:500;}
/* ── Responsive ── */
@media(max-width:860px){
  body{grid-template-columns:1fr;}
  .hero{padding:40px 32px;min-height:auto;}
  .hero-headline{font-size:34px;}
  .hero-wm{display:none;}
  .form-side{padding:48px 36px;}
}
@media(max-width:500px){
  .form-side{padding:36px 20px;}
  .grid{grid-template-columns:1fr;}
  .full{grid-column:1;}
  .divider,.login-hint{grid-column:1;}
}
</style>
</head>
<body>

<div class="hero">
  <div class="hero-wm"><img src="assets/veya-logo.png" alt=""></div>

  <div class="logo-row">
    <img src="assets/veya-logo.png" alt="Veya">
    <div class="wordmark">
      <strong>Veya</strong>
      <span>Education Hub</span>
    </div>
  </div>

  <div class="hero-foot">
    <h2 class="hero-headline">Find your path<br>to study <em>abroad</em></h2>
    <div class="dests">
      <div class="dest"><b></b>THAILAND · THB</div>
      <div class="dest"><b></b>SINGAPORE · SGD</div>
      <div class="dest"><b></b>MALAYSIA · MYR</div>
    </div>
  </div>
</div>

<div class="form-side">
  <!-- Back to home -->
  <a href="index.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#9CA3AF;margin-bottom:24px;transition:color .15s;text-decoration:none;"
     onmouseover="this.style.color='#1A56DB'" onmouseout="this.style.color='#9CA3AF'">
    ← Back to Homepage
  </a>
  <div class="form-head">
    <h1>Create your account</h1>
    <p>Save universities, compare fees in your currency,<br>and write reviews for students like you.</p>
  </div>

  <?php if ($success): ?>
    <div class="notice ok">Account created! You can now <a href="login.php">log in</a>.</div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="notice er"><ul><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="POST" action="register.php" novalidate>
    <div class="grid">

      <div class="full"><label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name"
          value="<?=htmlspecialchars($_POST['full_name']??'')?>"
          placeholder="e.g. Thu Thu Han" required></div>

      <div class="full"><label for="email">Email address</label>
        <input type="email" id="email" name="email"
          value="<?=htmlspecialchars($_POST['email']??'')?>"
          placeholder="you@example.com" required></div>

      <div><label for="password">Password</label>
        <input type="password" id="password" name="password"
          placeholder="Min. 8 characters" required minlength="8"></div>

      <div><label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password"
          placeholder="Repeat password" required minlength="8"></div>

      <div class="divider"><span>Your preferences</span></div>

      <div><label for="home_country_id">Home country</label>
        <select id="home_country_id" name="home_country_id">
          <option value="">— Not set —</option>
          <?php while($c=$countries->fetch_assoc()): ?>
            <option value="<?=$c['country_id']?>"><?=htmlspecialchars($c['country_name'])?></option>
          <?php endwhile; ?>
        </select></div>

      <div><label for="preferred_currency_code">Show fees in</label>
        <select id="preferred_currency_code" name="preferred_currency_code">
          <?php while($cur=$currencies->fetch_assoc()): ?>
            <option value="<?=$cur['currency_code']?>" <?=$cur['currency_code']==='MMK'?'selected':''?>>
              <?=htmlspecialchars($cur['currency_code'].' — '.$cur['currency_name'])?>
            </option>
          <?php endwhile; ?>
        </select></div>

      <div class="full"><button type="submit">Create account →</button></div>

      <p class="login-hint">Already have an account? <a href="login.php">Log in</a></p>

    </div>
  </form>
</div>

</body>
</html>