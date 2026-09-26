<?php
$pageTitle = 'Operations & Service Reports';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

// Period filter
$period = clean($_GET['period'] ?? 'all');
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

// Date filter logic — works for both MySQL and SQLite
if ($period === 'today') {
    $whereDate = $driver === 'sqlite'
        ? " AND date(j.scheduled_date) = date('now')"
        : " AND j.scheduled_date = CURDATE()";
} elseif ($period === 'month') {
    $whereDate = $driver === 'sqlite'
        ? " AND j.scheduled_date >= date('now', '-30 days')"
        : " AND j.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
} elseif ($period === 'year') {
    $whereDate = $driver === 'sqlite'
        ? " AND strftime('%Y', j.scheduled_date) = strftime('%Y', 'now')"
        : " AND YEAR(j.scheduled_date) = YEAR(CURDATE())";
} else {
    $whereDate = "";
}

// KPI Metrics
$totalJobs       = $db->query("SELECT COUNT(*) FROM job_cards j WHERE 1=1 {$whereDate}")->fetchColumn();
$signedJobs      = $db->query("SELECT COUNT(*) FROM job_cards j WHERE j.status = 'signed_off' {$whereDate}")->fetchColumn();
$inProgressJobs  = $db->query("SELECT COUNT(*) FROM job_cards j WHERE j.status IN ('en_route','in_progress') {$whereDate}")->fetchColumn();
$signRate        = $totalJobs > 0 ? round(($signedJobs / $totalJobs) * 100) : 0;

$totalRequests   = $db->query("SELECT COUNT(*) FROM service_requests WHERE 1=1")->fetchColumn();
$newRequests     = $db->query("SELECT COUNT(*) FROM service_requests WHERE status = 'new'")->fetchColumn();
$totalCustomers  = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$totalTechs      = $db->query("SELECT COUNT(*) FROM users WHERE role = 'technician'")->fetchColumn();

