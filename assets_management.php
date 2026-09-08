<?php
$pageTitle = 'Equipment & Assets';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

// Handle Add Asset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_asset'])) {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $assetName = clean($_POST['asset_name'] ?? '');
    $category = clean($_POST['category'] ?? '');
    $brand = clean($_POST['brand'] ?? '');
    $modelNumber = clean($_POST['model_number'] ?? '');
    $serialNumber = clean($_POST['serial_number'] ?? '');
    $installDate = !empty($_POST['install_date']) ? clean($_POST['install_date']) : null;
    $warrantyExpiry = !empty($_POST['warranty_expiry']) ? clean($_POST['warranty_expiry']) : null;
    $locationAtSite = clean($_POST['location_at_site'] ?? '');
    $status = clean($_POST['status'] ?? 'active');
    $notes = clean($_POST['notes'] ?? '');

    if ($customerId > 0 && $assetName && $category) {
        $stmt = $db->prepare("
            INSERT INTO customer_assets (customer_id, asset_name, category, brand, model_number, serial_number, install_date, warranty_expiry, location_at_site, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$customerId, $assetName, $category, $brand, $modelNumber, $serialNumber, $installDate, $warrantyExpiry, $locationAtSite, $status, $notes]);

        set_flash('success', "Equipment asset '{$assetName}' registered successfully.");
        header("Location: assets_management.php");
        exit;
    } else {
        set_flash('error', 'Please select a Customer and specify Asset Name and Category.');
    }
}

// Handle Edit Asset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_asset'])) {
    $assetId = (int)$_POST['asset_id'];
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $assetName = clean($_POST['asset_name'] ?? '');
    $category = clean($_POST['category'] ?? '');
    $brand = clean($_POST['brand'] ?? '');
    $modelNumber = clean($_POST['model_number'] ?? '');
    $serialNumber = clean($_POST['serial_number'] ?? '');
    $installDate = !empty($_POST['install_date']) ? clean($_POST['install_date']) : null;
    $warrantyExpiry = !empty($_POST['warranty_expiry']) ? clean($_POST['warranty_expiry']) : null;
    $locationAtSite = clean($_POST['location_at_site'] ?? '');
    $status = clean($_POST['status'] ?? 'active');
    $notes = clean($_POST['notes'] ?? '');

    if ($assetId > 0 && $customerId > 0 && $assetName && $category) {
        $stmt = $db->prepare("
            UPDATE customer_assets
            SET customer_id = ?, asset_name = ?, category = ?, brand = ?, model_number = ?, serial_number = ?,
                install_date = ?, warranty_expiry = ?, location_at_site = ?, status = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$customerId, $assetName, $category, $brand, $modelNumber, $serialNumber, $installDate, $warrantyExpiry, $locationAtSite, $status, $notes, $assetId]);

        set_flash('success', "Asset '{$assetName}' updated successfully.");
        header("Location: assets_management.php");
        exit;
    }
}

// Handle Delete Asset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_asset'])) {
    $assetId = (int)$_POST['asset_id'];
    if ($assetId > 0) {
        $stmtName = $db->prepare("SELECT asset_name FROM customer_assets WHERE id = ?");
        $stmtName->execute([$assetId]);
        $aName = $stmtName->fetchColumn();

        $db->prepare("DELETE FROM customer_assets WHERE id = ?")->execute([$assetId]);
        set_flash('success', "Asset '{$aName}' removed from registry.");
        header("Location: assets_management.php");
        exit;
    }
}

$categoryFilter = clean($_GET['category'] ?? '');
$statusFilter = clean($_GET['status'] ?? '');

$query = "
    SELECT a.*, c.name as customer_name, c.estate_area,
           (SELECT COUNT(*) FROM job_cards j WHERE j.asset_id = a.id) as service_count
    FROM customer_assets a
    JOIN customers c ON a.customer_id = c.id
    WHERE 1=1
";
$params = [];
if ($categoryFilter) {
    $query .= " AND a.category = ?";
    $params[] = $categoryFilter;
}
if ($statusFilter) {
    $query .= " AND a.status = ?";
    $params[] = $statusFilter;
}
$query .= " ORDER BY a.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$assets = $stmt->fetchAll();

// All customers for dropdown
$allCustomers = $db->query("SELECT id, name, estate_area FROM customers ORDER BY name ASC")->fetchAll();

