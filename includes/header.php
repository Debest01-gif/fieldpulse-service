<?php
require_once __DIR__ . '/../config/db.php';

require_auth();

$currentPage = basename($_SERVER['PHP_SELF']);
$user = current_user() ?? ['name' => 'Operations User', 'role' => 'admin'];

// Count unread notifications
$db = get_db();
$unreadNotifCount = $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
$recentNotifs = $db->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Operations' ?> - <?= APP_NAME ?> Kenya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/signature-pad.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body>
<div class="app-container">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <button class="mobile-menu-btn" id="mobileMenuBtn">
                    <i data-lucide="menu"></i>
                </button>
                <div class="search-bar">
                    <i data-lucide="search" class="search-icon"></i>
                    <input type="text" placeholder="Search jobs, customers, technicians..." data-table-search="mainTable">
                </div>
            </div>

            <div class="topbar-right">
                <a href="<?= BASE_URL ?>/request_create.php" class="btn btn-primary btn-sm">
                    <i data-lucide="plus-circle"></i> New Service Request
                </a>

                <!-- Notification Bell -->
                <div id="notifWrapper" style="position: relative;">
                    <button class="action-icon-btn" id="notifBellBtn" onclick="toggleNotif(event)" title="Notifications">
                        <i data-lucide="bell"></i>
                        <?php if ($unreadNotifCount > 0): ?>
                            <span class="badge-dot"></span>
                        <?php endif; ?>
                    </button>
                    <!-- Notification Popup -->
                    <div id="notifDropdown" class="card" style="display: none; position: absolute; right: 0; top: 50px; width: 320px; z-index: 1000; box-shadow: var(--shadow-xl); border: 1px solid var(--border-color);">
                        <div class="card-header" style="padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 700; font-size: 13px;">Notifications</span>
                            <span class="badge badge-info"><?= $unreadNotifCount ?> new</span>
                        </div>
                        <div style="max-height: 280px; overflow-y: auto; padding: 6px 0;">
                            <?php if (empty($recentNotifs)): ?>
                                <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 12px;">
                                    <i data-lucide="bell-off" style="width:28px;height:28px;margin-bottom:8px;display:block;margin-left:auto;margin-right:auto;"></i>
                                    No notifications yet
                                </div>
                            <?php else: ?>
                                <?php foreach ($recentNotifs as $n): ?>
                                    <div style="padding: 10px 16px; border-bottom: 1px solid var(--border-color); font-size: 12px; cursor:pointer;" onmouseover="this.style.background='var(--bg-subtle)'" onmouseout="this.style.background=''">
                                        <div style="font-weight: 600; color: var(--text-main);"><?= htmlspecialchars($n['title']) ?></div>
                                        <div style="color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($n['message']) ?></div>
                                        <div style="color: var(--text-light); font-size: 10.5px; margin-top: 4px;"><?= time_ago($n['created_at']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div style="padding: 10px 16px; border-top: 1px solid var(--border-color); text-align: center;">
                            <a href="<?= BASE_URL ?>/index.php" style="font-size: 12px; color: var(--primary); text-decoration: none; font-weight: 600;">View Dashboard</a>
                        </div>
                    </div>
                </div>

                <div class="user-profile">
                    <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 2)) ?></div>
                    <div class="user-info">
                        <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
                        <span class="user-role"><?= htmlspecialchars(ucfirst($user['role'])) ?></span>
                    </div>
                </div>

                <a href="<?= BASE_URL ?>/logout.php" class="btn btn-secondary btn-sm" title="Sign Out" style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px;">
                    <i data-lucide="log-out" style="width: 14px; height: 14px; color: #f43f5e;"></i>
                    <span style="font-size: 12px;">Sign Out</span>
                </a>
            </div>
        </header>

        <div class="content-wrapper">
            <?php $flash = get_flash(); if ($flash): ?>
                <div class="flash-alert flash-<?= $flash['type'] ?>">
                    <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle-2' : 'alert-circle' ?>"></i>
                    <span><?= htmlspecialchars($flash['message']) ?></span>
                </div>
            <?php endif; ?>
