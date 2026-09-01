<?php
/**
 * FieldPulse Kenya - Auto-Installer & Demo Data Seeder
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'field_service_db';
$seedDemoData = filter_var(getenv('SEED_DEMO_DATA') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$adminName = getenv('ADMIN_NAME') ?: 'System Administrator';
$adminEmail = getenv('ADMIN_EMAIL') ?: 'admin@example.com';
$adminPhone = getenv('ADMIN_PHONE') ?: '0000000000';
$adminPassword = getenv('ADMIN_PASSWORD') ?: ($seedDemoData ? 'admin123' : '');

$messages = [];
$success = false;

try {
    if ($adminPassword === '') {
        throw new Exception("Set ADMIN_PASSWORD before running setup.php.");
    }

    // 1. Connect to the configured database. If it does not exist and the
    // account has permission, create it for local development.
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $databaseException) {
        if ((int) $databaseException->getCode() !== 1049) {
            throw $databaseException;
        }
        $serverPdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '``', $dbname) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    }
    $messages[] = "Database `$dbname` verified/created successfully.";

    // 2. Read database.sql. CREATE DATABASE/USE statements are stripped so
    // managed MySQL accounts without database-admin privileges also work.
    $sqlFile = __DIR__ . '/database.sql';
    if (!file_exists($sqlFile)) {
        $sqlFile = __DIR__ . '/../database.sql';
    }
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        $sql = preg_replace('/^\s*CREATE DATABASE IF NOT EXISTS[^;]+;\s*$/mi', '', $sql);
        $sql = preg_replace('/^\s*USE\s+`?[^;`]+`?\s*;\s*$/mi', '', $sql);
        $pdo->exec($sql);
        $messages[] = "Database tables created successfully.";
    } else {
        throw new Exception("database.sql file not found at " . $sqlFile);
    }

    // 5. Check if seed data already exists
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    if ($userCount == 0) {
        // Always create one administrator. Demo records are opt-in so a
        // public deployment never ships with a known password or fake data.
        $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);
        $users = [
            [$adminName, $adminEmail, $adminPhone, $passwordHash, 'admin', 'Management, Operations', 'available', '']
        ];
        if ($seedDemoData) {
            $users = array_merge($users, [
                ['Amina Wanjiku', 'dispatch@fieldpulse.co.ke', '+254733400500', $passwordHash, 'dispatcher', 'Scheduling, Customer Relations', 'available', 'avatar_2.png'],
                ['Dennis Otieno', 'dennis.tech@fieldpulse.co.ke', '+254712345678', $passwordHash, 'technician', 'Solar, Inverters, Battery Banks', 'on_job', 'avatar_3.png'],
                ['Brian Kipkemboi', 'brian.tech@fieldpulse.co.ke', '+254723456789', $passwordHash, 'technician', 'Electrical, Wiring, Generator Changeovers', 'available', 'avatar_4.png'],
                ['Kevin Mutua', 'kevin.tech@fieldpulse.co.ke', '+254734567890', $passwordHash, 'technician', 'CCTV, Access Control, Biometrics', 'available', 'avatar_5.png'],
                ['Samuel Njoroge', 'samuel.tech@fieldpulse.co.ke', '+254745678901', $passwordHash, 'technician', 'Plumbing, Solar Water Heaters, Booster Pumps', 'on_job', 'avatar_6.png'],
                ['Peter Macharia', 'peter.tech@fieldpulse.co.ke', '+254756789012', $passwordHash, 'technician', 'Fibre, Networking, Mikrotik, Wi-Fi Extenders', 'available', 'avatar_7.png'],
                ['Jackson Ochieng', 'jackson.tech@fieldpulse.co.ke', '+254767890123', $passwordHash, 'technician', 'HVAC, Cold Rooms, Air Conditioning', 'available', 'avatar_8.png']
            ]);
        }

        $stmtUser = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, trade_skills, status, avatar) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($users as $u) {
            $stmtUser->execute($u);
        }
        $messages[] = "Seeded " . count($users) . " system users & field technicians.";

        if ($seedDemoData) {
        // Seed Customers
        $customers = [
            ['Apex Towers Ltd', 'Esther Mutua', '+254722889900', '+254733889900', 'facilities@apextowers.co.ke', 'Upper Hill, Hospital Rd', 'Apex Towers, 5th Floor Server Room', 'Near Britam Tower', '-1.2995, 36.8184', 'commercial', 'Access requires security gate pass at reception'],
            ['Dr. Grace Kariuki', 'Dr. Grace', '+254721445566', NULL, 'drgrace@gmail.com', 'Karen, Miotoni Road', 'House 14, Miotoni Ridge Gated Estate', 'First black gate after Karen Country Lodge', '-1.3218, 36.7119', 'residential', 'Dogs in compound; please call before opening gate'],
            ['GreenLeaf Supermarket', 'Manager James', '+254720998877', '+254711998877', 'james@greenleaf.co.ke', 'Kilimani, Argwings Kodhek Rd', 'GreenLeaf Mall, Ground Floor', 'Opposite Yaya Centre', '-1.2921, 36.7856', 'commercial', 'Loading dock entrance behind the mall for maintenance teams'],
            ['Eng. David Kiprop', 'David Kiprop', '+254714332211', NULL, 'david.kiprop@hotmail.com', 'Lavington, James Gichuru Rd', 'Villa 8B, Acacia Court', 'Near Lavington Curve Mall', '-1.2789, 36.7721', 'residential', 'High security gated compound'],
            ['Prime Logistics Hub', 'Operations Dept', '+254724665544', NULL, 'maintenance@primelogistics.co.ke', 'Industrial Area, Enterprise Road', 'Warehouse 4C, Enterprise Park', 'Next to Crown Paints depot', '-1.3150, 36.8480', 'industrial', 'Safety boots and high-vis vest required on-site'],
            ['Mama Sarah Njeri', 'Sarah Njeri', '+254725112233', NULL, 'sarah.njeri@gmail.com', 'Runda, Pan Africa Insurance Ave', 'Plot 45, Runda Grove', 'Near UN Avenue Gate', '-1.2290, 36.8120', 'residential', 'Strict quiet hours before 8:30 AM']
        ];

        $stmtCust = $pdo->prepare("INSERT INTO customers (name, contact_person, phone, alternate_phone, email, estate_area, address, landmark, gps_coords, customer_type, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($customers as $c) {
            $stmtCust->execute($c);
        }
        $messages[] = "Seeded " . count($customers) . " client profiles.";

        // Seed Customer Assets / Equipment
        $assets = [
            [1, 'Main Server Room Air Conditioner', 'HVAC', 'Samsung', 'DVM S Eco 14HP', 'SAM-AC-99882', '2024-03-15', '2027-03-15', '5th Floor Server Room', 'active', 'Requires quarterly filter and refrigerant check'],
            [1, 'Commercial IP CCTV System 32-CH', 'CCTV', 'Hikvision', 'DS-7732NI-I4', 'HIK-NVR-32441', '2023-11-10', '2025-11-10', 'Ground Floor Security Control Desk', 'active', '32 PoE 4MP Cameras spread across floor 1-10'],
            [2, 'Solar Hybrid Backup System 10kVA', 'Solar', 'Deye', 'SUN-10K-SG04LP3', 'DEYE-2024-8841', '2024-01-20', '2029-01-20', 'Outer Utility Room & Roof Panels', 'active', '4x 5.12kWh Shoto Lithium Batteries installed'],
            [2, 'Solar Water Heating System 300L', 'Plumbing', 'Solahart', '300J Free Heat', 'SOL-300-44910', '2023-06-05', '2028-06-05', 'Main Roof East Wing', 'needs_service', 'Pressure valve reported slightly weeping'],
            [3, 'Backup Diesel Generator 50kVA', 'Electrical', 'Perkins / FG Wilson', 'P50-1 Silent', 'FGW-50-77821', '2022-08-14', '2025-08-14', 'Rear Yard Generator Shed', 'active', 'ATS panel wired to main board'],
            [4, 'Smart Home Automation & WiFi 6 Mesh', 'Fibre', 'Ubiquiti UniFi', 'Dream Machine Pro + 4x U6-Pro', 'UBQ-UDM-6612', '2024-05-10', '2026-05-10', 'Central Corridor Rack', 'active', 'Supports whole house streaming and smart switches'],
            [5, 'Perimeter CCTV & AI Intrusion Detection', 'CCTV', 'Dahua', 'DH-NVR5464-4KS2', 'DH-NVR-90812', '2023-09-01', '2025-09-01', 'Guardhouse Rack 1', 'needs_service', 'Cam 12 & 14 infrared night vision flickering']
        ];

        $stmtAsset = $pdo->prepare("INSERT INTO customer_assets (customer_id, asset_name, category, brand, model_number, serial_number, install_date, warranty_expiry, location_at_site, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($assets as $a) {
            $stmtAsset->execute($a);
        }
        $messages[] = "Seeded " . count($assets) . " equipment assets.";

        // Seed Inventory Parts
        $parts = [
            ['SOL-MC4-PAIR', 'MC4 Solar Connectors Male/Female Pair', 'Solar', 'pair', 45, 10, 350.00, 'TUV certified 1000V DC rated solar connector pair'],
            ['SOL-DC-SPD', '1000V DC Surge Protective Device (SPD)', 'Solar', 'pcs', 18, 5, 2400.00, 'Type 2 DC surge protection for solar string combiners'],
            ['CCTV-BNC-CON', 'HD Coaxial BNC Video Balun / Connectors', 'CCTV', 'pack', 25, 8, 450.00, 'Pack of 10 screw-type CCTV BNC terminals'],
            ['NET-CAT6-ROLL', 'Cat6 Pure Copper UTP Cable (305m Drum)', 'Fibre', 'drum', 6, 2, 14500.00, 'D-Link high performance gigabit ethernet networking cable'],
            ['NET-RJ45-BOX', 'RJ45 Modular Plug Connectors (Box 100pcs)', 'Fibre', 'box', 12, 3, 1200.00, 'Gold-plated 8P8C pass-through connectors'],
            ['ELEC-MCB-32A', 'Schneider 32A Single Pole MCB Circuit Breaker', 'Electrical', 'pcs', 30, 8, 850.00, 'Acti9 6kA C-curve DIN rail circuit breaker'],
            ['ELEC-CBL-16MM', '16mm 3-Core Armoured Copper Cable', 'Electrical', 'meters', 120, 30, 950.00, 'SWA underground feeder cable for heavy loads'],
            ['HVAC-GAS-R410A', 'R410A Refrigerant Gas Cylinder 11.3kg', 'HVAC', 'cylinder', 5, 2, 11500.00, 'Eco-friendly refrigerant for modern split and VRF systems'],
            ['PLUMB-VALVE-1IN', '1-inch Heavy Duty Brass Ball Valve Pegler', 'Plumbing', 'pcs', 22, 6, 1650.00, 'Full bore PN25 brass isolation valve with lever handle'],
            ['PLUMB-PPR-25MM', 'PPR Pipe 25mm Hot/Cold Water (4m length)', 'Plumbing', 'pcs', 40, 10, 480.00, 'High pressure PN20 certified PPR plumbing pipe']
        ];

        $stmtPart = $pdo->prepare("INSERT INTO inventory_parts (part_code, part_name, category, unit, in_stock, min_stock_alert, unit_cost, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($parts as $p) {
            $stmtPart->execute($p);
        }
        $messages[] = "Seeded " . count($parts) . " inventory parts and materials.";

        // Seed Service Requests
        $requests = [
            ['REQ-2026-001', 2, 3, 'Solar', 'Solar Inverter Tripping During Grid Switchover', 'Customer reports the Deye 10kW hybrid inverter is giving error F58 when KPLC power cuts out. House loses power for 30 seconds before coming back.', 'urgent', 'assigned', '2026-08-31', 'Morning (09:00 - 12:00)', 1],
            ['REQ-2026-002', 1, 1, 'HVAC', 'Server Room Air Conditioner Dripping Water', 'The primary Samsung AC in the main server room on 5th floor has condensation overflowing the drain pan. Critical server rack nearby.', 'emergency', 'in_progress', '2026-08-31', 'Immediate', 2],
            ['REQ-2026-003', 5, 7, 'CCTV', 'Perimeter Cameras 12 & 14 Night Vision Glitch', 'Cameras covering the south loading bay flicker heavily at night and fail to trigger night vision IR illumination.', 'normal', 'new', '2026-09-01', 'Afternoon (14:00 - 17:00)', 2],
            ['REQ-2026-004', 3, 5, 'Electrical', 'Routine Bi-Monthly Generator ATS Service', 'Bi-monthly service check for the Perkins 50kVA generator, fuel lines, battery voltage check and ATS transfer test.', 'normal', 'completed', '2026-08-30', 'Morning (08:30 - 11:30)', 1],
            ['REQ-2026-005', 4, 6, 'Fibre', 'Mesh WiFi Deadzone in Garden Gazebo Area', 'Client wants to extend the existing Ubiquiti UniFi network to the outdoor patio and detached guest house.', 'low', 'new', '2026-09-02', 'Flexible', 1]
        ];

        $stmtReq = $pdo->prepare("INSERT INTO service_requests (ticket_no, customer_id, asset_id, trade_category, title, description, priority, status, preferred_date, preferred_time_slot, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($requests as $r) {
            $stmtReq->execute($r);
        }
        $messages[] = "Seeded " . count($requests) . " service requests.";

        // Seed Job Cards
        $jobs = [
            [
                'JOB-2026-001', 1, 2, 3, 3, 'Solar',
                'Diagnose & Fix Hybrid Inverter ATS Switchover Lag',
                'Inspect Deye 10kVA inverter auxiliary relay configuration, check grid sense threshold, and update BMS firmware communicating with Shoto battery bank.',
                '2026-08-31', '09:30 AM', '2 hours', 'urgent', 'in_progress',
                'Preliminary test shows grid frequency sensitivity set too strict (48Hz-52Hz), causing rapid disconnect delay during brief KPLC dips.',
                'Adjusted micro-grid inverter threshold parameters. Re-crimped communication cable RJ45 from BMS port to CAN bus.',
                'Testing continuous UPS mode under 4.5kW simulated load.',
                NULL, NULL, NULL, NULL, '2026-08-31 09:35:00', NULL
            ],
            [
                'JOB-2026-002', 2, 1, 8, 1, 'HVAC',
                'Emergency Unclog & Drain Pan Service - Server Room AC',
                'Inspect Samsung 14HP unit, clear algae buildup in gravity drain tube, test condensation lift pump and check refrigerant pressure.',
                '2026-08-31', '10:00 AM', '1.5 hours', 'emergency', 'en_route',
                'Condensate pipe obstructed with dust slime near exterior wall exit.',
                NULL, 'Technician en route on Enterprise Rd.',
                NULL, NULL, NULL, NULL, NULL, NULL
            ],
            [
                'JOB-2026-003', 4, 3, 4, 5, 'Electrical',
                'Scheduled Maintenance: Perkins 50kVA Generator & ATS Panel',
                'Check oil viscosity, coolant level, 12V starter battery health, air filter condition, and simulate mains outage test.',
                '2026-08-30', '08:30 AM', '2.5 hours', 'normal', 'signed_off',
                'All generator fluid levels verified optimal. Starter battery measured 12.8V DC resting. ATS switched over in 4.2 seconds seamlessly.',
                'Cleaned air filter casing, tightened generator output lugs, tested auto start cycle 3 times successfully.',
                'Next routine maintenance due in 60 days.',
                'Excellent timely service by Brian Kipkemboi. Generator tested well.',
                'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAK8AAAA8CAYAAADtT7EFAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAAHSSURBVHhe7ds/CsIwGAfxT8W14OHq6uDi4OIBPIK38CCewuPg4ODq4OCg4ODs4uDg4ODg4Ozg6uDs4uLq4ODq4ODg4ODq4ODg4ODg',
                'James Mwangi (Store Manager)', '2026-08-30 11:15:00', '2026-08-30 08:35:00', '2026-08-30 11:10:00'
            ]
        ];

        $stmtJob = $pdo->prepare("INSERT INTO job_cards (job_number, request_id, customer_id, technician_id, asset_id, trade_category, title, job_description, scheduled_date, scheduled_time, estimated_duration, priority, status, diagnosis, work_performed, technician_notes, customer_feedback, signature_data, signed_by_name, signed_at, started_at, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($jobs as $j) {
            $stmtJob->execute($j);
        }
        $messages[] = "Seeded " . count($jobs) . " official Job Cards.";

        // Seed Parts Used on JOB-2026-003
        $stmtJobPart = $pdo->prepare("INSERT INTO job_parts_used (job_id, part_id, quantity, notes) VALUES (?, ?, ?, ?)");
        $stmtJobPart->execute([3, 5, 2, 'Re-crimped ATS control sensor wiring']);
        $messages[] = "Seeded job parts usage records.";

        // Seed Activity Logs
        $logs = [
            [1, 1, 'Job Created & Dispatched', 'Dispatched to Dennis Otieno (Solar Specialist)'],
            [1, 3, 'Status changed to In Progress', 'Arrived at Karen site and began inverter diagnostic test'],
            [2, 2, 'Dispatched Emergency Job', 'Dispatched to Jackson Ochieng (HVAC Tech)'],
            [3, 4, 'Job Signed Off', 'Customer confirmed generator test and signed digital job card']
        ];
        $stmtLog = $pdo->prepare("INSERT INTO job_logs (job_id, user_id, action, notes) VALUES (?, ?, ?, ?)");
        foreach ($logs as $l) {
            $stmtLog->execute($l);
        }
        $messages[] = "Seeded activity audit logs.";

        // Seed Notifications
        $notifs = [
            [1, 'dispatch', 'New Job Dispatched', 'You have been assigned JOB-2026-001 (Deye Inverter Fault) in Karen.', 'job_view.php?id=1', 0],
            [1, 'status_update', 'Job In Progress', 'Dennis Otieno started work on JOB-2026-001.', 'job_view.php?id=1', 0],
            [NULL, 'signature', 'Job Signed Off', 'JOB-2026-003 was signed off by Store Manager James Mwangi.', 'job_view.php?id=3', 1]
        ];
        $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($notifs as $n) {
            $stmtNotif->execute($n);
        }
        $messages[] = "Seeded notification alerts.";
        }
    }

    $success = true;
} catch (Exception $e) {
    $messages[] = "ERROR: " . $e->getMessage();
}

// Create uploads directory if not exists
$uploadDir = __DIR__ . '/assets/uploads';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup & Migration - FieldPulse Kenya</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0284c7;
            --primary-dark: #0369a1;
            --success: #10b981;
            --dark: #0f172a;
            --card-bg: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border: rgba(255, 255, 255, 0.08);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            background: #090d16;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .setup-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            max-width: 620px;
            width: 100%;
            padding: 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .header {
            text-align: center;
            margin-bottom: 24px;
        }
        .badge {
            display: inline-block;
            background: rgba(2, 132, 199, 0.15);
            color: #38bdf8;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 26px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 6px;
        }
        p.subtitle {
            color: var(--text-muted);
            font-size: 14px;
        }
        .log-box {
            background: #0f172a;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin: 20px 0;
            font-family: monospace;
            font-size: 13px;
            max-height: 220px;
            overflow-y: auto;
        }
        .log-item {
            padding: 4px 0;
            color: #38bdf8;
        }
        .log-item.error {
            color: #f43f5e;
        }
        .btn {
            display: block;
            width: 100%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 14px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            text-align: center;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.35);
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.45);
        }
    </style>
</head>
<body>
    <div class="setup-card">
        <div class="header">
            <span class="badge">Database Setup</span>
            <h1>FieldPulse Kenya</h1>
            <p class="subtitle">Field Service Management System Auto-Initializer</p>
        </div>

        <div class="log-box">
            <?php foreach ($messages as $msg): ?>
                <div class="log-item <?= str_contains($msg, 'ERROR') ? 'error' : '' ?>">
                    ✓ <?= htmlspecialchars($msg) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($success): ?>
            <a href="index.php" class="btn">🚀 Open Field Operations Dashboard</a>
        <?php else: ?>
            <button onclick="window.location.reload();" class="btn" style="background: #e11d48;">Retry Initialization</button>
        <?php endif; ?>
    </div>
</body>
</html>