// Trade Distribution
$tradesBreakdown = $db->query("
    SELECT trade_category, COUNT(*) as total_jobs,
           SUM(CASE WHEN status = 'signed_off' THEN 1 ELSE 0 END) as completed_jobs
    FROM job_cards j
    WHERE 1=1 {$whereDate}
    GROUP BY trade_category
    ORDER BY total_jobs DESC
")->fetchAll();

// Technician Rankings
$techRankings = $db->query("
    SELECT u.id, u.name, u.trade_skills, u.status as tech_status,
           COUNT(j.id) as total_assigned,
           SUM(CASE WHEN j.status = 'signed_off' THEN 1 ELSE 0 END) as signed_count,
           SUM(CASE WHEN j.status IN ('scheduled', 'en_route', 'in_progress') THEN 1 ELSE 0 END) as in_progress_count
    FROM users u
    LEFT JOIN job_cards j ON j.technician_id = u.id
    WHERE u.role = 'technician'
    GROUP BY u.id
    ORDER BY signed_count DESC, total_assigned DESC
")->fetchAll();

// Top Consumed Parts
$topParts = $db->query("
    SELECT p.part_code, p.part_name, p.category, p.unit, p.in_stock,
           SUM(u.quantity) as total_qty,
           COUNT(DISTINCT u.job_id) as job_count
    FROM job_parts_used u
    JOIN inventory_parts p ON u.part_id = p.id
    GROUP BY p.id
    ORDER BY total_qty DESC
    LIMIT 8
")->fetchAll();

// Recent Completed Jobs (for printed job log)
$recentJobs = $db->query("
    SELECT j.*, c.name as customer_name, u.name as tech_name, u.phone as tech_phone
    FROM job_cards j
    JOIN customers c ON j.customer_id = c.id
    JOIN users u ON j.technician_id = u.id
    WHERE j.status IN ('signed_off','completed')
    ORDER BY j.updated_at DESC
    LIMIT 20
")->fetchAll();

// Request summary by status
$requestsByStatus = $db->query("
    SELECT status, COUNT(*) as cnt FROM service_requests GROUP BY status ORDER BY cnt DESC
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
@media print {
    .page-header .page-actions,
    .period-filters,
    .no-print { display: none !important; }
    .card { break-inside: avoid; }
    .print-header { display: block !important; }
}
.print-header {
    display: none;
    text-align: center;
    padding: 10px 0 20px;
    border-bottom: 2px solid #ccc;
    margin-bottom: 20px;
}
.print-header h1 { font-size: 20px; font-weight: 800; }
.print-header p { font-size: 12px; color: #666; }
</style>

<!-- Print header (hidden on screen) -->
<div class="print-header">
    <h1>⚡ FieldPulse Kenya — Operations Report</h1>
    <p>Generated on <?= date('d F Y, h:i A') ?> &nbsp;|&nbsp; Period: <?= strtoupper($period) ?></p>
</div>

<div class="page-header no-print">
    <div>
        <h1 class="page-title">
            <i data-lucide="bar-chart-3" style="width:22px;height:22px;color:#38bdf8;vertical-align:middle;"></i>
            Operations &amp; Service Reports
        </h1>
        <p class="page-subtitle">Turnaround analytics, technician throughput, customer stats &amp; trade insights</p>
    </div>
    <div class="page-actions">
        <button onclick="window.print()" class="btn btn-secondary">
            <i data-lucide="printer"></i> Print / Save PDF
        </button>
        <a href="reports.php?period=<?= $period ?>" class="btn btn-secondary">
            <i data-lucide="refresh-cw"></i> Refresh
        </a>
    </div>
</div>

<!-- Period Filter -->
<div class="period-filters no-print" style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;">
    <?php foreach(['all'=>'All Time','today'=>'Today','month'=>'Last 30 Days','year'=>'This Year'] as $k=>$label): ?>
        <a href="reports.php?period=<?= $k ?>" class="btn btn-sm <?= $period === $k ? 'btn-primary' : 'btn-secondary' ?>"><?= $label ?></a>
    <?php endforeach; ?>
    <span style="font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:5px;margin-left:6px;">
        <i data-lucide="clock" style="width:13px;height:13px;"></i>
        Showing: <?= $period === 'all' ? 'All Time Data' : ucfirst($period) ?> &nbsp;|&nbsp; Generated: <?= date('d M Y, g:ia') ?>
    </span>
</div>

<!-- KPI Stats -->
<div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Total Work Orders</span>
            <span class="stat-value"><?= $totalJobs ?></span>
            <span class="stat-subtext"><i data-lucide="clipboard" style="width:13px;height:13px;"></i> Dispatched</span>
        </div>
        <div class="stat-icon blue"><i data-lucide="clipboard-list"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Signed Off / Closed</span>
            <span class="stat-value"><?= $signedJobs ?></span>
            <span class="stat-subtext positive"><i data-lucide="shield-check" style="width:13px;height:13px;"></i> <?= $signRate ?>% Sign-off Rate</span>
        </div>
        <div class="stat-icon green"><i data-lucide="check-circle-2"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Active / In Progress</span>
            <span class="stat-value"><?= $inProgressJobs ?></span>
            <span class="stat-subtext"><i data-lucide="activity" style="width:13px;height:13px;"></i> Currently Running</span>
        </div>
        <div class="stat-icon" style="color:#f59e0b;background:rgba(245,158,11,0.12);"><i data-lucide="wrench"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Service Requests</span>
            <span class="stat-value"><?= $totalRequests ?></span>
            <span class="stat-subtext" style="color:#f43f5e;"><?= $newRequests ?> Pending Action</span>
        </div>
        <div class="stat-icon" style="color:#f43f5e;background:rgba(244,63,94,0.12);"><i data-lucide="inbox"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Total Customers</span>
            <span class="stat-value"><?= $totalCustomers ?></span>
            <span class="stat-subtext"><i data-lucide="users" style="width:13px;height:13px;"></i> On Record</span>
        </div>
        <div class="stat-icon purple"><i data-lucide="building-2"></i></div>
    </div>
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Field Technicians</span>
            <span class="stat-value"><?= $totalTechs ?></span>
            <span class="stat-subtext"><i data-lucide="hard-hat" style="width:13px;height:13px;"></i> Deployed Crew</span>
        </div>
        <div class="stat-icon" style="color:#a78bfa;background:rgba(167,139,250,0.12);"><i data-lucide="hard-hat"></i></div>
    </div>
</div>

<div class="grid-2">
    <!-- Trade Breakdown -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i data-lucide="pie-chart"></i> Work Orders by Trade Category</span>
        </div>
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Trade</th>
                        <th>Total Jobs</th>
                        <th>Completed</th>
                        <th>Completion %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tradesBreakdown)): ?>
                        <tr><td colspan="4" style="text-align:center;padding:24px;color:var(--text-muted);">No jobs recorded yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($tradesBreakdown as $tb):
                        $compPct = $tb['total_jobs'] > 0 ? round(($tb['completed_jobs'] / $tb['total_jobs']) * 100) : 0;
                        $barColor = $compPct >= 80 ? '#34d399' : ($compPct >= 50 ? '#f59e0b' : '#f43f5e');
                    ?>
                        <tr>
                            <td><?= get_trade_badge($tb['trade_category']) ?></td>
                            <td><strong><?= $tb['total_jobs'] ?></strong></td>
                            <td><span class="badge badge-success"><?= $tb['completed_jobs'] ?></span></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="flex:1;height:6px;background:var(--bg-subtle);border-radius:3px;overflow:hidden;">
                                        <div style="width:<?= $compPct ?>%;height:100%;background:<?= $barColor ?>;border-radius:3px;"></div>
                                    </div>
                                    <span style="font-size:11px;font-weight:700;"><?= $compPct ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Service Request Status -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i data-lucide="inbox"></i> Service Request Status Summary</span>
        </div>
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr><th>Status</th><th>Count</th><th>Share</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($requestsByStatus)): ?>
                        <tr><td colspan="3" style="text-align:center;padding:20px;color:var(--text-muted);">No requests yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($requestsByStatus as $rs): ?>
                        <tr>
                            <td><?= get_status_badge($rs['status']) ?></td>
                            <td><strong><?= $rs['cnt'] ?></strong></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <div style="flex:1;height:5px;background:var(--bg-subtle);border-radius:3px;overflow:hidden;">
                                        <div style="width:<?= $totalRequests > 0 ? round($rs['cnt']/$totalRequests*100) : 0 ?>%;height:100%;background:var(--primary);"></div>
                                    </div>
                                    <span style="font-size:11px;"><?= $totalRequests > 0 ? round($rs['cnt']/$totalRequests*100) : 0 ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Most Consumed Parts -->
<div class="card" style="margin-top:24px;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="package"></i> Most Consumed Materials on Jobs</span>
        <a href="inventory.php" class="btn btn-sm btn-secondary no-print">View Inventory</a>
    </div>
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Material</th>
                    <th>Category</th>
                    <th>Total Used</th>
                    <th>Jobs Used On</th>
                    <th>In Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($topParts)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-muted);">No parts usage recorded yet.</td></tr>
                <?php else: ?>
                <?php foreach ($topParts as $tp): ?>
                    <tr>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($tp['part_name']) ?></div>
                            <span style="font-family:var(--font-mono);font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($tp['part_code']) ?></span>
                        </td>
                        <td><?= get_trade_badge($tp['category']) ?></td>
                        <td><span class="badge badge-info"><?= $tp['total_qty'] ?> <?= htmlspecialchars($tp['unit']) ?></span></td>
                        <td><span class="badge badge-secondary"><?= $tp['job_count'] ?> Jobs</span></td>
                        <td>
                            <span class="badge <?= $tp['in_stock'] <= 5 ? 'badge-danger' : 'badge-success' ?>">
                                <?= $tp['in_stock'] ?> left
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Technician Performance -->
<div class="card" style="margin-top:24px;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="award"></i> Technician Field Throughput &amp; Performance</span>
    </div>
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Technician</th>
                    <th>Specializations</th>
                    <th>Total Assigned</th>
                    <th>Signed Off</th>
                    <th>In Progress</th>
                    <th>Resolution Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($techRankings as $tr):
                    $techRate = $tr['total_assigned'] > 0 ? round(($tr['signed_count'] / $tr['total_assigned']) * 100) : 0;
                    $rateColor = $techRate >= 80 ? 'badge-success' : ($techRate >= 50 ? 'badge-warning' : 'badge-secondary');
                ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#0284c7,#7c3aed);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:12px;flex-shrink:0;">
                                    <?= strtoupper(substr($tr['name'], 0, 2)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:700;font-size:13px;"><?= htmlspecialchars($tr['name']) ?></div>
                                    <?= get_status_badge($tr['tech_status']) ?>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($tr['trade_skills'] ?: 'General') ?></td>
                        <td><strong><?= $tr['total_assigned'] ?></strong></td>
                        <td><span class="badge badge-success"><?= $tr['signed_count'] ?></span></td>
                        <td><span class="badge badge-amber"><?= $tr['in_progress_count'] ?></span></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="width:80px;height:6px;background:var(--bg-subtle);border-radius:3px;overflow:hidden;">
                                    <div style="width:<?= $techRate ?>%;height:100%;background:<?= $techRate >= 80 ? '#34d399' : ($techRate >= 50 ? '#f59e0b' : '#94a3b8') ?>;"></div>
                                </div>
                                <span class="badge <?= $rateColor ?>"><?= $techRate ?>%</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Completed Jobs - Printable Log -->
<?php if (!empty($recentJobs)): ?>
<div class="card" style="margin-top:24px;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="file-check-2"></i> Recent Completed Job Cards Log</span>
        <button onclick="window.print()" class="btn btn-sm btn-secondary no-print">
            <i data-lucide="printer"></i> Print This Report
        </button>
    </div>
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Job #</th>
                    <th>Customer</th>
                    <th>Technician</th>
                    <th>Trade</th>
                    <th>Date</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentJobs as $j): ?>
                <tr>
                    <td>
                        <a href="job_view.php?id=<?= $j['id'] ?>" style="font-family:var(--font-mono);font-size:12px;color:var(--primary);font-weight:600;text-decoration:none;">
                            <?= htmlspecialchars($j['job_number']) ?>
                        </a>
                    </td>
                    <td style="font-size:13px;"><?= htmlspecialchars($j['customer_name']) ?></td>
                    <td>
                        <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars($j['tech_name']) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($j['tech_phone']) ?></div>
                    </td>
                    <td><?= get_trade_badge($j['trade_category']) ?></td>
                    <td style="font-size:12px;"><?= format_date($j['scheduled_date']) ?></td>
                    <td><?= get_status_badge($j['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
