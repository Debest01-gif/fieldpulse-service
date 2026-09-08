<?php
$pageTitle = 'Parts & Materials Inventory';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

// Handle Add Part
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_part'])) {
    $code = clean($_POST['part_code'] ?? '');
    $name = clean($_POST['part_name'] ?? '');
    $category = clean($_POST['category'] ?? '');
    $unit = clean($_POST['unit'] ?? 'pcs');
    $inStock = (int)($_POST['in_stock'] ?? 0);
    $minAlert = (int)($_POST['min_stock_alert'] ?? 5);
    $desc = clean($_POST['description'] ?? '');

    if ($code && $name && $category) {
        $stmt = $db->prepare("INSERT INTO inventory_parts (part_code, part_name, category, unit, in_stock, min_stock_alert, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$code, $name, $category, $unit, $inStock, $minAlert, $desc]);

        set_flash('success', "Part '{$name}' added to inventory catalog.");
        header("Location: inventory.php");
        exit;
    }
}

// Handle Edit Part
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_part'])) {
    $partId = (int)$_POST['part_id'];
    $code = clean($_POST['part_code'] ?? '');
    $name = clean($_POST['part_name'] ?? '');
    $category = clean($_POST['category'] ?? '');
    $unit = clean($_POST['unit'] ?? 'pcs');
    $inStock = (int)($_POST['in_stock'] ?? 0);
    $minAlert = (int)($_POST['min_stock_alert'] ?? 5);
    $desc = clean($_POST['description'] ?? '');

    if ($partId > 0 && $code && $name && $category) {
        $stmt = $db->prepare("
            UPDATE inventory_parts
            SET part_code = ?, part_name = ?, category = ?, unit = ?, in_stock = ?, min_stock_alert = ?, description = ?
            WHERE id = ?
        ");
        $stmt->execute([$code, $name, $category, $unit, $inStock, $minAlert, $desc, $partId]);

        set_flash('success', "Part '{$name}' updated successfully.");
        header("Location: inventory.php");
        exit;
    }
}

// Handle Delete Part
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_part'])) {
    $partId = (int)$_POST['part_id'];
    if ($partId > 0) {
        $stmtName = $db->prepare("SELECT part_name FROM inventory_parts WHERE id = ?");
        $stmtName->execute([$partId]);
        $pName = $stmtName->fetchColumn();

        // Check if used in job records
        $usedCount = $db->prepare("SELECT COUNT(*) FROM job_parts_used WHERE part_id = ?");
        $usedCount->execute([$partId]);
        if ($usedCount->fetchColumn() > 0) {
            set_flash('error', "Cannot delete part '{$pName}' because it has been recorded on completed job sheets.");
        } else {
            $db->prepare("DELETE FROM inventory_parts WHERE id = ?")->execute([$partId]);
            set_flash('success', "Part '{$pName}' removed from catalog.");
        }
        header("Location: inventory.php");
        exit;
    }
}

// Handle Quick Restock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restock_part'])) {
    $partId = (int)$_POST['part_id'];
    $addQty = (int)$_POST['add_quantity'];
    if ($partId > 0 && $addQty > 0) {
        $db->prepare("UPDATE inventory_parts SET in_stock = in_stock + ? WHERE id = ?")->execute([$addQty, $partId]);
        set_flash('success', "Stock quantity updated successfully.");
        header("Location: inventory.php");
        exit;
    }
}

// Category filter
$catFilter = clean($_GET['category'] ?? '');
$query = "
    SELECT p.*,
           (SELECT IFNULL(SUM(u.quantity), 0) FROM job_parts_used u WHERE u.part_id = p.id) as total_used_on_jobs
    FROM inventory_parts p
    WHERE 1=1
";
$params = [];
if ($catFilter) {
    $query .= " AND p.category = ?";
    $params[] = $catFilter;
}
$query .= " ORDER BY p.category ASC, p.part_name ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$parts = $stmt->fetchAll();

