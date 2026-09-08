<?php
$pageTitle = 'Job Card Details';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

$jobId = (int)($_GET['id'] ?? 0);
if ($jobId <= 0) {
    set_flash('error', 'Invalid Job Card ID.');
    header('Location: jobs.php');
    exit;
}

// Fetch complete Job Card details
$stmt = $db->prepare("
    SELECT j.*,
           c.name as customer_name, c.phone as customer_phone, c.alternate_phone, c.email as customer_email,
           c.estate_area, c.address as customer_address, c.landmark, c.gps_coords, c.customer_type,
           u.name as tech_name, u.phone as tech_phone, u.trade_skills as tech_skills, u.status as tech_status,
           a.asset_name, a.brand as asset_brand, a.model_number as asset_model, a.serial_number as asset_serial,
           a.install_date as asset_install_date, a.warranty_expiry as asset_warranty, a.location_at_site as asset_location
    FROM job_cards j
    JOIN customers c ON j.customer_id = c.id
    JOIN users u ON j.technician_id = u.id
    LEFT JOIN customer_assets a ON j.asset_id = a.id
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) {
    set_flash('error', 'Job Card not found.');
    header('Location: jobs.php');
    exit;
}

// Handle Form Submissions on Job View
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Update Status
    if ($action === 'update_status') {
        $newStatus = clean($_POST['status'] ?? '');
        if ($newStatus) {
            $updateSql = "UPDATE job_cards SET status = ?, updated_at = NOW()";
            $params = [$newStatus];

            if ($newStatus === 'in_progress' && empty($job['started_at'])) {
                $updateSql .= ", started_at = NOW()";
            } elseif (in_array($newStatus, ['completed', 'signed_off']) && empty($job['completed_at'])) {
                $updateSql .= ", completed_at = NOW()";
            }
            $updateSql .= " WHERE id = ?";
            $params[] = $jobId;

            $stmtUpdate = $db->prepare($updateSql);
            $stmtUpdate->execute($params);

            log_job_activity($jobId, "Status updated to " . ucfirst(str_replace('_', ' ', $newStatus)), null, $_SESSION['user']['id'] ?? 1);
            set_flash('success', "Job status updated to " . ucfirst(str_replace('_', ' ', $newStatus)));
            header("Location: job_view.php?id={$jobId}");
            exit;
        }
    }

    // 2. Update Work Done / Diagnosis / Notes
    if ($action === 'update_notes') {
        $diagnosis = clean($_POST['diagnosis'] ?? '');
        $workPerformed = clean($_POST['work_performed'] ?? '');
        $techNotes = clean($_POST['technician_notes'] ?? '');

        $stmtNotes = $db->prepare("
            UPDATE job_cards
            SET diagnosis = ?, work_performed = ?, technician_notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmtNotes->execute([$diagnosis, $workPerformed, $techNotes, $jobId]);

        log_job_activity($jobId, "Work logs and diagnosis updated", null, $_SESSION['user']['id'] ?? 1);
        set_flash('success', "Job work details saved successfully.");
        header("Location: job_view.php?id={$jobId}");
        exit;
    }

    // 3. Add Part Used
    if ($action === 'add_part') {
        $partId = (int)($_POST['part_id'] ?? 0);
        $quantity = (float)($_POST['quantity'] ?? 1);
        $partNotes = clean($_POST['part_notes'] ?? '');

        if ($partId > 0 && $quantity > 0) {
            // Deduct stock
            $db->prepare("UPDATE inventory_parts SET in_stock = in_stock - ? WHERE id = ?")->execute([$quantity, $partId]);
            // Insert job part
            $db->prepare("INSERT INTO job_parts_used (job_id, part_id, quantity, notes) VALUES (?, ?, ?, ?)")->execute([$jobId, $partId, $quantity, $partNotes]);

            log_job_activity($jobId, "Part added: {$quantity} units consumed", $partNotes, $_SESSION['user']['id'] ?? 1);
            set_flash('success', "Part recorded and inventory deducted.");
            header("Location: job_view.php?id={$jobId}");
            exit;
        }
    }

    // 4. Remove Part Used
    if ($action === 'remove_part') {
        $usageId = (int)($_POST['usage_id'] ?? 0);
        $stmtUsage = $db->prepare("SELECT * FROM job_parts_used WHERE id = ? AND job_id = ?");
        $stmtUsage->execute([$usageId, $jobId]);
        $usage = $stmtUsage->fetch();

        if ($usage) {
            // Restore inventory
            $db->prepare("UPDATE inventory_parts SET in_stock = in_stock + ? WHERE id = ?")->execute([$usage['quantity'], $usage['part_id']]);
            $db->prepare("DELETE FROM job_parts_used WHERE id = ?")->execute([$usageId]);

            log_job_activity($jobId, "Part record removed & restocked", null, $_SESSION['user']['id'] ?? 1);
            set_flash('info', "Part removed and stock restored.");
            header("Location: job_view.php?id={$jobId}");
            exit;
        }
    }

    // 5. Upload Job Photo
    if ($action === 'upload_photo') {
        $photoType = clean($_POST['photo_type'] ?? 'during');
        $caption = clean($_POST['caption'] ?? '');

        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowed)) {
                $filename = 'job_' . $jobId . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $target = __DIR__ . '/assets/uploads/' . $filename;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                    $relPath = 'assets/uploads/' . $filename;
                    $db->prepare("INSERT INTO job_photos (job_id, photo_path, photo_type, caption, uploaded_by) VALUES (?, ?, ?, ?, ?)")
                       ->execute([$jobId, $relPath, $photoType, $caption, $_SESSION['user']['id'] ?? 1]);

                    log_job_activity($jobId, "Photo uploaded ({$photoType})", $caption, $_SESSION['user']['id'] ?? 1);
                    set_flash('success', "Photo uploaded successfully.");
                    header("Location: job_view.php?id={$jobId}");
                    exit;
                }
            } else {
                set_flash('error', 'Only JPG, PNG, WEBP images are allowed.');
            }
        }
    }

    // 6. Save Customer Digital Signature
    if ($action === 'save_signature') {
        $sigData = $_POST['signature_data'] ?? '';
        $signedByName = clean($_POST['signed_by_name'] ?? '');
        $feedback = clean($_POST['customer_feedback'] ?? '');

        if (!empty($sigData) && !empty($signedByName)) {
            $stmtSig = $db->prepare("
                UPDATE job_cards
                SET signature_data = ?, signed_by_name = ?, customer_feedback = ?, signed_at = NOW(), status = 'signed_off', completed_at = IFNULL(completed_at, NOW()), updated_at = NOW()
                WHERE id = ?
            ");
            $stmtSig->execute([$sigData, $signedByName, $feedback, $jobId]);

            log_job_activity($jobId, "Job Signed Off by {$signedByName}", $feedback, $_SESSION['user']['id'] ?? 1);
            create_notification("Job Card Signed Off", "Job {$job['job_number']} completed and signed by {$signedByName}.", "signature", "job_view.php?id={$jobId}");

            set_flash('success', "Customer signature captured! Job has been signed off and closed.");
            header("Location: job_view.php?id={$jobId}");
            exit;
        } else {
            set_flash('error', 'Please provide both customer signature and signatory name.');
        }
    }
}

