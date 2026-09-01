<?php
$pageTitle = 'Operations & Service Reports';
require_once __DIR__ . '/config/db.php';
$db = get_db();

// Period filter
$period = clean($_GET['period'] ?? 'all');
$whereDate = "";
if ($period === 'today') {
    $whereDate = " AND j.scheduled_date = CURDATE()";
} elseif ($period === 'month') {
    $whereDate = " AND j.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
}

// KPI Metrics
$totalJobs = $db->query("SELECT COUNT(*) FROM job_cards j WHERE 1=1 {$whereDate}")->fetchColumn();
$signedJobs = $db->query("SELECT COUNT(*) FROM job_cards j WHERE j.status = 'signed_off' {$whereDate}")->fetchColumn();
$signRate = $totalJobs > 0 ? round(($signedJobs / $totalJobs) * 100) : 0;

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
    SELECT u.name, u.trade_skills,
           COUNT(j.id) as total_assigned,
           SUM(CASE WHEN j.status = 'signed_off' THEN 1 ELSE 0 END) as signed_count,
           SUM(CASE WHEN j.status IN ('scheduled', 'en_route', 'in_progress') THEN 1 ELSE 0 END) as in_progress_count
    FROM users u
    LEFT JOIN job_cards j ON j.technician_id = u.id {$whereDate}
    WHERE u.role = 'technician'
    GROUP BY u.id
    ORDER BY signed_count DESC, total_assigned DESC
")->fetchAll();

// Top Consumed Parts
$topParts = $db->query("
    SELECT p.part_code, p.part_name, p.category, p.unit,
           SUM(u.quantity) as total_qty,
           COUNT(DISTINCT u.job_id) as job_count
    FROM job_parts_used u
    JOIN inventory_parts p ON u.part_id = p.id
    GROUP BY p.id
    ORDER BY total_qty DESC
    LIMIT 6
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Operations & Field Service Reports</h1>
        <p class="page-subtitle">Turnaround analytics, technician throughput, and trade category insights</p>
    </div>
    <div class="page-actions">
        <button onclick="window.print()" class="btn btn-secondary">
            <i data-lucide="printer"></i> Print Report
        </button>
    </div>
</div>

<!-- Period Filter -->
<div style="display: flex; gap: 8px; margin-bottom: 20px;">
    <a href="reports.php" class="btn btn-sm <?= $period === 'all' ? 'btn-primary' : 'btn-secondary' ?>">All Time</a>
    <a href="reports.php?period=month" class="btn btn-sm <?= $period === 'month' ? 'btn-primary' : 'btn-secondary' ?>">Last 30 Days</a>
    <a href="reports.php?period=today" class="btn btn-sm <?= $period === 'today' ? 'btn-primary' : 'btn-secondary' ?>">Today Only</a>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Total Work Orders</span>
            <span class="stat-value"><?= $totalJobs ?></span>
            <span class="stat-subtext"><i data-lucide="clipboard" style="width: 13px; height: 13px;"></i> Dispatched</span>
        </div>
        <div class="stat-icon blue"><i data-lucide="clipboard-list"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Customer Signed Off</span>
            <span class="stat-value"><?= $signedJobs ?></span>
            <span class="stat-subtext positive"><i data-lucide="shield-check" style="width: 13px; height: 13px;"></i> <?= $signRate ?>% Sign-off Rate</span>
        </div>
        <div class="stat-icon green"><i data-lucide="check-circle-2"></i></div>
    </div>

    <div class="stat-card">
        <div class="stat-info">
            <span class="stat-label">Active Trades Covered</span>
            <span class="stat-value"><?= count($tradesBreakdown) ?></span>
            <span class="stat-subtext"><i data-lucide="tool" style="width: 13px; height: 13px;"></i> Specializations</span>
        </div>
        <div class="stat-icon purple"><i data-lucide="layers"></i></div>
    </div>
</div>

<div class="grid-2">
    <!-- Trade Breakdown Table -->
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
                    <?php foreach ($tradesBreakdown as $tb): ?>
                        <?php $compPct = $tb['total_jobs'] > 0 ? round(($tb['completed_jobs'] / $tb['total_jobs']) * 100) : 0; ?>
                        <tr>
                            <td><?= get_trade_badge($tb['trade_category']) ?></td>
                            <td><strong><?= $tb['total_jobs'] ?></strong></td>
                            <td><span class="badge badge-success"><?= $tb['completed_jobs'] ?></span></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="flex: 1; height: 6px; background: var(--bg-subtle); border-radius: 3px; overflow: hidden;">
                                        <div style="width: <?= $compPct ?>%; height: 100%; background: var(--primary);"></div>
                                    </div>
                                    <span style="font-size: 11px; font-weight: 700;"><?= $compPct ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Consumed Parts -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i data-lucide="package"></i> Most Consumed Materials on Jobs</span>
        </div>
        <div class="table-responsive">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th>Category</th>
                        <th>Total Consumed</th>
                        <th>Jobs Used On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topParts)): ?>
                        <tr><td colspan="4" style="text-align: center; padding: 20px; color: var(--text-muted);">No parts recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($topParts as $tp): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($tp['part_name']) ?></div>
                                    <span style="font-family: var(--font-mono); font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($tp['part_code']) ?></span>
                                </td>
                                <td><?= get_trade_badge($tp['category']) ?></td>
                                <td><span class="badge badge-info"><?= $tp['total_qty'] ?> <?= htmlspecialchars($tp['unit']) ?></span></td>
                                <td><span class="badge badge-secondary"><?= $tp['job_count'] ?> Jobs</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Technician Performance Table -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="award"></i> Technician Field Throughput & Performance</span>
    </div>
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Technician</th>
                    <th>Trade Skills</th>
                    <th>Total Assigned Jobs</th>
                    <th>Signed Off / Closed</th>
                    <th>In Progress</th>
                    <th>Resolution Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($techRankings as $tr): ?>
                    <?php $techRate = $tr['total_assigned'] > 0 ? round(($tr['signed_count'] / $tr['total_assigned']) * 100) : 0; ?>
                    <tr>
                        <td>
                            <div style="font-weight: 700; font-size: 13.5px;"><?= htmlspecialchars($tr['name']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($tr['trade_skills'] ?: 'General') ?></td>
                        <td><strong><?= $tr['total_assigned'] ?></strong></td>
                        <td><span class="badge badge-success"><?= $tr['signed_count'] ?></span></td>
                        <td><span class="badge badge-amber"><?= $tr['in_progress_count'] ?></span></td>
                        <td>
                            <span class="badge <?= $techRate >= 70 ? 'badge-success' : 'badge-secondary' ?>">
                                <?= $techRate ?>% Closed
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