// Low stock count
$lowStockCount = $db->query("SELECT COUNT(*) FROM inventory_parts WHERE in_stock <= min_stock_alert")->fetchColumn();

$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'General'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Parts & Materials Inventory</h1>
        <p class="page-subtitle">Track materials, spare parts, and consumables recorded on field service job cards</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('addPartModal')">
            <i data-lucide="plus-circle"></i> Add New Material / Part
        </button>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Catalogued Parts</span>
            <span class="stat-value"><?= count($parts) ?></span>
            <span class="stat-subtext"><i data-lucide="package" style="width: 13px; height: 13px;"></i> Unique items</span>
        </div>
        <div class="stat-icon blue"><i data-lucide="layers"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Low Stock Alerts</span>
            <span class="stat-value" style="<?= $lowStockCount > 0 ? 'color: var(--danger);' : '' ?>"><?= $lowStockCount ?></span>
            <span class="stat-subtext <?= $lowStockCount > 0 ? 'alert' : 'positive' ?>">
                <i data-lucide="alert-triangle" style="width: 13px; height: 13px;"></i> <?= $lowStockCount > 0 ? 'Requires Restock' : 'Stock Optimal' ?>
            </span>
        </div>
        <div class="stat-icon red"><i data-lucide="alert-circle"></i></div>
    </div>
</div>

<!-- Parts Table -->
<div class="card">
    <div class="table-responsive">
        <table class="custom-table" id="mainTable">
            <thead>
                <tr>
                    <th>Part Code</th>
                    <th>Part Name & Description</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th>Current Stock</th>
                    <th>Min Alert Level</th>
                    <th>Used on Jobs</th>
                    <th>Stock Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($parts as $p): ?>
                    <?php $isLow = $p['in_stock'] <= $p['min_stock_alert']; ?>
                    <tr>
                        <td>
                            <span style="font-family: var(--font-mono); font-weight: 700; color: var(--primary);">
                                <?= htmlspecialchars($p['part_code']) ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($p['part_name']) ?></div>
                            <div style="font-size: 11.5px; color: var(--text-muted);"><?= htmlspecialchars($p['description'] ?: '-') ?></div>
                        </td>
                        <td><?= get_trade_badge($p['category']) ?></td>
                        <td><span class="badge badge-secondary"><?= htmlspecialchars($p['unit']) ?></span></td>
                        <td>
                            <span style="font-size: 15px; font-weight: 800; font-family: var(--font-mono); <?= $isLow ? 'color: var(--danger);' : 'color: var(--text-main);' ?>">
                                <?= $p['in_stock'] ?>
                            </span>
                        </td>
                        <td><span style="font-size: 12px; color: var(--text-muted);"><?= $p['min_stock_alert'] ?></span></td>
                        <td>
                            <span class="badge badge-info"><?= $p['total_used_on_jobs'] ?> <?= htmlspecialchars($p['unit']) ?></span>
                        </td>
                        <td>
                            <?php if ($isLow): ?>
                                <span class="badge badge-danger"><i data-lucide="alert-triangle" class="badge-icon"></i> Low Stock</span>
                            <?php else: ?>
                                <span class="badge badge-success"><i data-lucide="check" class="badge-icon"></i> In Stock</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; gap: 6px; align-items: center;">
                                <!-- Quick Restock inline form -->
                                <form method="POST" action="inventory.php" style="display: flex; gap: 4px; align-items: center;" title="Quick Restock">
                                    <input type="hidden" name="restock_part" value="1">
                                    <input type="hidden" name="part_id" value="<?= $p['id'] ?>">
                                    <input type="number" min="1" name="add_quantity" value="5" style="width: 50px; padding: 4px; border: 1px solid var(--border-color); border-radius: 4px; font-size: 12px; text-align: center;">
                                    <button type="submit" class="btn btn-secondary btn-sm" title="Add to stock">
                                        <i data-lucide="plus" style="width: 12px; height: 12px;"></i>
                                    </button>
                                </form>

                                <button type="button" class="btn btn-secondary btn-sm" title="Edit Part Details"
                                        onclick='editPart(<?= json_encode($p) ?>)'>
                                    <i data-lucide="edit" style="width: 13px; height: 13px;"></i>
                                </button>

                                <form method="POST" action="inventory.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete material \'<?= htmlspecialchars(addslashes($p['part_name'])) ?>\'?');">
                                    <input type="hidden" name="delete_part" value="1">
                                    <input type="hidden" name="part_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background: rgba(225, 29, 72, 0.15); color: #f43f5e; border: 1px solid rgba(225, 29, 72, 0.3); padding: 5px 8px;" title="Delete Part">
                                        <i data-lucide="trash-2" style="width: 13px; height: 13px;"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add New Part -->
