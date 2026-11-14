<?php
// contact.php — Veya Education Hub Contact Us
session_start();
require_once "db_connect.php";

// ── Table setup ───────────────────────────────────────────────────────
// PHP 8 mysqli throws exceptions on SQL errors, so every setup query is
// wrapped in try/catch. A failure here must never break the page.
try {
    $conn->query("CREATE TABLE IF NOT EXISTS inquiries (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT DEFAULT 0,
        name        VARCHAR(200) NOT NULL,
        email       VARCHAR(255) NOT NULL,
        subject     VARCHAR(255) NOT NULL,
        message     TEXT         NOT NULL,
        status      ENUM('New','Read','Replied') DEFAULT 'New',
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) { /* table already exists - fine */ }

// If an older version of this table exists without user_id, add it.
// Checked via information_schema so no error is ever triggered.
try {
    $colq = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE()
                            AND TABLE_NAME   = 'inquiries'
                            AND COLUMN_NAME  = 'user_id'");
    if ($colq && (int)$colq->fetch_row()[0] === 0) {
        $conn->query("ALTER TABLE inquiries ADD COLUMN user_id INT DEFAULT 0 AFTER id");
    }
} catch (Throwable $e) { /* ignore - column check is best effort */ }

$success     = false;
$errors      = [];
$is_logged   = isset($_SESSION['user_id']);
$user_id     = $is_logged ? (int)$_SESSION['user_id'] : 0;
$user_name   = $_SESSION['full_name'] ?? '';
$rate_limited = false;
$messages_today = 0;

$is_admin = $is_logged && ($_SESSION['role'] ?? '') === 'Admin';

// Rate limit check (only for logged-in users). Wrapped so a DB hiccup
// can never take the whole page down.
if ($is_logged) {
    try {
        $rate_window = date('Y-m-d H:i:s', time() - 86400);
        $rc = $conn->prepare("SELECT COUNT(*) FROM inquiries WHERE user_id=? AND created_at > ?");
        $rc->bind_param("is", $user_id, $rate_window);
        $rc->execute();
        $messages_today = (int)$rc->get_result()->fetch_row()[0];
        $rc->close();
    } catch (Throwable $e) {
        $messages_today = 0;
    }
    $rate_limited = $messages_today >= 3;
}

// Handle POST (only for logged-in users)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_logged) {
    if (!csrf_verify()) {
        $errors[] = 'Security error. Please refresh and try again.';
    } elseif ($rate_limited) {
        $errors[] = 'You have sent 3 messages in the last 24 hours. Please wait before sending another.';
    } else {
        $name    = trim($_POST['name']    ?? '');
        $email   = trim($_POST['email']   ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (empty($name))                               $errors[] = 'Your name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        if (empty($subject))                            $errors[] = 'Please enter a subject.';
        if (strlen($subject) > 255)                     $errors[] = 'Subject too long (max 255 characters).';
        if (strlen($message) < 10)                      $errors[] = 'Message must be at least 10 characters.';
        if (strlen($message) > 3000)                    $errors[] = 'Message too long (max 3000 characters).';

        if (empty($errors)) {
            try {
                $s = $conn->prepare("INSERT INTO inquiries (user_id,name,email,subject,message) VALUES (?,?,?,?,?)");
                $s->bind_param("issss", $user_id, $name, $email, $subject, $message);
                if ($s->execute()) { $success = true; $messages_today++; }
                else $errors[] = 'Something went wrong. Please try again.';
                $s->close();
            } catch (Throwable $e) {
                $errors[] = 'Unable to send your message right now. Please try again later.';
            }
        }
    }
}

$messages_left  = max(0, 3 - $messages_today);
$prefill_name   = $_POST['name']    ?? $user_name;
$prefill_email  = $_POST['email']   ?? '';
$prefill_sub    = $_POST['subject'] ?? '';
$prefill_msg    = $_POST['message'] ?? '';
$nav_active = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Contact Us — Veya Education Hub</title>
<?php include 'includes/nav.php'; ?>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#F8FAFF;color:#0A1628;-webkit-font-smoothing:antialiased;display:flex;flex-direction:column;min-height:100vh;}
a{text-decoration:none;color:#1A56DB;}
a:hover{text-decoration:underline;}
main{flex:1;}
.ph{background:linear-gradient(135deg,#0A1628,#1E3A5F);padding:48px 40px;}
.ph-in{max-width:680px;margin:0 auto;}
.ph-bc{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:12px;display:flex;gap:6px;}
.ph-bc a{color:rgba(255,255,255,.4);}
.ph-title{font-family:'Instrument Serif',serif;font-size:clamp(26px,4vw,40px);color:#fff;font-weight:400;margin-bottom:8px;}
.ph-sub{font-size:13px;color:rgba(255,255,255,.5);}
.wrap{max-width:680px;margin:0 auto;padding:48px 40px;}
.card{background:#fff;border:1px solid #E5E7EB;border-radius:16px;padding:36px;}
.info-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px;}
.info-card{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:16px;}
.info-card .ico{font-size:20px;margin-bottom:6px;}
.info-card .ttl{font-size:12px;font-weight:700;color:#0A1628;margin-bottom:3px;}
.info-card .val{font-size:12px;color:#6B7280;line-height:1.5;}
.field{margin-bottom:18px;}
.field label{display:block;font-size:13px;font-weight:700;color:#0A1628;margin-bottom:6px;}
.field input,.field textarea{
  width:100%;padding:11px 14px;
  font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;color:#0A1628;
  background:#F9FAFB;border:1.5px solid #E5E7EB;border-radius:9px;
  transition:border-color .15s;outline:none;
}
.field input:focus,.field textarea:focus{border-color:#1A56DB;background:#fff;}
.field textarea{resize:vertical;min-height:120px;}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.char-count{font-size:11px;color:#9CA3AF;text-align:right;margin-top:4px;}
.char-count.warn{color:#EF4444;}
.err{background:#FEF2F2;border:1px solid #FCA5A5;border-radius:9px;padding:12px 16px;font-size:13px;color:#DC2626;margin-bottom:18px;}
.ok{background:#F0FDF4;border:1px solid #86EFAC;border-radius:14px;padding:24px;text-align:center;margin-bottom:8px;}
.ok h3{font-size:17px;font-weight:800;color:#065F46;margin-bottom:8px;}
.ok p{font-size:13px;color:#065F46;line-height:1.7;}
.rate-warn{background:#FFFBEB;border:1px solid #FDE68A;border-radius:9px;padding:11px 14px;font-size:13px;color:#92400E;margin-bottom:18px;}
.btn{width:100%;padding:13px;background:#1A56DB;color:#fff;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;border:none;border-radius:10px;cursor:pointer;transition:background .15s;}
.btn:hover{background:#1E40AF;}
.btn:disabled{background:#93C5FD;cursor:not-allowed;}
@media(max-width:640px){.wrap,.ph{padding:32px 20px;}.card{padding:22px;}.field-row,.info-row{grid-template-columns:1fr;}}
</style>
</head>
<body>
<main>

<div class="ph">
  <div class="ph-in">
    <div class="ph-bc"><a href="index.php">Home</a> / <span>Contact Us</span></div>
    <div class="ph-title">Contact Us</div>
    <div class="ph-sub">Have a question? Send us a message and we'll reply to your email.</div>
  </div>
</div>

<div class="wrap">

  <div class="info-row">
    <div class="info-card">
      <div class="ico">⏱️</div>
      <div class="ttl">Response Time</div>
      <div class="val">We aim to reply within 2–3 business days to your email address.</div>
    </div>
    <div class="info-card">
      <div class="ico">📩</div>
      <div class="ttl">Messages Left Today</div>
      <div class="val">
        <?php if ($rate_limited): ?>
          <span style="color:#EF4444;font-weight:700;">Limit reached (3/3)</span>. Try again in 24 hours.
        <?php else: ?>
          <span style="font-weight:700;color:#065F46;"><?= $messages_left ?> of 3</span> messages remaining today.
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <?php if (!$is_logged): ?>
    <!-- Guest: page visible but show login prompt instead of form -->
    <div style="text-align:center;padding:32px 16px;">
      <div style="font-size:48px;margin-bottom:16px;">📬</div>
      <h3 style="font-size:18px;font-weight:800;color:#0A1628;margin-bottom:10px;">Log in to send a message</h3>
      <p style="font-size:14px;color:#6B7280;line-height:1.7;max-width:380px;margin:0 auto 24px;">
        You need a free Veya account to contact us. This helps us reply to the right person and prevents spam.
      </p>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
        <a href="login.php?next=contact.php"
           style="padding:12px 24px;background:#1A56DB;color:#fff;font-size:14px;font-weight:700;border-radius:10px;text-decoration:none;transition:background .15s;"
           onmouseover="this.style.background='#1E40AF'" onmouseout="this.style.background='#1A56DB'">
          Log In →
        </a>
        <a href="register.php"
           style="padding:12px 24px;background:#F3F4F6;color:#374151;font-size:14px;font-weight:700;border-radius:10px;text-decoration:none;transition:background .15s;"
           onmouseover="this.style.background='#E5E7EB'" onmouseout="this.style.background='#F3F4F6'">
          Create Free Account
        </a>
      </div>
      <p style="font-size:12px;color:#9CA3AF;margin-top:20px;">
        Have questions? Browse our <a href="universities.php" style="color:#1A56DB;">universities</a> or <a href="scholarships.php" style="color:#1A56DB;">scholarships</a> pages first.
      </p>
    </div>
    <?php elseif ($is_admin): ?>
    <!-- Admin: sending a message to yourself makes no sense - point to the inbox -->
    <div style="text-align:center;padding:32px 16px;">
      <div style="font-size:48px;margin-bottom:16px;">🛠️</div>
      <h3 style="font-size:18px;font-weight:800;color:#0A1628;margin-bottom:10px;">You are logged in as an administrator</h3>
      <p style="font-size:14px;color:#6B7280;line-height:1.7;max-width:400px;margin:0 auto 24px;">
        This form is for students to contact the Veya team. As an admin, you read and reply to
        those messages from the admin inbox instead.
      </p>
      <a href="admin/inquiries.php"
         style="padding:12px 24px;background:#1A56DB;color:#fff;font-size:14px;font-weight:700;border-radius:10px;text-decoration:none;transition:background .15s;"
         onmouseover="this.style.background='#1E40AF'" onmouseout="this.style.background='#1A56DB'">
        📬 Open Admin Inbox →
      </a>
    </div>

    <?php elseif ($success): ?>
      <div class="ok">
        <div style="font-size:40px;margin-bottom:10px;">✅</div>
        <h3>Message sent successfully!</h3>
        <p>
          Thank you, <?= htmlspecialchars(explode(' ', $user_name)[0]) ?>. We've received your message and will reply
          to <strong><?= htmlspecialchars($prefill_email ?: 'your email') ?></strong> within 2–3 business days.
        </p>
        <?php if ($messages_left > 0): ?>
          <p style="margin-top:8px;color:#6B7280;font-size:12px;">You can send <?= $messages_left ?> more message<?=$messages_left!==1?'s':''?> today if needed.</p>
        <?php else: ?>
          <p style="margin-top:8px;color:#6B7280;font-size:12px;">You've reached today's limit. You can send more messages in 24 hours.</p>
        <?php endif; ?>
      </div>
      <div style="text-align:center;margin-top:12px;display:flex;gap:12px;justify-content:center;">
        <a href="index.php" style="font-size:13px;font-weight:700;color:#1A56DB;">← Homepage</a>
        <a href="universities.php" style="font-size:13px;font-weight:700;color:#1A56DB;">Browse Universities →</a>
      </div>

    <?php else: ?>

      <?php if ($rate_limited): ?>
        <div class="rate-warn">
          ⏰ You have reached the daily limit of 3 messages. Please wait 24 hours before sending another message. If this is urgent, please try again tomorrow.
        </div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="err">
          <?php foreach($errors as $e): ?>
            <div>⚠️ <?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="contact.php" <?= $rate_limited ? 'style="opacity:.6;pointer-events:none;"' : '' ?>>
        <?= csrf_field() ?>

        <div class="field-row">
          <div class="field">
            <label for="ct_name">Your Name *</label>
            <input type="text" id="ct_name" name="name"
                   value="<?= htmlspecialchars($prefill_name) ?>"
                   placeholder="Full name" maxlength="200" required
                   <?= $rate_limited ? 'disabled' : '' ?>>
          </div>
          <div class="field">
            <label for="ct_email">Reply-to Email *</label>
            <input type="email" id="ct_email" name="email"
                   value="<?= htmlspecialchars($prefill_email) ?>"
                   placeholder="We'll reply here" maxlength="255" required
                   <?= $rate_limited ? 'disabled' : '' ?>>
            <div style="font-size:11px;color:#9CA3AF;margin-top:4px;">Enter the email address you want our reply sent to.</div>
          </div>
        </div>

        <div class="field">
          <label for="ct_sub">Subject *</label>
          <input type="text" id="ct_sub" name="subject"
                 value="<?= htmlspecialchars($prefill_sub) ?>"
                 placeholder="e.g. Question about NUS scholarship" maxlength="255" required
                 <?= $rate_limited ? 'disabled' : '' ?>>
        </div>

        <div class="field">
          <label for="ct_msg">Message * <span style="font-size:11px;color:#9CA3AF;font-weight:400;">(10–3000 characters)</span></label>
          <textarea id="ct_msg" name="message" maxlength="3000"
                    placeholder="Write your question or feedback here…" required
                    oninput="updateCount(this)"
                    <?= $rate_limited ? 'disabled' : '' ?>><?= htmlspecialchars($prefill_msg) ?></textarea>
          <div id="charCount" class="char-count">0 / 3000</div>
        </div>

        <button type="submit" class="btn" <?= $rate_limited ? 'disabled' : '' ?>>
          Send Message →
        </button>
        <p style="font-size:12px;color:#9CA3AF;text-align:center;margin-top:12px;">
          By submitting, you agree to our <a href="privacy.php" style="color:#1A56DB;">Privacy Policy</a>.
          Max 3 messages per 24 hours.
        </p>
      </form>

    <?php endif; ?>
  </div>
</div>
</main>

<?php include 'includes/footer.php'; ?>

<script>
function updateCount(el) {
  const count = el.value.length;
  const el2   = document.getElementById('charCount');
  el2.textContent = count + ' / 3000';
  el2.classList.toggle('warn', count > 2800);
}
// Init count on load
const ta = document.getElementById('ct_msg');
if (ta) updateCount(ta);
</script>
</body>
</html>