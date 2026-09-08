<?php
$pageTitle = 'Field Technicians';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

// Handle Add Technician POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tech'])) {
    $name = clean($_POST['name'] ?? '');
    $email = clean($_POST['email'] ?? '');
    $phone = clean($_POST['phone'] ?? '');
    $skills = clean($_POST['trade_skills'] ?? '');
    $status = clean($_POST['status'] ?? 'available');
    $passHash = password_hash('tech123', PASSWORD_BCRYPT);

    if ($name && $email && $phone) {
        $stmt = $db->prepare("INSERT INTO users (name, email, phone, password, role, trade_skills, status) VALUES (?, ?, ?, ?, 'technician', ?, ?)");
        $stmt->execute([$name, $email, $phone, $passHash, $skills, $status]);

        set_flash('success', "Technician {$name} registered successfully.");
        header("Location: technicians.php");
        exit;
    }
}

// Handle Edit Technician POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_tech'])) {
    $techId = (int)$_POST['tech_id'];
    $name = clean($_POST['name'] ?? '');
    $email = clean($_POST['email'] ?? '');
    $phone = clean($_POST['phone'] ?? '');
    $skills = clean($_POST['trade_skills'] ?? '');
    $status = clean($_POST['status'] ?? 'available');

    if ($techId > 0 && $name && $email && $phone) {
        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, phone = ?, trade_skills = ?, status = ? WHERE id = ? AND role = 'technician'");
        $stmt->execute([$name, $email, $phone, $skills, $status, $techId]);

        set_flash('success', "Technician {$name} profile updated successfully.");
        header("Location: technicians.php");
        exit;
    }
}

// Handle Delete Technician POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tech'])) {
    $techId = (int)$_POST['tech_id'];
    if ($techId > 0) {
        // Check if has assigned jobs
        $jobCount = $db->prepare("SELECT COUNT(*) FROM job_cards WHERE technician_id = ?");
        $jobCount->execute([$techId]);
        if ($jobCount->fetchColumn() > 0) {
            // Set to inactive / off_duty
            $db->prepare("UPDATE users SET status = 'off_duty' WHERE id = ?")->execute([$techId]);
            set_flash('warning', "Technician has associated job records. Status was set to Off Duty instead of deletion to preserve history.");
        } else {
            $db->prepare("DELETE FROM users WHERE id = ? AND role = 'technician'")->execute([$techId]);
            set_flash('success', "Technician removed from team.");
        }
        header("Location: technicians.php");
        exit;
    }
}

// Handle Status Change POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    $techId = (int)$_POST['tech_id'];
    $newStatus = clean($_POST['new_status']);
    $db->prepare("UPDATE users SET status = ? WHERE id = ? AND role = 'technician'")->execute([$newStatus, $techId]);
    set_flash('success', "Technician status updated.");
    header("Location: technicians.php");
    exit;
}

// Fetch technicians with active and completed job counts
$techs = $db->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM job_cards j WHERE j.technician_id = u.id AND j.status IN ('scheduled', 'en_route', 'in_progress', 'pending_parts')) as active_jobs,
           (SELECT COUNT(*) FROM job_cards j WHERE j.technician_id = u.id AND j.status IN ('completed', 'signed_off')) as completed_jobs
    FROM users u
    WHERE u.role = 'technician'
    ORDER BY u.name ASC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Field Technicians Roster</h1>
        <p class="page-subtitle">Manage service crews, trade skills, availability, and active job workloads</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('addTechModal')">
            <i data-lucide="user-plus"></i> Add Technician
        </button>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Total Field Crew</span>
            <span class="stat-value"><?= count($techs) ?></span>
            <span class="stat-subtext"><i data-lucide="hard-hat" style="width: 13px; height: 13px;"></i> Certified techs</span>
        </div>
        <div class="stat-icon blue"><i data-lucide="users"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Available for Dispatch</span>
            <span class="stat-value"><?= count(array_filter($techs, fn($t) => $t['status'] === 'available')) ?></span>
            <span class="stat-subtext positive"><i data-lucide="check" style="width: 13px; height: 13px;"></i> Ready</span>
        </div>
        <div class="stat-icon green"><i data-lucide="check-circle"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Currently On Job</span>
            <span class="stat-value"><?= count(array_filter($techs, fn($t) => $t['status'] === 'on_job')) ?></span>
            <span class="stat-subtext warning"><i data-lucide="activity" style="width: 13px; height: 13px;"></i> In field</span>
        </div>
        <div class="stat-icon amber"><i data-lucide="truck"></i></div>
    </div>
</div>

