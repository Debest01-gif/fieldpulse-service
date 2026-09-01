<?php
$pageTitle = 'Job Cards';
require_once __DIR__ . '/config/db.php';
$db = get_db();

// Handle Delete Job
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_job'])) {
    $delId = (int)$_POST['job_id'];
    if ($delId > 0) {
        $stmtJ = $db->prepare("SELECT job_number FROM job_cards WHERE id = ?");
        $stmtJ->execute([$delId]);
        $jNo = $stmtJ->fetchColumn();

        $db->prepare("DELETE FROM job_cards WHERE id = ?")->execute([$delId]);
        set_flash('success', "Job card {$jNo} deleted successfully.");
        header("Location: jobs.php");
        exit;
    }
}

// Filters
$statusFilter = clean($_GET['status'] ?? '');
$tradeFilter = clean($_GET['trade'] ?? '');
$techFilter = (int)($_GET['tech_id'] ?? 0);
$priorityFilter = clean($_GET['priority'] ?? '');

$query = "
    SELECT j.*, c.name as customer_name, c.phone as customer_phone, c.estate_area, c.landmark,
           u.name as tech_name, u.phone as tech_phone, u.trade_skills,
           a.asset_name
    FROM job_cards j
    JOIN customers c ON j.customer_id = c.id
    JOIN users u ON j.technician_id = u.id
    LEFT JOIN customer_assets a ON j.asset_id = a.id
    WHERE 1=1
";
$params = [];

if ($statusFilter) {
    $query .= " AND j.status = ?";
    $params[] = $statusFilter;
}
if ($tradeFilter) {
    $query .= " AND j.trade_category = ?";
    $params[] = $tradeFilter;
}
if ($techFilter) {
    $query .= " AND j.technician_id = ?";
    $params[] = $techFilter;
}
if ($priorityFilter) {
    $query .= " AND j.priority = ?";
    $params[] = $priorityFilter;
}

$query .= " ORDER BY j.scheduled_date DESC, j.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

// Technicians for filter
$techs = $db->query("SELECT id, name FROM users WHERE role = 'technician' ORDER BY name ASC")->fetchAll();
$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'Cleaning', 'Maintenance'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Job Cards Management</h1>
        <p class="page-subtitle">Track work orders from dispatch through diagnosis, parts logging, to final customer sign-off</p>
    </div>
    <div class="page-actions">
        <a href="job_create.php" class="btn btn-primary">
            <i data-lucide="plus-circle"></i> Create Job Card
        </a>
    </div>
</div>

