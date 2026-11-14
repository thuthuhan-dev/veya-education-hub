<?php
// admin/inquiries.php — Contact message inbox
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../login.php"); exit;
}
require_once "../db_connect.php";

// Ensure inquiries table exists
$conn->query("CREATE TABLE IF NOT EXISTS inquiries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('New','Read','Replied') DEFAULT 'New',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$msg = '';

// ── Handle status change ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';
    $iid    = (int)($_POST['id'] ?? 0);
    if ($iid > 0) {
        if ($action === 'mark_read') {
            $s = $conn->prepare("UPDATE inquiries SET status='Read' WHERE id=?");
            $s->bind_param("i",$iid); $s->execute(); $s->close();
        } elseif ($action === 'mark_replied') {
            $s = $conn->prepare("UPDATE inquiries SET status='Replied' WHERE id=?");
            $s->bind_param("i",$iid); $s->execute();
        } elseif ($action === 'delete') {
            $s = $conn->prepare("DELETE FROM inquiries WHERE id=?");
            $s->bind_param("i",$iid); $s->execute();
            $msg = 'Message deleted.';
        }
        header("Location: inquiries.php?msg=".urlencode($msg)); exit;
    }
}

// ── Filters ───────────────────────────────────────────────────────────
$filter  = $_GET['status'] ?? 'all';
$page    = max(1,(int)($_GET['page']??1));
$per     = 20;
$offset  = ($page-1)*$per;

$where = $filter !== 'all' ? "WHERE status='" . $conn->real_escape_string($filter) . "'" : '';
$total = (int)$conn->query("SELECT COUNT(*) FROM inquiries $where")->fetch_row()[0];
$pages = max(1, ceil($total / $per));