<!-- Technicians Table -->
<div class="card">
    <div class="table-responsive">
        <table class="custom-table" id="mainTable">
            <thead>
                <tr>
                    <th>Technician</th>
                    <th>Trade Skills & Specialties</th>
                    <th>Phone / WhatsApp</th>
                    <th>Active Workload</th>
                    <th>Total Completed</th>
                    <th>Current Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($techs as $t): ?>
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="user-avatar" style="width: 38px; height: 38px; font-size: 13px; background: #0284c7;">
                                    <?= strtoupper(substr($t['name'], 0, 2)) ?>
                                </div>
                                <div>
                                    <div style="font-weight: 700; color: var(--text-main); font-size: 14px;"><?= htmlspecialchars($t['name']) ?></div>
                                    <div style="font-size: 11.5px; color: var(--text-muted);"><?= htmlspecialchars($t['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="font-size: 13px; font-weight: 500;"><?= htmlspecialchars($t['trade_skills'] ?: 'General Field Tech') ?></div>
                        </td>
                        <td>
                            <a href="tel:<?= htmlspecialchars($t['phone']) ?>" style="font-weight: 600;">
                                <?= htmlspecialchars($t['phone']) ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($t['active_jobs'] > 0): ?>
                                <span class="badge badge-purple"><?= $t['active_jobs'] ?> Active Job<?= $t['active_jobs'] > 1 ? 's' : '' ?></span>
                            <?php else: ?>
                                <span style="font-size: 12px; color: var(--text-muted);">Idle</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-teal"><?= $t['completed_jobs'] ?> Finished</span>
                        </td>
                        <td>
                            <form method="POST" action="technicians.php" style="display: inline-block;">
                                <input type="hidden" name="change_status" value="1">
                                <input type="hidden" name="tech_id" value="<?= $t['id'] ?>">
                                <select name="new_status" onchange="this.form.submit()" style="font-size: 12px; padding: 4px 8px; border-radius: 4px; border: 1px solid var(--border-color); background: var(--bg-subtle);">
                                    <option value="available" <?= $t['status'] === 'available' ? 'selected' : '' ?>>🟢 Available</option>
                                    <option value="on_job" <?= $t['status'] === 'on_job' ? 'selected' : '' ?>>🟡 On Job</option>
                                    <option value="off_duty" <?= $t['status'] === 'off_duty' ? 'selected' : '' ?>>⚪ Off Duty</option>
                                </select>
                            </form>
                        </td>
                        <td>
                            <div style="display: flex; gap: 6px; align-items: center;">
                                <button type="button" class="btn btn-secondary btn-sm" title="Edit Technician"
                                        onclick='editTech(<?= json_encode($t) ?>)'>
                                    <i data-lucide="edit" style="width: 13px; height: 13px;"></i>
                                </button>
                                <a href="jobs.php?tech_id=<?= $t['id'] ?>" class="btn btn-secondary btn-sm" title="View Assigned Jobs">
                                    <i data-lucide="clipboard-list" style="width: 13px; height: 13px;"></i> Jobs
                                </a>
                                <?php
                                $waUrl = get_whatsapp_url($t['phone'], "Hello {$t['name']}, message from FieldPulse Operations dispatch desk.");
                                ?>
                                <a href="<?= $waUrl ?>" target="_blank" class="btn btn-whatsapp btn-sm" title="Direct WhatsApp">
                                    <i data-lucide="message-square" style="width: 13px; height: 13px;"></i>
                                </a>
                                <form method="POST" action="technicians.php" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to remove technician \'<?= htmlspecialchars(addslashes($t['name'])) ?>\'?');">
                                    <input type="hidden" name="delete_tech" value="1">
                                    <input type="hidden" name="tech_id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="btn btn-sm" style="background: rgba(225, 29, 72, 0.15); color: #f43f5e; border: 1px solid rgba(225, 29, 72, 0.3); padding: 5px 8px;" title="Delete Technician">
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

<!-- Modal: Add Technician -->
<div id="addTechModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="user-plus"></i> Add Field Technician</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="technicians.php">
            <input type="hidden" name="add_tech" value="1">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Dennis Otieno" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone Number (WhatsApp) <span class="required">*</span></label>
                        <input type="text" name="phone" class="form-control" placeholder="e.g. +254 712 345 678" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email Address <span class="required">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="e.g. dennis.tech@fieldpulse.co.ke" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Trade Skills & Specialties <span class="required">*</span></label>
                    <input type="text" name="trade_skills" class="form-control" placeholder="e.g. Solar, Inverters, Battery Banks, Off-grid" required>
                    <span class="form-hint">Comma separated list of trade certifications and skills.</span>
                </div>

                <div class="form-group">
                    <label class="form-label">Initial Status</label>
                    <select name="status" class="form-control">
                        <option value="available">Available for Dispatch</option>
                        <option value="on_job">On Job</option>
                        <option value="off_duty">Off Duty</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                <button type="submit" class="btn btn-primary">Register Technician</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Technician -->
<div id="editTechModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-header">
            <span class="modal-title"><i data-lucide="edit"></i> Edit Technician Profile</span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="technicians.php">
            <input type="hidden" name="edit_tech" value="1">
            <input type="hidden" name="tech_id" id="edit_tech_id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" name="name" id="edit_tech_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Phone Number (WhatsApp) <span class="required">*</span></label>
                        <input type="text" name="phone" id="edit_tech_phone" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email Address <span class="required">*</span></label>
                        <input type="email" name="email" id="edit_tech_email" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Trade Skills & Specialties <span class="required">*</span></label>
                    <input type="text" name="trade_skills" id="edit_tech_skills" class="form-control" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" id="edit_tech_status" class="form-control">
                        <option value="available">Available for Dispatch</option>
                        <option value="on_job">On Job</option>
                        <option value="off_duty">Off Duty</option>
                    </select>
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
function editTech(data) {
    document.getElementById('edit_tech_id').value = data.id;
    document.getElementById('edit_tech_name').value = data.name;
    document.getElementById('edit_tech_phone').value = data.phone;
    document.getElementById('edit_tech_email').value = data.email;
    document.getElementById('edit_tech_skills').value = data.trade_skills || '';
    document.getElementById('edit_tech_status').value = data.status || 'available';
    openModal('editTechModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
