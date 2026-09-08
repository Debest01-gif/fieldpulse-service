<?php
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

// Active tech ID (can be selected via query string or session user)
$selectedTechId = (int)($_GET['tech_id'] ?? (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'technician' ? $_SESSION['user']['id'] : ($_SESSION['tech_portal_id'] ?? 3)));
$_SESSION['tech_portal_id'] = $selectedTechId;

// All techs for picker
$allTechs = $db->query("SELECT id, name, trade_skills, status FROM users WHERE role = 'technician' ORDER BY name ASC")->fetchAll();

// Fetch current tech
$stmtCurrentTech = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmtCurrentTech->execute([$selectedTechId]);
$currentTech = $stmtCurrentTech->fetch();

// Specific job if passed
$selectedJobId = (int)($_GET['job_id'] ?? 0);

// Fetch jobs assigned to this technician
$stmtTechJobs = $db->prepare("
    SELECT j.*,
           c.name as customer_name, c.phone as customer_phone, c.estate_area, c.address as customer_address, c.landmark, c.gps_coords,
           a.asset_name, a.brand as asset_brand, a.model_number as asset_model, a.serial_number as asset_serial
    FROM job_cards j
    JOIN customers c ON j.customer_id = c.id
    LEFT JOIN customer_assets a ON j.asset_id = a.id
    WHERE j.technician_id = ?
    ORDER BY
        CASE
            WHEN j.status = 'in_progress' THEN 1
            WHEN j.status = 'en_route' THEN 2
            WHEN j.status = 'scheduled' THEN 3
            ELSE 4
        END,
        j.scheduled_date DESC
");
$stmtTechJobs->execute([$selectedTechId]);
$techJobs = $stmtTechJobs->fetchAll();

// Handle form actions from tech portal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $jobId = (int)($_POST['job_id'] ?? 0);

    if ($action === 'tech_update_status' && $jobId > 0) {
        $newStatus = clean($_POST['new_status']);
        $sql = "UPDATE job_cards SET status = ?, updated_at = NOW()";
        if ($newStatus === 'in_progress') $sql .= ", started_at = IFNULL(started_at, NOW())";
        if ($newStatus === 'completed') $sql .= ", completed_at = IFNULL(completed_at, NOW())";
        $sql .= " WHERE id = ?";
        $db->prepare($sql)->execute([$newStatus, $jobId]);

        log_job_activity($jobId, "Status updated to " . ucfirst(str_replace('_', ' ', $newStatus)), "Updated via Mobile Tech Portal", $selectedTechId);
        create_notification("Tech Status Update", "{$currentTech['name']} updated Job #{$jobId} to " . ucfirst(str_replace('_', ' ', $newStatus)), "status_update", "job_view.php?id={$jobId}");

        set_flash('success', "Status changed to " . ucfirst(str_replace('_', ' ', $newStatus)));
        header("Location: tech_portal.php?tech_id={$selectedTechId}&job_id={$jobId}");
        exit;
    }

    if ($action === 'tech_save_notes' && $jobId > 0) {
        $diagnosis = clean($_POST['diagnosis'] ?? '');
        $workDone = clean($_POST['work_performed'] ?? '');
        $notes = clean($_POST['technician_notes'] ?? '');

        $db->prepare("UPDATE job_cards SET diagnosis = ?, work_performed = ?, technician_notes = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$diagnosis, $workDone, $notes, $jobId]);

        log_job_activity($jobId, "Work logs recorded by technician", null, $selectedTechId);
        set_flash('success', "Work details saved!");
        header("Location: tech_portal.php?tech_id={$selectedTechId}&job_id={$jobId}");
        exit;
    }

    if ($action === 'tech_add_part' && $jobId > 0) {
        $partId = (int)$_POST['part_id'];
        $qty = (float)$_POST['quantity'];
        $partNote = clean($_POST['part_notes'] ?? '');

        if ($partId > 0 && $qty > 0) {
            $db->prepare("UPDATE inventory_parts SET in_stock = in_stock - ? WHERE id = ?")->execute([$qty, $partId]);
            $db->prepare("INSERT INTO job_parts_used (job_id, part_id, quantity, notes) VALUES (?, ?, ?, ?)")->execute([$jobId, $partId, $qty, $partNote]);

            log_job_activity($jobId, "Part logged on site: {$qty} units", $partNote, $selectedTechId);
            set_flash('success', "Part logged & stock updated.");
            header("Location: tech_portal.php?tech_id={$selectedTechId}&job_id={$jobId}");
            exit;
        }
    }

    if ($action === 'tech_upload_photo' && $jobId > 0) {
        $pType = clean($_POST['photo_type'] ?? 'during');
        $caption = clean($_POST['caption'] ?? '');
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $fname = 'tech_' . $jobId . '_' . time() . '.' . $ext;
                $target = __DIR__ . '/assets/uploads/' . $fname;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                    $db->prepare("INSERT INTO job_photos (job_id, photo_path, photo_type, caption, uploaded_by) VALUES (?, ?, ?, ?, ?)")
                       ->execute([$jobId, 'assets/uploads/' . $fname, $pType, $caption, $selectedTechId]);

                    log_job_activity($jobId, "Photo uploaded from site ({$pType})", $caption, $selectedTechId);
                    set_flash('success', "Photo uploaded successfully!");
                    header("Location: tech_portal.php?tech_id={$selectedTechId}&job_id={$jobId}");
                    exit;
                }
            }
        }
    }

    if ($action === 'tech_sign_off' && $jobId > 0) {
        $sigData = $_POST['signature_data'] ?? '';
        $signedByName = clean($_POST['signed_by_name'] ?? '');
        $feedback = clean($_POST['customer_feedback'] ?? '');

        if ($sigData && $signedByName) {
            $db->prepare("
                UPDATE job_cards
                SET signature_data = ?, signed_by_name = ?, customer_feedback = ?, signed_at = NOW(), status = 'signed_off', completed_at = IFNULL(completed_at, NOW()), updated_at = NOW()
                WHERE id = ?
            ")->execute([$sigData, $signedByName, $feedback, $jobId]);

            log_job_activity($jobId, "Customer Sign-off captured via Mobile Portal by {$signedByName}", $feedback, $selectedTechId);
            create_notification("Job Signed Off", "Job #{$jobId} signed off on site by {$signedByName}", "signature", "job_view.php?id={$jobId}");

            set_flash('success', "Work completed & customer signature saved successfully!");
            header("Location: tech_portal.php?tech_id={$selectedTechId}&job_id={$jobId}");
            exit;
        }
    }
}

