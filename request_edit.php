<?php
$pageTitle = 'Edit Service Request';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

$requestId = (int)($_GET['id'] ?? 0);
if ($requestId <= 0) {
    set_flash('error', 'Invalid Request ID.');
    header('Location: requests.php');
    exit;
}

$stmtReq = $db->prepare("SELECT * FROM service_requests WHERE id = ?");
$stmtReq->execute([$requestId]);
$req = $stmtReq->fetch();
if (!$req) {
    set_flash('error', 'Service request not found.');
    header('Location: requests.php');
    exit;
}

$customers = $db->query("SELECT * FROM customers ORDER BY name ASC")->fetchAll();
$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'Cleaning', 'Maintenance'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $assetId = !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
    $tradeCategory = clean($_POST['trade_category'] ?? '');
    $title = clean($_POST['title'] ?? '');
    $description = clean($_POST['description'] ?? '');
    $priority = clean($_POST['priority'] ?? 'normal');
    $status = clean($_POST['status'] ?? 'new');
    $preferredDate = !empty($_POST['preferred_date']) ? clean($_POST['preferred_date']) : null;
    $preferredTimeSlot = clean($_POST['preferred_time_slot'] ?? '');

    // Form validation
    if (!$customerId || empty($tradeCategory) || empty($title) || empty($description)) {
        set_flash('error', 'Please fill in all required fields (Customer, Trade, Title, Description).');
    } else {
        $stmt = $db->prepare("
            UPDATE service_requests
            SET customer_id = ?, asset_id = ?, trade_category = ?, title = ?, description = ?,
                priority = ?, status = ?, preferred_date = ?, preferred_time_slot = ?
            WHERE id = ?
        ");
        $stmt->execute([$customerId, $assetId, $tradeCategory, $title, $description, $priority, $status, $preferredDate, $preferredTimeSlot, $requestId]);

        set_flash('success', "Service Request {$req['ticket_no']} updated successfully.");

        if (isset($_POST['dispatch_now'])) {
            header("Location: job_create.php?request_id={$requestId}");
            exit;
        }

        header("Location: requests.php");
        exit;
    }
}

// Fetch all assets for dynamic client-side filtering
$allAssets = $db->query("SELECT id, customer_id, asset_name, category, brand, model_number FROM customer_assets WHERE status != 'decommissioned'")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Service Request (<?= htmlspecialchars($req['ticket_no']) ?>)</h1>
        <p class="page-subtitle">Update inquiry details, urgency priority, and customer schedule requirements</p>
    </div>
    <div class="page-actions">
        <a href="requests.php" class="btn btn-secondary">
            <i data-lucide="arrow-left"></i> Cancel / Back
        </a>
    </div>
