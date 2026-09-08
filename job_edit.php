<?php
$pageTitle = 'Edit Job Card';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

$jobId = (int)($_GET['id'] ?? 0);
if ($jobId <= 0) {
    set_flash('error', 'Invalid Job Card ID.');
    header('Location: jobs.php');
    exit;
}

$stmtJob = $db->prepare("SELECT * FROM job_cards WHERE id = ?");
$stmtJob->execute([$jobId]);
$job = $stmtJob->fetch();
if (!$job) {
    set_flash('error', 'Job card not found.');
    header('Location: jobs.php');
    exit;
}

$customers = $db->query("SELECT * FROM customers ORDER BY name ASC")->fetchAll();
$techs = $db->query("SELECT * FROM users WHERE role = 'technician' ORDER BY status ASC, name ASC")->fetchAll();
$allAssets = $db->query("SELECT * FROM customer_assets WHERE status != 'decommissioned'")->fetchAll();
$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'Cleaning', 'Maintenance'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $status = clean($_POST['status'] ?? 'scheduled');
    $diagnosis = clean($_POST['diagnosis'] ?? '');
    $workPerformed = clean($_POST['work_performed'] ?? '');
    $technicianNotes = clean($_POST['technician_notes'] ?? '');
    $customerFeedback = clean($_POST['customer_feedback'] ?? '');
    $signedByName = clean($_POST['signed_by_name'] ?? '');

    if (!$customerId || !$technicianId || empty($tradeCategory) || empty($title)) {
        set_flash('error', 'Please fill in all mandatory fields: Customer, Technician, Trade Category, and Title.');
    } else {
        $stmt = $db->prepare("
            UPDATE job_cards
            SET customer_id = ?, technician_id = ?, asset_id = ?, trade_category = ?,
                title = ?, job_description = ?, scheduled_date = ?, scheduled_time = ?,
                estimated_duration = ?, priority = ?, status = ?, diagnosis = ?,
                work_performed = ?, technician_notes = ?, customer_feedback = ?, signed_by_name = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $customerId, $technicianId, $assetId, $tradeCategory,
            $title, $description, $scheduledDate, $scheduledTime,
            $estimatedDuration, $priority, $status, $diagnosis,
            $workPerformed, $technicianNotes, $customerFeedback, $signedByName,
            $jobId
        ]);

        log_job_activity($jobId, 'Job Card Updated', "Job card details modified by dispatcher/admin.", $_SESSION['user']['id'] ?? 1);

        set_flash('success', "Job Card {$job['job_number']} updated successfully.");
        header("Location: job_view.php?id={$jobId}");
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Job Card (<?= htmlspecialchars($job['job_number']) ?>)</h1>
        <p class="page-subtitle">Update schedule, technician assignment, field diagnosis, and work details</p>
    </div>
    <div class="page-actions">
        <a href="job_view.php?id=<?= $jobId ?>" class="btn btn-secondary">
            <i data-lucide="arrow-left"></i> Cancel / Back to Job View
        </a>
    </div>
</div>

