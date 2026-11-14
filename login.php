<?php
// login.php — Veya Education Hub
session_start();

// Already logged in → redirect
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php"); exit;
}

require_once "db_connect.php";

// Validate the ?next= parameter — only allow relative paths on this server
// to prevent open redirect attacks (no http://, no //, no external domains)
function safe_next_url($next) {
    if (empty($next)) return null;
    $next = trim($next);
    // Must be a relative path only (no scheme, no double slash at start)
    if (preg_match('~^https?://~i', $next)) return null;
    if (str_starts_with($next, '//'))        return null;
    if (str_starts_with($next, '\\'))        return null;
    // Only allow safe relative path characters
    if (!preg_match('~^[a-zA-Z0-9\/_\-.?=&]+$~', $next)) return null;
    return $next;
}

$next_url = safe_next_url($_GET['next'] ?? $_POST['next'] ?? '');
$timeout_msg = '';
if (isset($_GET['timeout']) || isset($_SESSION['timeout_msg'])) {
    $timeout_msg = $_SESSION['timeout_msg'] ?? 'Your session expired. Please log in again.';
    unset($_SESSION['timeout_msg']);
}

$error         = "";
$success       = "";
$locked_out    = false;   // true while the user must wait before retrying
$locked_minutes = 0;      // whole minutes remaining, shown to the user
$locked_seconds = 0;      // exact seconds remaining, used for the JS countdown

// Check for a "just registered" message passed via URL
if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $success = "Account created successfully. You can now log in.";
}