</div>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="edit"></i> Modify Request Details</span>
    </div>
    <form method="POST" action="request_edit.php?id=<?= $requestId ?>">
        <div class="card-body">
            <!-- Customer Picker -->
            <div class="form-group">
                <label class="form-label">Customer <span class="required">*</span></label>
                <select name="customer_id" id="customerId" class="form-control" required onchange="filterCustomerAssets()">
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $req['customer_id'] == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['estate_area']) ?> - <?= htmlspecialchars($c['phone']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Customer Installed Asset (Optional) -->
            <div class="form-group">
                <label class="form-label">Related Installed Asset / Equipment (Optional)</label>
                <select name="asset_id" id="assetSelect" class="form-control">
                    <option value="">-- General Site Service (No specific asset) --</option>
                </select>
            </div>

            <!-- Trade Category & Priority -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Trade / Service Category <span class="required">*</span></label>
                    <select name="trade_category" class="form-control" required>
                        <?php foreach ($tradeCategories as $trade): ?>
                            <option value="<?= $trade ?>" <?= $req['trade_category'] == $trade ? 'selected' : '' ?>><?= $trade ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Priority Level <span class="required">*</span></label>
                    <select name="priority" class="form-control" required>
                        <option value="normal" <?= $req['priority'] == 'normal' ? 'selected' : '' ?>>Normal (Routine)</option>
                        <option value="low" <?= $req['priority'] == 'low' ? 'selected' : '' ?>>Low Priority</option>
                        <option value="urgent" <?= $req['priority'] == 'urgent' ? 'selected' : '' ?>>Urgent (High)</option>
                        <option value="emergency" <?= $req['priority'] == 'emergency' ? 'selected' : '' ?>>⚡ Emergency Breakdown</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Request Status <span class="required">*</span></label>
                    <select name="status" class="form-control" required>
                        <option value="new" <?= $req['status'] == 'new' ? 'selected' : '' ?>>New / Unassigned</option>
                        <option value="assigned" <?= $req['status'] == 'assigned' ? 'selected' : '' ?>>Assigned / Dispatched</option>
                        <option value="in_progress" <?= $req['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="completed" <?= $req['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $req['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
            </div>

            <!-- Issue Title -->
            <div class="form-group">
                <label class="form-label">Short Issue Summary / Title <span class="required">*</span></label>
                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($req['title']) ?>" required>
            </div>

            <!-- Detailed Problem Description -->
            <div class="form-group">
                <label class="form-label">Detailed Description of Symptoms / Work Required <span class="required">*</span></label>
                <textarea name="description" class="form-control" rows="4" required><?= htmlspecialchars($req['description']) ?></textarea>
            </div>

            <!-- Preferred Scheduling Preferences -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Customer Preferred Date</label>
                    <input type="date" name="preferred_date" class="form-control" value="<?= htmlspecialchars($req['preferred_date'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Preferred Time Slot</label>
                    <select name="preferred_time_slot" class="form-control">
                        <option value="Flexible / Anytime" <?= $req['preferred_time_slot'] == 'Flexible / Anytime' ? 'selected' : '' ?>>Flexible / Anytime</option>
                        <option value="Morning (08:30 - 12:00)" <?= $req['preferred_time_slot'] == 'Morning (08:30 - 12:00)' ? 'selected' : '' ?>>Morning (08:30 - 12:00)</option>
                        <option value="Afternoon (13:00 - 16:30)" <?= $req['preferred_time_slot'] == 'Afternoon (13:00 - 16:30)' ? 'selected' : '' ?>>Afternoon (13:00 - 16:30)</option>
                        <option value="Evening (17:00 - 19:00)" <?= $req['preferred_time_slot'] == 'Evening (17:00 - 19:00)' ? 'selected' : '' ?>>Evening (17:00 - 19:00)</option>
                        <option value="Immediate" <?= $req['preferred_time_slot'] == 'Immediate' ? 'selected' : '' ?>>Immediate (Emergency)</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center;">
            <a href="requests.php" class="btn btn-secondary">Cancel</a>
            <div style="display: flex; gap: 8px;">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="save"></i> Update Service Request
                </button>
                <button type="submit" name="dispatch_now" value="1" class="btn btn-warning" style="background: #f59e0b; color: #000;">
                    <i data-lucide="send"></i> Update & Dispatch Job
                </button>
            </div>
        </div>
    </form>
</div>

<script>
const allAssetsData = <?= json_encode($allAssets) ?>;
const currentAssetId = <?= json_encode($req['asset_id']) ?>;

function filterCustomerAssets() {
    const custId = parseInt(document.getElementById('customerId').value);
    const assetSelect = document.getElementById('assetSelect');

    assetSelect.innerHTML = '<option value="">-- General Site Service (No specific asset) --</option>';

    if (!custId) return;

    const matched = allAssetsData.filter(a => a.customer_id === custId);
    matched.forEach(ast => {
        const opt = document.createElement('option');
        opt.value = ast.id;
        opt.textContent = `${ast.asset_name} (${ast.category} - ${ast.brand || ''} ${ast.model_number || ''})`;
        if (currentAssetId && ast.id == currentAssetId) {
            opt.selected = true;
        }
        assetSelect.appendChild(opt);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    filterCustomerAssets();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
