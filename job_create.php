<?php
$pageTitle = 'Dispatch & Create Job Card';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

$requestId = (int)($_GET['request_id'] ?? 0);
$requestData = null;

if ($requestId > 0) {
    $stmt = $db->prepare("SELECT * FROM service_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $requestData = $stmt->fetch();
}

$customers = $db->query("SELECT * FROM customers ORDER BY name ASC")->fetchAll();
$techs = $db->query("SELECT * FROM users WHERE role = 'technician' ORDER BY status ASC, name ASC")->fetchAll();
$allAssets = $db->query("SELECT * FROM customer_assets WHERE status != 'decommissioned'")->fetchAll();
$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'Cleaning', 'Maintenance'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reqId = !empty($_POST['request_id']) ? (int)$_POST['request_id'] : null;
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $technicianId = (int)($_POST['technician_id'] ?? 0);
    $assetId = !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
    $tradeCategory = clean($_POST['trade_category'] ?? '');
    $title = clean($_POST['title'] ?? '');
    $description = clean($_POST['job_description'] ?? '');
    $scheduledDate = clean($_POST['scheduled_date'] ?? date('Y-m-d'));
    $scheduledTime = clean($_POST['scheduled_time'] ?? '09:00 AM');
    $estimatedDuration = clean($_POST['estimated_duration'] ?? '2 hours');
    $priority = clean($_POST['priority'] ?? 'normal');

    if (!$customerId || !$technicianId || empty($tradeCategory) || empty($title)) {
        set_flash('error', 'Please fill in all mandatory fields: Customer, Technician, Trade Category, and Title.');
    } else {
        // Generate Job Number
        $jobNumber = 'JOB-' . date('Y') . '-' . str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("
            INSERT INTO job_cards (
                job_number, request_id, customer_id, technician_id, asset_id, trade_category,
                title, job_description, scheduled_date, scheduled_time, estimated_duration,
                priority, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
        ");
        $stmt->execute([
            $jobNumber, $reqId, $customerId, $technicianId, $assetId, $tradeCategory,
            $title, $description, $scheduledDate, $scheduledTime, $estimatedDuration, $priority
        ]);
        $newJobId = $db->lastInsertId();

        // If from a request, update request status to 'assigned'
        if ($reqId) {
            $stmtReq = $db->prepare("UPDATE service_requests SET status = 'assigned' WHERE id = ?");
            $stmtReq->execute([$reqId]);
        }

        // Log action
        log_job_activity($newJobId, 'Job Card Created & Dispatched', "Dispatched to technician ID {$technicianId}", $_SESSION['user']['id'] ?? 1);

        // Fetch tech details for notification
        $stmtTech = $db->prepare("SELECT name, phone FROM users WHERE id = ?");
        $stmtTech->execute([$technicianId]);
        $tech = $stmtTech->fetch();

        create_notification("Job Dispatched", "Job {$jobNumber} assigned to {$tech['name']}.", "dispatch", "job_view.php?id={$newJobId}", $technicianId);

        set_flash('success', "Job Card {$jobNumber} created & dispatched successfully!");
        header("Location: job_view.php?id={$newJobId}");
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Dispatch & Create Work Order</h1>
        <p class="page-subtitle">Assign field technician, schedule service time, and issue official Job Card</p>
    </div>
    <div class="page-actions">
        <a href="jobs.php" class="btn btn-secondary">
            <i data-lucide="arrow-left"></i> Back to Jobs
        </a>
    </div>
</div>

<div class="card" style="max-width: 860px; margin: 0 auto;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="send"></i> Work Order & Technician Assignment</span>
        <?php if ($requestData): ?>
            <span class="badge badge-info">Linked to <?= htmlspecialchars($requestData['ticket_no']) ?></span>
        <?php endif; ?>
    </div>
    <form method="POST" action="job_create.php">
        <?php if ($requestData): ?>
            <input type="hidden" name="request_id" value="<?= $requestData['id'] ?>">
        <?php endif; ?>

        <div class="card-body">
            <div class="form-row">
                <!-- Customer Picker -->
                <div class="form-group" style="flex: 2;">
                    <label class="form-label">Customer / Client <span class="required">*</span></label>
                    <select name="customer_id" id="customerId" class="form-control" required onchange="filterCustomerAssets()">
                        <option value="">-- Choose Customer --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($requestData && $requestData['customer_id'] == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['estate_area']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Asset Picker -->
                <div class="form-group" style="flex: 2;">
                    <label class="form-label">Installed Equipment / Asset</label>
                    <select name="asset_id" id="assetSelect" class="form-control">
                        <option value="">-- General Site Service (No specific asset) --</option>
                    </select>
                </div>
            </div>

            <!-- Technician Picker & Trade -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Assign Field Technician <span class="required">*</span></label>
                    <select name="technician_id" class="form-control" required>
                        <option value="">-- Select Available Technician --</option>
                        <?php foreach ($techs as $t): ?>
                            <option value="<?= $t['id'] ?>">
                                <?= htmlspecialchars($t['name']) ?> [<?= ucfirst($t['status']) ?>] - <?= htmlspecialchars($t['trade_skills'] ?: 'General') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Trade Category <span class="required">*</span></label>
                    <select name="trade_category" class="form-control" required>
                        <option value="">-- Select Trade --</option>
                        <?php foreach ($tradeCategories as $trade): ?>
                            <option value="<?= $trade ?>" <?= ($requestData && $requestData['trade_category'] == $trade) ? 'selected' : '' ?>><?= $trade ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Job Title -->
            <div class="form-group">
                <label class="form-label">Job Title / Task Objective <span class="required">*</span></label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Inverter ATS Switchover Diagnostic & Repair" value="<?= htmlspecialchars($requestData['title'] ?? '') ?>" required>
            </div>

            <!-- Job Instructions -->
            <div class="form-group">
                <label class="form-label">Field Work Instructions & Symptoms</label>
                <textarea name="job_description" class="form-control" placeholder="Describe the required tasks, error codes, site safety instructions..." rows="4"><?= htmlspecialchars($requestData['description'] ?? '') ?></textarea>
            </div>

            <!-- Schedule & Priority -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Scheduled Date <span class="required">*</span></label>
                    <input type="date" name="scheduled_date" class="form-control" value="<?= htmlspecialchars($requestData['preferred_date'] ?? date('Y-m-d')) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Scheduled Time Slot <span class="required">*</span></label>
                    <input type="text" name="scheduled_time" class="form-control" placeholder="e.g. 09:30 AM" value="<?= htmlspecialchars($requestData['preferred_time_slot'] ?? '09:00 AM') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Estimated Duration</label>
                    <input type="text" name="estimated_duration" class="form-control" placeholder="e.g. 2 hours" value="2 hours">
                </div>

                <div class="form-group">
                    <label class="form-label">Priority Level</label>
                    <select name="priority" class="form-control">
                        <option value="normal" <?= ($requestData && $requestData['priority'] == 'normal') ? 'selected' : '' ?>>Normal</option>
                        <option value="urgent" <?= ($requestData && $requestData['priority'] == 'urgent') ? 'selected' : '' ?>>Urgent</option>
                        <option value="emergency" <?= ($requestData && $requestData['priority'] == 'emergency') ? 'selected' : '' ?>>⚡ Emergency</option>
                        <option value="low" <?= ($requestData && $requestData['priority'] == 'low') ? 'selected' : '' ?>>Low</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <a href="jobs.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i data-lucide="send"></i> Confirm & Dispatch Job Card
            </button>
        </div>
    </form>
</div>

<script>
const allAssetsData = <?= json_encode($allAssets) ?>;
const preselectedAssetId = <?= $requestData ? (int)($requestData['asset_id'] ?? 0) : 0 ?>;

function filterCustomerAssets() {
    const customerId = document.getElementById('customerId').value;
    const assetSelect = document.getElementById('assetSelect');

    assetSelect.innerHTML = '<option value="">-- General Site Service (No specific asset) --</option>';
    if (!customerId) return;

    const filtered = allAssetsData.filter(a => a.customer_id == customerId);
    filtered.forEach(a => {
        const opt = document.createElement('option');
        opt.value = a.id;
        opt.textContent = `${a.asset_name} (${a.category} - ${a.brand || ''} ${a.model_number || ''})`;
        if (a.id == preselectedAssetId) opt.selected = true;
        assetSelect.appendChild(opt);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    filterCustomerAssets();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
