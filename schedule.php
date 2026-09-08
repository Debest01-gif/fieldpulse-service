<?php
$pageTitle = 'Dispatch & Scheduling Board';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

// Selected date
$selectedDate = clean($_GET['date'] ?? date('Y-m-d'));
$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

// Fetch all technicians
$techs = $db->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM job_cards j WHERE j.technician_id = u.id AND j.scheduled_date = '{$selectedDate}') as today_job_count
    FROM users u
    WHERE u.role = 'technician'
    ORDER BY u.name ASC
")->fetchAll();

// Fetch jobs scheduled for this date
$stmtJobs = $db->prepare("
    SELECT j.*, c.name as customer_name, c.estate_area, c.landmark,
           u.name as tech_name
    FROM job_cards j
    JOIN customers c ON j.customer_id = c.id
    JOIN users u ON j.technician_id = u.id
    WHERE j.scheduled_date = ?
    ORDER BY j.scheduled_time ASC
");
$stmtJobs->execute([$selectedDate]);
$scheduledJobs = $stmtJobs->fetchAll();

// Group jobs by technician id
$jobsByTech = [];
foreach ($scheduledJobs as $job) {
    $jobsByTech[$job['technician_id']][] = $job;
}

// Fetch unassigned service requests waiting for dispatch
$unassignedRequests = $db->query("
    SELECT r.*, c.name as customer_name, c.estate_area
    FROM service_requests r
    JOIN customers c ON r.customer_id = c.id
    WHERE r.status = 'new'
    ORDER BY r.created_at DESC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Dispatch & Scheduling Board</h1>
        <p class="page-subtitle">Technician daily dispatch timeline and route coordination</p>
    </div>
    <div class="page-actions">
        <a href="job_create.php" class="btn btn-primary">
            <i data-lucide="plus"></i> Dispatch Job
        </a>
    </div>
</div>

<!-- Date Navigation & Controls -->
<div class="card" style="margin-bottom: 24px; padding: 16px 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="schedule.php?date=<?= $prevDate ?>" class="btn btn-secondary btn-sm"><i data-lucide="chevron-left"></i> Prev Day</a>
            <a href="schedule.php?date=<?= date('Y-m-d') ?>" class="btn btn-secondary btn-sm <?= $selectedDate === date('Y-m-d') ? 'active' : '' ?>">Today</a>
            <a href="schedule.php?date=<?= $nextDate ?>" class="btn btn-secondary btn-sm">Next Day <i data-lucide="chevron-right"></i></a>
        </div>

        <form method="GET" action="schedule.php" style="display: flex; align-items: center; gap: 10px;">
            <span style="font-weight: 600; font-size: 13px; color: var(--text-muted);">Select Date:</span>
            <input type="date" name="date" class="form-control" value="<?= $selectedDate ?>" onchange="this.form.submit()" style="width: 170px; padding: 6px 12px;">
        </form>

        <div style="font-size: 14px; font-weight: 700; color: var(--text-main);">
            <i data-lucide="calendar" style="width: 16px; height: 16px; vertical-align: middle; color: var(--primary);"></i>
            <?= date('l, d F Y', strtotime($selectedDate)) ?>
        </div>
    </div>
</div>

<!-- Scheduling Board Layout -->
<div class="grid-2-1">
    <!-- Left: Technician Columns / Schedule Grid -->
    <div style="display: flex; flex-direction: column; gap: 18px;">
        <?php if (empty($techs)): ?>
            <div class="card" style="padding: 24px; text-align: center; color: var(--text-muted);">No field technicians found.</div>
        <?php else: ?>
            <?php foreach ($techs as $tech): ?>
                <?php $techJobs = $jobsByTech[$tech['id']] ?? []; ?>
                <div class="card" style="border-left: 4px solid #0284c7;">
                    <div class="card-header" style="background: var(--bg-subtle); padding: 12px 20px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="user-avatar" style="width: 32px; height: 32px; font-size: 12px;">
                                <?= strtoupper(substr($tech['name'], 0, 2)) ?>
                            </div>
                            <div>
                                <span style="font-weight: 700; font-size: 14px;"><?= htmlspecialchars($tech['name']) ?></span>
                                <span style="font-size: 11.5px; color: var(--text-muted); margin-left: 6px;">(<?= htmlspecialchars($tech['trade_skills'] ?: 'Field Specialist') ?>)</span>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span class="badge badge-info"><?= count($techJobs) ?> Job<?= count($techJobs) == 1 ? '' : 's' ?></span>
                            <?= get_status_badge($tech['status']) ?>
                        </div>
                    </div>

                    <div style="padding: 16px 20px;">
                        <?php if (empty($techJobs)): ?>
                            <div style="padding: 12px; border: 1px dashed var(--border-color); border-radius: var(--radius-md); text-align: center; color: var(--text-muted); font-size: 12.5px;">
                                No jobs scheduled for <?= htmlspecialchars($tech['name']) ?> on this date.
                                <a href="job_create.php?scheduled_date=<?= $selectedDate ?>&technician_id=<?= $tech['id'] ?>" style="font-weight: 600; margin-left: 6px;">+ Assign Job</a>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <?php foreach ($techJobs as $job): ?>
                                    <div style="background: var(--bg-surface); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s ease;">
                                        <div style="display: flex; align-items: center; gap: 14px;">
                                            <div style="background: var(--bg-subtle); padding: 6px 10px; border-radius: var(--radius-sm); font-weight: 700; font-size: 12px; color: var(--primary);">
                                                <?= htmlspecialchars($job['scheduled_time']) ?>
                                            </div>
                                            <div>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <a href="job_view.php?id=<?= $job['id'] ?>" style="font-weight: 700; color: var(--text-main); font-size: 13.5px;">
                                                        <?= htmlspecialchars($job['job_number']) ?> - <?= htmlspecialchars($job['title']) ?>
                                                    </a>
                                                    <?= get_trade_badge($job['trade_category']) ?>
                                                </div>
                                                <div style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">
                                                    <i data-lucide="user" style="width: 11px; height: 11px; vertical-align: middle;"></i> <?= htmlspecialchars($job['customer_name']) ?>
                                                    <span style="margin: 0 4px;">•</span>
                                                    <i data-lucide="map-pin" style="width: 11px; height: 11px; vertical-align: middle;"></i> <?= htmlspecialchars($job['estate_area']) ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <?= get_status_badge($job['status']) ?>
                                            <a href="job_view.php?id=<?= $job['id'] ?>" class="btn btn-secondary btn-sm">
                                                <i data-lucide="arrow-right" style="width: 13px; height: 13px;"></i>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Right: Unassigned Inquiries Tray -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i data-lucide="inbox"></i> Requests Needing Dispatch</span>
            <span class="badge badge-warning"><?= count($unassignedRequests) ?></span>
        </div>
        <div style="padding: 14px 18px; display: flex; flex-direction: column; gap: 12px;">
            <?php if (empty($unassignedRequests)): ?>
                <div style="text-align: center; padding: 20px; color: var(--text-muted); font-size: 12.5px;">
                    <i data-lucide="check-circle" style="width: 32px; height: 32px; color: var(--success); margin: 0 auto 6px; display: block;"></i>
                    All incoming requests are currently dispatched!
                </div>
            <?php else: ?>
                <?php foreach ($unassignedRequests as $req): ?>
                    <div style="background: var(--bg-subtle); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                            <span style="font-family: var(--font-mono); font-weight: 700; font-size: 12px;"><?= htmlspecialchars($req['ticket_no']) ?></span>
                            <?= get_priority_badge($req['priority']) ?>
                        </div>
                        <div style="font-weight: 600; font-size: 13px;"><?= htmlspecialchars($req['title']) ?></div>
                        <div style="font-size: 11.5px; color: var(--text-muted); margin: 3px 0;">
                            <?= htmlspecialchars($req['customer_name']) ?> (<?= htmlspecialchars($req['estate_area']) ?>)
                        </div>
                        <div style="margin-top: 8px;">
                            <a href="job_create.php?request_id=<?= $req['id'] ?>&scheduled_date=<?= $selectedDate ?>" class="btn btn-primary btn-sm" style="width: 100%; font-size: 12px; padding: 5px;">
                                <i data-lucide="send" style="width: 12px; height: 12px;"></i> Assign on <?= date('d M', strtotime($selectedDate)) ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