<!-- Status Navigation Tabs -->
<div style="display: flex; gap: 8px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 4px;">
    <a href="jobs.php" class="btn btn-sm <?= empty($statusFilter) ? 'btn-primary' : 'btn-secondary' ?>">All Jobs</a>
    <a href="jobs.php?status=scheduled" class="btn btn-sm <?= $statusFilter == 'scheduled' ? 'btn-primary' : 'btn-secondary' ?>">📅 Scheduled</a>
    <a href="jobs.php?status=en_route" class="btn btn-sm <?= $statusFilter == 'en_route' ? 'btn-primary' : 'btn-secondary' ?>">🚚 En Route</a>
    <a href="jobs.php?status=in_progress" class="btn btn-sm <?= $statusFilter == 'in_progress' ? 'btn-primary' : 'btn-secondary' ?>">⚡ In Progress</a>
    <a href="jobs.php?status=pending_parts" class="btn btn-sm <?= $statusFilter == 'pending_parts' ? 'btn-primary' : 'btn-secondary' ?>">📦 Pending Parts</a>
    <a href="jobs.php?status=completed" class="btn btn-sm <?= $statusFilter == 'completed' ? 'btn-primary' : 'btn-secondary' ?>">✅ Completed</a>
    <a href="jobs.php?status=signed_off" class="btn btn-sm <?= $statusFilter == 'signed_off' ? 'btn-primary' : 'btn-secondary' ?>">✍️ Signed & Closed</a>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 24px; padding: 16px 20px;">
    <form method="GET" action="jobs.php" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center;">
        <?php if ($statusFilter): ?>
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
        <?php endif; ?>

        <div style="flex: 1; min-width: 160px;">
            <select name="trade" class="form-control" onchange="this.form.submit()">
                <option value="">-- Filter by Trade --</option>
                <?php foreach ($tradeCategories as $trade): ?>
                    <option value="<?= $trade ?>" <?= $tradeFilter == $trade ? 'selected' : '' ?>><?= $trade ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 160px;">
            <select name="tech_id" class="form-control" onchange="this.form.submit()">
                <option value="">-- Filter by Technician --</option>
                <?php foreach ($techs as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $techFilter == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 160px;">
            <select name="priority" class="form-control" onchange="this.form.submit()">
                <option value="">-- Filter by Priority --</option>
                <option value="emergency" <?= $priorityFilter == 'emergency' ? 'selected' : '' ?>>Emergency</option>
                <option value="urgent" <?= $priorityFilter == 'urgent' ? 'selected' : '' ?>>Urgent</option>
                <option value="normal" <?= $priorityFilter == 'normal' ? 'selected' : '' ?>>Normal</option>
                <option value="low" <?= $priorityFilter == 'low' ? 'selected' : '' ?>>Low</option>
            </select>
        </div>

        <?php if ($tradeFilter || $techFilter || $priorityFilter): ?>
            <a href="jobs.php<?= $statusFilter ? '?status='.$statusFilter : '' ?>" class="btn btn-secondary btn-sm"><i data-lucide="x"></i> Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Job Cards Table -->
<div class="card">
    <div class="table-responsive">
        <table class="custom-table" id="mainTable">
            <thead>
                <tr>
                    <th>Job Number</th>
                    <th>Customer & Location</th>
                    <th>Trade Category</th>
                    <th>Assigned Tech</th>
                    <th>Schedule</th>
                    <th>Status</th>
                    <th>Customer Sign-off</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jobs)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                            <i data-lucide="clipboard" style="width: 36px; height: 36px; margin: 0 auto 10px; display: block; opacity: 0.5;"></i>
                            No job cards found matching your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td>
                                <a href="job_view.php?id=<?= $job['id'] ?>" style="font-weight: 800; color: var(--primary); font-family: var(--font-mono); font-size: 14px;">
                                    <?= htmlspecialchars($job['job_number']) ?>
                                </a>
                                <div style="margin-top: 3px;"><?= get_priority_badge($job['priority']) ?></div>
                            </td>
                            <td>
                                <div class="table-cell-title"><?= htmlspecialchars($job['customer_name']) ?></div>
                                <div class="table-cell-sub">
                                    <i data-lucide="map-pin" style="width: 11px; height: 11px; vertical-align: middle;"></i>
                                    <?= htmlspecialchars($job['estate_area']) ?>
                                </div>
                            </td>
                            <td>
                                <?= get_trade_badge($job['trade_category']) ?>
                                <div style="font-size: 12px; font-weight: 500; margin-top: 3px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?= htmlspecialchars($job['title']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; font-size: 13px;"><?= htmlspecialchars($job['tech_name']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($job['tech_phone']) ?></div>
                            </td>
                            <td>
                                <div style="font-size: 12.5px; font-weight: 600;"><?= format_date($job['scheduled_date']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($job['scheduled_time']) ?></div>
                            </td>
                            <td>
                                <?= get_status_badge($job['status']) ?>
                            </td>
                            <td>
                                <?php if ($job['signature_data']): ?>
                                    <span class="badge badge-success"><i data-lucide="shield-check" class="badge-icon"></i> Signed</span>
                                    <div style="font-size: 10.5px; color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($job['signed_by_name'] ?: 'Customer') ?></div>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: var(--text-muted);">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <a href="job_view.php?id=<?= $job['id'] ?>" class="btn btn-primary btn-sm" title="View & Manage Job">
                                        <i data-lucide="eye" style="width: 13px; height: 13px;"></i>
                                    </a>
                                    <a href="job_edit.php?id=<?= $job['id'] ?>" class="btn btn-secondary btn-sm" title="Edit Job Details">
                                        <i data-lucide="edit" style="width: 13px; height: 13px;"></i>
                                    </a>
                                    <a href="job_print.php?id=<?= $job['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="Print Job Sheet">
                                        <i data-lucide="printer" style="width: 13px; height: 13px;"></i>
                                    </a>
                                    <?php
                                    $waMsg = "FieldPulse Kenya: Job {$job['job_number']} assigned to {$job['tech_name']}. Customer: {$job['customer_name']} ({$job['estate_area']}). Time: {$job['scheduled_time']} {$job['scheduled_date']}. Task: {$job['title']}";
                                    $waUrl = get_whatsapp_url($job['tech_phone'], $waMsg);
                                    ?>
                                    <a href="<?= $waUrl ?>" target="_blank" class="btn btn-whatsapp btn-sm" title="Send WhatsApp to Tech">
                                        <i data-lucide="message-square" style="width: 13px; height: 13px;"></i>
                                    </a>
                                    <form method="POST" action="jobs.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete job card \'<?= htmlspecialchars(addslashes($job['job_number'])) ?>\'?');">
                                        <input type="hidden" name="delete_job" value="1">
                                        <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background: rgba(225, 29, 72, 0.15); color: #f43f5e; border: 1px solid rgba(225, 29, 72, 0.3); padding: 5px 8px;" title="Delete Job Card">
                                            <i data-lucide="trash-2" style="width: 13px; height: 13px;"></i>
                                        </button>
                                    </form>
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
