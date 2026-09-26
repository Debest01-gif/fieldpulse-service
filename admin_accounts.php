<?php
$pageTitle = 'Account Management';
require_once __DIR__ . '/config/db.php';
require_role('admin');
$db = get_db();

// Ensure account_status column exists
try { $db->exec("ALTER TABLE users ADD COLUMN account_status TEXT NOT NULL DEFAULT 'active'"); } catch(Exception $e){}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId > 0) {
        if ($action === 'approve') {
            $db->prepare("UPDATE users SET account_status = 'active' WHERE id = ?")->execute([$userId]);
            // Get user info to notify
            $u = $db->prepare("SELECT name, email FROM users WHERE id = ?")->execute([$userId]) ? $db->query("SELECT name, email FROM users WHERE id = $userId")->fetch() : null;
            create_notification("Account Approved", "Your account has been approved. You can now sign in.", 'user', null, $userId);
            set_flash('success', 'Account approved successfully.');
        } elseif ($action === 'suspend') {
            $db->prepare("UPDATE users SET account_status = 'suspended' WHERE id = ?")->execute([$userId]);
            set_flash('success', 'Account suspended.');
        } elseif ($action === 'activate') {
            $db->prepare("UPDATE users SET account_status = 'active' WHERE id = ?")->execute([$userId]);
            set_flash('success', 'Account activated.');
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM users WHERE id = ? AND role NOT IN ('admin')")->execute([$userId]);
            set_flash('success', 'Account deleted.');
        }
    }
    header("Location: admin_accounts.php");
    exit;
}

// Filter
$filter = $_GET['filter'] ?? 'all';
$search = clean($_GET['q'] ?? '');

$where = "WHERE 1=1";
if ($filter === 'pending') $where .= " AND account_status = 'pending'";
elseif ($filter === 'active') $where .= " AND account_status = 'active'";
elseif ($filter === 'suspended') $where .= " AND account_status = 'suspended'";

if ($search) $where .= " AND (name LIKE " . $db->quote("%$search%") . " OR email LIKE " . $db->quote("%$search%") . " OR phone LIKE " . $db->quote("%$search%") . ")";

$users = $db->query("SELECT * FROM users $where ORDER BY CASE account_status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, created_at DESC")->fetchAll();

$pendingCount = $db->query("SELECT COUNT(*) FROM users WHERE account_status = 'pending'")->fetchColumn();
$activeCount  = $db->query("SELECT COUNT(*) FROM users WHERE account_status = 'active'")->fetchColumn();
$suspendedCount = $db->query("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'")->fetchColumn();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i data-lucide="users-2" style="width:22px;height:22px;color:#38bdf8;vertical-align:middle;"></i>
            Account Management
            <?php if ($pendingCount > 0): ?>
                <span class="badge badge-danger" style="font-size:13px;margin-left:8px;"><?= $pendingCount ?> Pending</span>
            <?php endif; ?>
        </h1>
        <p class="page-subtitle">Approve new customer registrations, manage access, and control user accounts</p>
    </div>
    <div class="page-actions">
        <a href="register.php" class="btn btn-primary">
            <i data-lucide="user-plus"></i> Add Staff Account
        </a>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card" style="cursor:pointer;" onclick="location.href='admin_accounts.php?filter=pending'">
        <div class="stat-info">
            <span class="stat-label">Pending Approval</span>
            <span class="stat-value" style="color:#f59e0b;"><?= $pendingCount ?></span>
            <span class="stat-subtext">Awaiting review</span>
        </div>
        <div class="stat-icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;"><i data-lucide="clock"></i></div>
    </div>
    <div class="stat-card" style="cursor:pointer;" onclick="location.href='admin_accounts.php?filter=active'">
        <div class="stat-info">
            <span class="stat-label">Active Accounts</span>
            <span class="stat-value" style="color:#34d399;"><?= $activeCount ?></span>
            <span class="stat-subtext">Currently active</span>
        </div>
        <div class="stat-icon" style="background:rgba(16,185,129,0.15);color:#34d399;"><i data-lucide="check-circle-2"></i></div>
    </div>
    <div class="stat-card" style="cursor:pointer;" onclick="location.href='admin_accounts.php?filter=suspended'">
        <div class="stat-info">
            <span class="stat-label">Suspended</span>
            <span class="stat-value" style="color:#f43f5e;"><?= $suspendedCount ?></span>
            <span class="stat-subtext">Access disabled</span>
        </div>
        <div class="stat-icon" style="background:rgba(244,63,94,0.15);color:#f43f5e;"><i data-lucide="x-circle"></i></div>
    </div>
