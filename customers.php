<?php
$pageTitle = 'Customers & Sites';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

$typeFilter = clean($_GET['type'] ?? '');

// Handle Delete Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_customer'])) {
    $delId = (int)$_POST['customer_id'];
    if ($delId > 0) {
        $stmtCust = $db->prepare("SELECT name FROM customers WHERE id = ?");
        $stmtCust->execute([$delId]);
        $custName = $stmtCust->fetchColumn();
        
        $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$delId]);
        set_flash('success', "Customer '{$custName}' and related records were deleted successfully.");
        header("Location: customers.php");
        exit;
    }
}

$query = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM customer_assets a WHERE a.customer_id = c.id) as asset_count,
           (SELECT COUNT(*) FROM job_cards j WHERE j.customer_id = c.id) as job_count
    FROM customers c
    WHERE 1=1
";
$params = [];
if ($typeFilter) {
    $query .= " AND c.customer_type = ?";
    $params[] = $typeFilter;
}
$query .= " ORDER BY c.name ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$customers = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Customers & Site Locations</h1>
        <p class="page-subtitle">Client profiles, physical site addresses, registered equipment, and service histories</p>
    </div>
    <div class="page-actions">
        <a href="customer_create.php" class="btn btn-primary">
            <i data-lucide="user-plus"></i> Add New Customer
        </a>
    </div>
</div>

<!-- Type Filter Tabs -->
<div style="display: flex; gap: 8px; margin-bottom: 20px;">
    <a href="customers.php" class="btn btn-sm <?= empty($typeFilter) ? 'btn-primary' : 'btn-secondary' ?>">All Clients</a>
    <a href="customers.php?type=residential" class="btn btn-sm <?= $typeFilter == 'residential' ? 'btn-primary' : 'btn-secondary' ?>">Residential</a>
    <a href="customers.php?type=commercial" class="btn btn-sm <?= $typeFilter == 'commercial' ? 'btn-primary' : 'btn-secondary' ?>">Commercial</a>
    <a href="customers.php?type=industrial" class="btn btn-sm <?= $typeFilter == 'industrial' ? 'btn-primary' : 'btn-secondary' ?>">Industrial</a>
</div>

<!-- Customers Table -->
<div class="card">
    <div class="table-responsive">
        <table class="custom-table" id="mainTable">
            <thead>
                <tr>
                    <th>Customer / Company</th>
                    <th>Contact Person</th>
                    <th>Estate / Physical Area</th>
                    <th>Phone & Email</th>
                    <th>Client Type</th>
                    <th>Assets</th>
                    <th>Job Cards</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 30px; color: var(--text-muted);">No customers found. <a href="customer_create.php">Create customer</a></td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td>
                                <a href="customer_view.php?id=<?= $c['id'] ?>" style="font-weight: 700; color: var(--primary); font-size: 14px;">
                                    <?= htmlspecialchars($c['name']) ?>
                                </a>
                                <?php if ($c['landmark']): ?>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                        <i data-lucide="map-pin" style="width: 10px; height: 10px; vertical-align: middle;"></i> <?= htmlspecialchars($c['landmark']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($c['contact_person'] ?: '-') ?></td>
                            <td><strong><?= htmlspecialchars($c['estate_area']) ?></strong></td>
                            <td>
                                <a href="tel:<?= htmlspecialchars($c['phone']) ?>" style="font-weight: 600;">
                                    <?= htmlspecialchars($c['phone']) ?>
                                </a>
                                <?php if ($c['email']): ?>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($c['email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $c['customer_type'] === 'commercial' ? 'badge-primary' : ($c['customer_type'] === 'industrial' ? 'badge-amber' : 'badge-teal') ?>">
                                    <?= ucfirst($c['customer_type']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-purple"><?= $c['asset_count'] ?> Equipment</span>
                            </td>
                            <td>
                                <span class="badge badge-info"><?= $c['job_count'] ?> Jobs</span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <a href="customer_view.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" title="View Customer Profile & History">
                                        <i data-lucide="eye" style="width: 13px; height: 13px;"></i>
                                    </a>
                                    <a href="customer_edit.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" title="Edit Customer Details">
                                        <i data-lucide="edit" style="width: 13px; height: 13px;"></i>
                                    </a>
                                    <?php
                                    $waUrl = get_whatsapp_url($c['phone'], "Hello {$c['name']}, this is FieldPulse Kenya customer support.");
                                    ?>
                                    <a href="<?= $waUrl ?>" target="_blank" class="btn btn-whatsapp btn-sm" title="WhatsApp Customer">
                                        <i data-lucide="message-square" style="width: 13px; height: 13px;"></i>
                                    </a>
                                    <form method="POST" action="customers.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to permanently delete customer \'<?= htmlspecialchars(addslashes($c['name'])) ?>\'? This will also remove their associated equipment and service records.');">
                                        <input type="hidden" name="delete_customer" value="1">
                                        <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background: rgba(225, 29, 72, 0.15); color: #f43f5e; border: 1px solid rgba(225, 29, 72, 0.3); padding: 5px 8px;" title="Delete Customer">
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
