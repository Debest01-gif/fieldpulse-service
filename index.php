<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();
$user = current_user();

// Check if user is a customer/member
$isMember = ($user && $user['role'] === 'member');

if ($isMember) {
    // -------------------------------------------------------------
    // MEMBER DASHBOARD DATA
    // -------------------------------------------------------------
    $stmtC = $db->prepare("SELECT * FROM customers WHERE user_id = ? OR email = ? OR phone = ? LIMIT 1");
    $stmtC->execute([$user['id'], $user['email'], $user['phone']]);
    $memberCustomer = $stmtC->fetch();
    $memberCustomerId = $memberCustomer ? $memberCustomer['id'] : 0;

    // Fetch active service requests for this member
    $stmtMemberReqs = $db->prepare("
        SELECT r.*, c.name as customer_name, c.phone as customer_phone, c.estate_area, c.address as customer_address, c.landmark,
               j.id as job_id, j.job_number, j.status as job_status, j.scheduled_date as job_date, j.scheduled_time as job_time,
               j.estimated_duration, j.diagnosis, j.work_performed,
               u.id as tech_id, u.name as tech_name, u.phone as tech_phone, u.trade_skills as tech_skills, u.status as tech_status, u.avatar as tech_avatar,
               a.asset_name, a.brand as asset_brand
        FROM service_requests r
        JOIN customers c ON r.customer_id = c.id
        LEFT JOIN job_cards j ON j.request_id = r.id
        LEFT JOIN users u ON j.technician_id = u.id
        LEFT JOIN customer_assets a ON r.asset_id = a.id
        WHERE r.customer_id = ?
        ORDER BY 
            CASE WHEN r.status IN ('new', 'assigned', 'in_progress') THEN 1 ELSE 2 END,
            r.created_at DESC
    ");
    $stmtMemberReqs->execute([$memberCustomerId]);
    $memberAllRequests = $stmtMemberReqs->fetchAll();

    // Member registered assets
    $stmtAssets = $db->prepare("SELECT * FROM customer_assets WHERE customer_id = ? ORDER BY install_date DESC");
    $stmtAssets->execute([$memberCustomerId]);
    $memberAssets = $stmtAssets->fetchAll();

} else {
    // -------------------------------------------------------------
    // ADMIN / DISPATCHER / TECH OPERATIONS DASHBOARD DATA
    // -------------------------------------------------------------
    $activeJobsCount = $db->query("SELECT COUNT(*) FROM job_cards WHERE status IN ('scheduled', 'en_route', 'in_progress', 'pending_parts')")->fetchColumn();
    $todayJobsCount = $db->query("SELECT COUNT(*) FROM job_cards WHERE scheduled_date = '" . date('Y-m-d') . "'")->fetchColumn();
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
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($isMember): ?>
    <!-- ============================================================== -->
    <!-- MEMBER PORTAL DASHBOARD VIEW -->
    <!-- ============================================================== -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Welcome, <?= htmlspecialchars($user['name']) ?></h1>
            <p class="page-subtitle">Your Member Service Dashboard • Track field technicians, view assigned contacts, and book maintenance</p>
        </div>
        <div class="page-actions">
            <a href="book_service.php" class="btn btn-primary" style="font-weight: 700; font-size: 14px;">
                <i data-lucide="plus-circle"></i> Book New Field Service
            </a>
            <a href="customer_portal.php" class="btn btn-secondary">
                <i data-lucide="activity"></i> Full Live Tracker
            </a>
        </div>
    </div>

    <!-- Quick Booking Trade Cards Banner -->
    <div class="card" style="margin-bottom: 26px; background: radial-gradient(circle at 90% 10%, rgba(2, 132, 199, 0.15), transparent 40%), var(--bg-surface);">
        <div class="card-header">
            <span class="card-title"><i data-lucide="sparkles" style="color: var(--primary);"></i> Book Any Field Service with Instant Technician Dispatch</span>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                <?php
                $memberTradeShortcuts = [
                    ['trade' => 'Solar', 'label' => 'Solar & Inverters', 'icon' => 'sun', 'color' => '#f59e0b'],
                    ['trade' => 'Electrical', 'label' => 'Electrical & Wiring', 'icon' => 'zap', 'color' => '#38bdf8'],
                    ['trade' => 'CCTV', 'label' => 'CCTV & Security', 'icon' => 'video', 'color' => '#10b981'],
                    ['trade' => 'Plumbing', 'label' => 'Plumbing & Pumps', 'icon' => 'droplet', 'color' => '#06b6d4'],
                    ['trade' => 'Fibre', 'label' => 'WiFi & Network', 'icon' => 'wifi', 'color' => '#8b5cf6'],
                    ['trade' => 'HVAC', 'label' => 'AC & Cooling', 'icon' => 'wind', 'color' => '#0284c7'],
                    ['trade' => 'Appliance', 'label' => 'Appliance Repair', 'icon' => 'tv', 'color' => '#ec4899'],
                    ['trade' => 'Cleaning', 'label' => 'Cleaning Services', 'icon' => 'sparkles', 'color' => '#14b8a6'],
                    ['trade' => 'Maintenance', 'label' => 'Handyman Services', 'icon' => 'wrench', 'color' => '#f97316'],
                ];
                ?>
                <?php foreach ($memberTradeShortcuts as $sc): ?>
                    <a href="book_service.php?trade=<?= urlencode($sc['trade']) ?>" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; padding: 8px 14px;">
                        <i data-lucide="<?= $sc['icon'] ?>" style="width: 14px; height: 14px; color: <?= $sc['color'] ?>;"></i>
                        <?= htmlspecialchars($sc['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Active Service Requests & Assigned Field Technicians -->
    <div class="grid-2-1">
        <!-- Left: Active Bookings & Assigned Technicians -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="calendar-check"></i> My Service Bookings & Assigned Field Crew</span>
                <a href="book_service.php" class="btn btn-primary btn-sm">+ Book Service</a>
            </div>

            <?php if (empty($memberAllRequests)): ?>
                <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                    <i data-lucide="clipboard-list" style="width: 44px; height: 44px; margin: 0 auto 12px; display: block; opacity: 0.5;"></i>
                    <h3 style="font-size: 16px; color: var(--text-main); margin-bottom: 4px;">No service requests logged yet</h3>
                    <p style="font-size: 13px; max-width: 400px; margin: 0 auto 16px;">Need solar inverter servicing, electrical wiring, CCTV setup, or plumbing repairs? Book your first service now.</p>
                    <a href="book_service.php" class="btn btn-primary">Book a Field Specialist</a>
                </div>
            <?php else: ?>
                <div style="padding: 16px 20px; display: flex; flex-direction: column; gap: 18px;">
                    <?php foreach ($memberAllRequests as $req): ?>
                        <div style="background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 18px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <span style="font-family: var(--font-mono); font-weight: 800; color: var(--primary); font-size: 15px;">
                                        <?= htmlspecialchars($req['ticket_no']) ?>
                                    </span>
                                    <span style="margin-left: 8px;"><?= get_trade_badge($req['trade_category']) ?></span>
                                    <span style="margin-left: 4px;"><?= get_priority_badge($req['priority']) ?></span>
                                </div>
                                <?= get_status_badge($req['job_status'] ?? $req['status']) ?>
                            </div>

                            <h3 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;">
                                <?= htmlspecialchars($req['title']) ?>
                            </h3>

                            <p style="font-size: 12.5px; color: var(--text-muted); line-height: 1.4; margin-bottom: 12px;">
                                <?= htmlspecialchars($req['description']) ?>
                            </p>

                            <!-- Assigned Technician Box (If assigned) -->
                            <?php if (!empty($req['tech_name'])): ?>
                                <div style="background: rgba(2, 132, 199, 0.08); border: 1px solid rgba(56, 189, 248, 0.25); border-radius: var(--radius-md); padding: 14px; margin-bottom: 12px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                        <div style="font-size: 11px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px;">
                                            <i data-lucide="shield-check" style="width: 13px; height: 13px; vertical-align: middle;"></i> Assigned Field Technician
                                        </div>
                                        <span class="badge badge-teal" style="font-size: 11px;">
                                            <i data-lucide="activity" style="width: 10px; height: 10px; vertical-align: middle;"></i> <?= ucfirst($req['tech_status'] ?? 'Available') ?>
                                        </span>
                                    </div>

                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div class="user-avatar" style="width: 44px; height: 44px; font-size: 14px; background: #0284c7; font-weight: 800;">
                                                <?= strtoupper(substr($req['tech_name'], 0, 2)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 800; font-size: 14.5px; color: var(--text-main);">
                                                    <?= htmlspecialchars($req['tech_name']) ?>
                                                </div>
                                                <div style="font-size: 11.5px; color: var(--primary);">
                                                    <?= htmlspecialchars($req['tech_skills'] ?: 'Certified Field Specialist') ?>
                                                </div>
                                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                                    <i data-lucide="clock" style="width: 11px; height: 11px; vertical-align: middle;"></i> Scheduled: <?= format_date($req['job_date'] ?? $req['preferred_date']) ?> (<?= htmlspecialchars($req['job_time'] ?? $req['preferred_time_slot']) ?>)
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Contact Buttons -->
                                        <div style="display: flex; gap: 8px;">
                                            <a href="tel:<?= htmlspecialchars($req['tech_phone']) ?>" class="btn btn-primary btn-sm" style="font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                                <i data-lucide="phone-call" style="width: 13px; height: 13px;"></i>
                                                Call <?= htmlspecialchars(explode(' ', $req['tech_name'])[0]) ?>
                                            </a>

                                            <?php
                                            $waCustomerMsg = "Hello {$req['tech_name']}, I am {$req['customer_name']} regarding my service booking ({$req['ticket_no']}: {$req['title']}).";
                                            $waCustomerUrl = get_whatsapp_url($req['tech_phone'], $waCustomerMsg);
                                            ?>
                                            <a href="<?= $waCustomerUrl ?>" target="_blank" class="btn btn-whatsapp btn-sm" style="font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                                                <i data-lucide="message-square" style="width: 13px; height: 13px;"></i>
                                                WhatsApp
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="background: rgba(245, 158, 11, 0.08); border: 1px dashed rgba(245, 158, 11, 0.3); border-radius: var(--radius-md); padding: 10px 14px; font-size: 12.5px; color: #f59e0b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                                    <i data-lucide="clock" style="width: 15px; height: 15px; flex-shrink: 0;"></i>
                                    <span>Dispatch in progress — our team is assigning a technician for your preferred slot: <strong><?= format_date($req['preferred_date']) ?> (<?= htmlspecialchars($req['preferred_time_slot']) ?>)</strong>.</span>
                                </div>
                            <?php endif; ?>

                            <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid var(--border-color); font-size: 12px;">
                                <span style="color: var(--text-muted);">
                                    <i data-lucide="map-pin" style="width: 11px; height: 11px; vertical-align: middle;"></i> <?= htmlspecialchars($req['estate_area']) ?>
                                </span>
                                <a href="customer_portal.php?ticket=<?= urlencode($req['ticket_no']) ?>" target="_blank" style="color: var(--primary); font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                    <span>Live Progress & Resolution</span>
                                    <i data-lucide="arrow-right" style="width: 13px; height: 13px;"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right: Registered Equipment & Site Info -->
        <div style="display: flex; flex-direction: column; gap: 24px;">
            <!-- Registered Equipment Assets -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i data-lucide="cpu"></i> My Registered Equipment</span>
                    <a href="book_service.php" class="btn btn-secondary btn-sm">+ Add</a>
                </div>
                <div style="padding: 14px 18px;">
                    <?php if (empty($memberAssets)): ?>
                        <div style="text-align: center; padding: 18px; color: var(--text-muted); font-size: 12.5px;">
                            No equipment registered yet. When booking services, specify your inverter, AC, or solar models to keep maintenance records.
                        </div>
                    <?php else: ?>
                        <?php foreach ($memberAssets as $ast): ?>
                            <div style="padding: 10px 0; border-bottom: 1px solid var(--border-color);">
                                <div style="font-weight: 700; font-size: 13px; color: var(--text-main);"><?= htmlspecialchars($ast['asset_name']) ?></div>
                                <div style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;">
                                    <?= htmlspecialchars($ast['brand'] ?: '') ?> <?= htmlspecialchars($ast['model_number'] ?: '') ?> • 
                                    <span class="badge badge-subtle-info" style="font-size: 10.5px;"><?= htmlspecialchars($ast['category']) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Customer Support Assistance -->
            <div class="card" style="background: rgba(2, 132, 199, 0.04); border: 1px solid rgba(56, 189, 248, 0.2);">
                <div class="card-header">
                    <span class="card-title"><i data-lucide="headset"></i> 24/7 Operations Helpdesk</span>
                </div>
                <div class="card-body" style="font-size: 13px; color: var(--text-muted);">
                    <p style="margin-bottom: 12px; line-height: 1.4;">Need emergency repairs or assistance with your service booking? Our Nairobi operations center is on call.</p>
                    <?php
                    $waSupportUrl = get_whatsapp_url('+254722100200', "Hello FieldPulse Support, I am {$user['name']} requesting assistance with my service booking.");
                    ?>
                    <a href="<?= $waSupportUrl ?>" target="_blank" class="btn btn-whatsapp" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i data-lucide="message-square"></i> WhatsApp Operations Support
                    </a>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ============================================================== -->
    <!-- ADMIN / DISPATCHER OPERATIONS DASHBOARD VIEW -->
    <!-- ============================================================== -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Operations & Dispatch Centre</h1>
            <p class="page-subtitle">Real-time overview of field teams, active job cards, and customer requests across Kenya</p>
        </div>
        <div class="page-actions">
            <a href="<?= BASE_URL ?>/requests.php" class="btn btn-secondary">
                <i data-lucide="inbox"></i> Dispatch Desk
            </a>
            <a href="<?= BASE_URL ?>/job_create.php" class="btn btn-primary">
                <i data-lucide="send"></i> Dispatch Job
            </a>
            <a href="<?= BASE_URL ?>/book_service.php" class="btn btn-secondary" target="_blank">
                <i data-lucide="globe"></i> Booking Portal
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
                    <i data-lucide="inbox" style="width:13px;height:13px;"></i> Customer requests
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
                                <div style="margin-top: 8px; display: flex; gap: 6px;">
                                    <a href="requests.php" class="btn btn-primary btn-sm" style="font-size: 11px; padding: 4px 10px;">
                                        <i data-lucide="zap" style="width: 12px; height: 12px;"></i> Connect Tech
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Supported Trade Categories -->
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
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