</div>

<!-- Filters + Search -->
<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:20px;">
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php foreach(['all'=>'All Accounts','pending'=>'Pending','active'=>'Active','suspended'=>'Suspended'] as $k=>$label): ?>
            <a href="admin_accounts.php?filter=<?= $k ?><?= $search ? '&q='.urlencode($search) : '' ?>"
               class="btn btn-sm <?= $filter === $k ? 'btn-primary' : 'btn-secondary' ?>">
                <?= $label ?>
                <?php if($k === 'pending' && $pendingCount > 0): ?><span class="badge badge-danger" style="margin-left:4px;"><?= $pendingCount ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <form method="GET" action="admin_accounts.php" style="display:flex;gap:6px;margin-left:auto;">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="form-control" style="width:220px;padding:8px 12px;" placeholder="Search name, email, phone…">
        <button type="submit" class="btn btn-secondary btn-sm"><i data-lucide="search"></i></button>
    </form>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <span class="card-title"><i data-lucide="list"></i> Users (<?= count($users) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="custom-table" id="mainTable">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Contact</th>
                    <th>Role</th>
                    <th>Account Status</th>
                    <th>Registered</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">No accounts found.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                <?php
                    $acctStatus = $u['account_status'] ?? 'active';
                    $statusClass = match($acctStatus) {
                        'pending' => 'badge-warning',
                        'active'  => 'badge-success',
                        'suspended' => 'badge-danger',
                        default => 'badge-secondary'
                    };
                    $statusIcon = match($acctStatus) {
                        'pending' => 'clock',
                        'active'  => 'check-circle',
                        'suspended' => 'x-circle',
                        default => 'circle'
                    };
                ?>
                <tr>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#0284c7,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0;">
                                <?= strtoupper(substr($u['name'], 0, 2)) ?>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:13.5px;"><?= htmlspecialchars($u['name']) ?></div>
                                <div style="font-size:11.5px;color:var(--text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="font-size:13px;"><?= htmlspecialchars($u['phone']) ?></td>
                    <td>
                        <?php
                            $roleColors = ['admin'=>'badge-danger','dispatcher'=>'badge-purple','technician'=>'badge-info','member'=>'badge-teal'];
                            $rc = $roleColors[$u['role']] ?? 'badge-secondary';
                        ?>
                        <span class="badge <?= $rc ?>"><?= ucfirst($u['role']) ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $statusClass ?>">
                            <i data-lucide="<?= $statusIcon ?>" class="badge-icon"></i>
                            <?= ucfirst($acctStatus) ?>
                        </span>
                    </td>
                    <td style="font-size:12px;color:var(--text-muted);"><?= format_date($u['created_at']) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;">
                            <?php if ($acctStatus === 'pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary"
                                        onclick="return confirm('Approve account for <?= htmlspecialchars($u['name']) ?>?')">
                                        <i data-lucide="check"></i> Approve
                                    </button>
                                </form>
                            <?php elseif ($acctStatus === 'active'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="suspend">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-secondary"
                                        onclick="return confirm('Suspend this account?')">
                                        <i data-lucide="pause-circle"></i> Suspend
                                    </button>
                                </form>
                            <?php elseif ($acctStatus === 'suspended'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary"
                                        onclick="return confirm('Re-activate this account?')">
                                        <i data-lucide="play-circle"></i> Activate
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($u['role'] !== 'admin'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Permanently delete this account? This cannot be undone.')">
                                    <i data-lucide="trash-2"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
