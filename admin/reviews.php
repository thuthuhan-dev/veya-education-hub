<?php
// admin/reviews.php — Veya Admin Panel
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php"); exit;
}
require_once "../db_connect.php";

// ── Handle actions ────────────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) { die('<p style="font-family:sans-serif;padding:40px;color:#DC2626;">Security error — invalid CSRF token.</p>'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rev_id = (int)($_POST['review_id'] ?? 0);
    $action = $_POST['review_action'] ?? '';
    if ($rev_id > 0 && in_array($action, ['Approved','Rejected','Pending'])) {
        $s = $conn->prepare("UPDATE reviews SET status=? WHERE review_id=?");
        $s->bind_param("si", $action, $rev_id); $s->execute(); $s->close();
        $msg = "Review #$rev_id set to $action.";
    }
    // Permanently remove the row from the database (not just a status change)
    if ($rev_id > 0 && $action === 'Delete') {
        $s = $conn->prepare("DELETE FROM reviews WHERE review_id=?");
        $s->bind_param("i", $rev_id); $s->execute(); $s->close();
        $msg = "Review #$rev_id permanently deleted from the database.";
    }
    // Bulk action
    if (!empty($_POST['bulk_ids']) && !empty($_POST['bulk_action'])) {
        $bulk_action = $_POST['bulk_action'];
        if ($bulk_action === 'Delete') {
            $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'])));
            foreach ($ids as $id) {
                $s = $conn->prepare("DELETE FROM reviews WHERE review_id=?");
                $s->bind_param("i", $id); $s->execute(); $s->close();
            }
            $msg = count($ids)." reviews permanently deleted.";
        }
        if (in_array($bulk_action, ['Approved','Rejected'])) {
            $ids = array_filter(array_map('intval', explode(',', $_POST['bulk_ids'])));
            foreach ($ids as $id) {
                $s = $conn->prepare("UPDATE reviews SET status=? WHERE review_id=?");
                $s->bind_param("si", $bulk_action, $id); $s->execute(); $s->close();
            }
            $msg = count($ids)." reviews set to $bulk_action.";
        }
    }
}

// ── Filters ───────────────────────────────────────────────────────────
$filter_status  = $_GET['status']  ?? '';
$filter_country = (int)($_GET['country'] ?? 0);
$filter_rating  = (int)($_GET['rating']  ?? 0);
$search         = trim($_GET['q'] ?? '');
$page           = max(1,(int)($_GET['page'] ?? 1));
$per_page       = 12;
$offset         = ($page-1)*$per_page;

// ── WHERE ─────────────────────────────────────────────────────────────
$where = ["1=1"];
$params = []; $types = "";
if ($filter_status !== '') {
    $where[] = "r.status=?"; $params[] = $filter_status; $types .= "s";
}
if ($filter_country > 0) {
    $where[] = "c.country_id=?"; $params[] = $filter_country; $types .= "i";
}
if ($filter_rating > 0) {
    $where[] = "r.rating=?"; $params[] = $filter_rating; $types .= "i";
}
if ($search !== '') {
    $where[] = "(u.full_name LIKE ? OR uni.name LIKE ? OR r.comment LIKE ?)";
    $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like; $types .= "sss";
}
$where_sql = "WHERE ".implode(" AND ", $where);

// ── Count ─────────────────────────────────────────────────────────────
$cs = $conn->prepare("SELECT COUNT(*) FROM reviews r JOIN users u ON u.user_id=r.user_id JOIN universities uni ON uni.university_id=r.university_id JOIN countries c ON c.country_id=uni.country_id $where_sql");
if ($types) $cs->bind_param($types,...$params);
$cs->execute();
$total = $cs->get_result()->fetch_row()[0];
$total_pages = max(1,ceil($total/$per_page));
$cs->close();

