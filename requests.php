<?php
$pageTitle = 'Service Requests & Technician Dispatch';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

// Handle Delete Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $delId = (int)$_POST['request_id'];
    if ($delId > 0) {
        $stmtT = $db->prepare("SELECT ticket_no FROM service_requests WHERE id = ?");
        $stmtT->execute([$delId]);
        $tNo = $stmtT->fetchColumn();

        $db->prepare("DELETE FROM service_requests WHERE id = ?")->execute([$delId]);
        set_flash('success', "Service request {$tNo} deleted successfully.");
        header("Location: requests.php");
        exit;
    }
}

// Handle Connect Field Technician to Customer POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['connect_technician'])) {
    $reqId = (int)$_POST['request_id'];
    $techId = (int)$_POST['technician_id'];
    $schedDate = clean($_POST['scheduled_date'] ?? date('Y-m-d'));
    $schedTime = clean($_POST['scheduled_time'] ?? '09:00 AM');
    $duration = clean($_POST['estimated_duration'] ?? '2 hours');
    $priority = clean($_POST['priority'] ?? 'normal');
    $jobTitle = clean($_POST['job_title'] ?? '');
    $jobDesc = clean($_POST['job_description'] ?? '');

    if ($reqId > 0 && $techId > 0) {
        $stmtR = $db->prepare("SELECT r.*, c.name as customer_name, c.phone as customer_phone, c.estate_area FROM service_requests r JOIN customers c ON r.customer_id = c.id WHERE r.id = ?");
        $stmtR->execute([$reqId]);
        $reqData = $stmtR->fetch();

        if ($reqData) {
            $stmtT = $db->prepare("SELECT name, phone FROM users WHERE id = ?");
            $stmtT->execute([$techId]);
            $techData = $stmtT->fetch();

            $stmtExistingJob = $db->prepare("SELECT id FROM job_cards WHERE request_id = ?");
            $stmtExistingJob->execute([$reqId]);
            $existingJobId = $stmtExistingJob->fetchColumn();

            if ($existingJobId) {
                $db->prepare("
                    UPDATE job_cards 
                    SET technician_id = ?, scheduled_date = ?, scheduled_time = ?, estimated_duration = ?, priority = ?, title = ?, job_description = ?, status = 'scheduled'
                    WHERE id = ?
                ")->execute([$techId, $schedDate, $schedTime, $duration, $priority, !empty($jobTitle) ? $jobTitle : $reqData['title'], !empty($jobDesc) ? $jobDesc : $reqData['description'], $existingJobId]);
                $jobId = $existingJobId;
            } else {
                $jobNum = 'JOB-' . date('Y') . '-' . str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);
                $finalTitle = !empty($jobTitle) ? $jobTitle : $reqData['title'];
                $finalDesc = !empty($jobDesc) ? $jobDesc : $reqData['description'];

                $stmtNewJob = $db->prepare("
                    INSERT INTO job_cards (job_number, request_id, customer_id, technician_id, asset_id, trade_category, title, job_description, scheduled_date, scheduled_time, estimated_duration, priority, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
                ");
                $stmtNewJob->execute([$jobNum, $reqId, $reqData['customer_id'], $techId, $reqData['asset_id'], $reqData['trade_category'], $finalTitle, $finalDesc, $schedDate, $schedTime, $duration, $priority]);
                $jobId = $db->lastInsertId();
            }

            // Update request status to assigned
            $db->prepare("UPDATE service_requests SET status = 'assigned' WHERE id = ?")->execute([$reqId]);

            // Set technician status to on_job
            $db->prepare("UPDATE users SET status = 'on_job' WHERE id = ?")->execute([$techId]);

            // Log activity
            log_job_activity($jobId, "Technician {$techData['name']} connected to {$reqData['customer_name']}", "Dispatched for {$reqData['trade_category']} service on {$schedDate} ({$schedTime})", $_SESSION['user']['id'] ?? 1);

            // Notify technician
            create_notification(
                "New Job Assigned",
                "You have been assigned to customer {$reqData['customer_name']} ({$reqData['estate_area']}) for {$reqData['trade_category']} service.",
                "dispatch",
                "tech_portal.php?job_id={$jobId}",
                $techId
            );

            set_flash('success', "⚡ Connected Technician {$techData['name']} with customer {$reqData['customer_name']}! Work Order dispatched.");
            header("Location: requests.php");
            exit;
        }
    }
}

// Filters
$statusFilter = clean($_GET['status'] ?? '');
$tradeFilter = clean($_GET['trade'] ?? '');
$priorityFilter = clean($_GET['priority'] ?? '');

$query = "
    SELECT r.*, c.name as customer_name, c.phone as customer_phone, c.estate_area, c.address as customer_address, c.landmark,
           a.asset_name, a.brand as asset_brand,
           j.id as job_id, j.job_number, j.status as job_status, j.scheduled_date as job_date, j.scheduled_time as job_time,
           u.id as tech_id, u.name as tech_name, u.phone as tech_phone, u.trade_skills as tech_skills, u.status as tech_status
    FROM service_requests r
    JOIN customers c ON r.customer_id = c.id
    LEFT JOIN customer_assets a ON r.asset_id = a.id
    LEFT JOIN job_cards j ON j.request_id = r.id
    LEFT JOIN users u ON j.technician_id = u.id
    WHERE 1=1
";
$params = [];

if ($statusFilter) {
    $query .= " AND r.status = ?";
    $params[] = $statusFilter;
}
if ($tradeFilter) {
    $query .= " AND r.trade_category = ?";
    $params[] = $tradeFilter;
}
if ($priorityFilter) {
    $query .= " AND r.priority = ?";
    $params[] = $priorityFilter;
}

$query .= " ORDER BY r.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// All technicians for the Quick Connect modal
$allTechs = $db->query("SELECT id, name, phone, trade_skills, status FROM users WHERE role = 'technician' ORDER BY status ASC, name ASC")->fetchAll();

// Trades for filter dropdown
$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'Cleaning', 'Maintenance'];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Service Requests & Dispatch Desk</h1>
        <p class="page-subtitle">Connect field technicians with customers, schedule arrival windows, and dispatch work orders</p>
    </div>
    <div class="page-actions">
        <a href="book_service.php" class="btn btn-secondary">
            <i data-lucide="globe"></i> Customer Booking Portal
        </a>
        <a href="request_create.php" class="btn btn-primary">
            <i data-lucide="plus-circle"></i> Log Service Request
        </a>
    </div>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 24px; padding: 16px 20px;">
    <form method="GET" action="requests.php" style="display: flex; flex-wrap: wrap; gap: 14px; align-items: center;">
        <div style="flex: 1; min-width: 160px;">
            <select name="status" class="form-control" onchange="this.form.submit()">
                <option value="">-- All Statuses --</option>
                <option value="new" <?= $statusFilter == 'new' ? 'selected' : '' ?>>New / Unassigned</option>
                <option value="assigned" <?= $statusFilter == 'assigned' ? 'selected' : '' ?>>Assigned / Dispatched</option>
                <option value="in_progress" <?= $statusFilter == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                <option value="completed" <?= $statusFilter == 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
        </div>

        <div style="flex: 1; min-width: 160px;">
            <select name="trade" class="form-control" onchange="this.form.submit()">
                <option value="">-- All Trades --</option>
                <?php foreach ($tradeCategories as $trade): ?>
                    <option value="<?= $trade ?>" <?= $tradeFilter == $trade ? 'selected' : '' ?>><?= $trade ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="flex: 1; min-width: 160px;">
            <select name="priority" class="form-control" onchange="this.form.submit()">
                <option value="">-- All Priorities --</option>
                <option value="emergency" <?= $priorityFilter == 'emergency' ? 'selected' : '' ?>>⚡ Emergency</option>
                <option value="urgent" <?= $priorityFilter == 'urgent' ? 'selected' : '' ?>>Urgent</option>
                <option value="normal" <?= $priorityFilter == 'normal' ? 'selected' : '' ?>>Normal</option>
                <option value="low" <?= $priorityFilter == 'low' ? 'selected' : '' ?>>Low</option>
            </select>
        </div>

        <?php if ($statusFilter || $tradeFilter || $priorityFilter): ?>
            <a href="requests.php" class="btn btn-secondary btn-sm"><i data-lucide="x"></i> Clear Filters</a>
        <?php endif; ?>
    </form>
