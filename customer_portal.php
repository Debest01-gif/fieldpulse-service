<?php
/**
 * FieldPulse Kenya - Customer & Member Service Portal
 * Live Service Booking Tracker, Assigned Technician Card with Direct Call/WhatsApp, and Real-Time Work Progress
 */
require_once __DIR__ . '/config/db.php';
$db = get_db();

$user = current_user();
$searchQuery = trim($_GET['search'] ?? ($_GET['ticket'] ?? ($_GET['job'] ?? ($_GET['phone'] ?? ''))));

// If user is logged in as a member, automatically find their customer records and requests
$memberRequests = [];
$memberCustomer = null;

if ($user && $user['role'] === 'member') {
    // Find customer by email or phone or user_id
    $stmtC = $db->prepare("SELECT * FROM customers WHERE email = ? OR phone = ? OR user_id = ? LIMIT 1");
    $stmtC->execute([$user['email'], $user['phone'], $user['id']]);
    $memberCustomer = $stmtC->fetch();

    if ($memberCustomer) {
        $stmtMR = $db->prepare("
            SELECT r.*, c.name as customer_name, c.phone as customer_phone, c.estate_area, c.address as customer_address, c.landmark,
                   j.id as job_id, j.job_number, j.status as job_status, j.scheduled_date as job_date, j.scheduled_time as job_time,
                   j.estimated_duration, j.diagnosis, j.work_performed, j.technician_notes, j.signature_data, j.signed_by_name, j.signed_at,
                   u.id as tech_id, u.name as tech_name, u.phone as tech_phone, u.email as tech_email, u.trade_skills as tech_skills, u.status as tech_status, u.avatar as tech_avatar,
                   a.asset_name, a.brand as asset_brand, a.model_number as asset_model
            FROM service_requests r
            JOIN customers c ON r.customer_id = c.id
            LEFT JOIN job_cards j ON j.request_id = r.id
            LEFT JOIN users u ON j.technician_id = u.id
            LEFT JOIN customer_assets a ON r.asset_id = a.id
            WHERE r.customer_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmtMR->execute([$memberCustomer['id']]);
        $memberRequests = $stmtMR->fetchAll();
    }
}

// Active Request being viewed
$activeRequest = null;
$activeJob = null;
$assignedTech = null;
$jobPhotos = [];
$partsUsed = [];

if (!empty($searchQuery)) {
    // Search by ticket_no or job_number or customer phone or request ID
    $stmtReq = $db->prepare("
        SELECT r.*, c.id as cust_id, c.name as customer_name, c.phone as customer_phone, c.email as customer_email,
               c.estate_area, c.address as customer_address, c.landmark, c.gps_coords,
               a.asset_name, a.brand as asset_brand, a.model_number as asset_model, a.serial_number as asset_serial,
               j.id as job_id, j.job_number, j.status as job_status, j.scheduled_date as job_date, j.scheduled_time as job_time,
               j.estimated_duration, j.diagnosis, j.work_performed, j.technician_notes, j.customer_feedback,
               j.signature_data, j.signed_by_name, j.signed_at, j.started_at, j.completed_at,
               u.id as tech_id, u.name as tech_name, u.phone as tech_phone, u.email as tech_email, u.trade_skills as tech_skills, u.status as tech_status, u.avatar as tech_avatar
        FROM service_requests r
        JOIN customers c ON r.customer_id = c.id
        LEFT JOIN customer_assets a ON r.asset_id = a.id
        LEFT JOIN job_cards j ON j.request_id = r.id
        LEFT JOIN users u ON j.technician_id = u.id
        WHERE r.ticket_no = ? 
           OR j.job_number = ? 
           OR c.phone LIKE ?
           OR c.email = ?
        ORDER BY r.created_at DESC
        LIMIT 1
    ");
    $stmtReq->execute([$searchQuery, $searchQuery, '%' . preg_replace('/[^0-9]/', '', $searchQuery) . '%', $searchQuery]);
    $activeRequest = $stmtReq->fetch();

    if ($activeRequest && !empty($activeRequest['job_id'])) {
        // Fetch photos
        $stmtPh = $db->prepare("SELECT * FROM job_photos WHERE job_id = ? ORDER BY created_at ASC");
        $stmtPh->execute([$activeRequest['job_id']]);
        $jobPhotos = $stmtPh->fetchAll();

        // Fetch parts used
        $stmtPt = $db->prepare("
            SELECT pu.*, ip.part_name, ip.part_code, ip.unit
            FROM job_parts_used pu
            JOIN inventory_parts ip ON pu.part_id = ip.id
            WHERE pu.job_id = ?
        ");
        $stmtPt->execute([$activeRequest['job_id']]);
        $partsUsed = $stmtPt->fetchAll();
    }
} elseif (!empty($memberRequests)) {
    // Default to the most recent member request if logged in
    $activeRequest = $memberRequests[0];
    if (!empty($activeRequest['job_id'])) {
        $stmtPh = $db->prepare("SELECT * FROM job_photos WHERE job_id = ? ORDER BY created_at ASC");
        $stmtPh->execute([$activeRequest['job_id']]);
        $jobPhotos = $stmtPh->fetchAll();

        $stmtPt = $db->prepare("
            SELECT pu.*, ip.part_name, ip.part_code, ip.unit
            FROM job_parts_used pu
            JOIN inventory_parts ip ON pu.part_id = ip.id
            WHERE pu.job_id = ?
        ");
        $stmtPt->execute([$activeRequest['job_id']]);
        $partsUsed = $stmtPt->fetchAll();
    }
}

// Helper for live progress step calculation
function get_stepper_stage(?array $req): int {
    if (!$req) return 1;
    $status = strtolower($req['job_status'] ?? $req['status'] ?? 'new');
    if (in_array($status, ['completed', 'signed_off'])) return 5;
    if ($status === 'in_progress' || $status === 'pending_parts') return 4;
    if ($status === 'en_route') return 3;
    if (!empty($req['tech_name']) || $status === 'assigned' || $status === 'scheduled') return 2;
    return 1;
}

$currentStage = get_stepper_stage($activeRequest);

// Trade categories for service catalog
$fieldServicesCatalog = [
    [
        'title' => 'Solar & Power Inverters',
        'trade' => 'Solar',
        'icon' => 'sun',
        'desc' => 'Deye, Growatt & Victron hybrid inverters, solar panels, battery banks & solar water heating.',
        'color' => '#f59e0b',
        'bg' => 'rgba(245, 158, 11, 0.12)'
    ],
    [
        'title' => 'Electrical Engineering',
        'trade' => 'Electrical',
        'icon' => 'zap',
        'desc' => 'House wiring, breaker panel faults, generator ATS changeovers, surge protection & 3-phase power.',
        'color' => '#38bdf8',
        'bg' => 'rgba(56, 189, 248, 0.12)'
    ],
    [
        'title' => 'CCTV & Smart Security',
        'trade' => 'CCTV',
        'icon' => 'video',
        'desc' => 'Hikvision & Dahua IP CCTV systems, NVR setup, access control, biometrics & perimeter alarms.',
        'color' => '#10b981',
        'bg' => 'rgba(16, 185, 129, 0.12)'
    ],
    [
        'title' => 'Plumbing & Water Systems',
        'trade' => 'Plumbing',
        'icon' => 'droplet',
        'desc' => 'Booster pump repairs, solar water heaters (Solahart), pipe leaks, PPR fittings & water tanks.',
        'color' => '#06b6d4',
        'bg' => 'rgba(6, 182, 212, 0.12)'
    ],
    [
        'title' => 'Fibre, Mesh WiFi & Cabling',
        'trade' => 'Fibre',
        'icon' => 'wifi',
        'desc' => 'Ubiquiti UniFi WiFi 6 mesh, optical fibre drops, CAT6 cabling, router & firewall configuration.',
        'color' => '#8b5cf6',
        'bg' => 'rgba(139, 92, 246, 0.12)'
    ],
    [
        'title' => 'HVAC & Air Conditioning',
        'trade' => 'HVAC',
        'icon' => 'wind',
        'desc' => 'Server room AC servicing, split systems, refrigerant recharge (R410A/R32), VRF & cold rooms.',
        'color' => '#0284c7',
        'bg' => 'rgba(2, 132, 199, 0.12)'
    ],
    [
        'title' => 'Appliance Repair',
        'trade' => 'Appliance',
        'icon' => 'tv',
        'desc' => 'Washing machines, commercial & domestic ovens, refrigerators, microwaves & dispensers.',
        'color' => '#ec4899',
        'bg' => 'rgba(236, 72, 153, 0.12)'
    ],
    [
        'title' => 'Computer & IT Systems',
        'trade' => 'Computer',
        'icon' => 'monitor',
        'desc' => 'Office PCs, servers, POS billing machines, network printers & data backup solutions.',
        'color' => '#64748b',
        'bg' => 'rgba(100, 116, 139, 0.12)'
    ],
    [
        'title' => 'Cleaning & Hygiene',
        'trade' => 'Cleaning',
        'icon' => 'sparkles',
        'desc' => 'Deep cleaning, post-construction cleaning, sofa & carpet steam cleaning & fumigation.',
        'color' => '#14b8a6',
        'bg' => 'rgba(20, 184, 166, 0.12)'
    ],
    [
        'title' => 'Facility Maintenance',
        'trade' => 'Maintenance',
        'icon' => 'wrench',
        'desc' => 'General handyman services, steel welding, door locks, painting & property repairs.',
        'color' => '#f97316',
        'bg' => 'rgba(249, 115, 22, 0.12)'
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Service Portal & Technician Tracker - FieldPulse Kenya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root {
            --portal-bg: #0b1120;
            --portal-card: #131d31;
            --portal-card-subtle: #1a2642;
            --portal-border: rgba(255, 255, 255, 0.09);
            --portal-accent: #0284c7;
            --portal-cyan: #38bdf8;
            --portal-emerald: #10b981;
            --portal-amber: #f59e0b;
        }

        body {
            background-color: var(--portal-bg);
            color: #f8fafc;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }

        /* Top Navigation Bar */
        .portal-nav {
            background: rgba(19, 29, 49, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--portal-border);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 14px 24px;
        }
        .portal-nav-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        .portal-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #fff;
        }
        .portal-brand-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4);
        }
        .portal-brand-text h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 19px;
            font-weight: 800;
            letter-spacing: -0.3px;
            margin: 0;
            line-height: 1.1;
        }
        .portal-brand-text span {
            font-size: 11px;
            color: #38bdf8;
            font-weight: 600;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .nav-cta-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Container */
        .portal-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        /* Hero / Search Section */
        .portal-hero {
            background: radial-gradient(circle at 80% 20%, rgba(2, 132, 199, 0.15), transparent 50%),
                        linear-gradient(180deg, rgba(30, 41, 59, 0.6) 0%, rgba(15, 23, 42, 0.8) 100%);
            border: 1px solid var(--portal-border);
            border-radius: 24px;
            padding: 32px 36px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5);
            position: relative;
            overflow: hidden;
        }
        .portal-hero h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 8px;
        }
        .portal-hero p {
            color: #94a3b8;
            font-size: 14.5px;
            max-width: 650px;
            margin-bottom: 22px;
            line-height: 1.5;
        }
        .search-tracker-bar {
            display: flex;
            gap: 10px;
            max-width: 620px;
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 14px;
            padding: 6px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }
        .search-tracker-input {
            flex: 1;
            background: transparent;
            border: none;
            color: #fff;
            padding: 10px 16px;
            font-size: 14.5px;
            outline: none;
            font-family: 'Inter', sans-serif;
        }
        .search-tracker-input::placeholder {
            color: #64748b;
        }
        .search-tracker-btn {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 22px;
            font-weight: 700;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .search-tracker-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.45);
        }

        /* Live Progress Stepper */
        .stepper-card {
            background: var(--portal-card);
            border: 1px solid var(--portal-border);
            border-radius: 20px;
            padding: 26px 28px;
            margin-bottom: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .stepper-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .stepper-container {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin: 15px 0 5px;
        }
        .stepper-progress-bg {
            position: absolute;
            top: 22px;
            left: 30px;
            right: 30px;
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            z-index: 1;
        }
        .stepper-progress-fill {
            position: absolute;
            top: 22px;
            left: 30px;
            height: 4px;
            background: linear-gradient(90deg, #38bdf8, #10b981);
            z-index: 2;
            transition: width 0.4s ease;
        }
        .step-node {
            position: relative;
            z-index: 3;
            text-align: center;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .step-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #1e293b;
            border: 2px solid rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        .step-node.completed .step-circle {
            background: #10b981;
            border-color: #10b981;
            color: #fff;
            box-shadow: 0 0 18px rgba(16, 185, 129, 0.4);
        }
        .step-node.active .step-circle {
            background: #0284c7;
            border-color: #38bdf8;
            color: #fff;
            box-shadow: 0 0 20px rgba(56, 189, 248, 0.6);
            transform: scale(1.1);
        }
        .step-label {
            font-size: 12.5px;
            font-weight: 600;
            color: #94a3b8;
            line-height: 1.2;
            max-width: 120px;
        }
        .step-node.active .step-label {
            color: #38bdf8;
            font-weight: 700;
        }
        .step-node.completed .step-label {
            color: #f8fafc;
        }

        /* 2-Column Grid Layout */
        .portal-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 24px;
            margin-bottom: 30px;
        }
        @media (max-width: 900px) {
            .portal-grid { grid-template-columns: 1fr; }
            .stepper-container { overflow-x: auto; padding-bottom: 10px; }
            .step-node { min-width: 100px; }
        }

        /* Technician Card */
        .tech-hero-card {
            background: linear-gradient(135deg, rgba(2, 132, 199, 0.15) 0%, rgba(19, 29, 49, 0.95) 100%);
            border: 1px solid rgba(56, 189, 248, 0.3);
            border-radius: 20px;
            padding: 26px;
            box-shadow: 0 15px 35px -10px rgba(2, 132, 199, 0.25);
            position: relative;
            overflow: hidden;
        }
        .tech-hero-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 160px;
            height: 160px;
            background: radial-gradient(circle, rgba(56, 189, 248, 0.12), transparent 70%);
            pointer-events: none;
        }
        .tech-profile-header {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 20px;
        }
        .tech-large-avatar {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            background: linear-gradient(135deg, #0284c7, #38bdf8);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            font-weight: 800;
            color: #fff;
            box-shadow: 0 8px 20px rgba(2, 132, 199, 0.4);
            border: 2px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
        }
        .tech-title-info h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 21px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .verified-tech-badge {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #34d399;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Tech Action Buttons */
        .tech-contact-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 20px 0 16px;
        }
        .btn-call-tech {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff;
            border: none;
            padding: 13px 18px;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-size: 14.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4);
            transition: all 0.2s ease;
        }
        .btn-call-tech:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(2, 132, 199, 0.55);
        }
        .btn-whatsapp-tech {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
            padding: 13px 18px;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-size: 14.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);
            transition: all 0.2s ease;
        }
        .btn-whatsapp-tech:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(16, 185, 129, 0.55);
        }

        /* Info Item Rows */
        .portal-info-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 13px;
        }
        .portal-info-row:last-child {
            border-bottom: none;
        }
        .portal-info-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #38bdf8;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .portal-info-content {
            flex: 1;
        }
        .portal-info-label {
            font-size: 11.5px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        .portal-info-val {
            color: #f8fafc;
            font-weight: 600;
        }

        /* Service Cards Catalog */
        .services-catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
            margin-top: 16px;
        }
        .service-catalog-card {
            background: var(--portal-card);
            border: 1px solid var(--portal-border);
            border-radius: 16px;
            padding: 20px;
            text-decoration: none;
            color: inherit;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .service-catalog-card:hover {
            transform: translateY(-3px);
            border-color: rgba(56, 189, 248, 0.4);
            box-shadow: 0 12px 28px -8px rgba(0, 0, 0, 0.5);
            background: var(--portal-card-subtle);
        }
        .service-cat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }
        .service-cat-title {
            font-family: 'Outfit', sans-serif;
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 6px;
        }
        .service-cat-desc {
            font-size: 12.5px;
            color: #94a3b8;
            line-height: 1.4;
            margin-bottom: 14px;
        }
        .service-cat-cta {
            font-size: 12.5px;
            font-weight: 700;
            color: #38bdf8;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Unassigned tech prompt banner */
        .unassigned-tech-banner {
            background: rgba(245, 158, 11, 0.08);
            border: 1px dashed rgba(245, 158, 11, 0.35);
            border-radius: 16px;
            padding: 24px;
            text-align: center;
        }
    </style>
</head>
<body>

<!-- Navigation Header -->
<header class="portal-nav">
    <div class="portal-nav-inner">
        <a href="customer_portal.php" class="portal-brand">
            <div class="portal-brand-icon">
                <i data-lucide="zap" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="portal-brand-text">
                <h1>FieldPulse</h1>
                <span>Customer & Member Hub</span>
            </div>
        </a>

        <div class="nav-cta-group">
            <a href="book_service.php" class="btn btn-primary" style="font-weight: 700; font-family: 'Outfit', sans-serif; display: flex; align-items: center; gap: 6px;">
                <i data-lucide="calendar-plus" style="width: 16px; height: 16px;"></i> Book Field Service
            </a>

            <?php if ($user): ?>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <a href="index.php" class="btn btn-secondary btn-sm" style="font-size: 12px;">
                        <i data-lucide="layout-dashboard" style="width: 14px; height: 14px;"></i> Dashboard
                    </a>
                    <a href="logout.php" class="btn btn-secondary btn-sm" title="Sign Out">
                        <i data-lucide="log-out" style="width: 14px; height: 14px; color: #f43f5e;"></i>
                    </a>
                </div>
            <?php else: ?>
                <a href="login.php" class="btn btn-secondary btn-sm" style="font-size: 12.5px;">
                    <i data-lucide="user" style="width: 14px; height: 14px;"></i> Member Sign In
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="portal-container">

    <?php $flash = get_flash(); if ($flash): ?>
        <div class="flash-alert flash-<?= $flash['type'] ?>" style="margin-bottom: 24px;">
            <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle-2' : 'alert-circle' ?>"></i>
            <span><?= htmlspecialchars($flash['message']) ?></span>
        </div>
    <?php endif; ?>

    <!-- Hero Tracker & Lookup Bar -->
    <div class="portal-hero">
        <h2>Real-Time Service Tracker & Field Dispatch</h2>
        <p>Track your technician's live arrival status, view assigned technician direct contacts (Call & WhatsApp), and monitor real-time repair progress across Kenya.</p>
        
        <form method="GET" action="customer_portal.php" class="search-tracker-bar">
            <input type="text" name="search" class="search-tracker-input" placeholder="Enter Ticket # (e.g. REQ-2026-001) or Phone #..." value="<?= htmlspecialchars($searchQuery) ?>" required>
            <button type="submit" class="search-tracker-btn">
                <i data-lucide="search" style="width: 16px; height: 16px;"></i> Track Request
            </button>
        </form>
    </div>

    <!-- Active Request Details If Loaded -->
    <?php if ($activeRequest): ?>
        <!-- Live Stepper Card -->
        <div class="stepper-card">
            <div class="stepper-header">
                <div>
                    <span style="font-size: 12px; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px;">Tracking Ticket:</span>
                    <span style="font-family: var(--font-mono); font-size: 20px; font-weight: 800; color: #38bdf8; margin-left: 6px;">
                        <?= htmlspecialchars($activeRequest['ticket_no']) ?>
                    </span>
                    <span style="margin-left: 10px;"><?= get_trade_badge($activeRequest['trade_category']) ?></span>
                    <span style="margin-left: 6px;"><?= get_priority_badge($activeRequest['priority']) ?></span>
                </div>
                <div>
                    <?= get_status_badge($activeRequest['job_status'] ?? $activeRequest['status']) ?>
                </div>
            </div>

            <!-- Stepper Progress Bar -->
            <div class="stepper-container">
                <div class="stepper-progress-bg"></div>
                <div class="stepper-progress-fill" style="width: <?= min(100, ($currentStage - 1) * 25) ?>%;"></div>

                <!-- Step 1 -->
                <div class="step-node <?= $currentStage >= 1 ? ($currentStage == 1 ? 'active' : 'completed') : '' ?>">
                    <div class="step-circle">
                        <?php if ($currentStage > 1): ?>
                            <i data-lucide="check" style="width: 20px; height: 20px;"></i>
                        <?php else: ?>
                            1
                        <?php endif; ?>
                    </div>
                    <div class="step-label">1. Request Received</div>
                    <div style="font-size: 10.5px; color: #64748b; margin-top: 2px;"><?= format_date($activeRequest['created_at']) ?></div>
                </div>

                <!-- Step 2 -->
                <div class="step-node <?= $currentStage >= 2 ? ($currentStage == 2 ? 'active' : 'completed') : '' ?>">
                    <div class="step-circle">
                        <?php if ($currentStage > 2): ?>
                            <i data-lucide="check" style="width: 20px; height: 20px;"></i>
                        <?php else: ?>
                            2
                        <?php endif; ?>
                    </div>
                    <div class="step-label">2. Tech Assigned</div>
                    <div style="font-size: 10.5px; color: #64748b; margin-top: 2px;"><?= !empty($activeRequest['tech_name']) ? htmlspecialchars($activeRequest['tech_name']) : 'In Queue' ?></div>
                </div>

                <!-- Step 3 -->
                <div class="step-node <?= $currentStage >= 3 ? ($currentStage == 3 ? 'active' : 'completed') : '' ?>">
                    <div class="step-circle">
                        <?php if ($currentStage > 3): ?>
                            <i data-lucide="check" style="width: 20px; height: 20px;"></i>
                        <?php else: ?>
                            3
                        <?php endif; ?>
                    </div>
                    <div class="step-label">3. En Route to Site</div>
                    <div style="font-size: 10.5px; color: #64748b; margin-top: 2px;"><?= ($currentStage >= 3) ? 'On the way' : 'Scheduled' ?></div>
                </div>

                <!-- Step 4 -->
                <div class="step-node <?= $currentStage >= 4 ? ($currentStage == 4 ? 'active' : 'completed') : '' ?>">
                    <div class="step-circle">
                        <?php if ($currentStage > 4): ?>
                            <i data-lucide="check" style="width: 20px; height: 20px;"></i>
                        <?php else: ?>
                            4
                        <?php endif; ?>
                    </div>
                    <div class="step-label">4. Work In Progress</div>
                    <div style="font-size: 10.5px; color: #64748b; margin-top: 2px;"><?= ($currentStage >= 4) ? 'On-site repairs' : 'Pending' ?></div>
                </div>

                <!-- Step 5 -->
                <div class="step-node <?= $currentStage >= 5 ? 'completed' : '' ?>">
                    <div class="step-circle">
                        <i data-lucide="shield-check" style="width: 20px; height: 20px;"></i>
                    </div>
                    <div class="step-label">5. Done & Signed Off</div>
                    <div style="font-size: 10.5px; color: #64748b; margin-top: 2px;"><?= ($currentStage == 5) ? 'Quality verified' : 'Pending sign' ?></div>
                </div>
            </div>
        </div>

        <!-- 2-Column Content: Technician Contact Card & Service Details -->
        <div class="portal-grid">
            <!-- Left: Assigned Technician Card -->
            <div>
                <?php if (!empty($activeRequest['tech_name'])): ?>
                    <div class="tech-hero-card">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                            <span class="verified-tech-badge">
                                <i data-lucide="check-circle-2" style="width: 13px; height: 13px;"></i> Certified Field Specialist
                            </span>
                            <?= get_status_badge($activeRequest['job_status'] ?? $activeRequest['tech_status'] ?? 'assigned') ?>
                        </div>

                        <div class="tech-profile-header">
                            <div class="tech-large-avatar">
                                <?= strtoupper(substr($activeRequest['tech_name'], 0, 2)) ?>
                            </div>
                            <div class="tech-title-info">
                                <h3>
                                    <?= htmlspecialchars($activeRequest['tech_name']) ?>
                                </h3>
                                <div style="color: #38bdf8; font-size: 13px; font-weight: 500;">
                                    <i data-lucide="tool" style="width: 13px; height: 13px; vertical-align: middle;"></i>
                                    <?= htmlspecialchars($activeRequest['tech_skills'] ?: 'Certified Field Operations Specialist') ?>
                                </div>
                                <div style="color: #94a3b8; font-size: 12px; margin-top: 3px;">
                                    <i data-lucide="phone" style="width: 12px; height: 12px; vertical-align: middle;"></i>
                                    <?= htmlspecialchars($activeRequest['tech_phone']) ?>
                                </div>
                            </div>
                        </div>

                        <!-- 1-Click Call & WhatsApp Action Buttons -->
                        <div class="tech-contact-actions">
                            <a href="tel:<?= htmlspecialchars($activeRequest['tech_phone']) ?>" class="btn-call-tech">
                                <i data-lucide="phone-call" style="width: 18px; height: 18px;"></i>
                                Call <?= htmlspecialchars(explode(' ', $activeRequest['tech_name'])[0]) ?>
                            </a>

                            <?php
                            $waMsg = "Hello {$activeRequest['tech_name']}, I am {$activeRequest['customer_name']} regarding my service booking ({$activeRequest['ticket_no']} - {$activeRequest['title']}) at {$activeRequest['estate_area']}. Looking forward to your visit.";
                            $waTechUrl = get_whatsapp_url($activeRequest['tech_phone'], $waMsg);
                            ?>
                            <a href="<?= $waTechUrl ?>" target="_blank" class="btn-whatsapp-tech">
                                <i data-lucide="message-square" style="width: 18px; height: 18px;"></i>
                                WhatsApp Chat
                            </a>
                        </div>

                        <!-- Scheduled Slot & Site Info -->
                        <div style="background: rgba(0,0,0,0.3); border-radius: 14px; padding: 14px; margin-top: 14px;">
                            <div class="portal-info-row">
                                <div class="portal-info-icon"><i data-lucide="calendar"></i></div>
                                <div class="portal-info-content">
                                    <div class="portal-info-label">Scheduled Date & Time</div>
                                    <div class="portal-info-val">
                                        <?= format_date($activeRequest['job_date'] ?? $activeRequest['preferred_date']) ?> • 
                                        <?= htmlspecialchars($activeRequest['job_time'] ?? $activeRequest['preferred_time_slot'] ?? '09:00 AM') ?>
                                        <?php if (!empty($activeRequest['estimated_duration'])): ?>
                                            <span style="color: #38bdf8; font-weight: normal; font-size: 12px;">(Est. <?= htmlspecialchars($activeRequest['estimated_duration']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="portal-info-row">
                                <div class="portal-info-icon"><i data-lucide="map-pin"></i></div>
                                <div class="portal-info-content">
                                    <div class="portal-info-label">Service Premises</div>
                                    <div class="portal-info-val">
                                        <?= htmlspecialchars($activeRequest['estate_area']) ?> - <?= htmlspecialchars($activeRequest['customer_address']) ?>
                                        <?php if (!empty($activeRequest['landmark'])): ?>
                                            <div style="font-size: 11.5px; color: #fde68a; margin-top: 2px;">
                                                <strong>Gate / Landmark:</strong> <?= htmlspecialchars($activeRequest['landmark']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                <?php else: ?>
                    <!-- Waiting for Admin to assign technician -->
                    <div class="unassigned-tech-banner">
                        <i data-lucide="clock" style="width: 44px; height: 44px; color: #f59e0b; margin: 0 auto 12px; display: block;"></i>
                        <h3 style="font-family: 'Outfit', sans-serif; font-size: 18px; font-weight: 700; color: #fff; margin-bottom: 6px;">
                            Technician Dispatch in Progress
                        </h3>
                        <p style="font-size: 13.5px; color: #94a3b8; max-width: 440px; margin: 0 auto 14px; line-height: 1.4;">
                            Our operations control center has received your request for <strong><?= htmlspecialchars($activeRequest['trade_category']) ?></strong> service and is assigning the nearest certified field technician.
                        </p>
                        <div style="display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; color: #38bdf8;">
                            <i data-lucide="shield-check" style="width: 15px; height: 15px;"></i> You will see the technician's name, direct phone & WhatsApp contacts here immediately upon dispatch.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Technician Notes, Work Performed & Photos -->
                <?php if (!empty($activeRequest['diagnosis']) || !empty($activeRequest['work_performed']) || !empty($jobPhotos) || !empty($partsUsed)): ?>
                    <div class="stepper-card" style="margin-top: 24px;">
                        <h3 style="font-family: 'Outfit', sans-serif; font-size: 17px; font-weight: 700; color: #fff; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="file-text" style="color: #38bdf8; width: 18px; height: 18px;"></i>
                            Live Field Work Report & Resolution
                        </h3>

                        <?php if (!empty($activeRequest['diagnosis'])): ?>
                            <div style="background: rgba(0,0,0,0.2); padding: 12px 14px; border-radius: 10px; margin-bottom: 12px;">
                                <div style="font-size: 11.5px; font-weight: 700; color: #f59e0b; text-transform: uppercase;">Diagnosis / Fault Found:</div>
                                <div style="font-size: 13px; color: #e2e8f0; margin-top: 4px;"><?= nl2br(htmlspecialchars($activeRequest['diagnosis'])) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($activeRequest['work_performed'])): ?>
                            <div style="background: rgba(0,0,0,0.2); padding: 12px 14px; border-radius: 10px; margin-bottom: 12px;">
                                <div style="font-size: 11.5px; font-weight: 700; color: #10b981; text-transform: uppercase;">Work Performed:</div>
                                <div style="font-size: 13px; color: #e2e8f0; margin-top: 4px;"><?= nl2br(htmlspecialchars($activeRequest['work_performed'])) ?></div>
                            </div>
                        <?php endif; ?>

                        <!-- Parts Used -->
                        <?php if (!empty($partsUsed)): ?>
                            <div style="margin-top: 14px;">
                                <div style="font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 6px;">Materials & Parts Installed:</div>
                                <div style="display: flex; flex-direction: column; gap: 6px;">
                                    <?php foreach ($partsUsed as $pt): ?>
                                        <div style="display: flex; justify-content: space-between; font-size: 12.5px; background: rgba(255,255,255,0.04); padding: 8px 12px; border-radius: 8px;">
                                            <span><strong><?= htmlspecialchars($pt['part_name']) ?></strong> (<?= htmlspecialchars($pt['part_code']) ?>)</span>
                                            <span style="color: #38bdf8; font-weight: 700;"><?= $pt['quantity'] ?> <?= htmlspecialchars($pt['unit']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Site Photos -->
                        <?php if (!empty($jobPhotos)): ?>
                            <div style="margin-top: 16px;">
                                <div style="font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 8px;">Site & Repair Photos:</div>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px;">
                                    <?php foreach ($jobPhotos as $photo): ?>
                                        <div style="border-radius: 10px; overflow: hidden; border: 1px solid var(--portal-border); background: #000;">
                                            <img src="<?= htmlspecialchars($photo['photo_path']) ?>" alt="Site Photo" style="width: 100%; height: 95px; object-fit: cover;">
                                            <div style="padding: 4px 6px; font-size: 10.5px; color: #94a3b8; text-transform: capitalize; text-align: center;">
                                                <?= htmlspecialchars($photo['photo_type']) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Sign-off status -->
                        <?php if (!empty($activeRequest['signature_data'])): ?>
                            <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 12px; padding: 14px; margin-top: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 36px; height: 36px; border-radius: 50%; background: #10b981; display: flex; align-items: center; justify-content: center; color: #fff;">
                                        <i data-lucide="check" style="width: 20px; height: 20px;"></i>
                                    </div>
                                    <div>
                                        <div style="font-weight: 700; font-size: 13.5px; color: #fff;">Verified & Signed Off On Site</div>
                                        <div style="font-size: 11.5px; color: #94a3b8;">Signed by <?= htmlspecialchars($activeRequest['signed_by_name']) ?> on <?= format_datetime($activeRequest['signed_at']) ?></div>
                                    </div>
                                </div>
                                <img src="<?= $activeRequest['signature_data'] ?>" alt="Signature" style="max-height: 40px; background: #fff; padding: 2px 6px; border-radius: 6px;">
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Service Issue Summary & Site Details -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div class="stepper-card">
                    <h3 style="font-family: 'Outfit', sans-serif; font-size: 17px; font-weight: 700; color: #fff; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
                        <i data-lucide="clipboard" style="color: #38bdf8; width: 18px; height: 18px;"></i>
                        Service Request Summary
                    </h3>

                    <div style="margin-bottom: 12px;">
                        <div style="font-size: 11.5px; color: #94a3b8; text-transform: uppercase;">Issue Title</div>
                        <div style="font-size: 15px; font-weight: 700; color: #fff; margin-top: 2px;">
                            <?= htmlspecialchars($activeRequest['title']) ?>
                        </div>
                    </div>

                    <div style="margin-bottom: 14px;">
                        <div style="font-size: 11.5px; color: #94a3b8; text-transform: uppercase;">Problem Description</div>
                        <div style="font-size: 13px; color: #cbd5e1; margin-top: 4px; line-height: 1.4; background: rgba(0,0,0,0.2); padding: 10px 12px; border-radius: 8px;">
                            <?= nl2br(htmlspecialchars($activeRequest['description'])) ?>
                        </div>
                    </div>

                    <!-- Equipment Asset Details -->
                    <?php if (!empty($activeRequest['asset_name'])): ?>
                        <div style="background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.2); border-radius: 10px; padding: 12px; margin-bottom: 14px;">
                            <div style="font-size: 11px; font-weight: 700; color: #38bdf8; text-transform: uppercase; margin-bottom: 3px;">
                                <i data-lucide="cpu" style="width: 12px; height: 12px; vertical-align: middle;"></i> Registered Equipment
                            </div>
                            <div style="font-weight: 700; color: #fff; font-size: 13.5px;"><?= htmlspecialchars($activeRequest['asset_name']) ?></div>
                            <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">
                                <?= htmlspecialchars($activeRequest['asset_brand'] ?: '') ?> <?= htmlspecialchars($activeRequest['asset_model'] ?: '') ?>
                                <?php if (!empty($activeRequest['asset_serial'])): ?>
                                    • S/N: <span style="font-family: var(--font-mono); color: #e2e8f0;"><?= htmlspecialchars($activeRequest['asset_serial']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="portal-info-row">
                        <div class="portal-info-icon"><i data-lucide="user"></i></div>
                        <div class="portal-info-content">
                            <div class="portal-info-label">Customer Contact</div>
                            <div class="portal-info-val">
                                <?= htmlspecialchars($activeRequest['customer_name']) ?> (<?= htmlspecialchars($activeRequest['customer_phone']) ?>)
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($activeRequest['job_number'])): ?>
                        <div class="portal-info-row">
                            <div class="portal-info-icon"><i data-lucide="file-check"></i></div>
                            <div class="portal-info-content">
                                <div class="portal-info-label">Work Order Reference</div>
                                <div class="portal-info-val" style="font-family: var(--font-mono); color: #38bdf8;">
                                    <?= htmlspecialchars($activeRequest['job_number']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top: 16px;">
                        <a href="book_service.php" class="btn btn-secondary" style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px;">
                            <i data-lucide="plus-circle" style="width: 15px; height: 15px;"></i> Book Another Service
                        </a>
                    </div>
                </div>

                <!-- Operations Helpdesk Card -->
                <div class="stepper-card" style="background: rgba(15, 23, 42, 0.7);">
                    <h4 style="font-family: 'Outfit', sans-serif; font-size: 15px; font-weight: 700; color: #fff; margin-bottom: 8px;">
                        Need Help or Rescheduling?
                    </h4>
                    <p style="font-size: 12.5px; color: #94a3b8; line-height: 1.4; margin-bottom: 12px;">
                        Our 24/7 Operations & Dispatch Desk in Nairobi is on standby to assist with any scheduling changes or questions.
                    </p>
                    <?php
                    $waSupportMsg = "Hello FieldPulse Support, I need assistance regarding my service request {$activeRequest['ticket_no']}.";
                    $waSupportUrl = get_whatsapp_url('+254722100200', $waSupportMsg);
                    ?>
                    <a href="<?= $waSupportUrl ?>" target="_blank" class="btn btn-whatsapp" style="width: 100%; font-size: 13px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <i data-lucide="message-circle" style="width: 15px; height: 15px;"></i> WhatsApp Dispatch Desk
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Member Requests List (If logged in as member with multiple bookings) -->
    <?php if (!empty($memberRequests) && count($memberRequests) > 1): ?>
        <div class="stepper-card" style="margin-bottom: 30px;">
            <h3 style="font-family: 'Outfit', sans-serif; font-size: 18px; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="list" style="color: #38bdf8;"></i> All Your Service Bookings
            </h3>
            <div class="table-responsive">
                <table class="custom-table" style="color: #f8fafc;">
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Trade Service</th>
                            <th>Issue Summary</th>
                            <th>Assigned Technician</th>
                            <th>Scheduled Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($memberRequests as $mr): ?>
                            <tr>
                                <td>
                                    <span style="font-family: var(--font-mono); font-weight: 700; color: #38bdf8;">
                                        <?= htmlspecialchars($mr['ticket_no']) ?>
                                    </span>
                                </td>
                                <td><?= get_trade_badge($mr['trade_category']) ?></td>
                                <td>
                                    <div style="font-weight: 600; font-size: 13px;"><?= htmlspecialchars($mr['title']) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($mr['tech_name'])): ?>
                                        <div style="font-weight: 700; color: #fff; font-size: 13px; display: flex; align-items: center; gap: 6px;">
                                            <i data-lucide="user-check" style="width: 13px; height: 13px; color: #34d399;"></i>
                                            <?= htmlspecialchars($mr['tech_name']) ?>
                                        </div>
                                        <div style="font-size: 11px; color: #94a3b8;"><?= htmlspecialchars($mr['tech_phone']) ?></div>
                                    <?php else: ?>
                                        <span style="font-size: 11.5px; color: #f59e0b;">⏳ Dispatch in progress</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= format_date($mr['job_date'] ?? $mr['preferred_date']) ?></td>
                                <td><?= get_status_badge($mr['job_status'] ?? $mr['status']) ?></td>
                                <td>
                                    <a href="customer_portal.php?ticket=<?= urlencode($mr['ticket_no']) ?>" class="btn btn-secondary btn-sm" style="font-size: 11.5px; padding: 4px 10px;">
                                        <i data-lucide="eye" style="width: 12px; height: 12px;"></i> View & Track
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Browse & Book Any Field Service Section -->
    <div style="margin-top: 40px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
            <div>
                <h2 style="font-family: 'Outfit', sans-serif; font-size: 24px; font-weight: 800; color: #fff;">Book Field Engineering & Maintenance Services</h2>
                <p style="color: #94a3b8; font-size: 14px; margin-top: 4px;">Choose from our specialized field trades. Certified technicians dispatched to your residential, commercial, or industrial premises.</p>
            </div>
            <a href="book_service.php" class="btn btn-primary" style="font-weight: 700;">
                <i data-lucide="plus"></i> Open Booking Form
            </a>
        </div>

        <div class="services-catalog-grid">
            <?php foreach ($fieldServicesCatalog as $cat): ?>
                <a href="book_service.php?trade=<?= urlencode($cat['trade']) ?>" class="service-catalog-card">
                    <div>
                        <div class="service-cat-icon" style="background: <?= $cat['bg'] ?>; color: <?= $cat['color'] ?>;">
                            <i data-lucide="<?= $cat['icon'] ?>" style="width: 22px; height: 22px;"></i>
                        </div>
                        <h4 class="service-cat-title"><?= htmlspecialchars($cat['title']) ?></h4>
                        <p class="service-cat-desc"><?= htmlspecialchars($cat['desc']) ?></p>
                    </div>
                    <div class="service-cat-cta">
                        <span>Book Service</span>
                        <i data-lucide="arrow-right" style="width: 14px; height: 14px;"></i>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- Footer -->
<footer style="border-top: 1px solid var(--portal-border); padding: 30px 20px; text-align: center; color: #64748b; font-size: 13px; margin-top: 60px;">
    <div style="max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px;">
        <div>
            &copy; <?= date('Y') ?> <strong>FieldPulse Kenya</strong>. All rights reserved. Field Operations & Technician Management.
        </div>
        <div style="display: flex; gap: 16px;">
            <a href="book_service.php" style="color: #38bdf8; text-decoration: none;">Book Service</a>
            <a href="customer_portal.php" style="color: #38bdf8; text-decoration: none;">Track Booking</a>
            <a href="login.php" style="color: #38bdf8; text-decoration: none;">Staff Sign In</a>
        </div>
    </div>
</footer>

<script>
    if (window.lucide) {
        lucide.createIcons();
    }
</script>
</body>
</html>