// ── Stats ─────────────────────────────────────────────────────────────
$rstats = $conn->query("SELECT status, COUNT(*) AS cnt FROM reviews GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$status_counts = ['Pending'=>0,'Approved'=>0,'Rejected'=>0];
foreach($rstats as $r) $status_counts[$r['status']] = (int)$r['cnt'];

// ── Query ─────────────────────────────────────────────────────────────
$all_params = [...$params, $per_page, $offset];
$all_types  = $types."ii";
$stmt = $conn->prepare("
    SELECT r.review_id, r.rating, r.title, r.comment, r.status, r.created_at,
           u.full_name, u.email, u.user_id AS reviewer_id,
           uni.name AS uni_name, uni.university_id,
           c.country_name, c.country_code
    FROM reviews r
    JOIN users u ON u.user_id=r.user_id
    JOIN universities uni ON uni.university_id=r.university_id
    JOIN countries c ON c.country_id=uni.country_id
    $where_sql
    ORDER BY FIELD(r.status,'Pending','Approved','Rejected'), r.created_at DESC
    LIMIT ? OFFSET ?
");
if ($all_types) $stmt->bind_param($all_types,...$all_params);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$countries_list = $conn->query("SELECT c.country_id,c.country_name FROM countries c JOIN universities u ON u.country_id=c.country_id WHERE c.is_active=1 GROUP BY c.country_id ORDER BY c.country_name");
$flags = ['TH'=>'🇹🇭','SG'=>'🇸🇬','MY'=>'🇲🇾'];
$status_styles = [
    'Pending'  => ['bg'=>'#FEF3C7','color'=>'#92400E','icon'=>'⏳'],
    'Approved' => ['bg'=>'#ECFDF5','color'=>'#065F46','icon'=>'✅'],
    'Rejected' => ['bg'=>'#FEF2F2','color'=>'#991B1B','icon'=>'❌'],
];
$admin_name = explode(' ',$_SESSION['full_name'])[0];
$ac = ['linear-gradient(135deg,#6366F1,#8B5CF6)','linear-gradient(135deg,#DC2626,#EF4444)'];
$avatar_color = $ac[$_SESSION['user_id']%count($ac)];
$initial = strtoupper($_SESSION['full_name'][0]??'A');

function qs($overrides=[]) {
    global $filter_status,$filter_country,$filter_rating,$search;
    $p = array_filter(['status'=>$filter_status,'country'=>$filter_country?:null,'rating'=>$filter_rating?:null,'q'=>$search]);
    return http_build_query(array_merge($p,$overrides));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reviews — Veya Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F1F5F9;display:flex;min-height:100vh;-webkit-font-smoothing:antialiased;}
a{text-decoration:none;color:inherit;}

.main{margin-left:240px;flex:1;}
.topbar{background:#fff;border-bottom:1px solid #E5E7EB;padding:0 32px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:18px;font-weight:800;color:#0A1628;}
.content{padding:28px 32px;}

.alert-msg{background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;padding:12px 16px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:20px;}

/* STATUS TABS */
.status-tabs{display:flex;gap:8px;margin-bottom:22px;flex-wrap:wrap;}
.stab{padding:9px 18px;border-radius:9px;font-size:13px;font-weight:700;border:1px solid #E5E7EB;background:#fff;color:#6B7280;transition:all .15s;display:flex;align-items:center;gap:7px;}
.stab:hover{border-color:#1A56DB;color:#1A56DB;}
.stab.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}
.stab .n{font-size:11px;padding:1px 7px;border-radius:99px;background:rgba(0,0,0,.12);}
.stab.active .n{background:rgba(255,255,255,.25);}

/* FILTERS ROW */
.filters-row{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center;}
.fi{font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;color:#374151;background:#fff;border:1px solid #E5E7EB;padding:9px 13px;border-radius:9px;outline:none;cursor:pointer;transition:border-color .15s;}
.fi:focus{border-color:#93C5FD;}
.fi-search{flex:1;min-width:200px;}
.fi-search::placeholder{color:#9CA3AF;font-weight:400;}
.fi-btn{padding:9px 20px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#1A56DB;border:none;border-radius:9px;cursor:pointer;transition:background .15s;}
.fi-btn:hover{background:#1E40AF;}

/* BULK BAR */
.bulk-bar{background:#EFF6FF;border:1px solid #BFDBFE;border-radius:9px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.bulk-bar span{font-size:13px;font-weight:600;color:#1E40AF;}
.bulk-sel{font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:600;color:#374151;background:#fff;border:1px solid #BFDBFE;padding:8px 12px;border-radius:8px;outline:none;}
.bulk-apply{padding:8px 18px;font-family:'Plus Jakarta Sans',sans-serif;font-size:13px;font-weight:700;color:#fff;background:#1A56DB;border:none;border-radius:8px;cursor:pointer;}

/* REVIEW CARDS */
.rev-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:24px;}
.rev-card{background:#fff;border:1px solid #E5E7EB;border-radius:13px;padding:16px;transition:box-shadow .2s,border-color .2s;position:relative;}
.rev-card:hover{box-shadow:0 6px 20px rgba(10,22,40,.07);border-color:#BFDBFE;}
.rev-card.selected{border-color:#1A56DB;background:#F8FBFF;}
.rc-select{position:absolute;top:12px;right:12px;width:18px;height:18px;accent-color:#1A56DB;cursor:pointer;}
.rc-head{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;}
.rc-av{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#6366F1,#8B5CF6);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#fff;flex-shrink:0;}
.rc-info{flex:1;min-width:0;}
.rc-name{font-size:13px;font-weight:700;color:#0A1628;margin-bottom:1px;}
.rc-email{font-size:11px;color:#9CA3AF;margin-bottom:4px;}
.rc-uni{font-size:12px;color:#6B7280;font-weight:600;display:flex;align-items:center;gap:4px;}
.rc-status{margin-left:auto;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:3px 9px;border-radius:99px;flex-shrink:0;}
.rc-stars{display:flex;gap:2px;margin-bottom:8px;}
.son{color:#FBBF24;font-size:13px;}.soff{color:#E5E7EB;font-size:13px;}
.rc-title{font-size:14px;font-weight:700;color:#0A1628;margin-bottom:5px;}
.rc-cmt{font-size:13px;color:#374151;line-height:1.6;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:10px;}
.rc-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.rc-date{font-size:11px;color:#9CA3AF;}
.rc-actions{display:flex;gap:6px;}
.btn-approve{font-size:11px;font-weight:700;color:#fff;background:#059669;padding:5px 12px;border-radius:6px;border:none;cursor:pointer;transition:background .15s;}
.btn-approve:hover{background:#047857;}
.btn-reject{font-size:11px;font-weight:700;color:#EF4444;background:#FEF2F2;padding:5px 12px;border-radius:6px;border:1px solid #FECACA;cursor:pointer;transition:all .15s;}
.btn-reject:hover{background:#EF4444;color:#fff;}
.btn-pending{font-size:11px;font-weight:700;color:#92400E;background:#FEF3C7;padding:5px 12px;border-radius:6px;border:1px solid #FDE68A;cursor:pointer;transition:all .15s;}
.btn-pending:hover{background:#F59E0B;color:#fff;}
.btn-view{font-size:11px;color:#1A56DB;font-weight:600;padding:5px 10px;border-radius:6px;background:#EFF6FF;transition:background .15s;}
.btn-view:hover{background:#DBEAFE;}

/* PAGINATION */
.pagination{display:flex;justify-content:center;gap:5px;}
.pg-btn{min-width:36px;height:36px;padding:0 10px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;font-size:13px;font-weight:600;border:1px solid #E5E7EB;color:#374151;background:#fff;transition:all .15s;}
.pg-btn:hover:not(.disabled):not(.active){border-color:#1A56DB;color:#1A56DB;}
.pg-btn.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}
.pg-btn.disabled{opacity:.35;pointer-events:none;}

/* Empty */
.empty{text-align:center;padding:60px;background:#fff;border:1px dashed #E5E7EB;border-radius:13px;grid-column:1/-1;}
.btn-delete-rev{padding:6px 13px;border-radius:7px;font-size:12px;font-weight:700;
  border:1px solid #FCA5A5;background:#FEF2F2;color:#EF4444;cursor:pointer;transition:all .15s;}
.btn-delete-rev:hover{background:#EF4444;color:#fff;border-color:#EF4444;}
</style>
</head>
<body>

<!-- SIDEBAR -->
<?php $admin_active = "reviews"; include "includes/sidebar.php"; ?>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="topbar-title">⭐ Reviews Management</div>
    <div class="topbar-right">
      <span class="topbar-meta"><?= $total ?> reviews total</span>
      <a href="../index.php" class="view-site" target="_blank">🌐 View Site</a>
    </div>
  </div>

  <div class="content">
    <?php if($msg): ?><div class="alert-msg">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <!-- Status tabs -->
    <div class="status-tabs">
      <?php foreach([''=>'All','Pending'=>'⏳ Pending','Approved'=>'✅ Approved','Rejected'=>'❌ Rejected'] as $val=>$lbl):
        $cnt = $val==='' ? array_sum($status_counts) : ($status_counts[$val]??0);
      ?>
      <a href="?<?= qs(['status'=>$val,'page'=>1]) ?>"
         class="stab <?= $filter_status===$val?'active':'' ?>">
        <?= $lbl ?><span class="n"><?= $cnt ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="GET" action="reviews.php" style="display:contents;">
      <?php if($filter_status): ?><input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>"><?php endif; ?>
      <div class="filters-row">
        <input class="fi fi-search" type="text" name="q" placeholder="Search reviewer, university, comment…" value="<?= htmlspecialchars($search) ?>">
        <select class="fi" name="country" onchange="this.form.submit()">
          <option value="">All Countries</option>
          <?php while($c=$countries_list->fetch_assoc()): ?>
            <option value="<?=$c['country_id']?>" <?=$filter_country==$c['country_id']?'selected':''?>><?=htmlspecialchars($c['country_name'])?></option>
          <?php endwhile; ?>
        </select>
        <select class="fi" name="rating" onchange="this.form.submit()">
          <option value="">All Ratings</option>
          <?php for($i=5;$i>=1;$i--): ?>
            <option value="<?=$i?>" <?=$filter_rating===$i?'selected':''?>><?=str_repeat('★',$i)?> <?=$i?>/5</option>
          <?php endfor; ?>
        </select>
        <button class="fi-btn" type="submit">Search</button>
        <?php if($search||$filter_country||$filter_rating): ?>
          <a href="reviews.php?status=<?=htmlspecialchars($filter_status)?>"
             style="font-size:13px;color:#EF4444;font-weight:600;">Clear filters</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- Bulk actions bar -->
    <form method="POST" id="bulk-form">
      <?= csrf_field() ?>
      <input type="hidden" name="bulk_ids" id="bulk-ids">
      <div class="bulk-bar" id="bulk-bar" style="display:none;">
        <span id="bulk-count">0 selected</span>
        <select class="bulk-sel" name="bulk_action">
          <option value="">— Choose action —</option>
          <option value="Approved">✅ Approve all selected</option>
          <option value="Rejected">❌ Reject all selected</option>
          <option value="Delete">🗑️ Permanently delete all selected</option>
        </select>
        <button type="submit" class="bulk-apply" onclick="return prepareBulk()">Apply</button>
        <button type="button" onclick="clearSelection()" style="font-size:13px;color:#6B7280;background:none;border:none;cursor:pointer;font-weight:600;">Clear</button>
      </div>
    </form>

    <!-- Review cards -->
    <div class="rev-grid">
      <?php if(empty($reviews)): ?>
        <div class="empty">
          <div style="font-size:36px;margin-bottom:10px;">⭐</div>
          <div style="font-size:16px;font-weight:700;color:#0A1628;margin-bottom:5px;">No reviews found</div>
          <div style="font-size:13px;color:#9CA3AF;">Try adjusting your filters.</div>
        </div>
      <?php else: foreach($reviews as $rev):
        $ss = $status_styles[$rev['status']] ?? ['bg'=>'#F3F4F6','color'=>'#374151','icon'=>'?'];
        $filled = (int)$rev['rating'];
        $initial_rev = strtoupper($rev['full_name'][0]??'U');
      ?>
      <div class="rev-card" id="card-<?= $rev['review_id'] ?>">
        <input type="checkbox" class="rc-select" data-id="<?= $rev['review_id'] ?>"
               onchange="updateBulk()">
        <div class="rc-head">
          <div class="rc-av"><?= $initial_rev ?></div>
          <div class="rc-info">
            <div class="rc-name"><?= htmlspecialchars($rev['full_name']) ?></div>
            <div class="rc-email"><?= htmlspecialchars($rev['email']) ?></div>
            <div class="rc-uni"><?= $flags[$rev['country_code']]??'' ?> <?= htmlspecialchars(mb_strimwidth($rev['uni_name'],0,30,'…')) ?></div>
          </div>
          <span class="rc-status" style="background:<?= $ss['bg'] ?>;color:<?= $ss['color'] ?>">
            <?= $ss['icon'].' '.$rev['status'] ?>
          </span>
        </div>
        <div class="rc-stars">
          <?php for($s=1;$s<=5;$s++) echo '<span class="'.($s<=$filled?'son':'soff').'">★</span>'; ?>
          <span style="font-size:12px;color:#374151;font-weight:700;margin-left:4px;"><?= $filled ?>/5</span>
        </div>
        <?php if($rev['title']): ?><div class="rc-title"><?= htmlspecialchars($rev['title']) ?></div><?php endif; ?>
        <div class="rc-cmt"><?= htmlspecialchars($rev['comment'] ?? '') ?></div>
        <div class="rc-footer">
          <div class="rc-date">🕒 <?= date('d M Y, H:i', strtotime($rev['created_at'])) ?></div>
          <div class="rc-actions">
            <?php if($rev['status'] !== 'Approved'): ?>
            <form method="POST" style="display:inline;">
      <?= csrf_field() ?>
              <input type="hidden" name="review_id" value="<?= $rev['review_id'] ?>">
              <input type="hidden" name="review_action" value="Approved">
              <button type="submit" class="btn-approve">✅ Approve</button>
            </form>
            <?php endif; ?>
            <?php if($rev['status'] !== 'Rejected'): ?>
            <form method="POST" style="display:inline;">
      <?= csrf_field() ?>
              <input type="hidden" name="review_id" value="<?= $rev['review_id'] ?>">
              <input type="hidden" name="review_action" value="Rejected">
              <button type="submit" class="btn-reject">❌ Reject</button>
            </form>
            <?php endif; ?>
            <?php if($rev['status'] !== 'Pending'): ?>
            <form method="POST" style="display:inline;">
      <?= csrf_field() ?>
              <input type="hidden" name="review_id" value="<?= $rev['review_id'] ?>">
              <input type="hidden" name="review_action" value="Pending">
              <button type="submit" class="btn-pending">⏳ Reset</button>
            </form>
            <?php endif; ?>
            <form method="POST" style="display:inline;"
                  onsubmit="return confirm('Permanently DELETE this review from the database? This cannot be undone.');">
              <?= csrf_field() ?>
              <input type="hidden" name="review_id" value="<?= $rev['review_id'] ?>">
              <input type="hidden" name="review_action" value="Delete">
              <button type="submit" class="btn-delete-rev">🗑️ Delete</button>
            </form>
            <a href="../university_detail.php?id=<?= $rev['university_id'] ?>&tab=reviews"
               target="_blank" class="btn-view">View →</a>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Pagination -->
    <?php if($total_pages > 1): ?>
    <nav class="pagination">
      <a href="?<?= qs(['page'=>$page-1]) ?>" class="pg-btn <?= $page<=1?'disabled':'' ?>">‹</a>
      <?php for($p=1;$p<=$total_pages;$p++):
        if($p===1||$p===$total_pages||abs($p-$page)<=1):
          echo "<a href='?".qs(['page'=>$p])."' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        elseif(abs($p-$page)===2):
          echo "<span style='color:#D1D5DB;padding:0 4px;'>…</span>";
        endif;
      endfor; ?>
      <a href="?<?= qs(['page'=>$page+1]) ?>" class="pg-btn <?= $page>=$total_pages?'disabled':'' ?>">›</a>
    </nav>
    <?php endif; ?>

  </div>
</div>

<script>
const selected = new Set();

function updateBulk() {
    document.querySelectorAll('.rc-select').forEach(cb => {
        const id = parseInt(cb.dataset.id);
        if (cb.checked) { selected.add(id); document.getElementById('card-'+id).classList.add('selected'); }
        else            { selected.delete(id); document.getElementById('card-'+id).classList.remove('selected'); }
    });
    const bar = document.getElementById('bulk-bar');
    bar.style.display = selected.size > 0 ? 'flex' : 'none';
    document.getElementById('bulk-count').textContent = selected.size + ' selected';
}

function prepareBulk() {
    document.getElementById('bulk-ids').value = [...selected].join(',');
    return selected.size > 0;
}

function clearSelection() {
    selected.clear();
    document.querySelectorAll('.rc-select').forEach(cb => cb.checked = false);
    document.querySelectorAll('.rev-card').forEach(c => c.classList.remove('selected'));
    document.getElementById('bulk-bar').style.display = 'none';
}
</script>
</body>
</html>