$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'Cleaning', 'Maintenance'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Installed Assets & Equipment Registry</h1>
        <p class="page-subtitle">Track hardware, inverters, CCTV NVRs, generators, and AC systems installed at customer sites</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('addAssetModal')">
            <i data-lucide="plus-circle"></i> Register New Equipment
        </button>
    </div>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 24px; padding: 16px 20px;">
    <form method="GET" action="assets_management.php" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center;">
        <div style="flex: 1; min-width: 160px;">
            <select name="category" class="form-control" onchange="this.form.submit()">
                <option value="">-- All Trade Categories --</option>
                <?php foreach ($tradeCategories as $cat): ?>
                    <option value="<?= $cat ?>" <?= $categoryFilter == $cat ? 'selected' : '' ?>><?= $cat ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 160px;">
            <select name="status" class="form-control" onchange="this.form.submit()">
                <option value="">-- All Equipment Statuses --</option>
                <option value="active" <?= $statusFilter == 'active' ? 'selected' : '' ?>>Active & Healthy</option>
                <option value="needs_service" <?= $statusFilter == 'needs_service' ? 'selected' : '' ?>>Needs Service</option>
                <option value="decommissioned" <?= $statusFilter == 'decommissioned' ? 'selected' : '' ?>>Decommissioned</option>
            </select>
        </div>

        <?php if ($categoryFilter || $statusFilter): ?>
            <a href="assets_management.php" class="btn btn-secondary btn-sm"><i data-lucide="x"></i> Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Assets Table -->