<div id="addPartModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="package-plus"></i> Add Material / Part</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="inventory.php">
            <input type="hidden" name="add_part" value="1">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Part SKU / Code <span class="required">*</span></label>
                        <input type="text" name="part_code" class="form-control" placeholder="e.g. SOL-MC4-PAIR" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Trade Category <span class="required">*</span></label>
                        <select name="category" class="form-control" required>
                            <?php foreach ($tradeCategories as $cat): ?>
                                <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Part Name / Material Description <span class="required">*</span></label>
                    <input type="text" name="part_name" class="form-control" placeholder="e.g. 16mm 3-Core Armoured Copper Cable" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Unit of Measure</label>
                        <select name="unit" class="form-control">
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="meters">Meters (m)</option>
                            <option value="roll">Roll / Drum</option>
                            <option value="box">Box / Pack</option>
                            <option value="pair">Pair</option>
                            <option value="cylinder">Cylinder</option>
                            <option value="kg">Kilograms (kg)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Initial Stock Count</label>
                        <input type="number" min="0" name="in_stock" class="form-control" value="10">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Low Stock Alert Threshold</label>
                        <input type="number" min="1" name="min_stock_alert" class="form-control" value="5">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Technical Specification / Notes</label>
                    <textarea name="description" class="form-control" placeholder="e.g. Type 2 DC surge protection 1000V rated" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                <button type="submit" class="btn btn-primary">Save to Catalog</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Part -->
<div id="editPartModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="edit"></i> Edit Material / Part</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="inventory.php">
            <input type="hidden" name="edit_part" value="1">
            <input type="hidden" name="part_id" id="edit_part_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Part SKU / Code <span class="required">*</span></label>
                        <input type="text" name="part_code" id="edit_part_code" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Trade Category <span class="required">*</span></label>
                        <select name="category" id="edit_category" class="form-control" required>
                            <?php foreach ($tradeCategories as $cat): ?>
                                <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Part Name / Material Description <span class="required">*</span></label>
                    <input type="text" name="part_name" id="edit_part_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Unit of Measure</label>
                        <select name="unit" id="edit_unit" class="form-control">
                            <option value="pcs">Pieces (pcs)</option>
                            <option value="meters">Meters (m)</option>
                            <option value="roll">Roll / Drum</option>
                            <option value="box">Box / Pack</option>
                            <option value="pair">Pair</option>
                            <option value="cylinder">Cylinder</option>
                            <option value="kg">Kilograms (kg)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Current Stock Count</label>
                        <input type="number" min="0" name="in_stock" id="edit_in_stock" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Low Stock Alert Threshold</label>
                        <input type="number" min="1" name="min_stock_alert" id="edit_min_stock_alert" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Technical Specification / Notes</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
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
function editPart(data) {
    document.getElementById('edit_part_id').value = data.id;
    document.getElementById('edit_part_code').value = data.part_code;
    document.getElementById('edit_part_name').value = data.part_name;
    document.getElementById('edit_category').value = data.category;
    document.getElementById('edit_unit').value = data.unit || 'pcs';
    document.getElementById('edit_in_stock').value = data.in_stock;
    document.getElementById('edit_min_stock_alert').value = data.min_stock_alert;
    document.getElementById('edit_description').value = data.description || '';
    openModal('editPartModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
