<?php
// admin/includes/sidebar.php - Shared sidebar for all admin pages
// Set $admin_active before including:  $admin_active = 'dashboard';
if (!isset($admin_active)) $admin_active = '';

// Count pending reviews
$pending_count = 0;
if (isset($conn)) {
    $pc = $conn->query("SELECT COUNT(*) FROM reviews WHERE status='Pending'");
    if ($pc) $pending_count = (int)$pc->fetch_row()[0];
}

// Current admin info
$admin_first   = isset($_SESSION['full_name']) ? explode(' ', $_SESSION['full_name'])[0] : 'Admin';
$admin_initial = strtoupper(isset($_SESSION['full_name']) ? $_SESSION['full_name'][0] : 'A');
$uid           = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$avatar_bg     = ($uid % 2)
    ? 'linear-gradient(135deg,#DC2626,#EF4444)'
    : 'linear-gradient(135deg,#6366F1,#8B5CF6)';

// Navigation items
$nav_items = array(
    array('dashboard.php',    '&#128202;', 'Dashboard',    'dashboard'),
    array('universities.php', '&#127979;', 'Universities', 'universities'),
    array('programs.php',     '&#128218;', 'Programs',     'programs'),
    array('scholarships.php', '&#127885;', 'Scholarships', 'scholarships'),
    array('reviews.php',      '&#11088;',  'Reviews',      'reviews'),
    array('users.php',        '&#128101;', 'Users',        'users'),
    array('inquiries.php',    '&#128140;', 'Inbox',        'inquiries'),
);

// Build sidebar HTML into a buffer to keep all variables in scope
ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;}

