<?php
// privacy.php — Veya Education Hub Privacy Policy
session_start();
require_once "db_connect.php";
$nav_active = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Privacy Policy — Veya Education Hub</title>
<?php include 'includes/nav.php'; ?>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;background:#F8FAFF;color:#0A1628;-webkit-font-smoothing:antialiased;}
a{color:#1A56DB;text-decoration:none;}
a:hover{text-decoration:underline;}

/* Hero */
.ph{background:linear-gradient(135deg,#0A1628,#1E3A5F);padding:48px 40px;}
.ph-in{max-width:820px;margin:0 auto;}
.ph-bc{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:12px;display:flex;gap:6px;}
.ph-bc a{color:rgba(255,255,255,.4);}
.ph-bc a:hover{color:#fff;}
.ph-title{font-family:'Instrument Serif',serif;font-size:clamp(26px,4vw,42px);color:#fff;font-weight:400;margin-bottom:8px;}
.ph-sub{font-size:13px;color:rgba(255,255,255,.5);}

/* Content */
.wrap{max-width:820px;margin:0 auto;padding:48px 40px;}
.section{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:28px 32px;margin-bottom:20px;}
.section h2{font-size:16px;font-weight:800;color:#0A1628;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.section p{font-size:14px;color:#374151;line-height:1.8;margin-bottom:12px;}
.section p:last-child{margin-bottom:0;}
.section ul{font-size:14px;color:#374151;line-height:1.8;padding-left:20px;margin-bottom:12px;}
.section ul li{margin-bottom:6px;}
.badge{font-size:11px;font-weight:700;background:#EFF6FF;color:#1E40AF;padding:3px 10px;border-radius:99px;margin-left:8px;}
.highlight{background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:16px 20px;font-size:13px;color:#065F46;margin-top:14px;}
.last-updated{font-size:12px;color:#9CA3AF;margin-top:4px;}

/* Footer */
footer{background:#0A1628;padding:22px 40px;margin-top:48px;}
.ft{max-width:820px;margin:0 auto;display:flex;justify-content:space-between;font-size:12px;color:rgba(255,255,255,.3);flex-wrap:wrap;gap:8px;}
.ft a{color:rgba(255,255,255,.3);}
.ft a:hover{color:rgba(255,255,255,.7);}
@media(max-width:640px){.wrap,.ph{padding:32px 20px;}.section{padding:20px;}
</style>
</head>
<body>

<div class="ph">
  <div class="ph-in">
    <div class="ph-bc"><a href="index.php">Home</a> / <span>Privacy Policy</span></div>
    <div class="ph-title">Privacy Policy</div>
    <div class="ph-sub last-updated">Last updated: <?= date('d F Y') ?></div>
  </div>
</div>

<div class="wrap">

  <div class="section">
    <h2>📋 Overview</h2>
    <p>Veya Education Hub ("Veya", "we", "us") helps international students find universities and scholarships in Thailand, Singapore, and Malaysia. Veya was initially created with Myanmar students in mind and continues to serve students from all countries seeking education abroad. This Privacy Policy explains what personal data we collect, why we collect it, and how you can control it.</p>
    <div class="highlight">
      💡 Short version: We collect only what is needed to run the service. We never sell your data. You can request deletion of your account at any time by contacting us.
    </div>
  </div>

  <div class="section">
    <h2>🗂️ What Data We Collect</h2>
    <ul>
      <li><strong>Account data:</strong> Your full name, email address, and hashed password (we never store your raw password).</li>
      <li><strong>Preferences:</strong> Your home country and preferred currency (MMK, THB, SGD, MYR, USD, EUR).</li>
      <li><strong>Saved items:</strong> Universities and scholarships you choose to save.</li>
      <li><strong>Reviews:</strong> University reviews and ratings you submit voluntarily.</li>
      <li><strong>Session data:</strong> A session cookie is stored in your browser while you are logged in. It contains no personal information — only a session identifier.</li>
      <li><strong>Usage data:</strong> We do not currently track page views, analytics, or browsing behaviour.</li>
    </ul>
  </div>

  <div class="section">
    <h2>🎯 Why We Collect It</h2>
    <ul>
      <li><strong>Account data</strong> — so you can log in and access your saved items and reviews.</li>
      <li><strong>Preferences</strong> — to display tuition fees in your preferred currency and personalise your experience.</li>
      <li><strong>Saved items</strong> — so you can keep track of universities and scholarships you are interested in.</li>
      <li><strong>Reviews</strong> — to help other Myanmar students make informed decisions about universities.</li>
    </ul>
  </div>

  <div class="section">
    <h2>🔒 How We Protect Your Data</h2>
    <ul>
      <li>Passwords are hashed using <strong>bcrypt</strong> — we cannot see your password, even if we wanted to.</li>
      <li>All database queries use <strong>prepared statements</strong> to prevent SQL injection.</li>
      <li>All output is <strong>HTML-escaped</strong> to prevent cross-site scripting (XSS).</li>
      <li>Login is protected with <strong>rate limiting</strong> — accounts are temporarily locked after 5 failed attempts.</li>
      <li>Password reset tokens <strong>expire after 1 hour</strong> and can only be used once.</li>
      <li>Sessions <strong>expire after 30 minutes</strong> of inactivity.</li>
    </ul>
  </div>

  <div class="section">
    <h2>🤝 Data Sharing</h2>
    <p>We <strong>do not sell, rent, or share</strong> your personal data with any third party.</p>
    <p>Your reviews are displayed publicly on the university detail page (other users can see the review content and your star rating, but <strong>not your name or email</strong>). Reviews are shown anonymously.</p>
    <p>University and scholarship information is sourced from publicly available information and official university websites. Veya is not affiliated with any of the listed universities.</p>
  </div>

  <div class="section">
    <h2>🍪 Cookies</h2>
    <p>We use <strong>one session cookie</strong> (named <code>PHPSESSID</code>) to keep you logged in while you use the site. This cookie is deleted when you log out or when your session expires (30 minutes of inactivity).</p>
    <p>We use <strong>localStorage</strong> (not a cookie) to remember your cookie consent choice and your university comparison selections within your browser. This data never leaves your device.</p>
    <p>We do not use advertising cookies, tracking pixels, or third-party analytics.</p>
  </div>

  <div class="section">
    <h2>📅 Data Retention</h2>
    <p>Your account data is retained as long as your account exists. If you request account deletion, all your personal data — including saved universities, saved scholarships, and reviews — is permanently deleted from our systems.</p>
    <p>Failed login attempt records are automatically deleted after 15 minutes.</p>
  </div>

  <div class="section">
    <h2>✅ Your Rights</h2>
    <ul>
      <li><strong>Access:</strong> You can view all your saved items and reviews on your Dashboard.</li>
      <li><strong>Edit:</strong> You can update your name, email, and currency preference on your Profile page.</li>
      <li><strong>Delete reviews:</strong> You can delete any review you wrote from your Dashboard.</li>
      <li><strong>Delete account:</strong> Contact the Veya admin to request full account deletion.</li>
    </ul>
    <div class="highlight">
      To request account deletion or ask any privacy questions, please contact the Veya administrator directly through your institution or program.
    </div>
  </div>

  <div class="section">
    <h2>🔄 Changes to This Policy</h2>
    <p>If we make significant changes to this policy, we will update the "Last updated" date at the top of this page. We recommend checking this page periodically.</p>
    <p>This service is provided for educational purposes as part of a student project. It is hosted locally and is not a commercial product.</p>
  </div>

</div>


<?php include 'includes/footer.php'; ?>

</body>
</html>