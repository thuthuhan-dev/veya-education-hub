<?php
// 404.php — Veya Custom Error Page
// To activate: add this to C:\xampp\htdocs\Veya\.htaccess
//   ErrorDocument 404 /veya/404.php
session_start();
http_response_code(404);
require_once "db_connect.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Page Not Found — Veya</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<?php $nav_active=''; include 'includes/nav.php'; ?>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F8FAFF;-webkit-font-smoothing:antialiased;}
.page{min-height:calc(100vh - 68px);display:flex;align-items:center;justify-content:center;padding:60px 24px;}
.card{background:#fff;border:1px solid #E5E7EB;border-radius:24px;padding:60px 48px;text-align:center;max-width:540px;width:100%;box-shadow:0 8px 40px rgba(10,22,40,.07);}
.num{font-family:'Instrument Serif',serif;font-size:96px;color:#1A56DB;line-height:1;font-weight:400;margin-bottom:8px;letter-spacing:-.04em;}
.emoji-big{font-size:40px;margin-bottom:18px;display:block;}
h1{font-family:'Instrument Serif',serif;font-size:28px;color:#0A1628;font-weight:400;margin-bottom:10px;}
.sub{font-size:15px;color:#6B7280;line-height:1.7;margin-bottom:32px;max-width:380px;margin-left:auto;margin-right:auto;}
.actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
.btn{padding:12px 24px;border-radius:10px;font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;font-weight:700;transition:all .15s;text-decoration:none;}
.btn-p{background:#1A56DB;color:#fff;}
.btn-p:hover{background:#1E40AF;}
.btn-s{background:#fff;color:#374151;border:1.5px solid #E5E7EB;}
.btn-s:hover{background:#F9FAFB;}
.divider{height:1px;background:#F3F4F6;margin:32px 0;}
.sug-title{font-size:12px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#9CA3AF;margin-bottom:14px;}
.sug-links{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
.sug-link{font-size:13px;font-weight:600;color:#1A56DB;background:#EFF6FF;padding:7px 16px;border-radius:8px;transition:background .15s;text-decoration:none;}
.sug-link:hover{background:#DBEAFE;}
</style>
</head>
<body>
<div class="page">
  <div class="card">
    <div class="num">404</div>
    <span class="emoji-big">🗺️</span>
    <h1>Page not found</h1>
    <p class="sub">The page you're looking for doesn't exist or may have moved. Let's get you back on track.</p>
    <div class="actions">
      <a href="index.php"        class="btn btn-p">🏠 Go Home</a>
      <a href="universities.php" class="btn btn-s">Browse Universities</a>
    </div>
    <div class="divider"></div>
    <div class="sug-title">You might be looking for</div>
    <div class="sug-links">
      <a href="universities.php" class="sug-link">🏫 Universities</a>
      <a href="scholarships.php" class="sug-link">🏅 Scholarships</a>
      <a href="programs.php"     class="sug-link">📚 Programs</a>
      <a href="compare.php"      class="sug-link">⚖️ Compare</a>
      <?php if(isset($_SESSION['user_id'])): ?>
      <a href="dashboard.php"    class="sug-link">📊 Dashboard</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
</body>
</html>