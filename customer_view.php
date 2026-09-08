<?php
$pageTitle = 'Customer Profile';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

$customerId = (int)($_GET['id'] ?? 0);
if ($customerId <= 0) {
    set_flash('error', 'Invalid Customer ID.');
    header('Location: customers.php');
    exit;
}

// Fetch Customer
$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();
if (!$customer) {
    set_flash('error', 'Customer not found.');
    header('Location: customers.php');
    exit;
}

// Handle Add Asset Post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_asset'])) {
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

    if ($assetName && $category) {
        $stmtAsset = $db->prepare("
            INSERT INTO customer_assets (customer_id, asset_name, category, brand, model_number, serial_number, install_date, warranty_expiry, location_at_site, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtAsset->execute([$customerId, $assetName, $category, $brand, $modelNumber, $serialNumber, $installDate, $warrantyExpiry, $locationAtSite, $status, $notes]);

        set_flash('success', "Equipment asset '{$assetName}' added to customer profile!");
        header("Location: customer_view.php?id={$customerId}");
        exit;
    }
}

// Handle Edit Asset Post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_asset'])) {
    $assetId = (int)$_POST['asset_id'];
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

    if ($assetId > 0 && $assetName && $category) {
        $stmtAsset = $db->prepare("
            UPDATE customer_assets
            SET asset_name = ?, category = ?, brand = ?, model_number = ?, serial_number = ?,
                install_date = ?, warranty_expiry = ?, location_at_site = ?, status = ?, notes = ?
            WHERE id = ? AND customer_id = ?
        ");
        $stmtAsset->execute([$assetName, $category, $brand, $modelNumber, $serialNumber, $installDate, $warrantyExpiry, $locationAtSite, $status, $notes, $assetId, $customerId]);

        set_flash('success', "Asset '{$assetName}' updated successfully.");
        header("Location: customer_view.php?id={$customerId}");
        exit;
    }
}

// Handle Delete Asset Post
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_asset'])) {
    $assetId = (int)$_POST['asset_id'];
    if ($assetId > 0) {
        $db->prepare("DELETE FROM customer_assets WHERE id = ? AND customer_id = ?")->execute([$assetId, $customerId]);
        set_flash('success', "Equipment asset removed successfully.");
        header("Location: customer_view.php?id={$customerId}");
        exit;
    }
}

// Fetch Customer Assets
$stmtAssets = $db->prepare("SELECT * FROM customer_assets WHERE customer_id = ? ORDER BY install_date DESC");
$stmtAssets->execute([$customerId]);
$assets = $stmtAssets->fetchAll();

