<?php
// includes/footer.php — Shared footer for all Veya pages
// Include with: include 'includes/footer.php';
// $base is set by nav.php (empty string for root, '../' for admin pages)
$footer_base = $base ?? '';
$footer_year = date('Y');

// Load country IDs for filter links
$footer_countries = [];
if (isset($conn)) {
    $fc = $conn->query("SELECT country_id, country_name, country_code FROM countries WHERE is_active=1 AND has_universities=1 ORDER BY country_name");
    if ($fc) while ($r = $fc->fetch_assoc()) $footer_countries[] = $r;
}
$footer_flags = ['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
?>
<footer style="background:#0A1628;color:#fff;padding:52px 40px 28px;margin-top:auto;">
  <div style="max-width:1200px;margin:0 auto;">

    <!-- Top section: 4 columns -->
    <div style="display:grid;grid-template-columns:1.5fr 1fr 1fr;gap:48px;margin-bottom:48px;">

      <!-- Brand column -->
      <div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
          <img src="<?= $footer_base ?>assets/veya-logo.png" alt="Veya"
               onerror="this.style.display='none'"
               style="height:24px;filter:brightness(0) invert(1);opacity:.85;">
          <span style="font-size:20px;font-weight:800;letter-spacing:-.01em;">Veya</span>
        </div>
        <p style="font-size:13px;color:rgba(255,255,255,.5);line-height:1.75;max-width:220px;margin-bottom:16px;">
          Empowering international students through transparent data and academic excellence. Your path to education abroad starts here.
        </p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php foreach ($footer_countries as $fc): ?>
          <a href="<?= $footer_base ?>universities.php?countries[]=<?= $fc['country_id'] ?>"
             style="font-size:11px;font-weight:700;color:rgba(255,255,255,.4);background:rgba(255,255,255,.07);padding:5px 12px;border-radius:99px;text-decoration:none;transition:all .15s;"
             onmouseover="this.style.background='rgba(255,255,255,.15)'" onmouseout="this.style.background='rgba(255,255,255,.07)'">
            <?= $footer_flags[$fc['country_code']] ?? '🏫' ?> <?= htmlspecialchars($fc['country_name']) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Quick Links -->
      <div>
        <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.35);margin-bottom:18px;">
          Quick Links
        </div>
        <?php
        $quick_links = [
          'Home'           => $footer_base.'index.php',
          'Universities'   => $footer_base.'universities.php',
          'Scholarships'   => $footer_base.'scholarships.php',
          'Compare'        => $footer_base.'compare.php',
          'Dashboard'      => $footer_base.'dashboard.php',
        ];
        foreach ($quick_links as $label => $href):
          if ($label === 'Dashboard' && !isset($_SESSION['user_id'])) continue;
        ?>
        <a href="<?= $href ?>"
           style="display:block;font-size:13px;color:rgba(255,255,255,.5);text-decoration:none;margin-bottom:10px;transition:color .15s;"
           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.5)'">
          <?= $label ?>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Support -->
      <div>
        <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.35);margin-bottom:18px;">
          Support
        </div>
        <a href="<?= $footer_base ?>universities.php?sort=ranking"
           style="display:block;font-size:13px;color:rgba(255,255,255,.5);text-decoration:none;margin-bottom:10px;transition:color .15s;"
           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.5)'">
          Top Ranked Universities 🏆
        </a>
        <a href="<?= $footer_base ?>contact.php"
           style="display:block;font-size:13px;color:rgba(255,255,255,.5);text-decoration:none;margin-bottom:10px;transition:color .15s;"
           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.5)'">
          Contact Us
        </a>
        <a href="<?= $footer_base ?>privacy.php"
           style="display:block;font-size:13px;color:rgba(255,255,255,.5);text-decoration:none;margin-bottom:10px;transition:color .15s;"
           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.5)'">
          Privacy Policy
        </a>
        <?php if (isset($_SESSION['user_id'])): ?>
        <a href="<?= $footer_base ?>profile.php"
           style="display:block;font-size:13px;color:rgba(255,255,255,.5);text-decoration:none;margin-bottom:10px;transition:color .15s;"
           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.5)'">
          Account Settings
        </a>
        <?php else: ?>
        <a href="<?= $footer_base ?>register.php"
           style="display:block;font-size:13px;color:rgba(255,255,255,.5);text-decoration:none;margin-bottom:10px;transition:color .15s;"
           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.5)'">
          Create Free Account
        </a>
        <?php endif; ?>
      </div>

      <!-- About Veya -->
      <div>
        <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.35);margin-bottom:18px;">
          About Veya
        </div>
        <p style="font-size:13px;color:rgba(255,255,255,.5);line-height:1.8;margin-bottom:20px;">
          Veya helps international students discover universities and scholarships in Thailand, Singapore, and Malaysia — with transparent data, real student reviews, and free comparison tools.
        </p>
        <?php if (!isset($_SESSION['user_id'])): ?>
        <a href="<?= $footer_base ?>register.php"
           style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:#1A56DB;color:#fff;font-size:13px;font-weight:700;border-radius:9px;text-decoration:none;transition:background .15s;"
           onmouseover="this.style.background='#1E40AF'" onmouseout="this.style.background='#1A56DB'">
          Create Free Account →
        </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Divider -->
    <div style="height:1px;background:rgba(255,255,255,.08);margin-bottom:24px;"></div>

    <!-- Bottom bar -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      <div style="font-size:12px;color:rgba(255,255,255,.3);">
        © <?= $footer_year ?> Veya Education Hub · Built for international students
      </div>
      <div style="display:flex;gap:20px;">
        <a href="<?= $footer_base ?>privacy.php"
           style="font-size:12px;color:rgba(255,255,255,.3);text-decoration:none;transition:color .15s;"
           onmouseover="this.style.color='rgba(255,255,255,.7)'" onmouseout="this.style.color='rgba(255,255,255,.3)'">
          Privacy Policy
        </a>
        <a href="<?= $footer_base ?>contact.php"
           style="font-size:12px;color:rgba(255,255,255,.3);text-decoration:none;transition:color .15s;"
           onmouseover="this.style.color='rgba(255,255,255,.7)'" onmouseout="this.style.color='rgba(255,255,255,.3)'">
          Contact Us
        </a>
        <span style="font-size:12px;color:rgba(255,255,255,.2);">
          PHP + MySQL · XAMPP
        </span>
      </div>
    </div>
  </div>

  <!-- Mobile responsive -->
  <style>
  @media(max-width:900px){
    footer > div > div:first-child{grid-template-columns:1fr 1fr !important;gap:28px !important;}
  }
  @media(max-width:560px){
    footer{padding:40px 20px 24px !important;}
    footer > div > div:first-child{grid-template-columns:1fr !important;}
  }
  </style>
</footer>