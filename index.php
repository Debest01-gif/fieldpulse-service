<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

// Fetch summary metrics
$activeJobsCount = $db->query("SELECT COUNT(*) FROM job_cards WHERE status IN ('scheduled', 'en_route', 'in_progress', 'pending_parts')")->fetchColumn();
$todayJobsCount = $db->query("SELECT COUNT(*) FROM job_cards WHERE scheduled_date = CURDATE()")->fetchColumn();
$pendingRequestsCount = $db->query("SELECT COUNT(*) FROM service_requests WHERE status = 'new'")->fetchColumn();
$availableTechsCount = $db->query("SELECT COUNT(*) FROM users WHERE role = 'technician' AND status = 'available'")->fetchColumn();
$completedJobsCount = $db->query("SELECT COUNT(*) FROM job_cards WHERE status IN ('completed', 'signed_off')")->fetchColumn();

// Fetch today's & active jobs
$stmtJobs = $db->query("
    SELECT j.*, c.name as customer_name, c.phone as customer_phone, c.estate_area, c.landmark,
           u.name as tech_name, u.phone as tech_phone
    FROM job_cards j
    JOIN customers c ON j.customer_id = c.id
    JOIN users u ON j.technician_id = u.id
    ORDER BY 
        CASE 
            WHEN j.priority = 'emergency' THEN 1
            WHEN j.priority = 'urgent' THEN 2
            ELSE 3
        END,
        j.scheduled_date ASC, j.created_at DESC
    LIMIT 8
");
$recentJobs = $stmtJobs->fetchAll();

// Fetch urgent service requests needing dispatch
$urgentRequests = $db->query("
    SELECT r.*, c.name as customer_name, c.estate_area, c.phone as customer_phone
    FROM service_requests r
    JOIN customers c ON r.customer_id = c.id
    WHERE r.status = 'new'
    ORDER BY 
        CASE WHEN r.priority = 'emergency' THEN 1 WHEN r.priority = 'urgent' THEN 2 ELSE 3 END,
        r.created_at DESC
    LIMIT 4
")->fetchAll();

// Fetch technicians status
$techs = $db->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM job_cards j WHERE j.technician_id = u.id AND j.status IN ('scheduled', 'en_route', 'in_progress')) as active_jobs
    FROM users u
    WHERE u.role = 'technician'
    ORDER BY u.status ASC, active_jobs DESC
    LIMIT 6
")->fetchAll();

// Trade category distribution
$tradesSummary = $db->query("
    SELECT trade_category, COUNT(*) as count 
    FROM job_cards 
    GROUP BY trade_category 
    ORDER BY count DESC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Operations & Dispatch Centre</h1>
        <p class="page-subtitle">Real-time overview of field teams, active job cards, and service requests across Kenya</p>
    </div>
    <div class="page-actions">
        <a href="<?= BASE_URL ?>/job_create.php" class="btn btn-primary">
            <i data-lucide="send"></i> Dispatch Job
        </a>
        <a href="<?= BASE_URL ?>/schedule.php" class="btn btn-secondary">
            <i data-lucide="calendar"></i> Schedule View
        </a>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Active Work Orders</span>
            <span class="stat-value"><?= $activeJobsCount ?></span>
            <span class="stat-subtext positive"><i data-lucide="activity" style="width:13px;height:13px;"></i> In the field</span>
        </div>
        <div class="stat-icon blue">
            <i data-lucide="wrench"></i>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Scheduled Today</span>
            <span class="stat-value"><?= $todayJobsCount ?></span>
            <span class="stat-subtext"><i data-lucide="clock" style="width:13px;height:13px;"></i> <?= date('d M Y') ?></span>
        </div>
        <div class="stat-icon purple">
            <i data-lucide="calendar-check"></i>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Pending Dispatch</span>
            <span class="stat-value"><?= $pendingRequestsCount ?></span>
            <span class="stat-subtext <?= $pendingRequestsCount > 0 ? 'warning' : 'positive' ?>">
                <i data-lucide="inbox" style="width:13px;height:13px;"></i> Inquiries waiting
            </span>
        </div>
        <div class="stat-icon amber">
            <i data-lucide="user-plus"></i>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Techs Available</span>
            <span class="stat-value"><?= $availableTechsCount ?></span>
            <span class="stat-subtext positive"><i data-lucide="check-circle" style="width:13px;height:13px;"></i> Ready for dispatch</span>
        </div>
        <div class="stat-icon green">
            <i data-lucide="hard-hat"></i>
        </div>
    </div>
</div>

<!-- Main 2-1 Layout -->
<div class="grid-2-1">
    <!-- Left: Active & Recent Job Cards Table -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i data-lucide="clipboard-list"></i> Field Work Orders & Live Tracking</span>
            <a href="<?= BASE_URL ?>/jobs.php" class="btn btn-secondary btn-sm">View All Jobs</a>
        </div>
        <div class="table-responsive">
            <table class="custom-table" id="mainTable">
                <thead>
                    <tr>
                        <th>Job #</th>
                        <th>Customer & Site</th>
                        <th>Trade</th>
                        <th>Technician</th>
                        <th>Schedule</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentJobs)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 24px; color: var(--text-muted);">No active jobs found. <a href="job_create.php">Create your first job card</a></td></tr>
                    <?php else: ?>
                        <?php foreach ($recentJobs as $job): ?>
                            <tr>
                                <td>
                                    <a href="job_view.php?id=<?= $job['id'] ?>" style="font-weight: 700; color: var(--primary);">
                                        <?= htmlspecialchars($job['job_number']) ?>
                                    </a>
                                    <div style="margin-top: 2px;"><?= get_priority_badge($job['priority']) ?></div>
                                </td>
                                <td>
                                    <div class="table-cell-title"><?= htmlspecialchars($job['customer_name']) ?></div>
                                    <div class="table-cell-sub"><i data-lucide="map-pin" style="width: 12px; height: 12px; vertical-align: middle;"></i> <?= htmlspecialchars($job['estate_area']) ?></div>
                                </td>
                                <td>
                                    <?= get_trade_badge($job['trade_category']) ?>
                                </td>
                                <td>
                                    <div style="font-weight: 600; font-size: 13px;"><?= htmlspecialchars($job['tech_name']) ?></div>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($job['tech_phone']) ?></div>
                                </td>
                                <td>
                                    <div style="font-weight: 500; font-size: 12.5px;"><?= format_date($job['scheduled_date']) ?></div>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($job['scheduled_time']) ?></div>
                                </td>
                                <td>
                                    <?= get_status_badge($job['status']) ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <a href="job_view.php?id=<?= $job['id'] ?>" class="btn btn-secondary btn-sm" title="View Details">
                                            <i data-lucide="eye" style="width: 14px; height: 14px;"></i>
                                        </a>
                                        <?php
                                        $waMsg = "Hello {$job['tech_name']}, you have been assigned Job {$job['job_number']} for {$job['customer_name']} in {$job['estate_area']}. Scheduled: {$job['scheduled_time']} ({$job['scheduled_date']}). Issue: {$job['title']}";
                                        $waUrl = get_whatsapp_url($job['tech_phone'], $waMsg);
                                        ?>
                                        <a href="<?= $waUrl ?>" target="_blank" class="btn btn-whatsapp btn-sm" title="Send WhatsApp Dispatch to Tech">
                                            <i data-lucide="message-square" style="width: 14px; height: 14px;"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Right Column: Tech Availability & Urgent Queue -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        <!-- Field Technicians Roster -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="hard-hat"></i> Field Technicians</span>
                <a href="<?= BASE_URL ?>/technicians.php" class="btn btn-secondary btn-sm">Manage</a>
            </div>
            <div style="padding: 12px 18px;">
                <?php foreach ($techs as $t): ?>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border-color);">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="user-avatar" style="width: 34px; height: 34px; font-size: 12px; background: #3b82f6;">
                                <?= strtoupper(substr($t['name'], 0, 2)) ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; font-size: 13px;"><?= htmlspecialchars($t['name']) ?></div>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($t['trade_skills'] ?: 'General Field Tech') ?></div>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?php if ($t['active_jobs'] > 0): ?>
                                <span class="badge badge-purple" style="font-size: 11px;"><?= $t['active_jobs'] ?> Job<?= $t['active_jobs'] > 1 ? 's' : '' ?></span>
                            <?php endif; ?>
                            <?= get_status_badge($t['status']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pending Service Requests -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="inbox"></i> Incoming Requests</span>
                <a href="<?= BASE_URL ?>/requests.php" class="btn btn-secondary btn-sm">All Requests</a>
            </div>
            <div style="padding: 12px 18px;">
                <?php if (empty($urgentRequests)): ?>
                    <div style="text-align: center; padding: 18px; color: var(--text-muted); font-size: 13px;">No new requests in queue.</div>
                <?php else: ?>
                    <?php foreach ($urgentRequests as $req): ?>
                        <div style="padding: 10px 0; border-bottom: 1px solid var(--border-color);">
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                <span style="font-weight: 700; font-size: 13px; color: var(--text-main);"><?= htmlspecialchars($req['ticket_no']) ?></span>
                                <?= get_priority_badge($req['priority']) ?>
                            </div>
                            <div style="font-size: 12.5px; font-weight: 600;"><?= htmlspecialchars($req['title']) ?></div>
                            <div style="font-size: 11.5px; color: var(--text-muted); margin: 3px 0;">
                                <i data-lucide="user" style="width: 11px; height: 11px; vertical-align: middle;"></i> <?= htmlspecialchars($req['customer_name']) ?> (<?= htmlspecialchars($req['estate_area']) ?>)
                            </div>
                            <div style="margin-top: 8px;">
                                <a href="job_create.php?request_id=<?= $req['id'] ?>" class="btn btn-primary btn-sm" style="font-size: 11px; padding: 4px 10px;">
                                    <i data-lucide="arrow-right" style="width: 12px; height: 12px;"></i> Dispatch Now
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Bottom Trade Distribution & Fast Links -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="pie-chart"></i> Supported Trade Categories & Active Job Volume</span>
    </div>
    <div class="card-body">
        <div style="display: flex; flex-wrap: wrap; gap: 14px;">
            <?php foreach ($tradesSummary as $ts): ?>
                <div style="background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 12px 18px; display: flex; align-items: center; gap: 12px; min-width: 180px;">
                    <div><?= get_trade_badge($ts['trade_category']) ?></div>
                    <div>
                        <div style="font-size: 18px; font-weight: 800;"><?= $ts['count'] ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);">Work Orders</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