/* --- Global admin page normalization ---
   Applied to ALL admin pages via this shared include.
   Normalizes topbar, main layout, and responsive behavior.
*/
.topbar{
  background:#fff;border-bottom:1px solid #E5E7EB;
  padding:0 32px;height:64px;
  display:flex;align-items:center;justify-content:space-between;
  gap:16px;position:sticky;top:0;z-index:50;
}
.topbar-title{font-size:18px;font-weight:800;color:#0A1628;display:flex;align-items:center;gap:8px;}
.topbar-right{display:flex;align-items:center;gap:12px;}
.topbar-meta{font-size:12px;color:#9CA3AF;}
.view-site{
  display:inline-flex;align-items:center;gap:5px;
  padding:7px 14px;background:#F3F4F6;border-radius:8px;
  font-size:12px;font-weight:700;color:#374151;text-decoration:none;transition:all .15s;
}
.view-site:hover{background:#E5E7EB;}

/* --- Desktop Sidebar (visible > 900px) --- */
.adm-sidebar{
  width:240px;background:#0A1628;position:fixed;top:0;left:0;height:100vh;
  z-index:100;display:flex;flex-direction:column;overflow-y:auto;
}
.adm-logo{
  padding:20px 18px;border-bottom:1px solid rgba(255,255,255,.08);
  display:flex;align-items:center;gap:10px;flex-shrink:0;
}
.adm-logo-text{font-size:17px;font-weight:800;color:#fff;letter-spacing:-.01em;}
.adm-badge{font-size:9px;font-weight:700;background:#EF4444;color:#fff;
  padding:2px 6px;border-radius:4px;letter-spacing:.06em;text-transform:uppercase;margin-left:2px;}
.adm-section{
  padding:14px 12px 4px;font-size:10px;font-weight:700;letter-spacing:.1em;
  text-transform:uppercase;color:rgba(255,255,255,.3);
}
.adm-link{
  display:flex;align-items:center;gap:10px;
  padding:10px 16px;border-radius:9px;margin:2px 8px;
  font-size:13px;font-weight:600;color:rgba(255,255,255,.6);
  text-decoration:none;transition:all .15s;
}
.adm-link:hover{background:rgba(255,255,255,.08);color:#fff;}
.adm-link.active{background:rgba(26,86,219,.45);color:#fff;}
.adm-link .ico{font-size:16px;width:22px;text-align:center;flex-shrink:0;}
.adm-link .badge-n{
  margin-left:auto;background:#EF4444;color:#fff;
  font-size:10px;font-weight:700;padding:1px 6px;border-radius:99px;min-width:18px;text-align:center;
}
.adm-divider{height:1px;background:rgba(255,255,255,.07);margin:8px 16px;}
.adm-user{
  margin-top:auto;padding:14px 16px;border-top:1px solid rgba(255,255,255,.08);
  display:flex;align-items:center;gap:10px;flex-shrink:0;
}
.adm-av{
  width:34px;height:34px;border-radius:50%;display:flex;align-items:center;
  justify-content:center;font-size:14px;font-weight:800;color:#fff;flex-shrink:0;
}
.adm-user-name{font-size:13px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.adm-user-role{font-size:10px;color:rgba(255,255,255,.4);}
.adm-logout{font-size:18px;color:rgba(255,255,255,.3);transition:color .15s;text-decoration:none;flex-shrink:0;}
.adm-logout:hover{color:#EF4444;}

/* --- Mobile Top Nav (visible <= 900px) --- */
.adm-topbar{
  position:fixed;top:0;left:0;right:0;height:52px;
  background:#0A1628;z-index:200;
  border-bottom:1px solid rgba(255,255,255,.1);
  align-items:stretch;
  overflow:hidden;
  /* Hidden on desktop */
  display:none;
}
.adm-toplink{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:2px;padding:4px 2px;text-decoration:none;
  color:rgba(255,255,255,.5);transition:all .15s;
  position:relative;
}
.adm-toplink:hover{background:rgba(255,255,255,.06);color:#fff;}
.adm-toplink.active{background:rgba(26,86,219,.5);color:#fff;}
.adm-toplink .ti{font-size:18px;line-height:1;}
.adm-toplink .tl{font-size:9px;font-weight:700;white-space:nowrap;}
.adm-toplink .tn{
  position:absolute;top:5px;right:4px;
  background:#EF4444;color:#fff;font-size:8px;font-weight:700;
  padding:1px 4px;border-radius:99px;
}

@media(max-width:900px){
  .adm-sidebar{display:none;}
  /* Show mobile topbar as 6-column grid */
  .adm-topbar{
    display:grid !important;
    grid-template-columns:repeat(7,1fr) !important;
  }
  /* Push .main content below the fixed 52px mobile nav */
  .main{
    margin-left:0 !important;
    padding-top:52px !important;
  }
  /* Sticky page topbar sits just below the mobile nav */
  .topbar{top:52px !important;position:sticky !important;}
  .content,.wrap,.page-content{padding-top:8px;}
}
@media(max-width:480px){
  /* Very small phones: hide labels, show icons only */
  .adm-toplink .tl{display:none;}
  .adm-toplink{justify-content:center;}
  /* Reduce topbar padding on mobile */
  .topbar{padding:0 12px !important;}
}
</style>

<!-- Mobile Top Nav -->
<nav class="adm-topbar">
<?php foreach ($nav_items as $item):
    $href  = $item[0];
    $icon  = $item[1];
    $label = $item[2];
    $key   = $item[3];
    $is_active = ($admin_active === $key);
?>
  <a href="<?php echo $href; ?>" class="adm-toplink<?php echo $is_active ? ' active' : ''; ?>" title="<?php echo htmlspecialchars($label); ?>">
    <span class="ti"><?php echo $icon; ?></span>
    <span class="tl"><?php echo htmlspecialchars($label); ?></span>
    <?php if ($key === 'reviews' && $pending_count > 0): ?>
      <span class="tn"><?php echo $pending_count; ?></span>
    <?php endif; ?>
  </a>
<?php endforeach; ?>
</nav>

<!-- Desktop Sidebar -->
<aside class="adm-sidebar">
  <div class="adm-logo">
    <img src="../assets/veya-logo.png" alt="Veya"
         onerror="this.style.display='none'"
         style="height:26px;filter:brightness(0) invert(1);opacity:.85;">
    <span class="adm-logo-text">Veya <span class="adm-badge">Admin</span></span>
  </div>

  <div class="adm-section">Main</div>
  <a href="dashboard.php"    class="adm-link<?php echo $admin_active==='dashboard'    ? ' active':''; ?>"><span class="ico">&#128202;</span> Dashboard</a>

  <div class="adm-section">Content</div>
  <a href="universities.php" class="adm-link<?php echo $admin_active==='universities' ? ' active':''; ?>"><span class="ico">&#127979;</span> Universities</a>
  <a href="programs.php"     class="adm-link<?php echo $admin_active==='programs'     ? ' active':''; ?>"><span class="ico">&#128218;</span> Programs &amp; Fees</a>
  <a href="scholarships.php" class="adm-link<?php echo $admin_active==='scholarships' ? ' active':''; ?>"><span class="ico">&#127885;</span> Scholarships</a>

  <div class="adm-section">Community</div>
  <a href="reviews.php"      class="adm-link<?php echo $admin_active==='reviews'      ? ' active':''; ?>">
    <span class="ico">&#11088;</span> Reviews
    <?php if ($pending_count > 0): ?><span class="badge-n"><?php echo $pending_count; ?></span><?php endif; ?>
  </a>
  <a href="users.php"        class="adm-link<?php echo $admin_active==='users'        ? ' active':''; ?>"><span class="ico">&#128101;</span> Users</a>

  <div class="adm-divider"></div>
  <a href="../index.php" class="adm-link" target="_blank"><span class="ico">&#127760;</span> View Website</a>

  <!-- Inbox with unread count -->
  <?php
  $unread_count = 0;
  $uq = $conn->query("SELECT COUNT(*) FROM inquiries WHERE status='New'");
  if ($uq) $unread_count = (int)$uq->fetch_row()[0];
  ?>
  <a href="inquiries.php" class="adm-link<?php echo $admin_active==='inquiries' ? ' active' : ''; ?>">
    <span class="ico">&#128140;</span> Inbox
    <?php if ($unread_count > 0): ?>
      <span class="badge-n"><?php echo $unread_count; ?></span>
    <?php endif; ?>
  </a>

  <div class="adm-user">
    <div class="adm-av" style="background:<?php echo $avatar_bg; ?>"><?php echo htmlspecialchars($admin_initial); ?></div>
    <div style="flex:1;min-width:0;">
      <div class="adm-user-name"><?php echo htmlspecialchars($admin_first); ?></div>
      <div class="adm-user-role">Administrator</div>
    </div>
    <a href="../logout.php" class="adm-logout" title="Log out">&#9211;</a>
  </div>
</aside>
<?php
$sidebar_html = ob_get_clean();
echo $sidebar_html;
?>