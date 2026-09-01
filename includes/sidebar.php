<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<aside class="app-sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">
            <i data-lucide="zap" style="width: 22px; height: 22px;"></i>
        </div>
        <div class="brand-text">
            <h2>FieldPulse</h2>
            <span>Operations Kenya</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Operations</div>
        
        <a href="<?= BASE_URL ?>/index.php" class="nav-link <?= $currentPage == 'index.php' ? 'active' : '' ?>">
            <i data-lucide="layout-dashboard" class="nav-icon"></i>
            <span>Dashboard</span>
        </a>

        <a href="<?= BASE_URL ?>/requests.php" class="nav-link <?= in_array($currentPage, ['requests.php', 'request_create.php']) ? 'active' : '' ?>">
            <i data-lucide="inbox" class="nav-icon"></i>
            <span>Service Requests</span>
        </a>

        <a href="<?= BASE_URL ?>/jobs.php" class="nav-link <?= in_array($currentPage, ['jobs.php', 'job_view.php', 'job_create.php']) ? 'active' : '' ?>">
            <i data-lucide="clipboard-list" class="nav-icon"></i>
            <span>Job Cards</span>
        </a>

        <a href="<?= BASE_URL ?>/schedule.php" class="nav-link <?= $currentPage == 'schedule.php' ? 'active' : '' ?>">
            <i data-lucide="calendar" class="nav-icon"></i>
            <span>Dispatch & Schedule</span>
        </a>

        <div class="nav-section-title">Directory & Assets</div>

        <a href="<?= BASE_URL ?>/customers.php" class="nav-link <?= in_array($currentPage, ['customers.php', 'customer_view.php', 'customer_create.php']) ? 'active' : '' ?>">
            <i data-lucide="users" class="nav-icon"></i>
            <span>Customers & Sites</span>
        </a>

        <a href="<?= BASE_URL ?>/assets_management.php" class="nav-link <?= $currentPage == 'assets_management.php' ? 'active' : '' ?>">
            <i data-lucide="cpu" class="nav-icon"></i>
            <span>Equipment / Assets</span>
        </a>

        <a href="<?= BASE_URL ?>/technicians.php" class="nav-link <?= $currentPage == 'technicians.php' ? 'active' : '' ?>">
            <i data-lucide="hard-hat" class="nav-icon"></i>
            <span>Field Technicians</span>
        </a>

        <div class="nav-section-title">Resources & Reports</div>

        <a href="<?= BASE_URL ?>/inventory.php" class="nav-link <?= $currentPage == 'inventory.php' ? 'active' : '' ?>">
            <i data-lucide="package" class="nav-icon"></i>
            <span>Parts & Materials</span>
        </a>

        <a href="<?= BASE_URL ?>/reports.php" class="nav-link <?= $currentPage == 'reports.php' ? 'active' : '' ?>">
            <i data-lucide="bar-chart-3" class="nav-icon"></i>
            <span>Service Reports</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/tech_portal.php" class="tech-portal-banner">
            <i data-lucide="smartphone"></i>
            <div>
                <div>Technician Portal</div>
                <div style="font-size: 11px; opacity: 0.8; font-weight: normal;">Mobile Workstation</div>
            </div>
        </a>
    </div>
</aside>