$precheck_email = trim($_POST['email'] ?? $_GET['email'] ?? $_SESSION['login_last_email'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

function veya_check_lockout($conn, $email, $ip) {
    $q = $conn->prepare(
        "SELECT COUNT(*) AS cnt,
                GREATEST(0, LEAST(900, TIMESTAMPDIFF(
                    SECOND, NOW(), DATE_ADD(MIN(attempted_at), INTERVAL 900 SECOND)
                ))) AS secs_left
         FROM login_attempts
         WHERE (email=? OR ip_address=?)
           AND attempted_at > (NOW() - INTERVAL 900 SECOND)"
    );
    $q->bind_param("ss", $email, $ip);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();

    $count = (int)($row['cnt'] ?? 0);
    $secs  = (int)($row['secs_left'] ?? 0);

    if ($count < 5 || $secs <= 0) return [false, 0];
    return [true, $secs]; // always between 1 and 900 seconds — never more
}

if ($precheck_email !== '') {
    [$locked_out, $locked_seconds] = veya_check_lockout($conn, $precheck_email, $ip);
    $locked_minutes = (int)ceil($locked_seconds / 60);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$locked_out) {
    $email    = trim($_POST["email"]    ?? "");
    $password = trim($_POST["password"] ?? "");

    // --- Basic validation ---
    if ($email === "" || $password === "") {
        $error = "Please enter both your email and password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

        // --- RATE LIMITING: re-check right before processing, in case
        // the 5th failed attempt was logged by a different request
        // that landed a moment ago (race-condition safety) ---
        [$is_locked, $secs] = veya_check_lockout($conn, $email, $ip);

        if ($is_locked) {
            $locked_out     = true;
            $locked_seconds = $secs;
            $locked_minutes = (int)ceil($secs / 60);
        } else {

        // --- Lookup user by email ---
        $stmt = $conn->prepare(
            "SELECT user_id, full_name, password_hash, role, preferred_currency_code, is_verified
             FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            // Log the attempt and give a generic message
            $la = $conn->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?,?)");
            $la->bind_param("ss", $email, $ip); $la->execute(); $la->close();
            $_SESSION['login_last_email'] = $email;
            $error = "Incorrect email or password.";
        } else {
            $user = $result->fetch_assoc();

            if (!password_verify($password, $user['password_hash'])) {
                // Log the failed attempt
                $la = $conn->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?,?)");
                $la->bind_param("ss", $email, $ip); $la->execute(); $la->close();
                $_SESSION['login_last_email'] = $email;
                $error = "Incorrect email or password.";
            } else {
                // SUCCESS — clear login attempts for this email and forget it
                $cl = $conn->prepare("DELETE FROM login_attempts WHERE email=?");
                $cl->bind_param("s", $email); $cl->execute(); $cl->close();
                unset($_SESSION['login_last_email']);

                // --- Regenerate session ID to prevent session fixation ---
                session_regenerate_id(true);
                $_SESSION['last_activity'] = time();

                // --- Store essential data in session ---
                $_SESSION['user_id']                 = $user['user_id'];
                $_SESSION['full_name']               = $user['full_name'];
                $_SESSION['role']                    = $user['role'];
                $_SESSION['preferred_currency_code'] = $user['preferred_currency_code'];

                // Redirect: ?next= takes priority, then role-based default
                if ($next_url) {
                    header("Location: " . $next_url);
                } elseif ($user['role'] === 'Admin') {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: dashboard.php");
                }
                exit;
            }
        }
        $stmt->close();
        } // end rate-limit else
    } // end outer else (valid email)
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in — Veya</title>
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

/* Notices */
.notice{
  border-radius:4px;padding:13px 14px;font-size:13px;
  margin-bottom:24px;line-height:1.5;border:1px solid;
}
.ok{background:#EBF5EE;color:#2E7D50;border-color:#A7D4B6;}
.er{background:#FAEDEA;color:#8B2A1F;border-color:#E5A89D;}

/* Form fields */
.field{margin-bottom:20px;}
label{
  display:block;font-size:11px;font-weight:600;
  letter-spacing:.07em;text-transform:uppercase;
  color:#6B7280;margin-bottom:7px;
}
input{
  width:100%;padding:11px 13px;
  font-family:'Inter',sans-serif;font-size:14px;color:#14213D;
  background:#fff;border:1px solid #DDD8CF;border-radius:3px;
  transition:border-color .15s,box-shadow .15s;
}
input:focus{
  outline:none;border-color:#1A56DB;
  box-shadow:0 0 0 3px rgba(217,118,44,.15);
}

/* Forgot password row */
.field-meta{
  display:flex;justify-content:space-between;align-items:center;margin-bottom:7px;
}
.field-meta label{margin-bottom:0;}
.field-meta a{
  font-size:12px;color:#1A56DB;text-decoration:none;font-weight:500;
}
.field-meta a:hover{text-decoration:underline;}

/* Submit */
button[type="submit"]{
  width:100%;padding:14px;font-family:'Inter',sans-serif;
  font-size:14px;font-weight:600;color:#FAF7F1;background:#14213D;
  border:none;border-radius:3px;cursor:pointer;letter-spacing:.02em;
  transition:background .15s,transform .1s;margin-top:6px;
}
button[type="submit"]:hover{background:#24365C;}
button[type="submit"]:active{transform:translateY(1px);}

/* Divider */
.or-divider{
  display:flex;align-items:center;gap:12px;margin:24px 0;
}
.or-divider::before,.or-divider::after{content:"";flex:1;height:1px;background:#E4DFD3;}
.or-divider span{
  font-family:'IBM Plex Mono',monospace;font-size:10px;
  letter-spacing:.1em;color:#9CA3AF;text-transform:uppercase;white-space:nowrap;
}

/* Register link */
.register-hint{
  text-align:center;font-size:13px;color:#9CA3AF;
}
.register-hint a{color:#1A56DB;text-decoration:none;font-weight:500;}
.register-hint a:hover{text-decoration:underline;}

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
}
</style>
</head>
<body>

<!-- ── Hero Panel ── -->
<div class="hero">
  <div class="hero-wm"><img src="assets/veya-logo.png" alt=""></div>

  <div class="logo-row">
    <img src="assets/veya-logo.png" alt="Veya logo">
    <div class="wordmark">
      <strong>Veya</strong>
      <span>Education Hub</span>
    </div>
  </div>

  <div class="hero-foot">
    <h2 class="hero-headline">Welcome<br>back <em>abroad</em></h2>
    <div class="dests">
      <div class="dest"><b></b>THAILAND · THB</div>
      <div class="dest"><b></b>SINGAPORE · SGD</div>
      <div class="dest"><b></b>MALAYSIA · MYR</div>
    </div>
  </div>
</div>

<!-- ── Form Panel ── -->
<div class="form-side">
  <!-- Back to home -->
  <a href="index.php" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#9CA3AF;margin-bottom:24px;transition:color .15s;text-decoration:none;"
     onmouseover="this.style.color='#1A56DB'" onmouseout="this.style.color='#9CA3AF'">
    ← Back to Homepage
  </a>
  <div class="form-head">
    <h1>Log in to your account</h1>
    <p>Access your saved universities, fee comparisons,<br>and student reviews.</p>
  </div>

  <?php if ($timeout_msg): ?>
    <div class="notice er" style="background:#FEF3C7;border-color:#F59E0B;color:#92400E;">
      ⏱️ <?= htmlspecialchars($timeout_msg) ?>
    </div>
  <?php endif; ?>
  <?php if ($success !== ""): ?>
    <div class="notice ok"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <?php if ($error !== ""): ?>
    <div class="notice er"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($locked_out): ?>
    <div class="notice er" style="background:#FEF2F2;border-color:#FCA5A5;color:#991B1B;">
      🔒 Too many failed login attempts.
      Please wait <strong id="lockCountdown"><?= $locked_minutes ?> minute<?= $locked_minutes===1?'':'s' ?></strong> before trying again,
      or <a href="forgot_password.php" style="color:#991B1B;text-decoration:underline;font-weight:700;">reset your password</a> instead.
    </div>
  <?php endif; ?>

  <form method="POST" action="login.php<?= $next_url ? '?next='.urlencode($next_url) : '' ?>" novalidate>
    <?php if($next_url): ?>
    <input type="hidden" name="next" value="<?= htmlspecialchars($next_url) ?>">
    <?php endif; ?>

    <div class="field">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email"
             value="<?= htmlspecialchars($_POST['email'] ?? $precheck_email) ?>"
             placeholder="you@example.com"
             autocomplete="email" required
             <?= $locked_out ? 'disabled' : '' ?>>
    </div>

    <div class="field">
      <div class="field-meta">
        <label for="password">Password</label>
        <a href="forgot_password.php">Forgot password?</a>
      </div>
      <input type="password" id="password" name="password"
             placeholder="Your password"
             autocomplete="current-password" required
             <?= $locked_out ? 'disabled' : '' ?>>
    </div>

    <button type="submit" id="loginSubmitBtn" <?= $locked_out ? 'disabled' : '' ?>>
      <?= $locked_out ? '🔒 Please wait…' : 'Log in →' ?>
    </button>

  </form>

  <?php if ($locked_out): ?>
  <script>
    // Live countdown so the page visibly shows the wait ticking down,
    // instead of the same static message on every refresh.
    (function () {
      let remaining = <?= (int)$locked_seconds ?>;
      const countEl = document.getElementById('lockCountdown');
      const btn     = document.getElementById('loginSubmitBtn');

      function render() {
        const m = Math.floor(remaining / 60);
        const s = remaining % 60;
        if (countEl) {
          countEl.textContent = m > 0
            ? (m + ' minute' + (m === 1 ? '' : 's') + ' ' + s + 's')
            : (s + ' second' + (s === 1 ? '' : 's'));
        }
      }
      render();

      const timer = setInterval(function () {
        remaining -= 1;
        if (remaining <= 0) {
          clearInterval(timer);
          // Wait is over — reload so PHP re-checks and unlocks the form
          window.location.reload();
          return;
        }
        render();
      }, 1000);
    })();
  </script>
  <?php endif; ?>

  <div class="or-divider"><span>New to Veya?</span></div>

  <p class="register-hint">
    Don't have an account? <a href="register.php">Create one — it's free</a>
  </p>
</div>

</body>
</html>