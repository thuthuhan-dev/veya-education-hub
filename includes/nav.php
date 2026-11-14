<?php
// includes/nav.php — Shared navigation for all student-facing pages
// Usage: include 'includes/nav.php'; (or '../includes/nav.php' from subdirs)
// Set $nav_active = 'home'|'universities'|'scholarships'|'compare'|'dashboard' before including

if (!isset($nav_active)) $nav_active = '';

$is_logged_in = isset($_SESSION['user_id']);
$nav_name     = $_SESSION['full_name']   ?? '';
$nav_role     = $_SESSION['role']        ?? '';
$nav_uid      = $_SESSION['user_id']     ?? 0;
$nav_initial  = strtoupper($nav_name[0] ?? 'U');

$av_colors = ['#6366F1','#059669','#D97706','#DC2626','#0891B2','#7C3AED'];
$nav_avatar_color = $av_colors[$nav_uid % count($av_colors)];
$nav_first_name = explode(' ', $nav_name)[0] ?? '';

// Detect if we're in a subdirectory (admin/)
$base = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../' : '';
?>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
/* ── NAV RESET ── */
.vn{position:sticky;top:0;z-index:200;background:rgba(255,255,255,.97);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid #E5E7EB;height:68px;padding:0 40px;display:flex;align-items:center;justify-content:space-between;gap:20px;}
.vn-logo{display:flex;align-items:center;gap:9px;flex-shrink:0;}
.vn-logo img{height:28px;}
.vn-logo-text{font-size:18px;font-weight:800;letter-spacing:-.02em;color:#0A1628;font-family:'Plus Jakarta Sans',sans-serif;}
.vn-links{display:flex;gap:2px;align-items:center;}
.vn-link{font-size:14px;font-weight:600;color:#6B7280;padding:7px 14px;border-radius:8px;transition:all .15s;font-family:'Plus Jakarta Sans',sans-serif;white-space:nowrap;}
.vn-link:hover{color:#0A1628;background:#F3F4F6;}
.vn-link.active{color:#1A56DB;background:#EFF6FF;font-weight:700;}
.vn-right{display:flex;align-items:center;gap:8px;flex-shrink:0;}
.vn-btn{font-size:13px;font-weight:700;padding:8px 18px;border-radius:8px;border:1.5px solid #E5E7EB;color:#374151;background:#fff;transition:all .15s;font-family:'Plus Jakarta Sans',sans-serif;white-space:nowrap;}
.vn-btn:hover{background:#F9FAFB;border-color:#D1D5DB;}
.vn-btn-p{background:#1A56DB;color:#fff;border-color:#1A56DB;}
.vn-btn-p:hover{background:#1E40AF;border-color:#1E40AF;}
/* User dropdown */
.vn-user{position:relative;}
.vn-user-btn{display:flex;align-items:center;gap:8px;padding:5px 12px 5px 5px;border-radius:99px;border:1.5px solid #E5E7EB;background:#fff;cursor:pointer;transition:all .15s;}
.vn-user-btn:hover{border-color:#D1D5DB;background:#F9FAFB;}
.vn-av{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;}
.vn-uname{font-size:13px;font-weight:700;color:#0A1628;font-family:'Plus Jakarta Sans',sans-serif;}
.vn-caret{font-size:10px;color:#9CA3AF;transition:transform .2s;}
.vn-dropdown{position:absolute;top:calc(100% + 8px);right:0;background:#fff;border:1px solid #E5E7EB;border-radius:12px;box-shadow:0 10px 40px rgba(10,22,40,.12);min-width:180px;overflow:hidden;opacity:0;transform:translateY(-6px);pointer-events:none;transition:all .2s;}
.vn-dropdown.open{opacity:1;transform:translateY(0);pointer-events:all;}
.vn-drop-item{display:flex;align-items:center;gap:9px;padding:11px 16px;font-size:13px;font-weight:600;color:#374151;font-family:'Plus Jakarta Sans',sans-serif;transition:background .12s;}
.vn-drop-item:hover{background:#F9FAFB;color:#0A1628;}
.vn-drop-item .di{font-size:15px;width:20px;text-align:center;}
.vn-drop-div{height:1px;background:#F3F4F6;margin:4px 0;}
.vn-drop-item.danger{color:#EF4444;}
.vn-drop-item.danger:hover{background:#FEF2F2;}
/* Mobile */
.vn-hamburger{display:none;flex-direction:column;gap:5px;padding:8px;cursor:pointer;background:none;border:none;}
.vn-hamburger span{width:22px;height:2px;background:#374151;border-radius:2px;transition:all .25s;}
.vn-mobile{display:none;position:fixed;top:68px;left:0;right:0;background:#fff;border-bottom:1px solid #E5E7EB;box-shadow:0 8px 24px rgba(10,22,40,.08);z-index:199;padding:12px 16px;}
.vn-mobile.open{display:block;}
.vn-mob-link{display:flex;align-items:center;gap:10px;padding:11px 14px;font-size:14px;font-weight:600;color:#374151;border-radius:9px;margin-bottom:3px;transition:background .12s;font-family:'Plus Jakarta Sans',sans-serif;}
.vn-mob-link:hover,.vn-mob-link.active{background:#EFF6FF;color:#1A56DB;}
.vn-mob-div{height:1px;background:#F3F4F6;margin:8px 0;}
.vn-mob-btn{display:block;text-align:center;padding:11px;font-size:14px;font-weight:700;border-radius:9px;margin-bottom:6px;font-family:'Plus Jakarta Sans',sans-serif;}
.vn-mob-btn-p{background:#1A56DB;color:#fff;}
.vn-mob-btn-s{background:#F3F4F6;color:#374151;}
@media(max-width:900px){.vn-links{display:none;}.vn-hamburger{display:flex;}.vn-right .vn-btn,.vn-right .vn-user{display:none;}}
@media(max-width:480px){.vn{padding:0 16px;height:60px;}.vn-logo-text{font-size:16px;}}
</style>

<!-- NAV -->
<nav class="vn">
  <a href="<?= $base ?>index.php" class="vn-logo">
    <img src="<?= $base ?>assets/veya-logo.png" alt="Veya" onerror="this.style.display='none'">
    <span class="vn-logo-text">Veya</span>
  </a>

  <div class="vn-links">
    <a href="<?= $base ?>index.php"          class="vn-link <?= $nav_active==='home'         ?'active':'' ?>">Home</a>
    <a href="<?= $base ?>universities.php"   class="vn-link <?= $nav_active==='universities' ?'active':'' ?>">Universities</a>
    <a href="<?= $base ?>scholarships.php"   class="vn-link <?= $nav_active==='scholarships' ?'active':'' ?>">Scholarships</a>
    <a href="<?= $base ?>compare.php"        class="vn-link <?= $nav_active==='compare'      ?'active':'' ?>">Compare</a>
    <?php if($is_logged_in): ?>
    <a href="<?= $base ?>dashboard.php"      class="vn-link <?= $nav_active==='dashboard'    ?'active':'' ?>">Dashboard</a>
    <?php endif; ?>
  </div>

  <div class="vn-right">
    <?php if($is_logged_in): ?>
      <div class="vn-user">
        <button class="vn-user-btn" onclick="toggleDrop()" id="vnUserBtn" aria-label="Account menu">
          <div class="vn-av" style="background:<?= $nav_avatar_color ?>"><?= $nav_initial ?></div>
          <span class="vn-uname"><?= htmlspecialchars($nav_first_name) ?></span>
          <span class="vn-caret" id="vnCaret">▾</span>
        </button>
        <div class="vn-dropdown" id="vnDrop">
          <a href="<?= $base ?>profile.php"  class="vn-drop-item"><span class="di">👤</span>My Profile</a>
          <a href="<?= $base ?>dashboard.php" class="vn-drop-item"><span class="di">📊</span>Dashboard</a>
          <?php if($nav_role === 'Admin'): ?>
          <div class="vn-drop-div"></div>
          <a href="<?= $base ?>admin/dashboard.php" class="vn-drop-item"><span class="di">⚙️</span>Admin Panel</a>
          <?php endif; ?>
          <div class="vn-drop-div"></div>
          <a href="<?= $base ?>logout.php" class="vn-drop-item danger"><span class="di">🚪</span>Log Out</a>
        </div>
      </div>
    <?php else: ?>
      <a href="<?= $base ?>login.php"    class="vn-btn">Log In</a>
      <a href="<?= $base ?>register.php" class="vn-btn vn-btn-p">Register Free</a>
    <?php endif; ?>

    <!-- Hamburger -->
    <button class="vn-hamburger" onclick="toggleMobile()" aria-label="Menu" id="vnHamburger">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<!-- MOBILE MENU -->
<div class="vn-mobile" id="vnMobile">
  <a href="<?= $base ?>index.php"        class="vn-mob-link <?= $nav_active==='home'         ?'active':'' ?>">🏠 Home</a>
  <a href="<?= $base ?>universities.php" class="vn-mob-link <?= $nav_active==='universities' ?'active':'' ?>">🏫 Universities</a>
  <a href="<?= $base ?>scholarships.php" class="vn-mob-link <?= $nav_active==='scholarships' ?'active':'' ?>">🏅 Scholarships</a>
  <a href="<?= $base ?>compare.php"      class="vn-mob-link <?= $nav_active==='compare'      ?'active':'' ?>">⚖️ Compare</a>
  <?php if($is_logged_in): ?>
  <a href="<?= $base ?>dashboard.php"    class="vn-mob-link <?= $nav_active==='dashboard'    ?'active':'' ?>">📊 Dashboard</a>
  <div class="vn-mob-div"></div>
  <a href="<?= $base ?>profile.php"      class="vn-mob-link">👤 My Profile</a>
  <?php if($nav_role === 'Admin'): ?>
  <a href="<?= $base ?>admin/dashboard.php" class="vn-mob-link">⚙️ Admin Panel</a>
  <?php endif; ?>
  <div class="vn-mob-div"></div>
  <a href="<?= $base ?>logout.php" class="vn-mob-btn vn-mob-btn-s">Log Out</a>
  <?php else: ?>
  <div class="vn-mob-div"></div>
  <a href="<?= $base ?>register.php" class="vn-mob-btn vn-mob-btn-p">Register Free</a>
  <a href="<?= $base ?>login.php"    class="vn-mob-btn vn-mob-btn-s">Log In</a>
  <?php endif; ?>
</div>

<script>
function toggleDrop(){
  const d=document.getElementById('vnDrop');
  const c=document.getElementById('vnCaret');
  const isOpen=d.classList.contains('open');
  d.classList.toggle('open');
  c.style.transform=isOpen?'':'rotate(180deg)';
}
function toggleMobile(){
  document.getElementById('vnMobile').classList.toggle('open');
}
// Close dropdown when clicking outside
document.addEventListener('click',function(e){
  const btn=document.getElementById('vnUserBtn');
  const drop=document.getElementById('vnDrop');
  if(drop && btn && !btn.contains(e.target) && !drop.contains(e.target)){
    drop.classList.remove('open');
    const c=document.getElementById('vnCaret');
    if(c) c.style.transform='';
  }
  if(!e.target.closest('.vn-hamburger') && !e.target.closest('.vn-mobile')){
    const m=document.getElementById('vnMobile');
    if(m) m.classList.remove('open');
  }
});
</script>

<!-- ── COOKIE CONSENT BANNER ── -->
<div id="cookieBanner" style="
  display:none;position:fixed;bottom:0;left:0;right:0;z-index:9990;
  background:#0A1628;color:rgba(255,255,255,.85);
  padding:14px 32px;font-family:'Plus Jakarta Sans',sans-serif;
  font-size:13px;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:12px;border-top:1px solid rgba(255,255,255,.1);
  box-shadow:0 -4px 24px rgba(0,0,0,.2);">
  <span>
    🍪 We use a session cookie to keep you logged in and localStorage to save your preferences.
    No tracking or advertising. <a href="<?= $base ?>privacy.php" style="color:#60A5FA;text-decoration:underline;">Privacy Policy</a>
  </span>
  <div style="display:flex;gap:8px;flex-shrink:0;">
    <button onclick="declineCookies()" style="padding:8px 16px;font-family:'Plus Jakarta Sans',sans-serif;font-size:12px;font-weight:600;background:transparent;border:1px solid rgba(255,255,255,.3);color:rgba(255,255,255,.6);border-radius:8px;cursor:pointer;">
      Decline
    </button>
    <button onclick="acceptCookies()" style="padding:8px 20px;font-family:'Plus Jakarta Sans',sans-serif;font-size:12px;font-weight:700;background:#1A56DB;border:none;color:#fff;border-radius:8px;cursor:pointer;transition:background .15s;"
      onmouseover="this.style.background='#1E40AF'" onmouseout="this.style.background='#1A56DB'">
      Accept & Continue
    </button>
  </div>
</div>
<script>
(function(){
  var consent = localStorage.getItem('veya_cookie_consent');
  if (!consent) {
    var b = document.getElementById('cookieBanner');
    if (b) b.style.display = 'flex';
  }
})();
function acceptCookies() {
  localStorage.setItem('veya_cookie_consent', 'accepted');
  document.getElementById('cookieBanner').style.display = 'none';
}
function declineCookies() {
  // On decline: acknowledge choice, hide banner, note that session cookies are
  // strictly necessary for login to work
  localStorage.setItem('veya_cookie_consent', 'declined');
  document.getElementById('cookieBanner').innerHTML =
    '<span style="color:rgba(255,255,255,.7);font-size:12px;">ℹ️ Note: The login session cookie is required for the site to function. Other preferences will not be saved. <button onclick="document.getElementById(\'cookieBanner\').style.display=\'none\'" style="background:none;border:none;color:#60A5FA;cursor:pointer;text-decoration:underline;font-size:12px;">Close</button></span>';
}
</script>