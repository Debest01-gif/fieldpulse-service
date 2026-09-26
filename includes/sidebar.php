<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$user = current_user() ?? ['name' => 'Operations User', 'role' => 'admin'];
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
        <?php if (($user['role'] ?? '') === 'member'): ?>
            <!-- Member Navigation -->
            <div class="nav-section-title">Member Services</div>
            
            <a href="<?= BASE_URL ?>/index.php" class="nav-link <?= $currentPage == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard" class="nav-icon"></i>
                <span>My Dashboard</span>
            </a>

            <a href="<?= BASE_URL ?>/book_service.php" class="nav-link <?= $currentPage == 'book_service.php' ? 'active' : '' ?>">
                <i data-lucide="calendar-plus" class="nav-icon" style="color: #38bdf8;"></i>
                <span style="font-weight: 700; color: #38bdf8;">Book Field Service</span>
            </a>

            <a href="<?= BASE_URL ?>/customer_portal.php" class="nav-link <?= $currentPage == 'customer_portal.php' ? 'active' : '' ?>">
                <i data-lucide="activity" class="nav-icon"></i>
                <span>Track Technicians</span>
            </a>

            <div class="nav-section-title">Directory</div>
            <a href="<?= BASE_URL ?>/technicians.php" class="nav-link <?= $currentPage == 'technicians.php' ? 'active' : '' ?>">
                <i data-lucide="hard-hat" class="nav-icon"></i>
                <span>Field Specialists</span>
            </a>
        <?php else: ?>
            <!-- Admin / Dispatcher Navigation -->
            <div class="nav-section-title">Operations</div>
            
            <a href="<?= BASE_URL ?>/index.php" class="nav-link <?= $currentPage == 'index.php' ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard" class="nav-icon"></i>
                <span>Dashboard</span>
            </a>

            <a href="<?= BASE_URL ?>/requests.php" class="nav-link <?= in_array($currentPage, ['requests.php', 'request_create.php']) ? 'active' : '' ?>">
                <i data-lucide="inbox" class="nav-icon"></i>
                <span>Service Requests</span>
            </a>

            <a href="<?= BASE_URL ?>/book_service.php" class="nav-link <?= $currentPage == 'book_service.php' ? 'active' : '' ?>">
                <i data-lucide="calendar-plus" class="nav-icon"></i>
                <span>Book Service Portal</span>
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

            <?php if (($user['role'] ?? '') === 'admin'): ?>
            <div class="nav-section-title">Administration</div>
            <?php
            // Count pending accounts for badge
            try {
                $pendingAccts = $db->query("SELECT COUNT(*) FROM users WHERE account_status = 'pending'")->fetchColumn();
            } catch(Exception $e) { $pendingAccts = 0; }
            ?>
            <a href="<?= BASE_URL ?>/admin_accounts.php" class="nav-link <?= in_array($currentPage, ['admin_accounts.php']) ? 'active' : '' ?>">
                <i data-lucide="users-2" class="nav-icon"></i>
                <span>Account Management</span>
                <?php if ($pendingAccts > 0): ?>
                    <span class="badge badge-danger" style="margin-left:auto;font-size:10px;padding:2px 6px;"><?= $pendingAccts ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= BASE_URL ?>/admin_settings.php" class="nav-link <?= in_array($currentPage, ['admin_settings.php']) ? 'active' : '' ?>">
                <i data-lucide="settings" class="nav-icon"></i>
                <span>System Settings</span>
            </a>
            <a href="<?= BASE_URL ?>/register.php" class="nav-link <?= $currentPage == 'register.php' ? 'active' : '' ?>">
                <i data-lucide="user-plus" class="nav-icon"></i>
                <span>Add Staff Account</span>
            </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= BASE_URL ?>/tech_portal.php" class="tech-portal-banner">
            <i data-lucide="smartphone"></i>
            <div>
                <div>Technician Portal</div>
                <div style="font-size: 11px; opacity: 0.8; font-weight: normal;">Mobile Workstation</div>
            </div>
        </a>
        <a href="<?= BASE_URL ?>/customer_portal.php" target="_blank" style="display:flex;align-items:center;gap:10px;padding:10px 14px;margin-top:6px;border-radius:10px;background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.18);color:#6ee7b7;font-size:12.5px;font-weight:600;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='rgba(16,185,129,0.15)'" onmouseout="this.style.background='rgba(16,185,129,0.08)'">
            <i data-lucide="globe" style="width:15px;height:15px;"></i>
            <div>Customer Status Portal</div>
        </a>
    </div>
</aside>