$rows  = $conn->query("SELECT * FROM inquiries $where ORDER BY
    CASE status WHEN 'New' THEN 0 WHEN 'Read' THEN 1 ELSE 2 END, created_at DESC
    LIMIT $per OFFSET $offset")->fetch_all(MYSQLI_ASSOC);

// Counts
$counts = ['New'=>0,'Read'=>0,'Replied'=>0,'all'=>0];
$cr = $conn->query("SELECT status, COUNT(*) n FROM inquiries GROUP BY status");
while ($r=$cr->fetch_assoc()) { $counts[$r['status']]=$r['n']; $counts['all']+=$r['n']; }
$new_count = $counts['New'];

$msg = $msg ?: ($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Inbox — Veya Admin</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Plus Jakarta Sans',sans-serif;color:#0A1628;background:#F1F5F9;-webkit-font-smoothing:antialiased;display:flex;min-height:100vh;}
a{text-decoration:none;color:inherit;}
.main{margin-left:240px;flex:1;display:flex;flex-direction:column;min-height:100vh;}
/* Topbar */
.topbar{background:#fff;border-bottom:1px solid #E5E7EB;padding:0 32px;height:64px;
  display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50;}
.topbar-title{font-size:18px;font-weight:800;color:#0A1628;display:flex;align-items:center;gap:8px;}
.topbar-right{display:flex;align-items:center;gap:12px;}
.view-site{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;background:#F3F4F6;border-radius:8px;font-size:12px;font-weight:700;color:#374151;transition:all .15s;}
.view-site:hover{background:#E5E7EB;}
/* Content */
.content{padding:28px 32px;flex:1;}
/* Message banner */
.notice{padding:11px 16px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:18px;}
.notice.ok{background:#F0FDF4;color:#065F46;border:1px solid #BBF7D0;}
/* Status tabs */
.tabs{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;}
.tab{padding:7px 16px;border-radius:99px;font-size:13px;font-weight:600;border:1.5px solid #E5E7EB;color:#6B7280;transition:all .15s;cursor:pointer;}
.tab:hover{border-color:#1A56DB;color:#1A56DB;}
.tab.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}
.tab .tc{font-size:10px;background:rgba(0,0,0,.15);padding:1px 6px;border-radius:99px;margin-left:4px;}
/* Table */
.table-wrap{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;}
table{width:100%;border-collapse:collapse;}
th{background:#F9FAFB;padding:11px 16px;text-align:left;font-size:11px;font-weight:700;
  letter-spacing:.07em;text-transform:uppercase;color:#6B7280;border-bottom:1px solid #E5E7EB;}
td{padding:14px 16px;border-bottom:1px solid #F1F5F9;vertical-align:top;font-size:13px;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#FAFBFF;}
tr.is-new td{background:#FFFBEB;}
tr.is-new:hover td{background:#FEF9E7;}
/* Status badge */
.badge{display:inline-block;font-size:10px;font-weight:700;padding:3px 9px;border-radius:99px;}
.badge-new{background:#FEF3C7;color:#92400E;}
.badge-read{background:#EFF6FF;color:#1E40AF;}
.badge-replied{background:#F0FDF4;color:#065F46;}
/* Message preview */
.msg-subject{font-weight:700;color:#0A1628;margin-bottom:3px;}
.msg-preview{font-size:12px;color:#9CA3AF;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:300px;}
/* Actions */
.actions{display:flex;gap:6px;flex-wrap:wrap;}
.btn-sm{padding:5px 11px;border-radius:7px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all .15s;}
.btn-read{background:#EFF6FF;color:#1E40AF;}
.btn-read:hover{background:#BFDBFE;}
.btn-replied{background:#F0FDF4;color:#065F46;}
.btn-replied:hover{background:#BBF7D0;}
.btn-del{background:#FEF2F2;color:#EF4444;}
.btn-del:hover{background:#FCA5A5;color:#fff;}
/* Expanded message modal */
.msg-full{font-size:13px;color:#374151;line-height:1.7;margin-top:6px;padding:12px;background:#F9FAFB;border-radius:8px;border:1px solid #E5E7EB;display:none;max-width:500px;}
.msg-toggle{font-size:11px;color:#1A56DB;cursor:pointer;font-weight:600;background:none;border:none;padding:0;}
/* Empty state */
.empty{text-align:center;padding:64px 20px;color:#9CA3AF;}
/* Pagination */
.pagination{display:flex;gap:6px;justify-content:center;margin-top:20px;}
.pg-btn{padding:7px 13px;border-radius:8px;font-size:13px;font-weight:600;border:1.5px solid #E5E7EB;color:#374151;background:#fff;}
.pg-btn.active{background:#1A56DB;border-color:#1A56DB;color:#fff;}
</style>
</head>
<body>
<?php $admin_active = 'inquiries'; include 'includes/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <div class="topbar-title">
      📬 Inbox
      <?php if($new_count>0): ?>
        <span style="background:#EF4444;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px;"><?=$new_count?> new</span>
      <?php endif; ?>
    </div>
    <div class="topbar-right">
      <span style="font-size:12px;color:#9CA3AF;"><?=$total?> message<?=$total!=1?'s':''?> total</span>
      <a href="../index.php" class="view-site" target="_blank">🌐 View Site</a>
    </div>
  </div>

  <div class="content">
    <?php if($msg): ?>
      <div class="notice ok">✅ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Status filter tabs -->
    <div class="tabs">
      <?php foreach(['all'=>'All','New'=>'New','Read'=>'Read','Replied'=>'Replied'] as $k=>$label): ?>
        <a href="inquiries.php?status=<?=$k?>" class="tab <?=$filter===$k?'active':''?>">
          <?=$label?> <span class="tc"><?=$counts[$k]?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap">
      <?php if(empty($rows)): ?>
        <div class="empty">
          <div style="font-size:40px;margin-bottom:12px;">📭</div>
          <div style="font-size:16px;font-weight:700;color:#0A1628;margin-bottom:6px;">No messages yet</div>
          <div style="font-size:13px;">When students use the Contact Us form, messages appear here.</div>
        </div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th style="width:40px;">#</th>
            <th>From</th>
            <th>Subject &amp; Message</th>
            <th>Status</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($rows as $row):
            $is_new = $row['status'] === 'New';
          ?>
          <tr class="<?= $is_new ? 'is-new' : '' ?>">
            <td style="color:#9CA3AF;font-size:12px;">#<?= $row['id'] ?></td>
            <td>
              <div style="font-weight:700;color:#0A1628;font-size:13px;"><?= htmlspecialchars($row['name']) ?></div>
              <div style="font-size:12px;color:#6B7280;"><?= htmlspecialchars($row['email']) ?></div>
            </td>
            <td>
              <div class="msg-subject"><?= htmlspecialchars($row['subject']) ?></div>
              <div class="msg-preview"><?= htmlspecialchars(substr($row['message'],0,80)).(strlen($row['message'])>80?'…':'') ?></div>
              <button class="msg-toggle" onclick="toggleMsg(this)">▼ Read full message</button>
              <div class="msg-full"><?= nl2br(htmlspecialchars($row['message'])) ?></div>
            </td>
            <td>
              <?php $bc = ['New'=>'badge-new','Read'=>'badge-read','Replied'=>'badge-replied'][$row['status']] ?>
              <span class="badge <?=$bc?>"><?= $row['status'] ?></span>
            </td>
            <td style="color:#6B7280;font-size:12px;white-space:nowrap;">
              <?= date('d M Y', strtotime($row['created_at'])) ?><br>
              <span style="color:#9CA3AF;"><?= date('H:i', strtotime($row['created_at'])) ?></span>
            </td>
            <td>
              <div class="actions">
                <?php if($row['status']==='New'): ?>
                <form method="POST" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?=$row['id']?>">
                  <input type="hidden" name="action" value="mark_read">
                  <button class="btn-sm btn-read">Mark Read</button>
                </form>
                <?php endif; ?>
                <?php if($row['status']!=='Replied'): ?>
                <form method="POST" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?=$row['id']?>">
                  <input type="hidden" name="action" value="mark_replied">
                  <button class="btn-sm btn-replied">Mark Replied</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Delete this message permanently?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?=$row['id']?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn-sm btn-del">Delete</button>
                </form>
              </div>
              <!-- Reply via email link -->
              <a href="mailto:<?=htmlspecialchars($row['email'])?>&subject=Re: <?=urlencode($row['subject'])?>"
                 style="display:inline-block;margin-top:6px;font-size:11px;color:#1A56DB;font-weight:600;">
                📧 Reply via email
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if($pages > 1): ?>
    <div class="pagination">
      <?php for($i=1;$i<=$pages;$i++): ?>
        <a href="?status=<?=$filter?>&page=<?=$i?>" class="pg-btn <?=$i===$page?'active':''?>"><?=$i?></a>
      <?php endfor; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function toggleMsg(btn) {
  const panel = btn.nextElementSibling;
  const open  = panel.style.display === 'block';
  panel.style.display = open ? 'none' : 'block';
  btn.textContent     = open ? '▼ Read full message' : '▲ Hide message';
}
</script>
</body>
</html>