<div class="card" style="max-width: 900px; margin: 0 auto;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="edit"></i> Modify Work Order Information</span>
    </div>
    <form method="POST" action="job_edit.php?id=<?= $jobId ?>">
        <div class="card-body">
            <!-- Customer & Asset -->
            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Client / Company <span class="required">*</span></label>
                    <select name="customer_id" id="customerId" class="form-control" required onchange="filterCustomerAssets()">
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $job['customer_id'] == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['estate_area']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Installed Equipment / Asset (Optional)</label>
                    <select name="asset_id" id="assetSelect" class="form-control">
                        <option value="">-- General Site Task (No specific asset) --</option>
                    </select>
                </div>
            </div>

            <!-- Technician & Trade -->
            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Assigned Technician <span class="required">*</span></label>
                    <select name="technician_id" class="form-control" required>
                        <?php foreach ($techs as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $job['technician_id'] == $t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['name']) ?> [<?= ucfirst($t['status']) ?> - <?= htmlspecialchars($t['trade_skills'] ?: 'General') ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Trade Category <span class="required">*</span></label>
                    <select name="trade_category" class="form-control" required>
                        <?php foreach ($tradeCategories as $trade): ?>
                            <option value="<?= $trade ?>" <?= $job['trade_category'] == $trade ? 'selected' : '' ?>><?= $trade ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Job Objective Title -->
            <div class="form-group">
                <label class="form-label">Job Title / Primary Task Objective <span class="required">*</span></label>
                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($job['title']) ?>" required>
            </div>

            <!-- Job Description / Instructions -->
            <div class="form-group">
                <label class="form-label">Scope of Work / Dispatch Instructions <span class="required">*</span></label>
                <textarea name="job_description" class="form-control" rows="3" required><?= htmlspecialchars($job['job_description']) ?></textarea>
            </div>

            <!-- Schedule & Priority & Status -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Scheduled Date <span class="required">*</span></label>
                    <input type="date" name="scheduled_date" class="form-control" value="<?= htmlspecialchars($job['scheduled_date']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Scheduled Time <span class="required">*</span></label>
                    <input type="text" name="scheduled_time" class="form-control" value="<?= htmlspecialchars($job['scheduled_time']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Estimated Duration</label>
                    <input type="text" name="estimated_duration" class="form-control" value="<?= htmlspecialchars($job['estimated_duration'] ?? '2 hours') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Priority Level <span class="required">*</span></label>
                    <select name="priority" class="form-control" required>
                        <option value="normal" <?= $job['priority'] === 'normal' ? 'selected' : '' ?>>Normal (Standard)</option>
                        <option value="low" <?= $job['priority'] === 'low' ? 'selected' : '' ?>>Low Priority</option>
                        <option value="urgent" <?= $job['priority'] === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                        <option value="emergency" <?= $job['priority'] === 'emergency' ? 'selected' : '' ?>>⚡ Emergency</option>
                    </select>
                </div>

                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Current Job Status <span class="required">*</span></label>
                    <select name="status" class="form-control" required>
                        <option value="scheduled" <?= $job['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                        <option value="en_route" <?= $job['status'] === 'en_route' ? 'selected' : '' ?>>En Route</option>
                        <option value="in_progress" <?= $job['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="pending_parts" <?= $job['status'] === 'pending_parts' ? 'selected' : '' ?>>Pending Parts</option>
                        <option value="completed" <?= $job['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="signed_off" <?= $job['status'] === 'signed_off' ? 'selected' : '' ?>>Signed & Closed</option>
                        <option value="cancelled" <?= $job['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
            </div>

            <!-- Field Technical Reporting Section -->
            <div style="background: var(--bg-subtle); padding: 18px; border-radius: var(--radius-md); margin-top: 15px; border: 1px solid var(--border-color);">
                <div style="font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="wrench" style="width: 16px; height: 16px; color: var(--primary);"></i>
                    Field Findings, Work Performed & Customer Notes
                </div>

                <div class="form-group">
                    <label class="form-label">Diagnosis / Root Cause Findings</label>
                    <textarea name="diagnosis" class="form-control" rows="2"><?= htmlspecialchars($job['diagnosis'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Work Performed / Corrective Action</label>
                    <textarea name="work_performed" class="form-control" rows="2"><?= htmlspecialchars($job['work_performed'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Technician Internal Notes</label>
                        <textarea name="technician_notes" class="form-control" rows="2"><?= htmlspecialchars($job['technician_notes'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Customer Feedback / Remarks</label>
                        <textarea name="customer_feedback" class="form-control" rows="2"><?= htmlspecialchars($job['customer_feedback'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Signed-off By (Customer Representative Name)</label>
                    <input type="text" name="signed_by_name" class="form-control" value="<?= htmlspecialchars($job['signed_by_name'] ?? '') ?>" placeholder="e.g. James Mwangi (Store Manager)">
                </div>
            </div>
        </div>

        <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center;">
            <a href="job_view.php?id=<?= $jobId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i data-lucide="save"></i> Update Job Card
            </button>
        </div>
    </form>
</div>

<script>
const allAssetsData = <?= json_encode($allAssets) ?>;
const currentAssetId = <?= json_encode($job['asset_id']) ?>;

function filterCustomerAssets() {
    const custId = parseInt(document.getElementById('customerId').value);
    const assetSelect = document.getElementById('assetSelect');

    assetSelect.innerHTML = '<option value="">-- General Site Task (No specific asset) --</option>';

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