// Fetch Customer Job History
$stmtJobs = $db->prepare("
    SELECT j.*, u.name as tech_name, a.asset_name
    FROM job_cards j
    JOIN users u ON j.technician_id = u.id
    LEFT JOIN customer_assets a ON j.asset_id = a.id
    WHERE j.customer_id = ?
    ORDER BY j.scheduled_date DESC, j.created_at DESC
");
$stmtJobs->execute([$customerId]);
$jobs = $stmtJobs->fetchAll();

// Fetch Customer Requests
$stmtReqs = $db->prepare("SELECT * FROM service_requests WHERE customer_id = ? ORDER BY created_at DESC");
$stmtReqs->execute([$customerId]);
$requests = $stmtReqs->fetchAll();

$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'Cleaning', 'Maintenance'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <h1 class="page-title"><?= htmlspecialchars($customer['name']) ?></h1>
            <span class="badge <?= $customer['customer_type'] === 'commercial' ? 'badge-primary' : 'badge-teal' ?>">
                <?= ucfirst($customer['customer_type']) ?>
            </span>
        </div>
        <p class="page-subtitle"><?= htmlspecialchars($customer['estate_area']) ?> • <?= htmlspecialchars($customer['address']) ?></p>
    </div>
    <div class="page-actions">
        <a href="customer_edit.php?id=<?= $customer['id'] ?>" class="btn btn-secondary">
            <i data-lucide="edit"></i> Edit Profile
        </a>
        <a href="job_create.php?customer_id=<?= $customer['id'] ?>" class="btn btn-primary">
            <i data-lucide="plus-circle"></i> Create Job Card
        </a>
        <a href="request_create.php?customer_id=<?= $customer['id'] ?>" class="btn btn-secondary">
            <i data-lucide="file-plus"></i> New Service Request
        </a>
        <?php
        $waUrl = get_whatsapp_url($customer['phone'], "Hello {$customer['name']}, this is FieldPulse Kenya.");
        ?>
        <a href="<?= $waUrl ?>" target="_blank" class="btn btn-whatsapp">
            <i data-lucide="message-square"></i> WhatsApp Client
        </a>
        <form method="POST" action="customers.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to permanently delete customer \'<?= htmlspecialchars(addslashes($customer['name'])) ?>\'?');">
            <input type="hidden" name="delete_customer" value="1">
            <input type="hidden" name="customer_id" value="<?= $customer['id'] ?>">
            <button type="submit" class="btn btn-danger" style="background: #e11d48;" title="Delete Customer">
                <i data-lucide="trash-2"></i> Delete
            </button>
        </form>
    </div>
</div>

<div class="grid-2-1">
    <!-- Left Column: Equipment Assets & Job History -->
    <div style="display: flex; flex-direction: column; gap: 24px;">

        <!-- Installed Assets / Equipment -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="cpu"></i> Installed Equipment & Assets</span>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('addAssetModal')">
                    <i data-lucide="plus"></i> Register Equipment
                </button>
            </div>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Asset Name</th>
                            <th>Trade / Category</th>
                            <th>Brand & Model</th>
                            <th>Serial #</th>
                            <th>Installed</th>
                            <th>Warranty</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assets)): ?>
                            <tr><td colspan="8" style="text-align: center; padding: 20px; color: var(--text-muted);">No equipment registered for this customer site yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assets as $ast): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($ast['asset_name']) ?></strong>
                                        <?php if ($ast['location_at_site']): ?>
                                            <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($ast['location_at_site']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= get_trade_badge($ast['category']) ?></td>
                                    <td><?= htmlspecialchars($ast['brand'] ?: '-') ?> <?= htmlspecialchars($ast['model_number'] ?: '') ?></td>
                                    <td><span style="font-family: var(--font-mono); font-size: 11.5px;"><?= htmlspecialchars($ast['serial_number'] ?: 'N/A') ?></span></td>
                                    <td><?= format_date($ast['install_date']) ?></td>
                                    <td>
                                        <?php if ($ast['warranty_expiry'] && strtotime($ast['warranty_expiry']) < time()): ?>
                                            <span style="color: var(--danger); font-size: 11.5px; font-weight: 600;">Expired</span>
                                        <?php else: ?>
                                            <?= format_date($ast['warranty_expiry']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $ast['status'] === 'active' ? 'badge-success' : 'badge-warning' ?>">
                                            <?= ucfirst(str_replace('_', ' ', $ast['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 6px; align-items: center;">
                                            <button type="button" class="btn btn-secondary btn-sm" title="Edit Asset"
                                                    onclick='editAsset(<?= json_encode($ast) ?>)'>
                                                <i data-lucide="edit" style="width: 12px; height: 12px;"></i>
                                            </button>
                                            <form method="POST" action="customer_view.php?id=<?= $customerId ?>" style="display: inline-block;" onsubmit="return confirm('Delete equipment \'<?= htmlspecialchars(addslashes($ast['asset_name'])) ?>\'?');">
                                                <input type="hidden" name="delete_asset" value="1">
                                                <input type="hidden" name="asset_id" value="<?= $ast['id'] ?>">
                                                <button type="submit" class="btn btn-sm" style="background: rgba(225, 29, 72, 0.15); color: #f43f5e; border: 1px solid rgba(225, 29, 72, 0.3); padding: 4px 6px;" title="Delete Asset">
                                                    <i data-lucide="trash-2" style="width: 12px; height: 12px;"></i>
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

        <!-- Full Job Cards History -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="history"></i> Work Orders & Service History</span>
            </div>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Job #</th>
                            <th>Trade</th>
                            <th>Task Objective</th>
                            <th>Technician</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Sign-off</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jobs)): ?>
                            <tr><td colspan="8" style="text-align: center; padding: 20px; color: var(--text-muted);">No job cards on file for this customer yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($jobs as $jb): ?>
                                <tr>
                                    <td>
                                        <a href="job_view.php?id=<?= $jb['id'] ?>" style="font-weight: 700; color: var(--primary);">
                                            <?= htmlspecialchars($jb['job_number']) ?>
                                        </a>
                                    </td>
                                    <td><?= get_trade_badge($jb['trade_category']) ?></td>
                                    <td>
                                        <div style="font-weight: 600; font-size: 13px;"><?= htmlspecialchars($jb['title']) ?></div>
                                        <?php if ($jb['asset_name']): ?>
                                            <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($jb['asset_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($jb['tech_name']) ?></td>
                                    <td><?= format_date($jb['scheduled_date']) ?></td>
                                    <td><?= get_status_badge($jb['status']) ?></td>
                                    <td>
                                        <?= $jb['signature_data'] ? '<span class="badge badge-success">Signed</span>' : '<span style="font-size:11px; color:var(--text-muted);">Pending</span>' ?>
                                    </td>
                                    <td>
                                        <a href="job_view.php?id=<?= $jb['id'] ?>" class="btn btn-secondary btn-sm">
                                            <i data-lucide="eye" style="width: 12px; height: 12px;"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Right Column: Site & Contact Info Card -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="map-pin"></i> Site & Contact Details</span>
            </div>
            <div class="card-body" style="font-size: 13px; display: flex; flex-direction: column; gap: 12px;">
                <div>
                    <span style="color: var(--text-muted);">Contact Person:</span><br>
                    <strong><?= htmlspecialchars($customer['contact_person'] ?: 'Main Contact') ?></strong>
                </div>

                <div>
                    <span style="color: var(--text-muted);">Primary Phone:</span><br>
                    <a href="tel:<?= htmlspecialchars($customer['phone']) ?>" style="font-weight: 700;">
                        <?= htmlspecialchars($customer['phone']) ?>
                    </a>
                </div>

                <?php if ($customer['alternate_phone']): ?>
                    <div>
                        <span style="color: var(--text-muted);">Alternate Phone:</span><br>
                        <strong><?= htmlspecialchars($customer['alternate_phone']) ?></strong>
                    </div>
                <?php endif; ?>

                <?php if ($customer['email']): ?>
                    <div>
                        <span style="color: var(--text-muted);">Email:</span><br>
                        <a href="mailto:<?= htmlspecialchars($customer['email']) ?>"><?= htmlspecialchars($customer['email']) ?></a>
                    </div>
                <?php endif; ?>

                <div>
                    <span style="color: var(--text-muted);">Estate / Area:</span><br>
                    <strong><?= htmlspecialchars($customer['estate_area']) ?></strong>
                </div>

                <div>
                    <span style="color: var(--text-muted);">Physical Address:</span><br>
                    <strong><?= nl2br(htmlspecialchars($customer['address'])) ?></strong>
                </div>

                <?php if ($customer['landmark']): ?>
                    <div style="background: #fef3c7; border: 1px solid #fde68a; padding: 10px; border-radius: var(--radius-sm); font-size: 12px; color: #92400e;">
                        <strong>Gate / Landmark Notes:</strong><br>
                        <?= htmlspecialchars($customer['landmark']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($customer['notes']): ?>
                    <div>
                        <span style="color: var(--text-muted);">Client Notes:</span><br>
                        <em><?= nl2br(htmlspecialchars($customer['notes'])) ?></em>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 10px;">
                    <?php
                    $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode($customer['address'] . " " . $customer['estate_area'] . " Kenya");
                    ?>
                    <a href="<?= $mapsUrl ?>" target="_blank" class="btn btn-secondary btn-sm" style="width: 100%;">
                        <i data-lucide="navigation"></i> Open in Google Maps
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Register Equipment Asset -->
<div id="addAssetModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="cpu"></i> Register Equipment / Asset</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="customer_view.php?id=<?= $customer['id'] ?>">
            <input type="hidden" name="add_asset" value="1">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Asset / Equipment Name <span class="required">*</span></label>
                    <input type="text" name="asset_name" class="form-control" placeholder="e.g. Deye 8kW Hybrid Inverter / Hikvision 16-ch NVR" required>
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
                        <input type="text" name="brand" class="form-control" placeholder="e.g. Deye, Hikvision, Carrier">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Model Number</label>
                        <input type="text" name="model_number" class="form-control" placeholder="e.g. SUN-8K-SG01LP1">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Serial Number</label>
                        <input type="text" name="serial_number" class="form-control" placeholder="e.g. SN-998822-2024">
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

                <div class="form-group">
                    <label class="form-label">Location at Site / Room</label>
                    <input type="text" name="location_at_site" class="form-control" placeholder="e.g. Main Power Room / Roof Mount">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Equipment Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Equipment Asset -->
<div id="editAssetModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="edit"></i> Edit Equipment / Asset</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="customer_view.php?id=<?= $customer['id'] ?>">
            <input type="hidden" name="edit_asset" value="1">
            <input type="hidden" name="asset_id" id="edit_asset_id">
            <div class="modal-body">
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