// Fetch Parts Used
$partsUsed = $db->prepare("
    SELECT u.*, p.part_code, p.part_name, p.unit, p.category as part_category
    FROM job_parts_used u
    JOIN inventory_parts p ON u.part_id = p.id
    WHERE u.job_id = ?
    ORDER BY u.recorded_at DESC
");
$partsUsed->execute([$jobId]);
$partsUsedList = $partsUsed->fetchAll();

// Fetch Inventory for dropdown
$inventoryList = $db->query("SELECT id, part_code, part_name, category, unit, in_stock FROM inventory_parts ORDER BY category ASC, part_name ASC")->fetchAll();

// Fetch Job Photos
$stmtPhotos = $db->prepare("SELECT * FROM job_photos WHERE job_id = ? ORDER BY created_at ASC");
$stmtPhotos->execute([$jobId]);
$photos = $stmtPhotos->fetchAll();

// Fetch Job Logs
$stmtLogs = $db->prepare("
    SELECT l.*, u.name as user_name
    FROM job_logs l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.job_id = ?
    ORDER BY l.created_at DESC
");
$stmtLogs->execute([$jobId]);
$logs = $stmtLogs->fetchAll();

// Stepper states helper
$stages = ['scheduled', 'en_route', 'in_progress', 'completed', 'signed_off'];
$currentIndex = array_search($job['status'], $stages);
if ($currentIndex === false) $currentIndex = 0;

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <h1 class="page-title"><?= htmlspecialchars($job['job_number']) ?></h1>
            <?= get_status_badge($job['status']) ?>
            <?= get_priority_badge($job['priority']) ?>
        </div>
        <p class="page-subtitle"><?= htmlspecialchars($job['title']) ?></p>
    </div>

    <div class="page-actions">
        <a href="job_edit.php?id=<?= $job['id'] ?>" class="btn btn-secondary" title="Edit Full Job Card Details">
            <i data-lucide="edit"></i> Edit Job
        </a>

        <a href="job_print.php?id=<?= $job['id'] ?>" target="_blank" class="btn btn-secondary">
            <i data-lucide="printer"></i> Print Work Order
        </a>

        <?php
        $waTechMsg = "FieldPulse Kenya: Job Card {$job['job_number']}\nClient: {$job['customer_name']}\nPhone: {$job['customer_phone']}\nEstate/Area: {$job['estate_area']}\nLandmark: {$job['landmark']}\nTask: {$job['title']}\nSchedule: {$job['scheduled_time']} ({$job['scheduled_date']})";
        $waTechUrl = get_whatsapp_url($job['tech_phone'], $waTechMsg);
        ?>
        <a href="<?= $waTechUrl ?>" target="_blank" class="btn btn-whatsapp">
            <i data-lucide="message-square"></i> Send Job to Tech
        </a>

        <a href="tech_portal.php?job_id=<?= $job['id'] ?>" class="btn btn-primary" title="Open Mobile Technician View">
            <i data-lucide="smartphone"></i> Tech Mobile View
        </a>

        <form method="POST" action="jobs.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete job card \'<?= htmlspecialchars(addslashes($job['job_number'])) ?>\'?');">
            <input type="hidden" name="delete_job" value="1">
            <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
            <button type="submit" class="btn btn-danger" style="background: #e11d48;" title="Delete Job Card">
                <i data-lucide="trash-2"></i> Delete
            </button>
        </form>
    </div>
</div>

<!-- Lifecycle Stepper Timeline -->
<div class="card" style="margin-bottom: 24px; padding: 20px 28px;">
    <div class="job-stepper">
        <div class="step-item <?= $currentIndex >= 0 ? ($currentIndex == 0 ? 'active' : 'completed') : '' ?>">
            <div class="step-circle"><i data-lucide="<?= $currentIndex > 0 ? 'check' : 'calendar' ?>" style="width: 18px; height: 18px;"></i></div>
            <span class="step-title">1. Scheduled</span>
        </div>
        <div class="step-item <?= $currentIndex >= 1 ? ($currentIndex == 1 ? 'active' : 'completed') : '' ?>">
            <div class="step-circle"><i data-lucide="<?= $currentIndex > 1 ? 'check' : 'truck' ?>" style="width: 18px; height: 18px;"></i></div>
            <span class="step-title">2. En Route</span>
        </div>
        <div class="step-item <?= $currentIndex >= 2 ? ($currentIndex == 2 ? 'active' : 'completed') : '' ?>">
            <div class="step-circle"><i data-lucide="<?= $currentIndex > 2 ? 'check' : 'wrench' ?>" style="width: 18px; height: 18px;"></i></div>
            <span class="step-title">3. In Progress</span>
        </div>
        <div class="step-item <?= $currentIndex >= 3 ? ($currentIndex == 3 ? 'active' : 'completed') : '' ?>">
            <div class="step-circle"><i data-lucide="<?= $currentIndex > 3 ? 'check' : 'check-circle' ?>" style="width: 18px; height: 18px;"></i></div>
            <span class="step-title">4. Completed</span>
        </div>
        <div class="step-item <?= $currentIndex >= 4 ? 'completed' : '' ?>">
            <div class="step-circle"><i data-lucide="shield-check" style="width: 18px; height: 18px;"></i></div>
            <span class="step-title">5. Signed Off</span>
        </div>
    </div>

    <!-- Quick Status Changer -->
    <form method="POST" action="job_view.php?id=<?= $job['id'] ?>" style="display: flex; align-items: center; justify-content: flex-end; gap: 12px; border-top: 1px solid var(--border-color); padding-top: 14px; margin-top: 10px;">
        <input type="hidden" name="action" value="update_status">
        <span style="font-size: 13px; font-weight: 600; color: var(--text-muted);">Quick Status Change:</span>
        <select name="status" class="form-control" style="width: 180px; padding: 6px 12px; font-size: 13px;">
            <option value="scheduled" <?= $job['status'] == 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
            <option value="en_route" <?= $job['status'] == 'en_route' ? 'selected' : '' ?>>En Route</option>
            <option value="in_progress" <?= $job['status'] == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
            <option value="pending_parts" <?= $job['status'] == 'pending_parts' ? 'selected' : '' ?>>Pending Parts</option>
            <option value="completed" <?= $job['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="signed_off" <?= $job['status'] == 'signed_off' ? 'selected' : '' ?>>Signed & Closed</option>
            <option value="cancelled" <?= $job['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
        <button type="submit" class="btn btn-secondary btn-sm">Update Status</button>
    </form>
</div>

<!-- Main 2-Column Dashboard Layout -->
<div class="grid-2-1">
    <!-- Left Column: Work Details, Diagnosis, Parts, Photos, Signature -->
    <div style="display: flex; flex-direction: column; gap: 24px;">

        <!-- Diagnosis & Work Done Editor -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="file-text"></i> Diagnosis, Work Performed & Field Notes</span>
            </div>
            <form method="POST" action="job_view.php?id=<?= $job['id'] ?>">
                <input type="hidden" name="action" value="update_notes">
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Initial Job Instructions / Symptoms</label>
                        <div style="background: var(--bg-subtle); padding: 12px 14px; border-radius: var(--radius-md); font-size: 13.5px; color: var(--text-main); border: 1px solid var(--border-color);">
                            <?= nl2br(htmlspecialchars($job['job_description'] ?: 'No initial description provided.')) ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">On-Site Diagnosis & Root Cause</label>
                        <textarea name="diagnosis" class="form-control" placeholder="Describe fault findings, measured voltages, inspection notes..." rows="3"><?= htmlspecialchars($job['diagnosis'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Work Performed / Corrective Action</label>
                        <textarea name="work_performed" class="form-control" placeholder="Describe repairs executed, configurations changed, calibration done..." rows="3"><?= htmlspecialchars($job['work_performed'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Technician Recommendations / Next Service Advice</label>
                        <textarea name="technician_notes" class="form-control" placeholder="e.g. Advised client to replace battery terminal clamps in 3 months..." rows="2"><?= htmlspecialchars($job['technician_notes'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i data-lucide="save"></i> Save Work Notes
                    </button>
                </div>
            </form>
        </div>

        <!-- Parts & Materials Consumed on Job -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="package"></i> Parts & Materials Consumed</span>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('addPartModal')">
                    <i data-lucide="plus"></i> Add Part Used
                </button>
            </div>
            <div class="table-responsive">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Part Code</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Qty Used</th>
                            <th>Notes</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($partsUsedList)): ?>
                            <tr><td colspan="6" style="text-align: center; padding: 20px; color: var(--text-muted);">No parts or materials logged for this job yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($partsUsedList as $pu): ?>
                                <tr>
                                    <td><span style="font-family: var(--font-mono); font-weight: 700;"><?= htmlspecialchars($pu['part_code']) ?></span></td>
                                    <td><strong><?= htmlspecialchars($pu['part_name']) ?></strong></td>
                                    <td><span class="badge badge-secondary"><?= htmlspecialchars($pu['part_category']) ?></span></td>
                                    <td><span class="badge badge-info"><?= $pu['quantity'] ?> <?= htmlspecialchars($pu['unit']) ?></span></td>
                                    <td><span style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($pu['notes'] ?: '-') ?></span></td>
                                    <td>
                                        <form method="POST" action="job_view.php?id=<?= $job['id'] ?>" onsubmit="return confirm('Remove part and restore stock?');">
                                            <input type="hidden" name="action" value="remove_part">
                                            <input type="hidden" name="usage_id" value="<?= $pu['id'] ?>">
                                            <button type="submit" class="btn btn-sm" style="color: var(--danger); background: none; border: none; cursor: pointer;">
                                                <i data-lucide="trash-2" style="width: 14px; height: 14px;"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Before & After Photos Gallery -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="camera"></i> Job Photos & Site Documentation</span>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openModal('uploadPhotoModal')">
                    <i data-lucide="upload"></i> Upload Photo
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($photos)): ?>
                    <div style="text-align: center; padding: 24px; color: var(--text-muted);">
                        <i data-lucide="image" style="width: 36px; height: 36px; margin: 0 auto 8px; display: block; opacity: 0.4;"></i>
                        No job photos uploaded yet. Technicians can attach before/during/after repair photos from site.
                    </div>
                <?php else: ?>
                    <div class="photo-gallery-grid">
                        <?php foreach ($photos as $ph): ?>
                            <div class="photo-card">
                                <a href="<?= htmlspecialchars($ph['photo_path']) ?>" target="_blank">
                                    <img src="<?= htmlspecialchars($ph['photo_path']) ?>" alt="<?= htmlspecialchars($ph['caption'] ?? '') ?>" onerror="this.src='https://images.unsplash.com/photo-1581092160607-ee22621dd758?w=500&auto=format&fit=crop&q=60'">
                                </a>
                                <span class="photo-badge <?= htmlspecialchars($ph['photo_type']) ?>"><?= ucfirst($ph['photo_type']) ?></span>
                                <?php if ($ph['caption']): ?>
                                    <div class="photo-caption"><?= htmlspecialchars($ph['caption']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Customer Digital Sign-off Section -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="check-square"></i> Customer Sign-Off & Confirmation</span>
            </div>
            <div class="card-body">
                <?php if ($job['signature_data']): ?>
                    <div class="signed-display-card">
                        <div style="display: inline-flex; align-items: center; gap: 8px; color: var(--success); font-weight: 700; margin-bottom: 12px;">
                            <i data-lucide="shield-check" style="width: 22px; height: 22px;"></i>
                            Work Confirmed & Signed by Customer
                        </div>
                        <div class="signed-image-container">
                            <img src="<?= $job['signature_data'] ?>" alt="Customer Signature">
                        </div>
                        <div class="signed-meta">
                            Signatory Name: <strong><?= htmlspecialchars($job['signed_by_name']) ?></strong><br>
                            Signed on: <strong><?= format_datetime($job['signed_at']) ?></strong>
                        </div>
                        <?php if ($job['customer_feedback']): ?>
                            <div style="margin-top: 14px; background: #ffffff; padding: 10px 14px; border-radius: var(--radius-md); font-style: italic; border: 1px solid var(--border-color); font-size: 13px;">
                                "<?= htmlspecialchars($job['customer_feedback']) ?>"
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px;">
                        Have the customer sign on the screen using their finger, stylus, or mouse to verify completion of work.
                    </p>

                    <form method="POST" action="job_view.php?id=<?= $job['id'] ?>" id="signatureForm">
                        <input type="hidden" name="action" value="save_signature">
                        <input type="hidden" name="signature_data" id="signatureDataInput">

                        <div class="form-group">
                            <label class="form-label">Signatory Full Name / Representative <span class="required">*</span></label>
                            <input type="text" name="signed_by_name" class="form-control" placeholder="e.g. Esther Mutua / Facility Officer" value="<?= htmlspecialchars($job['customer_name']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Customer Sign-Off Canvas (Draw Signature)</label>
                            <div class="signature-wrapper">
                                <canvas id="signatureCanvas" class="signature-canvas" width="600" height="180"></canvas>
                                <div class="signature-line">
                                    <span class="signature-line-text">Sign Above Line</span>
                                </div>
                                <div class="signature-controls">
                                    <button type="button" class="btn btn-secondary btn-sm" id="clearSigBtn">
                                        <i data-lucide="rotate-ccw"></i> Clear Signature
                                    </button>
                                    <span style="font-size: 11.5px; color: var(--text-muted);">Use finger or mouse</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Customer Feedback / Remarks</label>
                            <input type="text" name="customer_feedback" class="form-control" placeholder="e.g. Power restored, tested inverter switchover successfully.">
                        </div>

                        <button type="submit" class="btn btn-success btn-lg" style="width: 100%; margin-top: 10px;">
                            <i data-lucide="shield-check"></i> Submit Signature & Close Job Card
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Right Column: Customer Info, Technician Profile, Asset Specs, Logs -->
    <div style="display: flex; flex-direction: column; gap: 24px;">

        <!-- Customer Site Card -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="map-pin"></i> Customer & Site Details</span>
                <a href="customer_view.php?id=<?= $job['customer_id'] ?>" class="btn btn-secondary btn-sm">CRM Profile</a>
            </div>
            <div class="card-body">
                <div style="font-size: 16px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">
                    <?= htmlspecialchars($job['customer_name']) ?>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 12px;">
                    <?= ucfirst($job['customer_type']) ?> Client
                </div>

                <div style="display: flex; flex-direction: column; gap: 10px; font-size: 13px;">
                    <div>
                        <span style="color: var(--text-muted);">Estate / Area:</span><br>
                        <strong><?= htmlspecialchars($job['estate_area']) ?></strong>
                    </div>

                    <div>
                        <span style="color: var(--text-muted);">Physical Address / Site:</span><br>
                        <strong><?= nl2br(htmlspecialchars($job['customer_address'])) ?></strong>
                    </div>

                    <?php if ($job['landmark']): ?>
                        <div style="background: #fef3c7; border: 1px solid #fde68a; padding: 8px 12px; border-radius: var(--radius-sm); font-size: 12px; color: #92400e;">
                            <strong>Landmark / Gate Instructions:</strong><br>
                            <?= htmlspecialchars($job['landmark']) ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <span style="color: var(--text-muted);">Phone Number:</span><br>
                        <a href="tel:<?= htmlspecialchars($job['customer_phone']) ?>" style="font-weight: 700;">
                            <?= htmlspecialchars($job['customer_phone']) ?>
                        </a>
                    </div>
                </div>

                <div style="display: flex; gap: 8px; margin-top: 18px;">
                    <?php
                    $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode($job['customer_address'] . " " . $job['estate_area'] . " Kenya");
                    if ($job['gps_coords']) {
                        $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode($job['gps_coords']);
                    }
                    ?>
                    <a href="<?= $mapsUrl ?>" target="_blank" class="btn btn-secondary btn-sm" style="flex: 1;">
                        <i data-lucide="navigation"></i> Google Maps
                    </a>

                    <?php
                    $waCustomerMsg = "Hello {$job['customer_name']}, this is an update regarding Job {$job['job_number']} with FieldPulse Kenya. Technician {$job['tech_name']} is currently handling your service.";
                    $waCustomerUrl = get_whatsapp_url($job['customer_phone'], $waCustomerMsg);
                    ?>
                    <a href="<?= $waCustomerUrl ?>" target="_blank" class="btn btn-whatsapp btn-sm" style="flex: 1;">
                        <i data-lucide="message-square"></i> WhatsApp
                    </a>
                </div>
            </div>
        </div>

        <!-- Assigned Technician Card -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="hard-hat"></i> Assigned Field Technician</span>
                <a href="technicians.php" class="btn btn-secondary btn-sm">Change</a>
            </div>
            <div class="card-body">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
                    <div class="user-avatar" style="width: 44px; height: 44px; font-size: 16px; background: #0284c7;">
                        <?= strtoupper(substr($job['tech_name'], 0, 2)) ?>
                    </div>
                    <div>
                        <div style="font-size: 15px; font-weight: 700;"><?= htmlspecialchars($job['tech_name']) ?></div>
                        <div style="font-size: 12px; color: var(--text-muted);"><?= htmlspecialchars($job['tech_skills'] ?: 'Field Specialist') ?></div>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13px;">
                    <div>
                        <span style="color: var(--text-muted);">Contact:</span>
                        <a href="tel:<?= htmlspecialchars($job['tech_phone']) ?>" style="font-weight: 700; margin-left: 6px;">
                            <?= htmlspecialchars($job['tech_phone']) ?>
                        </a>
                    </div>
                    <div>
                        <span style="color: var(--text-muted);">Current Status:</span>
                        <?= get_status_badge($job['tech_status']) ?>
                    </div>
                    <div>
                        <span style="color: var(--text-muted);">Scheduled Slot:</span>
                        <strong><?= htmlspecialchars($job['scheduled_time']) ?> (<?= format_date($job['scheduled_date']) ?>)</strong>
                    </div>
                </div>

                <div style="margin-top: 14px;">
                    <a href="<?= $waTechUrl ?>" target="_blank" class="btn btn-whatsapp btn-sm" style="width: 100%;">
                        <i data-lucide="send"></i> Dispatch Details to Tech WhatsApp
                    </a>
                </div>
            </div>
        </div>

        <!-- Equipment / Installed Asset Details -->
        <?php if ($job['asset_name']): ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i data-lucide="cpu"></i> Installed Equipment Specs</span>
                </div>
                <div class="card-body" style="font-size: 13px; display: flex; flex-direction: column; gap: 8px;">
                    <div>
                        <span style="color: var(--text-muted);">Equipment:</span>
                        <strong><?= htmlspecialchars($job['asset_name']) ?></strong>
                    </div>
                    <div>
                        <span style="color: var(--text-muted);">Brand / Model:</span>
                        <strong><?= htmlspecialchars($job['asset_brand'] ?: '-') ?> <?= htmlspecialchars($job['asset_model'] ?: '') ?></strong>
                    </div>
                    <div>
                        <span style="color: var(--text-muted);">Serial Number:</span>
                        <span style="font-family: var(--font-mono);"><?= htmlspecialchars($job['asset_serial'] ?: 'N/A') ?></span>
                    </div>
                    <div>
                        <span style="color: var(--text-muted);">Installation Date:</span>
                        <strong><?= format_date($job['asset_install_date']) ?></strong>
                    </div>
                    <?php if ($job['asset_location']): ?>
                        <div>
                            <span style="color: var(--text-muted);">Site Location:</span>
                            <strong><?= htmlspecialchars($job['asset_location']) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Activity Audit Log -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="history"></i> Job Activity Trail</span>
            </div>
            <div style="padding: 12px 18px; max-height: 280px; overflow-y: auto;">
                <?php if (empty($logs)): ?>
                    <div style="font-size: 12px; color: var(--text-muted); text-align: center; padding: 12px;">No activity logged yet.</div>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <div style="padding: 8px 0; border-bottom: 1px solid var(--border-color); font-size: 12px;">
                            <div style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($log['action']) ?></div>
                            <?php if ($log['notes']): ?>
                                <div style="color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($log['notes']) ?></div>
                            <?php endif; ?>
                            <div style="font-size: 10.5px; color: var(--text-light); margin-top: 3px;">
                                <?= time_ago($log['created_at']) ?> <?= $log['user_name'] ? 'by ' . htmlspecialchars($log['user_name']) : '' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- Modal: Add Part Used -->
<div id="addPartModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="package-plus"></i> Record Part Used on Job</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="job_view.php?id=<?= $job['id'] ?>">
            <input type="hidden" name="action" value="add_part">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Select Material / Part <span class="required">*</span></label>
                    <select name="part_id" class="form-control" required>
                        <option value="">-- Choose from Catalog --</option>
                        <?php foreach ($inventoryList as $inv): ?>
                            <option value="<?= $inv['id'] ?>">
                                [<?= htmlspecialchars($inv['part_code']) ?>] <?= htmlspecialchars($inv['part_name']) ?> (Stock: <?= $inv['in_stock'] ?> <?= $inv['unit'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Quantity Used <span class="required">*</span></label>
                        <input type="number" step="0.5" min="0.5" name="quantity" class="form-control" value="1" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Specific Application / Notes</label>
                        <input type="text" name="part_notes" class="form-control" placeholder="e.g. Replaced faulty circuit breaker in main DB">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                <button type="submit" class="btn btn-primary">Record & Deduct Stock</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Upload Photo -->
<div id="uploadPhotoModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="camera"></i> Upload Site / Job Photo</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="job_view.php?id=<?= $job['id'] ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_photo">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Photo Stage / Category</label>
                    <select name="photo_type" class="form-control">
                        <option value="before">Before Work (Initial Defect / Site Condition)</option>
                        <option value="during">During Work (Internal components / Wiring)</option>
                        <option value="after">After Work (Completed Installation / Clean site)</option>
                        <option value="site_doc">Site Document / Meter Reading / Nameplate</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Select Photo File (JPG, PNG) <span class="required">*</span></label>
                    <input type="file" name="photo" class="form-control" accept="image/*" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Caption / Description</label>
                    <input type="text" name="caption" class="form-control" placeholder="e.g. Completed inverter wiring and surge protectors">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                <button type="submit" class="btn btn-primary">Upload Photo</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('signatureCanvas');
    if (canvas) {
        const sigPad = new SignaturePad(canvas);

        const clearBtn = document.getElementById('clearSigBtn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                sigPad.clear();
            });
        }

        const sigForm = document.getElementById('signatureForm');
        if (sigForm) {
            sigForm.addEventListener('submit', (e) => {
                if (sigPad.isEmpty()) {
                    e.preventDefault();
                    alert('Please have the customer sign on the canvas before submitting.');
                    return false;
                }
                const dataUrl = sigPad.toDataURL();
                document.getElementById('signatureDataInput').value = dataUrl;
            });
        }
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