<div class="card">
    <div class="table-responsive">
        <table class="custom-table" id="mainTable">
            <thead>
                <tr>
                    <th>Equipment / Asset</th>
                    <th>Customer & Site</th>
                    <th>Category</th>
                    <th>Brand & Model</th>
                    <th>Serial Number</th>
                    <th>Installed Date</th>
                    <th>Warranty</th>
                    <th>Service Logs</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assets)): ?>
                    <tr><td colspan="10" style="text-align: center; padding: 30px; color: var(--text-muted);">No assets registered yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($assets as $ast): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($ast['asset_name']) ?></div>
                                <?php if ($ast['location_at_site']): ?>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($ast['location_at_site']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="customer_view.php?id=<?= $ast['customer_id'] ?>" style="font-weight: 600;">
                                    <?= htmlspecialchars($ast['customer_name']) ?>
                                </a>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($ast['estate_area']) ?></div>
                            </td>
                            <td><?= get_trade_badge($ast['category']) ?></td>
                            <td><?= htmlspecialchars($ast['brand'] ?: '-') ?> <?= htmlspecialchars($ast['model_number'] ?: '') ?></td>
                            <td><span style="font-family: var(--font-mono); font-weight: 600; font-size: 12px;"><?= htmlspecialchars($ast['serial_number'] ?: 'N/A') ?></span></td>
                            <td><?= format_date($ast['install_date']) ?></td>
                            <td>
                                <?php if ($ast['warranty_expiry'] && strtotime($ast['warranty_expiry']) < time()): ?>
                                    <span style="color: var(--danger); font-weight: 600; font-size: 11.5px;">Expired</span>
                                <?php else: ?>
                                    <span style="font-size: 12px;"><?= format_date($ast['warranty_expiry']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-info"><?= $ast['service_count'] ?> Log<?= $ast['service_count'] == 1 ? '' : 's' ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $ast['status'] === 'active' ? 'badge-success' : ($ast['status'] === 'needs_service' ? 'badge-warning' : 'badge-muted') ?>">
                                    <?= ucfirst(str_replace('_', ' ', $ast['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <button type="button" class="btn btn-secondary btn-sm" title="Edit Asset"
                                            onclick='editAsset(<?= json_encode($ast) ?>)'>
                                        <i data-lucide="edit" style="width: 13px; height: 13px;"></i>
                                    </button>
                                    <form method="POST" action="assets_management.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete equipment \'<?= htmlspecialchars(addslashes($ast['asset_name'])) ?>\'?');">
                                        <input type="hidden" name="delete_asset" value="1">
                                        <input type="hidden" name="asset_id" value="<?= $ast['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background: rgba(225, 29, 72, 0.15); color: #f43f5e; border: 1px solid rgba(225, 29, 72, 0.3); padding: 5px 8px;" title="Delete Asset">
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

<!-- Modal: Add New Asset -->
<div id="addAssetModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="cpu"></i> Register Installed Equipment</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="assets_management.php">
            <input type="hidden" name="add_asset" value="1">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Customer / Site <span class="required">*</span></label>
                    <select name="customer_id" class="form-control" required>
                        <option value="">-- Select Customer --</option>
                        <?php foreach ($allCustomers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['estate_area']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Asset / Equipment Name <span class="required">*</span></label>
                    <input type="text" name="asset_name" class="form-control" placeholder="e.g. Deye 10kW Hybrid Inverter" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Trade Category <span class="required">*</span></label>
                        <select name="category" class="form-control" required>
                            <?php foreach ($tradeCategories as $trade): ?>
                                <option value="<?= $trade ?>"><?= $trade ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Brand / Manufacturer</label>
                        <input type="text" name="brand" class="form-control" placeholder="e.g. Deye, Hikvision, Perkins">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Model Number</label>
                        <input type="text" name="model_number" class="form-control" placeholder="e.g. SUN-10K-SG04LP3">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Serial Number</label>
                        <input type="text" name="serial_number" class="form-control" placeholder="e.g. SN-8841-2024">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Installation Date</label>
                        <input type="date" name="install_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Warranty Expiry Date</label>
                        <input type="date" name="warranty_expiry" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Location at Site / Room</label>
                        <input type="text" name="location_at_site" class="form-control" placeholder="e.g. Outer Utility Room / Roof Panels">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Initial Status</label>
                        <select name="status" class="form-control">
                            <option value="active">Active & Healthy</option>
                            <option value="needs_service">Needs Service</option>
                            <option value="decommissioned">Decommissioned</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Technical Notes / Service Specs</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="e.g. 4x 5.12kWh Shoto Lithium Batteries attached"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Equipment Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Asset -->
<div id="editAssetModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="edit"></i> Edit Installed Equipment</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="assets_management.php">
            <input type="hidden" name="edit_asset" value="1">
            <input type="hidden" name="asset_id" id="edit_asset_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Customer / Site <span class="required">*</span></label>
                    <select name="customer_id" id="edit_customer_id" class="form-control" required>
                        <?php foreach ($allCustomers as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['estate_area']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Asset / Equipment Name <span class="required">*</span></label>
                    <input type="text" name="asset_name" id="edit_asset_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Trade Category <span class="required">*</span></label>
                        <select name="category" id="edit_category" class="form-control" required>
                            <?php foreach ($tradeCategories as $trade): ?>
                                <option value="<?= $trade ?>"><?= $trade ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Brand / Manufacturer</label>
                        <input type="text" name="brand" id="edit_brand" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Model Number</label>
                        <input type="text" name="model_number" id="edit_model_number" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Serial Number</label>
                        <input type="text" name="serial_number" id="edit_serial_number" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Installation Date</label>
                        <input type="date" name="install_date" id="edit_install_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Warranty Expiry Date</label>
                        <input type="date" name="warranty_expiry" id="edit_warranty_expiry" class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Location at Site / Room</label>
                        <input type="text" name="location_at_site" id="edit_location_at_site" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="active">Active & Healthy</option>
                            <option value="needs_service">Needs Service</option>
                            <option value="decommissioned">Decommissioned</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Technical Notes / Service Specs</label>
                    <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function editAsset(data) {
    document.getElementById('edit_asset_id').value = data.id;
    document.getElementById('edit_customer_id').value = data.customer_id;
    document.getElementById('edit_asset_name').value = data.asset_name;
    document.getElementById('edit_category').value = data.category;
    document.getElementById('edit_brand').value = data.brand || '';
    document.getElementById('edit_model_number').value = data.model_number || '';
    document.getElementById('edit_serial_number').value = data.serial_number || '';
    document.getElementById('edit_install_date').value = data.install_date || '';
    document.getElementById('edit_warranty_expiry').value = data.warranty_expiry || '';
    document.getElementById('edit_location_at_site').value = data.location_at_site || '';
    document.getElementById('edit_status').value = data.status || 'active';
    document.getElementById('edit_notes').value = data.notes || '';
    openModal('editAssetModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