// Fetch Inventory for quick parts selector
$inventory = $db->query("SELECT id, part_code, part_name, unit, in_stock FROM inventory_parts ORDER BY part_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Technician Mobile Workstation - FieldPulse Kenya</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/signature-pad.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            background-color: #0f172a;
            color: #f8fafc;
            padding-bottom: 80px;
        }
        .mobile-header {
            background: #1e293b;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding: 16px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .tech-badge-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .portal-card {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin-bottom: 18px;
        }
        .action-btn-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 14px 0;
        }
        .status-btn {
            padding: 12px;
            border-radius: var(--radius-md);
            font-weight: 700;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            color: #fff;
            text-decoration: none;
        }
        .status-btn.en_route { background: #f59e0b; }
        .status-btn.in_progress { background: #0284c7; }
        .status-btn.completed { background: #10b981; }
        .status-btn.pending_parts { background: #ef4444; }
    </style>
</head>
<body>

<!-- Mobile Header -->
<header class="mobile-header">
    <div class="tech-badge-container">
        <div class="user-avatar" style="background: #0284c7; width: 36px; height: 36px; font-size: 13px;">
            <?= strtoupper(substr($currentTech['name'] ?? 'T', 0, 2)) ?>
        </div>
        <div>
            <div style="font-weight: 700; font-size: 14px; color: #fff;"><?= htmlspecialchars($currentTech['name'] ?? 'Technician') ?></div>
            <div style="font-size: 11px; color: #38bdf8;"><?= htmlspecialchars($currentTech['trade_skills'] ?: 'Field Specialist') ?></div>
        </div>
    </div>

    <!-- Tech Switcher Dropdown -->
    <form method="GET" action="tech_portal.php" style="display: flex; align-items: center; gap: 6px;">
        <select name="tech_id" onchange="this.form.submit()" style="background: #0f172a; color: #fff; border: 1px solid rgba(255,255,255,0.15); border-radius: 6px; padding: 5px 8px; font-size: 11px;">
            <?php foreach ($allTechs as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $t['id'] == $selectedTechId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a href="index.php" class="btn btn-secondary btn-sm" style="font-size: 11px; padding: 4px 8px;" title="Back to Admin">
            <i data-lucide="layout-dashboard" style="width: 13px; height: 13px;"></i>
        </a>
    </form>
</header>

<div style="max-width: 600px; margin: 0 auto; padding: 18px 16px;">

    <?php $flash = get_flash(); if ($flash): ?>
        <div class="flash-alert flash-<?= $flash['type'] ?>" style="margin-bottom: 16px;">
            <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle-2' : 'alert-circle' ?>"></i>
            <span><?= htmlspecialchars($flash['message']) ?></span>
        </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h2 style="font-size: 18px; font-weight: 800; color: #fff;">My Assigned Jobs Today</h2>
        <span class="badge badge-info"><?= count($techJobs) ?> Work Order<?= count($techJobs) == 1 ? '' : 's' ?></span>
    </div>

    <?php if (empty($techJobs)): ?>
        <div class="portal-card" style="text-align: center; padding: 36px 20px;">
            <i data-lucide="smile" style="width: 44px; height: 44px; color: #10b981; margin: 0 auto 10px; display: block;"></i>
            <h3 style="font-size: 16px; color: #fff;">No pending jobs assigned!</h3>
            <p style="font-size: 12.5px; color: #94a3b8; margin-top: 4px;">You have no active work orders scheduled for today. Check with dispatch.</p>
        </div>
    <?php else: ?>
        <?php foreach ($techJobs as $job): ?>
            <div class="portal-card" style="<?= ($selectedJobId == $job['id']) ? 'border: 2px solid #38bdf8;' : '' ?>">
                <!-- Job Header -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                    <div>
                        <span style="font-family: var(--font-mono); font-weight: 800; color: #38bdf8; font-size: 15px;">
                            <?= htmlspecialchars($job['job_number']) ?>
                        </span>
                        <div style="margin-top: 3px;"><?= get_priority_badge($job['priority']) ?></div>
                    </div>
                    <?= get_status_badge($job['status']) ?>
                </div>

                <h3 style="font-size: 16px; font-weight: 700; color: #fff; margin-bottom: 6px;">
                    <?= htmlspecialchars($job['title']) ?>
                </h3>

                <!-- Customer & Location Info -->
                <div style="background: rgba(0,0,0,0.25); padding: 12px; border-radius: var(--radius-md); font-size: 13px; margin: 10px 0; display: flex; flex-direction: column; gap: 6px;">
                    <div>
                        <i data-lucide="user" style="width: 13px; height: 13px; color: #38bdf8; vertical-align: middle;"></i>
                        <strong style="color: #fff;"><?= htmlspecialchars($job['customer_name']) ?></strong>
                    </div>
                    <div>
                        <i data-lucide="map-pin" style="width: 13px; height: 13px; color: #f59e0b; vertical-align: middle;"></i>
                        <span><?= htmlspecialchars($job['estate_area']) ?> - <?= htmlspecialchars($job['customer_address']) ?></span>
                    </div>
                    <?php if ($job['landmark']): ?>
                        <div style="color: #fde68a; font-size: 12px;">
                            <strong>Gate / Landmark:</strong> <?= htmlspecialchars($job['landmark']) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <i data-lucide="clock" style="width: 13px; height: 13px; color: #a78bfa; vertical-align: middle;"></i>
                        <span>Time: <strong><?= htmlspecialchars($job['scheduled_time']) ?></strong></span>
                    </div>
                </div>

                <!-- 1-Tap Mobile Actions: Maps & WhatsApp -->
                <div class="action-btn-grid">
                    <?php
                    $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode($job['customer_address'] . " " . $job['estate_area'] . " Kenya");
                    if ($job['gps_coords']) {
                        $mapsUrl = "https://www.google.com/maps/search/?api=1&query=" . urlencode($job['gps_coords']);
                    }
                    ?>
                    <a href="<?= $mapsUrl ?>" target="_blank" class="btn btn-secondary" style="background: #334155; color: #fff; border-color: rgba(255,255,255,0.1);">
                        <i data-lucide="navigation"></i> Navigate Map
                    </a>

                    <?php
                    $waCustomerMsg = "Hello {$job['customer_name']}, this is technician {$currentTech['name']} from FieldPulse regarding Job {$job['job_number']}.";
                    $waCustomerUrl = get_whatsapp_url($job['customer_phone'], $waCustomerMsg);
                    ?>
                    <a href="<?= $waCustomerUrl ?>" target="_blank" class="btn btn-whatsapp">
                        <i data-lucide="message-square"></i> WhatsApp
                    </a>
                </div>

                <!-- Field Status Transition Stepper Buttons -->
                <div style="margin-top: 14px;">
                    <div style="font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px;">Update Job Stage:</div>

                    <form method="POST" action="tech_portal.php" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <input type="hidden" name="action" value="tech_update_status">
                        <input type="hidden" name="job_id" value="<?= $job['id'] ?>">

                        <button type="submit" name="new_status" value="en_route" class="status-btn en_route" <?= $job['status'] == 'en_route' ? 'disabled style="opacity:0.6;"' : '' ?>>
                            <i data-lucide="truck"></i> I'm En Route
                        </button>

                        <button type="submit" name="new_status" value="in_progress" class="status-btn in_progress" <?= $job['status'] == 'in_progress' ? 'disabled style="opacity:0.6;"' : '' ?>>
                            <i data-lucide="wrench"></i> Start Work
                        </button>

                        <button type="submit" name="new_status" value="pending_parts" class="status-btn pending_parts">
                            <i data-lucide="package"></i> Need Parts
                        </button>

                        <button type="submit" name="new_status" value="completed" class="status-btn completed" <?= $job['status'] == 'completed' ? 'disabled style="opacity:0.6;"' : '' ?>>
                            <i data-lucide="check"></i> Finished Work
                        </button>
                    </form>
                </div>

                <!-- Work Details & Signature Accordion Button -->
                <div style="margin-top: 16px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 14px;">
                    <details <?= ($selectedJobId == $job['id']) ? 'open' : '' ?> style="color: #cbd5e1;">
                        <summary style="cursor: pointer; font-weight: 700; color: #38bdf8; font-size: 13.5px; padding: 4px 0;">
                            📝 Log Work Done, Parts & Customer Signature
                        </summary>

                        <div style="margin-top: 14px; display: flex; flex-direction: column; gap: 16px;">

                            <!-- 1. Notes Form -->
                            <form method="POST" action="tech_portal.php" style="background: rgba(0,0,0,0.2); padding: 14px; border-radius: var(--radius-md);">
                                <input type="hidden" name="action" value="tech_save_notes">
                                <input type="hidden" name="job_id" value="<?= $job['id'] ?>">

                                <div class="form-group">
                                    <label class="form-label" style="color: #fff; font-size: 12px;">Diagnosis / Fault Found</label>
                                    <textarea name="diagnosis" class="form-control" style="background: #0f172a; color: #fff; border-color: rgba(255,255,255,0.15);" rows="2" placeholder="e.g. Inverter switchover delay caused by strict grid sensitivity setting"><?= htmlspecialchars($job['diagnosis'] ?? '') ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" style="color: #fff; font-size: 12px;">Work Performed</label>
                                    <textarea name="work_performed" class="form-control" style="background: #0f172a; color: #fff; border-color: rgba(255,255,255,0.15);" rows="2" placeholder="e.g. Adjusted frequency threshold, tightened battery lugs, verified 5kW load"><?= htmlspecialchars($job['work_performed'] ?? '') ?></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary btn-sm" style="width: 100%;">
                                    <i data-lucide="save"></i> Save Notes
                                </button>
                            </form>

                            <!-- 2. Log Parts Used -->
                            <form method="POST" action="tech_portal.php" style="background: rgba(0,0,0,0.2); padding: 14px; border-radius: var(--radius-md);">
                                <input type="hidden" name="action" value="tech_add_part">
                                <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                <div style="font-weight: 700; font-size: 12px; color: #fff; margin-bottom: 8px;">📦 Record Part Used on Site</div>

                                <div class="form-group">
                                    <select name="part_id" class="form-control" style="background: #0f172a; color: #fff; border-color: rgba(255,255,255,0.15); font-size: 12px;" required>
                                        <option value="">-- Choose Material / Part --</option>
                                        <?php foreach ($inventory as $inv): ?>
                                            <option value="<?= $inv['id'] ?>"><?= htmlspecialchars($inv['part_name']) ?> (<?= $inv['in_stock'] ?> in stock)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <input type="number" step="0.5" min="0.5" name="quantity" class="form-control" value="1" placeholder="Qty" style="background: #0f172a; color: #fff; border-color: rgba(255,255,255,0.15);" required>
                                    </div>
                                    <div class="form-group">
                                        <input type="text" name="part_notes" class="form-control" placeholder="Notes" style="background: #0f172a; color: #fff; border-color: rgba(255,255,255,0.15);">
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; background: #334155; color: #fff;">
                                    <i data-lucide="plus"></i> Add Part
                                </button>
                            </form>

                            <!-- 3. Upload Photo -->
                            <form method="POST" action="tech_portal.php" enctype="multipart/form-data" style="background: rgba(0,0,0,0.2); padding: 14px; border-radius: var(--radius-md);">
                                <input type="hidden" name="action" value="tech_upload_photo">
                                <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                <div style="font-weight: 700; font-size: 12px; color: #fff; margin-bottom: 8px;">📷 Upload Site / Repair Photo</div>

                                <div class="form-group">
                                    <select name="photo_type" class="form-control" style="background: #0f172a; color: #fff; border-color: rgba(255,255,255,0.15); font-size: 12px;">
                                        <option value="before">Before Repair</option>
                                        <option value="during">During Work</option>
                                        <option value="after">After Completion</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <input type="file" name="photo" class="form-control" accept="image/*" capture="environment" style="background: #0f172a; color: #fff; border-color: rgba(255,255,255,0.15); font-size: 12px;" required>
                                </div>

                                <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%; background: #334155; color: #fff;">
                                    <i data-lucide="upload"></i> Upload from Camera
                                </button>
                            </form>

                            <!-- 4. Customer Touchscreen Signature -->
                            <div style="background: rgba(0,0,0,0.3); padding: 14px; border-radius: var(--radius-md); border: 1px solid rgba(255,255,255,0.1);">
                                <div style="font-weight: 700; font-size: 13px; color: #10b981; margin-bottom: 6px;">
                                    ✍️ Customer On-Site Signature
                                </div>

                                <?php if ($job['signature_data']): ?>
                                    <div style="text-align: center; padding: 10px;">
                                        <img src="<?= $job['signature_data'] ?>" alt="Signature" style="max-height: 60px; background: #fff; padding: 4px; border-radius: 4px;">
                                        <div style="font-size: 12px; color: #34d399; font-weight: 700; margin-top: 6px;">
                                            Signed by <?= htmlspecialchars($job['signed_by_name']) ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" action="tech_portal.php" id="techSigForm_<?= $job['id'] ?>">
                                        <input type="hidden" name="action" value="tech_sign_off">
                                        <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                        <input type="hidden" name="signature_data" id="techSigData_<?= $job['id'] ?>">

                                        <div class="form-group">
                                            <label class="form-label" style="color: #fff; font-size: 12px;">Customer Signatory Name</label>
                                            <input type="text" name="signed_by_name" class="form-control" placeholder="Customer Name" value="<?= htmlspecialchars($job['customer_name']) ?>" style="background: #0f172a; color: #fff; border-color: rgba(255,255,255,0.15);" required>
                                        </div>

                                        <div class="signature-wrapper" style="background: #fafafa; border-radius: 8px;">
                                            <canvas id="techCanvas_<?= $job['id'] ?>" class="signature-canvas" width="400" height="150"></canvas>
                                            <div class="signature-controls">
                                                <button type="button" class="btn btn-secondary btn-sm" id="techClearBtn_<?= $job['id'] ?>">Clear</button>
                                                <span style="font-size: 11px; color: #64748b;">Sign on screen</span>
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-success btn-lg" style="width: 100%; margin-top: 12px;">
                                            <i data-lucide="check-circle"></i> Complete & Close Job
                                        </button>
                                    </form>

                                    <script>
                                    document.addEventListener('DOMContentLoaded', () => {
                                        const c = document.getElementById('techCanvas_<?= $job['id'] ?>');
                                        if (c) {
                                            const sp = new SignaturePad(c);
                                            const clr = document.getElementById('techClearBtn_<?= $job['id'] ?>');
                                            if (clr) clr.addEventListener('click', () => sp.clear());

                                            const frm = document.getElementById('techSigForm_<?= $job['id'] ?>');
                                            if (frm) {
                                                frm.addEventListener('submit', (e) => {
                                                    if (sp.isEmpty()) {
                                                        e.preventDefault();
                                                        alert('Please have the customer sign on the screen.');
                                                        return false;
                                                    }
                                                    document.getElementById('techSigData_<?= $job['id'] ?>').value = sp.toDataURL();
                                                });
                                            }
                                        }
                                    });
                                    </script>
                                <?php endif; ?>
                            </div>

                        </div>
                    </details>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<script src="assets/js/signature.js"></script>
<script>
    if (window.lucide) {
        lucide.createIcons();
    }
</script>
</body>
</html>
