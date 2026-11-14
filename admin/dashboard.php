<?php
// admin/dashboard.php — Veya Admin Panel
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php?next=admin/dashboard.php");
    exit;
}
require_once "../db_connect.php";

// ── Stats ─────────────────────────────────────────────────────────────
$stats = [];
$queries = [
    'universities' => "SELECT COUNT(*) FROM universities WHERE is_active=1",
    'programs'     => "SELECT COUNT(*) FROM programs",
    'scholarships' => "SELECT COUNT(*) FROM scholarships",
    'users'        => "SELECT COUNT(*) FROM users",
    'reviews_total'=> "SELECT COUNT(*) FROM reviews",
    'reviews_pend' => "SELECT COUNT(*) FROM reviews WHERE status='Pending'",
    'saved'        => "SELECT COUNT(*) FROM saved_universities",
];
foreach ($queries as $key => $sql) {
    $stats[$key] = $conn->query($sql)->fetch_row()[0];
}

// ── Pending reviews ───────────────────────────────────────────────────
// Ensure inquiries table exists before querying it
$conn->query("CREATE TABLE IF NOT EXISTS inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL, email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL, message TEXT NOT NULL,
    status ENUM('New','Read','Replied') DEFAULT 'New',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$new_inquiries = (int)$conn->query("SELECT COUNT(*) FROM inquiries WHERE status='New'")->fetch_row()[0];

$pending_reviews = $conn->query("
    SELECT r.review_id, r.rating, r.title, r.comment, r.created_at,
           u.full_name, u.email,
           uni.name AS uni_name, uni.university_id,
           c.country_code
    FROM reviews r
    JOIN users u   ON u.user_id = r.user_id
    JOIN universities uni ON uni.university_id = r.university_id
    JOIN countries c ON c.country_id = uni.country_id
    WHERE r.status = 'Pending'
    ORDER BY r.created_at ASC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ── Recent users ──────────────────────────────────────────────────────
$recent_users = $conn->query("
    SELECT u.user_id, u.full_name, u.email, u.role,
           u.preferred_currency_code, u.created_at,
           c.country_name
    FROM users u
    LEFT JOIN countries c ON c.country_id = u.home_country_id
    ORDER BY u.created_at DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

// ── Universities by country ───────────────────────────────────────────
$by_country = $conn->query("
    SELECT c.country_name, c.country_code,
           COUNT(u.university_id) AS uni_count,
           SUM(CASE WHEN u.university_type='Public' THEN 1 ELSE 0 END) AS public_count,
           SUM(CASE WHEN u.university_type='Private' THEN 1 ELSE 0 END) AS private_count
    FROM countries c
    LEFT JOIN universities u ON u.country_id=c.country_id AND u.is_active=1
    WHERE c.is_active=1 AND c.has_universities=1
    GROUP BY c.country_id, c.country_name, c.country_code
    ORDER BY uni_count DESC
")->fetch_all(MYSQLI_ASSOC);

// ── Handle review approve/reject ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['review_action'])) {
    $rev_id = (int)($_POST['review_id'] ?? 0);
    $action = $_POST['review_action'] ?? '';
    if ($rev_id > 0 && in_array($action, ['Approved','Rejected'])) {
        $s = $conn->prepare("UPDATE reviews SET status=? WHERE review_id=?");
        $s->bind_param("si", $action, $rev_id);
        $s->execute(); $s->close();
        header("Location: dashboard.php?msg=".urlencode("Review $action successfully."));
        exit;
    }
}

$msg = $_GET['msg'] ?? '';
$flags = ['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$admin_name = explode(' ', $_SESSION['full_name'])[0];
$ac = ['linear-gradient(135deg,#6366F1,#8B5CF6)','linear-gradient(135deg,#DC2626,#EF4444)'];
$avatar_color = $ac[$_SESSION['user_id'] % count($ac)];
$initial = strtoupper($_SESSION['full_name'][0] ?? 'A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Dashboard — Veya</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F1F5F9;-webkit-font-smoothing:antialiased;display:flex;min-height:100vh;}
a{text-decoration:none;color:inherit;}

/* MAIN */
.main{margin-left:240px;flex:1;display:flex;flex-direction:column;min-height:100vh;}

/* TOP BAR */
.topbar{background:#fff;border-bottom:1px solid #E5E7EB;padding:0 32px;height:64px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:18px;font-weight:800;color:#0A1628;}
.topbar-right{display:flex;align-items:center;gap:10px;}
.view-site{font-size:13px;font-weight:700;color:#1A56DB;background:#EFF6FF;padding:8px 16px;border-radius:8px;transition:background .15s;display:flex;align-items:center;gap:6px;}
.view-site:hover{background:#DBEAFE;}

/* CONTENT */
.content{padding:28px 32px;flex:1;}

/* ALERT */
.alert-msg{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;padding:12px 16px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:20px;display:flex;align-items:center;gap:8px;}

/* STAT CARDS */
.stat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:20px;transition:box-shadow .2s;}
.stat-card:hover{box-shadow:0 8px 24px rgba(10,22,40,.07);}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;}
.stat-num{font-size:28px;font-weight:800;color:#0A1628;line-height:1;margin-bottom:3px;}
.stat-label{font-size:12px;color:#9CA3AF;font-weight:500;}
.stat-sub{font-size:11px;font-weight:600;margin-top:6px;display:flex;align-items:center;gap:4px;}
.stat-sub.green{color:#059669;}
.stat-sub.red{color:#EF4444;}
.stat-sub.blue{color:#1A56DB;}

/* TWO-COL LAYOUT */
.two-col{display:grid;grid-template-columns:1fr 340px;gap:20px;}

/* CARD */
.card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;margin-bottom:20px;}
.card-head{padding:16px 20px;border-bottom:1px solid #F3F4F6;display:flex;align-items:center;justify-content:space-between;}
.card-title{font-size:14px;font-weight:800;color:#0A1628;display:flex;align-items:center;gap:8px;}
.card-link{font-size:12px;font-weight:700;color:#1A56DB;transition:color .15s;}
.card-link:hover{color:#1E40AF;}

/* REVIEW ROW */
.rev-row{padding:14px 20px;border-bottom:1px solid #F9FAFB;display:flex;gap:12px;align-items:flex-start;}
.rev-row:last-child{border-bottom:none;}
.rev-av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6366F1,#8B5CF6);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;flex-shrink:0;}
.rev-body{flex:1;min-width:0;}
.rev-name{font-size:13px;font-weight:700;color:#0A1628;margin-bottom:1px;}
.rev-uni{font-size:11px;color:#9CA3AF;margin-bottom:4px;}
.rev-cmt{font-size:12px;color:#374151;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:7px;}
.rev-stars{display:flex;gap:2px;margin-bottom:6px;}
.son{color:#FBBF24;font-size:12px;}.soff{color:#E5E7EB;font-size:12px;}
.rev-actions{display:flex;gap:6px;}
.btn-approve{font-size:11px;font-weight:700;color:#fff;background:#059669;padding:5px 12px;border-radius:6px;border:none;cursor:pointer;transition:background .15s;}
.btn-approve:hover{background:#047857;}
.btn-reject{font-size:11px;font-weight:700;color:#EF4444;background:#FEF2F2;padding:5px 12px;border-radius:6px;border:1px solid #FECACA;cursor:pointer;transition:all .15s;}
.btn-reject:hover{background:#EF4444;color:#fff;}
.rev-date{font-size:11px;color:#9CA3AF;margin-top:5px;}

/* USER TABLE */
.user-table{width:100%;border-collapse:collapse;}
.user-table th{padding:10px 16px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;background:#F9FAFB;border-bottom:1px solid #E5E7EB;}
.user-table td{padding:12px 16px;font-size:13px;color:#374151;border-bottom:1px solid #F9FAFB;vertical-align:middle;}
.user-table tr:last-child td{border-bottom:none;}
.user-table tr:hover td{background:#FAFAFA;}
.role-badge{font-size:10px;font-weight:700;padding:3px 8px;border-radius:99px;letter-spacing:.05em;}
.role-Student{background:#EFF6FF;color:#1E40AF;}
.role-Admin{background:#FEF3C7;color:#92400E;}
.u-av{width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;margin-right:8px;vertical-align:middle;}

/* COUNTRY BREAKDOWN */
.country-item{padding:14px 20px;border-bottom:1px solid #F9FAFB;display:flex;align-items:center;gap:12px;}
.country-item:last-child{border-bottom:none;}
.country-flag{font-size:22px;}
.country-name{font-size:14px;font-weight:700;color:#0A1628;margin-bottom:2px;}
.country-detail{font-size:12px;color:#9CA3AF;}
.country-count{margin-left:auto;font-size:22px;font-weight:800;color:#0A1628;}
.country-bar{height:5px;background:#F3F4F6;border-radius:99px;overflow:hidden;margin-top:6px;}
.country-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#1A56DB,#60A5FA);}

/* QUICK ACTIONS */
.qa-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:16px;}
.qa-item{padding:14px;border-radius:10px;border:1px solid #E5E7EB;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600;color:#374151;transition:all .15s;}
.qa-item:hover{border-color:#1A56DB;color:#1A56DB;background:#EFF6FF;}
.qa-icon{font-size:18px;flex-shrink:0;}

/* Empty */
.empty-msg{padding:40px;text-align:center;color:#9CA3AF;font-size:14px;}

@media(max-width:1100px){.stat-grid{grid-template-columns:repeat(2,1fr);}.two-col{grid-template-columns:1fr;}}
@media(max-width:760px){.sidebar{transform:translateX(-100%);}.main{margin-left:0;}.content{padding:20px;}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<?php $admin_active = "dashboard"; include "includes/sidebar.php"; ?>

<!-- MAIN -->
<div class="main">

  <!-- TOP BAR -->
  <div class="topbar">
    <div class="topbar-title">📊 Dashboard Overview</div>
    <div class="topbar-right">
      <span style="font-size:12px;color:#9CA3AF;"><?= date('D, d M Y') ?></span>
      <a href="../index.php" class="view-site" target="_blank">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        View Site
      </a>
    </div>
  </div>

  <!-- CONTENT -->
  <div class="content">

    <?php if($msg): ?>
      <div class="alert-msg">✅ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-top">
          <div><div class="stat-num"><?= $stats['universities'] ?></div><div class="stat-label">Universities</div></div>
          <div class="stat-icon" style="background:#EFF6FF;">🏫</div>
        </div>
        <div class="stat-sub blue">Across 3 countries</div>
      </div>
      <div class="stat-card">
        <div class="stat-top">
          <div><div class="stat-num"><?= $stats['programs'] ?></div><div class="stat-label">Programs</div></div>
          <div class="stat-icon" style="background:#F0FDF4;">📚</div>
        </div>
        <div class="stat-sub green">Available for students</div>
      </div>
      <div class="stat-card">
        <div class="stat-top">
          <div><div class="stat-num"><?= $stats['scholarships'] ?></div><div class="stat-label">Scholarships</div></div>
          <div class="stat-icon" style="background:#FEF3C7;">🏅</div>
        </div>
        <div class="stat-sub green">Funding opportunities</div>
      </div>
      <div class="stat-card">
        <div class="stat-top">
          <div><div class="stat-num"><?= $stats['users'] ?></div><div class="stat-label">Users</div></div>
          <div class="stat-icon" style="background:#F5F3FF;">👥</div>
        </div>
        <div class="stat-sub blue">Registered accounts</div>
      </div>
      <div class="stat-card">
        <div class="stat-top">
          <div><div class="stat-num"><?= $stats['reviews_total'] ?></div><div class="stat-label">Total Reviews</div></div>
          <div class="stat-icon" style="background:#ECFDF5;">⭐</div>
        </div>
        <div class="stat-sub <?= $stats['reviews_pend']>0?'red':'green' ?>">
          <?= $stats['reviews_pend'] ?> pending approval
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-top">
          <div><div class="stat-num"><?= $stats['saved'] ?></div><div class="stat-label">Saved Universities</div></div>
          <div class="stat-icon" style="background:#FEF2F2;">❤️</div>
        </div>
        <div class="stat-sub blue">By all users</div>
      </div>
      <a href="inquiries.php" class="stat-card" style="text-decoration:none;color:inherit;<?= $new_inquiries>0?'border-color:#FBBF24;':'' ?>">
        <div class="stat-top">
          <div>
            <div class="stat-num"><?= $new_inquiries ?></div>
            <div class="stat-label">New Messages</div>
          </div>
          <div class="stat-icon" style="background:#FFFBEB;">📬</div>
        </div>
        <div class="stat-sub <?= $new_inquiries>0?'red':'green' ?>">
          <?= $new_inquiries>0 ? 'Unread — click to view' : 'All messages read' ?>
        </div>
      </a>
    </div>

    <div class="two-col">

      <!-- LEFT: Pending Reviews -->
      <div>
        <div class="card">
          <div class="card-head">
            <div class="card-title">
              ⏳ Pending Reviews
              <?php if($stats['reviews_pend']>0): ?>
                <span style="background:#EF4444;color:#fff;font-size:10px;padding:2px 7px;border-radius:99px;"><?= $stats['reviews_pend'] ?></span>
              <?php endif; ?>
            </div>
            <a href="reviews.php" class="card-link">View all →</a>
          </div>

          <?php if(empty($pending_reviews)): ?>
            <div class="empty-msg">✅ No pending reviews — all caught up!</div>
          <?php else: ?>
            <?php foreach($pending_reviews as $rev):
              $filled = (int)$rev['rating'];
            ?>
            <div class="rev-row">
              <div class="rev-av"><?= strtoupper($rev['full_name'][0]) ?></div>
              <div class="rev-body">
                <div class="rev-name"><?= htmlspecialchars($rev['full_name']) ?> <span style="font-size:11px;color:#9CA3AF;font-weight:400;">· <?= htmlspecialchars($rev['email']) ?></span></div>
                <div class="rev-uni"><?= $flags[$rev['country_code']]??'' ?> <?= htmlspecialchars($rev['uni_name']) ?></div>
                <div class="rev-stars">
                  <?php for($s=1;$s<=5;$s++) echo '<span class="'.($s<=$filled?'son':'soff').'">★</span>'; ?>
                  <span style="font-size:11px;color:#374151;font-weight:600;margin-left:4px;"><?= $filled ?>/5</span>
                </div>
                <?php if($rev['title']): ?><div style="font-size:12px;font-weight:700;color:#0A1628;margin-bottom:3px;"><?= htmlspecialchars($rev['title']) ?></div><?php endif; ?>
                <div class="rev-cmt"><?= htmlspecialchars($rev['comment'] ?? '') ?></div>
                <div class="rev-actions">
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="review_id"     value="<?= $rev['review_id'] ?>">
                    <input type="hidden" name="review_action" value="Approved">
                    <button type="submit" class="btn-approve">✅ Approve</button>
                  </form>
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="review_id"     value="<?= $rev['review_id'] ?>">
                    <input type="hidden" name="review_action" value="Rejected">
                    <button type="submit" class="btn-reject">❌ Reject</button>
                  </form>
                  <a href="../university_detail.php?id=<?= $rev['university_id'] ?>" target="_blank" style="font-size:11px;color:#9CA3AF;font-weight:600;padding:5px 8px;">View uni →</a>
                </div>
                <div class="rev-date"><?= date('d M Y, H:i', strtotime($rev['created_at'])) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Recent Users -->
        <div class="card">
          <div class="card-head">
            <div class="card-title">👥 Recent Users</div>
            <a href="users.php" class="card-link">View all →</a>
          </div>
          <div style="overflow-x:auto;">
            <table class="user-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Country</th>
                  <th>Joined</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $u_colors=['#6366F1','#059669','#D97706','#DC2626','#0891B2'];
                foreach($recent_users as $i=>$u):
                  $uc=$u_colors[$i%count($u_colors)];
                  $ini=strtoupper($u['full_name'][0]??'U');
                ?>
                <tr>
                  <td>
                    <div style="display:inline-flex;align-items:center;">
                      <span class="u-av" style="background:<?=$uc?>"><?=$ini?></span>
                      <?= htmlspecialchars($u['full_name']) ?>
                    </div>
                  </td>
                  <td style="color:#6B7280;"><?= htmlspecialchars($u['email']) ?></td>
                  <td><span class="role-badge role-<?=$u['role']?>"><?=$u['role']?></span></td>
                  <td><?= htmlspecialchars($u['country_name'] ?? '—') ?></td>
                  <td style="color:#9CA3AF;"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- RIGHT SIDEBAR -->
      <div>

        <!-- Country breakdown -->
        <div class="card">
          <div class="card-head">
            <div class="card-title">🌏 Universities by Country</div>
          </div>
          <?php
          $max_unis = max(array_column($by_country, 'uni_count')) ?: 1;
          foreach($by_country as $c):
            $pct = round($c['uni_count'] / $max_unis * 100);
          ?>
          <div class="country-item">
            <div class="country-flag"><?= $flags[$c['country_code']]??'🌐' ?></div>
            <div style="flex:1;">
              <div class="country-name"><?= htmlspecialchars($c['country_name']) ?></div>
              <div class="country-detail"><?= $c['public_count'] ?> public · <?= $c['private_count'] ?> private</div>
              <div class="country-bar"><div class="country-fill" style="width:<?=$pct?>%"></div></div>
            </div>
            <div class="country-count"><?= $c['uni_count'] ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Quick actions -->
        <div class="card">
          <div class="card-head">
            <div class="card-title">⚡ Quick Actions</div>
          </div>
          <div class="qa-grid">
            <a href="universities.php?action=add" class="qa-item">
              <span class="qa-icon">➕</span>Add University
            </a>
            <a href="programs.php?action=add" class="qa-item">
              <span class="qa-icon">📖</span>Add Program
            </a>
            <a href="scholarships.php?action=add" class="qa-item">
              <span class="qa-icon">🏅</span>Add Scholarship
            </a>
            <a href="reviews.php" class="qa-item">
              <span class="qa-icon">⭐</span>Review Queue
              <?php if($stats['reviews_pend']>0): ?>
                <span style="margin-left:auto;background:#EF4444;color:#fff;font-size:9px;padding:1px 5px;border-radius:99px;"><?=$stats['reviews_pend']?></span>
              <?php endif; ?>
            </a>
            <a href="../universities.php" class="qa-item" target="_blank">
              <span class="qa-icon">🌐</span>Browse Site
            </a>
            <a href="../logout.php" class="qa-item" style="color:#EF4444;">
              <span class="qa-icon">🚪</span>Log Out
            </a>
          </div>
        </div>

        <!-- System info -->
        <div class="card">
          <div class="card-head">
            <div class="card-title">⚙️ System Info</div>
          </div>
          <div style="padding:14px 20px;">
            <?php foreach([
              'PHP Version'  => PHP_VERSION,
              'Database'     => 'veya_db (MySQL)',
              'Admin'        => htmlspecialchars($_SESSION['full_name']),
              'Last Login'   => date('d M Y, H:i'),
              'Server Time'  => date('H:i:s'),
            ] as $k=>$v): ?>
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #F9FAFB;font-size:13px;">
              <span style="color:#9CA3AF;font-weight:500;"><?= $k ?></span>
              <span style="font-weight:700;color:#0A1628;"><?= $v ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->
</body>
</html>