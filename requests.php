<?php
$pageTitle = 'Service Requests';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

// Handle Delete Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $delId = (int)$_POST['request_id'];
    if ($delId > 0) {
        $stmtT = $db->prepare("SELECT ticket_no FROM service_requests WHERE id = ?");
        $stmtT->execute([$delId]);
        $tNo = $stmtT->fetchColumn();

        $db->prepare("DELETE FROM service_requests WHERE id = ?")->execute([$delId]);
        set_flash('success', "Service request {$tNo} deleted successfully.");
        header("Location: requests.php");
        exit;
    }
}

// Filters
$statusFilter = clean($_GET['status'] ?? '');
$tradeFilter = clean($_GET['trade'] ?? '');
$priorityFilter = clean($_GET['priority'] ?? '');

$query = "
    SELECT r.*, c.name as customer_name, c.phone as customer_phone, c.estate_area, c.landmark,
           a.asset_name, a.brand as asset_brand
    FROM service_requests r
    JOIN customers c ON r.customer_id = c.id
    LEFT JOIN customer_assets a ON r.asset_id = a.id
    WHERE 1=1
";
$params = [];

if ($statusFilter) {
    $query .= " AND r.status = ?";
    $params[] = $statusFilter;
}
if ($tradeFilter) {
    $query .= " AND r.trade_category = ?";
    $params[] = $tradeFilter;
}
if ($priorityFilter) {
    $query .= " AND r.priority = ?";
    $params[] = $priorityFilter;
}

$query .= " ORDER BY r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Trades for filter dropdown
$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'Cleaning', 'Maintenance'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Service Requests & Inquiries</h1>
        <p class="page-subtitle">Incoming customer requests waiting for assessment, quote, or technician dispatch</p>
    </div>
    <div class="page-actions">
        <a href="request_create.php" class="btn btn-primary">
            <i data-lucide="plus-circle"></i> New Service Request
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 24px; padding: 16px 20px;">
    <form method="GET" action="requests.php" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center;">
        <div style="flex: 1; min-width: 160px;">
            <select name="status" class="form-control" onchange="this.form.submit()">
                <option value="">-- All Statuses --</option>
                <option value="new" <?= $statusFilter == 'new' ? 'selected' : '' ?>>New / Unassigned</option>
                <option value="assigned" <?= $statusFilter == 'assigned' ? 'selected' : '' ?>>Assigned / Dispatched</option>
                <option value="in_progress" <?= $statusFilter == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                <option value="completed" <?= $statusFilter == 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
        </div>

        <div style="flex: 1; min-width: 160px;">
            <select name="trade" class="form-control" onchange="this.form.submit()">
                <option value="">-- All Trades --</option>
                <?php foreach ($tradeCategories as $trade): ?>
                    <option value="<?= $trade ?>" <?= $tradeFilter == $trade ? 'selected' : '' ?>><?= $trade ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 160px;">
            <select name="priority" class="form-control" onchange="this.form.submit()">
                <option value="">-- All Priorities --</option>
                <option value="emergency" <?= $priorityFilter == 'emergency' ? 'selected' : '' ?>>Emergency</option>
                <option value="urgent" <?= $priorityFilter == 'urgent' ? 'selected' : '' ?>>Urgent</option>
                <option value="normal" <?= $priorityFilter == 'normal' ? 'selected' : '' ?>>Normal</option>
                <option value="low" <?= $priorityFilter == 'low' ? 'selected' : '' ?>>Low</option>
            </select>
        </div>

        <?php if ($statusFilter || $tradeFilter || $priorityFilter): ?>
            <a href="requests.php" class="btn btn-secondary btn-sm"><i data-lucide="x"></i> Clear Filters</a>
        <?php endif; ?>
    </form>
</div>

<!-- Requests Table -->
<div class="card">
    <div class="table-responsive">
        <table class="custom-table" id="mainTable">
            <thead>
                <tr>
                    <th>Ticket #</th>
                    <th>Customer & Site</th>
                    <th>Trade Category</th>
                    <th>Issue Summary</th>
                    <th>Preferred Schedule</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                            <i data-lucide="inbox" style="width: 36px; height: 36px; margin: 0 auto 10px; display: block; opacity: 0.5;"></i>
                            No service requests found matching your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                        <tr>
                            <td>
                                <span style="font-weight: 700; color: var(--text-main); font-family: var(--font-mono);">
                                    <?= htmlspecialchars($req['ticket_no']) ?>
                                </span>
                                <div style="font-size: 11px; color: var(--text-light);"><?= time_ago($req['created_at']) ?></div>
                            </td>
                            <td>
                                <div class="table-cell-title"><?= htmlspecialchars($req['customer_name']) ?></div>
                                <div class="table-cell-sub">
                                    <i data-lucide="map-pin" style="width: 11px; height: 11px; vertical-align: middle;"></i>
                                    <?= htmlspecialchars($req['estate_area']) ?>
                                    <?php if ($req['landmark']): ?>
                                        <span style="opacity: 0.8;">(<?= htmlspecialchars($req['landmark']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?= get_trade_badge($req['trade_category']) ?>
                                <?php if ($req['asset_name']): ?>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 3px;">
                                        <i data-lucide="cpu" style="width: 10px; height: 10px; vertical-align: middle;"></i> <?= htmlspecialchars($req['asset_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="max-width: 280px;">
                                <div style="font-weight: 600;"><?= htmlspecialchars($req['title']) ?></div>
                                <div style="font-size: 12px; color: var(--text-muted); line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                                    <?= htmlspecialchars($req['description']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 12.5px; font-weight: 500;"><?= format_date($req['preferred_date']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($req['preferred_time_slot'] ?: 'Anytime') ?></div>
                            </td>
                            <td><?= get_priority_badge($req['priority']) ?></td>
                            <td><?= get_status_badge($req['status']) ?></td>
                            <td>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <?php if ($req['status'] === 'new'): ?>
                                        <a href="job_create.php?request_id=<?= $req['id'] ?>" class="btn btn-primary btn-sm" title="Dispatch to Field Technician">
                                            <i data-lucide="send" style="width: 13px; height: 13px;"></i> Dispatch
                                        </a>
                                    <?php endif; ?>

                                    <a href="request_edit.php?id=<?= $req['id'] ?>" class="btn btn-secondary btn-sm" title="Edit Service Request">
                                        <i data-lucide="edit" style="width: 13px; height: 13px;"></i>
                                    </a>

                                    <?php
                                    $waCustomerMsg = "Hello {$req['customer_name']}, we have received your service request ({$req['ticket_no']} - {$req['title']}). Our dispatch team is assigning a technician shortly.";
                                    $waCustomerUrl = get_whatsapp_url($req['customer_phone'], $waCustomerMsg);
                                    ?>
                                    <a href="<?= $waCustomerUrl ?>" target="_blank" class="btn btn-whatsapp btn-sm" title="WhatsApp Customer Update">
                                        <i data-lucide="message-square" style="width: 13px; height: 13px;"></i>
                                    </a>

                                    <form method="POST" action="requests.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete service request \'<?= htmlspecialchars(addslashes($req['ticket_no'])) ?>\'?');">
                                        <input type="hidden" name="delete_request" value="1">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background: rgba(225, 29, 72, 0.15); color: #f43f5e; border: 1px solid rgba(225, 29, 72, 0.3); padding: 5px 8px;" title="Delete Request">
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