</div>

<!-- Requests Table -->
<div class="card">
    <div class="table-responsive">
        <table class="custom-table" id="mainTable">
            <thead>
                <tr>
                    <th>Ticket #</th>
                    <th>Customer & Site</th>
                    <th>Trade Category</th>
                    <th>Issue Summary</th>
                    <th>Requested Schedule</th>
                    <th>Assigned Technician</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: var(--text-muted);">
                            <i data-lucide="inbox" style="width: 36px; height: 36px; margin: 0 auto 10px; display: block; opacity: 0.5;"></i>
                            No service requests found matching your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                        <tr>
                            <td>
                                <a href="customer_portal.php?ticket=<?= urlencode($req['ticket_no']) ?>" target="_blank" style="font-weight: 700; color: var(--primary); font-family: var(--font-mono);" title="Open Customer Live Tracker">
                                    <?= htmlspecialchars($req['ticket_no']) ?>
                                </a>
                                <div style="font-size: 11px; color: var(--text-light);"><?= time_ago($req['created_at']) ?></div>
                                <div style="margin-top: 3px;"><?= get_priority_badge($req['priority']) ?></div>
                            </td>
                            <td>
                                <div class="table-cell-title"><?= htmlspecialchars($req['customer_name']) ?></div>
                                <div class="table-cell-sub">
                                    <i data-lucide="map-pin" style="width: 11px; height: 11px; vertical-align: middle;"></i>
                                    <?= htmlspecialchars($req['estate_area']) ?>
                                    <?php if ($req['landmark']): ?>
                                        <span style="opacity: 0.8;">(<?= htmlspecialchars($req['landmark']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                    <i data-lucide="phone" style="width: 10px; height: 10px; vertical-align: middle;"></i> <?= htmlspecialchars($req['customer_phone']) ?>
                                </div>
                            </td>
                            <td>
                                <?= get_trade_badge($req['trade_category']) ?>
                                <?php if ($req['asset_name']): ?>
                                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 3px;">
                                        <i data-lucide="cpu" style="width: 10px; height: 10px; vertical-align: middle;"></i> <?= htmlspecialchars($req['asset_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="max-width: 240px;">
                                <div style="font-weight: 600;"><?= htmlspecialchars($req['title']) ?></div>
                                <div style="font-size: 12px; color: var(--text-muted); line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                                    <?= htmlspecialchars($req['description']) ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size: 12.5px; font-weight: 500;"><?= format_date($req['preferred_date']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($req['preferred_time_slot'] ?: 'Anytime') ?></div>
                            </td>

                            <!-- Assigned Technician Column -->
                            <td>
                                <?php if (!empty($req['tech_name'])): ?>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <div class="user-avatar" style="width: 32px; height: 32px; font-size: 11px; background: #0284c7;">
                                            <?= strtoupper(substr($req['tech_name'], 0, 2)) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 700; font-size: 13px; color: var(--text-main);">
                                                <?= htmlspecialchars($req['tech_name']) ?>
                                            </div>
                                            <div style="font-size: 11px; color: var(--text-muted);">
                                                <?= htmlspecialchars($req['tech_phone']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="margin-top: 6px; display: flex; gap: 4px;">
                                        <button type="button" class="btn btn-secondary btn-sm" style="font-size: 10.5px; padding: 2px 6px;" onclick='openConnectModal(<?= json_encode($req) ?>)'>
                                            <i data-lucide="refresh-cw" style="width: 10px; height: 10px;"></i> Reassign
                                        </button>
                                        <?php if (!empty($req['job_id'])): ?>
                                            <a href="job_view.php?id=<?= $req['job_id'] ?>" class="btn btn-secondary btn-sm" style="font-size: 10.5px; padding: 2px 6px;" title="View Job Card">
                                                <i data-lucide="file-text" style="width: 10px; height: 10px;"></i> Job
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <button type="button" class="btn btn-primary btn-sm" style="font-size: 12px; font-weight: 700; background: linear-gradient(135deg, #0284c7, #0369a1); box-shadow: 0 2px 8px rgba(2,132,199,0.35);" onclick='openConnectModal(<?= json_encode($req) ?>)'>
                                        <i data-lucide="zap" style="width: 13px; height: 13px;"></i> Connect Tech
                                    </button>
                                <?php endif; ?>
                            </td>

                            <td><?= get_status_badge($req['job_status'] ?? $req['status']) ?></td>

                            <td>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <!-- WhatsApp Customer -->
                                    <?php
                                    $techIntro = !empty($req['tech_name']) ? " Your assigned technician is {$req['tech_name']} ({$req['tech_phone']})." : "";
                                    $waCustomerMsg = "Hello {$req['customer_name']}, this is FieldPulse Kenya regarding your service request ({$req['ticket_no']} - {$req['title']}).{$techIntro} Track live updates here: " . (getenv('BASE_URL') ?: 'http://localhost') . "/customer_portal.php?ticket={$req['ticket_no']}";
                                    $waCustomerUrl = get_whatsapp_url($req['customer_phone'], $waCustomerMsg);
                                    ?>
                                    <a href="<?= $waCustomerUrl ?>" target="_blank" class="btn btn-whatsapp btn-sm" title="WhatsApp Customer Update">
                                        <i data-lucide="message-square" style="width: 13px; height: 13px;"></i>
                                    </a>

                                    <a href="customer_portal.php?ticket=<?= urlencode($req['ticket_no']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="Customer Tracking View">
                                        <i data-lucide="eye" style="width: 13px; height: 13px;"></i>
                                    </a>

                                    <a href="request_edit.php?id=<?= $req['id'] ?>" class="btn btn-secondary btn-sm" title="Edit Service Request">
                                        <i data-lucide="edit" style="width: 13px; height: 13px;"></i>
                                    </a>

                                    <form method="POST" action="requests.php" style="display: inline-block;" onsubmit="return confirm('Delete request \'<?= htmlspecialchars(addslashes($req['ticket_no'])) ?>\'?');">
                                        <input type="hidden" name="delete_request" value="1">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <button type="submit" class="btn btn-sm" style="background: rgba(225, 29, 72, 0.15); color: #f43f5e; border: 1px solid rgba(225, 29, 72, 0.3); padding: 5px 8px;" title="Delete Request">
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

<!-- Modal: Connect Field Technician to Customer -->
<div id="connectTechModal" class="modal-backdrop">
    <div class="modal-box" style="max-width: 680px;">
        <div class="modal-header">
            <span class="modal-title">
                <i data-lucide="zap" style="color: #38bdf8;"></i> Connect Field Technician with Customer
            </span>
            <button class="modal-close"><i data-lucide="x"></i></button>
        </div>
        <form method="POST" action="requests.php">
            <input type="hidden" name="connect_technician" value="1">
            <input type="hidden" name="request_id" id="modal_request_id">

            <div class="modal-body">
                <!-- Customer & Request Brief Banner -->
                <div style="background: rgba(2, 132, 199, 0.1); border: 1px solid rgba(56, 189, 248, 0.2); border-radius: 12px; padding: 14px; margin-bottom: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <span id="modal_ticket_no" style="font-family: var(--font-mono); font-weight: 800; color: #38bdf8; font-size: 15px;"></span>
                            <span id="modal_trade_badge" style="margin-left: 8px;"></span>
                        </div>
                        <span id="modal_priority_badge"></span>
                    </div>

                    <div style="font-size: 14px; font-weight: 700; color: #fff; margin-top: 6px;" id="modal_req_title"></div>
                    
                    <div style="font-size: 12.5px; color: #94a3b8; margin-top: 4px;">
                        <i data-lucide="user" style="width: 12px; height: 12px; vertical-align: middle;"></i> <strong id="modal_cust_name" style="color: #e2e8f0;"></strong> • 
                        <i data-lucide="map-pin" style="width: 12px; height: 12px; vertical-align: middle;"></i> <span id="modal_estate_area"></span>
                    </div>
                </div>

                <!-- Select Field Technician -->
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700;">
                        Assign Field Technician <span class="required">*</span>
                    </label>
                    <select name="technician_id" id="modal_technician_id" class="form-control" required style="font-size: 13.5px; padding: 10px;">
                        <option value="">-- Choose Field Technician --</option>
                        <?php foreach ($allTechs as $t): ?>
                            <option value="<?= $t['id'] ?>" data-skills="<?= htmlspecialchars(strtolower($t['trade_skills'])) ?>">
                                <?= htmlspecialchars($t['name']) ?> [<?= ucfirst($t['status']) ?>] • <?= htmlspecialchars($t['trade_skills'] ?: 'General') ?> (<?= htmlspecialchars($t['phone']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-hint" style="color: #38bdf8;">
                        💡 Matching technicians with relevant certifications (Solar, Electrical, CCTV, Plumbing, HVAC, Fibre) will be highlighted.
                    </span>
                </div>

                <!-- Scheduling & Slot -->
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Scheduled Date <span class="required">*</span></label>
                        <input type="date" name="scheduled_date" id="modal_sched_date" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Scheduled Time Slot <span class="required">*</span></label>
                        <input type="text" name="scheduled_time" id="modal_sched_time" class="form-control" placeholder="e.g. 09:30 AM" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Estimated Duration</label>
                        <input type="text" name="estimated_duration" class="form-control" value="2 hours" placeholder="e.g. 2 hours">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Priority</label>
                        <select name="priority" id="modal_priority_select" class="form-control">
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                            <option value="emergency">⚡ Emergency (Immediate)</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Job Order Title</label>
                    <input type="text" name="job_title" id="modal_job_title" class="form-control" placeholder="Work order title">
                </div>

                <div class="form-group">
                    <label class="form-label">Field Work Instructions & Notes</label>
                    <textarea name="job_description" id="modal_job_desc" class="form-control" rows="3" placeholder="Special site instructions, access gate instructions, technical steps..."></textarea>
                </div>

            </div>

            <div class="modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 700; padding: 10px 20px;">
                    <i data-lucide="send" style="width: 16px; height: 16px;"></i> Connect & Dispatch Technician
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openConnectModal(data) {
    document.getElementById('modal_request_id').value = data.id;
    document.getElementById('modal_ticket_no').textContent = data.ticket_no;
    document.getElementById('modal_req_title').textContent = data.title;
    document.getElementById('modal_cust_name').textContent = data.customer_name;
    document.getElementById('modal_estate_area').textContent = data.estate_area + (data.landmark ? ' (' + data.landmark + ')' : '');
    
    document.getElementById('modal_job_title').value = data.title;
    document.getElementById('modal_job_desc').value = data.description;
    document.getElementById('modal_sched_date').value = data.preferred_date || '<?= date('Y-m-d') ?>';
    document.getElementById('modal_sched_time').value = data.preferred_time_slot || '09:30 AM';
    
    // Select existing tech if assigned
    const techSelect = document.getElementById('modal_technician_id');
    if (data.tech_id) {
        techSelect.value = data.tech_id;
    } else {
        // Try auto-matching by trade skill
        const reqTrade = (data.trade_category || '').toLowerCase();
        let matched = false;
        for (let i = 0; i < techSelect.options.length; i++) {
            const opt = techSelect.options[i];
            const skills = (opt.getAttribute('data-skills') || '');
            if (skills.includes(reqTrade)) {
                techSelect.selectedIndex = i;
                matched = true;
                break;
            }
        }
        if (!matched) techSelect.selectedIndex = 0;
    }

    if (data.priority) {
        document.getElementById('modal_priority_select').value = data.priority;
    }

    openModal('connectTechModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
