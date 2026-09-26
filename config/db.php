<?php
/**
 * FieldPulse Kenya - Field Service Management System
 * Database Connection & Global Utilities
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session versioning: invalidate any old simulation-era sessions
define('SESSION_VERSION', 2);
if (($_SESSION['_version'] ?? 0) !== SESSION_VERSION) {
    // Old or unversioned session — destroy it completely and force login
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
    $_SESSION['_version'] = SESSION_VERSION;
}


// Database configuration with environment variable fallbacks
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') ?: 'field_service_db');
define('APP_NAME', 'FieldPulse');
define('APP_TAGLINE', 'Field Service Management System');

$baseUrl = getenv('BASE_URL');
if ($baseUrl === false || $baseUrl === null) {
    // Auto-detect base URL
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $baseUrl = ($scriptDir === '/' || $scriptDir === '.') ? '' : $scriptDir;
}
define('BASE_URL', rtrim($baseUrl, '/'));

/**
 * Returns the PDO database connection instance.
 * Automatically checks and initializes the database if not present.
 * If MySQL is not reachable, seamlessly falls back to SQLite so the system works 100% anywhere.
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbDriver = getenv('DB_DRIVER') ?: 'auto';

    if ($dbDriver !== 'sqlite') {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 2,
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Migration: add account_status if missing (MySQL)
            try { $pdo->exec("ALTER TABLE users ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'active'"); } catch(Exception $e){}
            return $pdo;
        } catch (PDOException $e) {
            // If MySQL is reachable but database doesn't exist, try creating it
            if ($e->getCode() == 1049) {
                try {
                    $tmpPdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT, DB_USER, DB_PASS);
                    $tmpPdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, $options);
                    init_database_schema($pdo, 'mysql');
                    return $pdo;
                } catch (Exception $ex) {
                    // Fall through to SQLite fallback
                }
            }
            // MySQL server is not running or unreachable: graceful SQLite fallback
        }
    }

    // SQLite fallback database path — writable on both XAMPP and Docker/Render
    $sqlitePath = __DIR__ . '/../field_service.sqlite';
    $isNew = !file_exists($sqlitePath) || filesize($sqlitePath) === 0;

    $pdo = new PDO("sqlite:" . $sqlitePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA foreign_keys = ON;");

    if ($isNew) {
        init_database_schema($pdo, 'sqlite');
    } else {
        // Migration: add account_status column if missing
        try { $pdo->exec("ALTER TABLE users ADD COLUMN account_status TEXT NOT NULL DEFAULT 'active'"); } catch(Exception $e){}
    }

    return $pdo;
}

/**
 * Initialize database schema and demo seed data
 */
function init_database_schema(PDO $pdo, string $driver = 'sqlite'): void {
    if ($driver === 'sqlite') {
        $schema = [
            "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                phone TEXT NOT NULL,
                password TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'member',
                trade_skills TEXT NULL,
                status TEXT NOT NULL DEFAULT 'available',
                account_status TEXT NOT NULL DEFAULT 'active',
                avatar TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                name TEXT NOT NULL,
                contact_person TEXT NULL,
                phone TEXT NOT NULL,
                alternate_phone TEXT NULL,
                email TEXT NULL,
                estate_area TEXT NOT NULL,
                address TEXT NOT NULL,
                landmark TEXT NULL,
                gps_coords TEXT NULL,
                customer_type TEXT NOT NULL DEFAULT 'residential',
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS customer_assets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL,
                asset_name TEXT NOT NULL,
                category TEXT NOT NULL,
                brand TEXT NULL,
                model_number TEXT NULL,
                serial_number TEXT NULL,
                install_date DATE NULL,
                warranty_expiry DATE NULL,
                location_at_site TEXT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS service_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_no TEXT NOT NULL UNIQUE,
                customer_id INTEGER NOT NULL,
                asset_id INTEGER NULL,
                trade_category TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT NOT NULL,
                priority TEXT NOT NULL DEFAULT 'normal',
                status TEXT NOT NULL DEFAULT 'new',
                preferred_date DATE NULL,
                preferred_time_slot TEXT NULL,
                created_by INTEGER NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                FOREIGN KEY (asset_id) REFERENCES customer_assets(id) ON DELETE SET NULL
            )",
            "CREATE TABLE IF NOT EXISTS job_cards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_number TEXT NOT NULL UNIQUE,
                request_id INTEGER NULL,
                customer_id INTEGER NOT NULL,
                technician_id INTEGER NOT NULL,
                asset_id INTEGER NULL,
                trade_category TEXT NOT NULL,
                title TEXT NOT NULL,
                job_description TEXT NOT NULL,
                scheduled_date DATE NOT NULL,
                scheduled_time TEXT NOT NULL,
                estimated_duration TEXT NULL,
                priority TEXT NOT NULL DEFAULT 'normal',
                status TEXT NOT NULL DEFAULT 'scheduled',
                diagnosis TEXT NULL,
                work_performed TEXT NULL,
                technician_notes TEXT NULL,
                customer_feedback TEXT NULL,
                signature_data TEXT NULL,
                signed_by_name TEXT NULL,
                signed_at DATETIME NULL,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE SET NULL,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (asset_id) REFERENCES customer_assets(id) ON DELETE SET NULL
            )",
            "CREATE TABLE IF NOT EXISTS job_photos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                photo_path TEXT NOT NULL,
                photo_type TEXT NOT NULL DEFAULT 'during',
                caption TEXT NULL,
                uploaded_by INTEGER NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (job_id) REFERENCES job_cards(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS inventory_parts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                part_code TEXT NOT NULL UNIQUE,
                part_name TEXT NOT NULL,
                category TEXT NOT NULL,
                unit TEXT NOT NULL DEFAULT 'pcs',
                in_stock INTEGER NOT NULL DEFAULT 0,
                min_stock_alert INTEGER NOT NULL DEFAULT 5,
                unit_cost REAL NULL,
                description TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS job_parts_used (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                part_id INTEGER NOT NULL,
                quantity REAL NOT NULL DEFAULT 1,
                notes TEXT NULL,
                recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (job_id) REFERENCES job_cards(id) ON DELETE CASCADE,
                FOREIGN KEY (part_id) REFERENCES inventory_parts(id) ON DELETE RESTRICT
            )",
            "CREATE TABLE IF NOT EXISTS job_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                user_id INTEGER NULL,
                action TEXT NOT NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (job_id) REFERENCES job_cards(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                message TEXT NOT NULL,
                link TEXT NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )"
        ];

        foreach ($schema as $q) {
            $pdo->exec($q);
        }
    }

    // Check if users exist before seeding
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        $passwordHash = password_hash('admin123', PASSWORD_BCRYPT);
        
        $users = [
            ['Kiptoo Mwangi', 'admin@fieldpulse.co.ke', '+254722100200', $passwordHash, 'admin', 'Management, Operations', 'available', 'avatar_1.png'],
            ['Amina Wanjiku', 'dispatch@fieldpulse.co.ke', '+254733400500', $passwordHash, 'dispatcher', 'Scheduling, Customer Relations', 'available', 'avatar_2.png'],
            ['Dennis Otieno', 'dennis.tech@fieldpulse.co.ke', '+254712345678', $passwordHash, 'technician', 'Solar, Inverters, Battery Banks, Off-Grid', 'on_job', 'avatar_3.png'],
            ['Brian Kipkemboi', 'brian.tech@fieldpulse.co.ke', '+254723456789', $passwordHash, 'technician', 'Electrical, Wiring, Generator Changeovers, Lighting', 'available', 'avatar_4.png'],
            ['Kevin Mutua', 'kevin.tech@fieldpulse.co.ke', '+254734567890', $passwordHash, 'technician', 'CCTV, Access Control, Biometrics, Alarms', 'available', 'avatar_5.png'],
            ['Samuel Njoroge', 'samuel.tech@fieldpulse.co.ke', '+254745678901', $passwordHash, 'technician', 'Plumbing, Solar Water Heaters, Booster Pumps, Drainage', 'on_job', 'avatar_6.png'],
            ['Peter Macharia', 'peter.tech@fieldpulse.co.ke', '+254756789012', $passwordHash, 'technician', 'Fibre, Networking, Mikrotik, Wi-Fi 6 Mesh Extenders', 'available', 'avatar_7.png'],
            ['Jackson Ochieng', 'jackson.tech@fieldpulse.co.ke', '+254767890123', $passwordHash, 'technician', 'HVAC, Cold Rooms, Air Conditioning, Refrigeration', 'available', 'avatar_8.png'],
            ['Grace Wambui', 'grace.member@gmail.com', '+254721445566', $passwordHash, 'member', 'Solar, HVAC & Home Automation', 'available', 'avatar_9.png']
        ];

        $stmtUser = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, trade_skills, status, avatar) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($users as $u) {
            $stmtUser->execute($u);
        }

        // Seed Customers
        $customers = [
            [1, 'Apex Towers Ltd', 'Esther Mutua', '+254722889900', '+254733889900', 'facilities@apextowers.co.ke', 'Upper Hill, Hospital Rd', 'Apex Towers, 5th Floor Server Room', 'Near Britam Tower', '-1.2995, 36.8184', 'commercial', 'Access requires security gate pass at reception'],
            [9, 'Dr. Grace Kariuki', 'Dr. Grace', '+254721445566', NULL, 'grace.member@gmail.com', 'Karen, Miotoni Road', 'House 14, Miotoni Ridge Gated Estate', 'First black gate after Karen Country Lodge', '-1.3218, 36.7119', 'residential', 'Dogs in compound; please call before opening gate'],
            [NULL, 'GreenLeaf Supermarket', 'Manager James', '+254720998877', '+254711998877', 'james@greenleaf.co.ke', 'Kilimani, Argwings Kodhek Rd', 'GreenLeaf Mall, Ground Floor', 'Opposite Yaya Centre', '-1.2921, 36.7856', 'commercial', 'Loading dock entrance behind the mall for maintenance teams'],
            [NULL, 'Eng. David Kiprop', 'David Kiprop', '+254714332211', NULL, 'david.kiprop@hotmail.com', 'Lavington, James Gichuru Rd', 'Villa 8B, Acacia Court', 'Near Lavington Curve Mall', '-1.2789, 36.7721', 'residential', 'High security gated compound'],
            [NULL, 'Prime Logistics Hub', 'Operations Dept', '+254724665544', NULL, 'maintenance@primelogistics.co.ke', 'Industrial Area, Enterprise Road', 'Warehouse 4C, Enterprise Park', 'Next to Crown Paints depot', '-1.3150, 36.8480', 'industrial', 'Safety boots and high-vis vest required on-site'],
            [NULL, 'Mama Sarah Njeri', 'Sarah Njeri', '+254725112233', NULL, 'sarah.njeri@gmail.com', 'Runda, Pan Africa Insurance Ave', 'Plot 45, Runda Grove', 'Near UN Avenue Gate', '-1.2290, 36.8120', 'residential', 'Strict quiet hours before 8:30 AM']
        ];

        $stmtCust = $pdo->prepare("INSERT INTO customers (user_id, name, contact_person, phone, alternate_phone, email, estate_area, address, landmark, gps_coords, customer_type, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($customers as $c) {
            $stmtCust->execute($c);
        }

        // Seed Assets
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

        // Seed Inventory
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

        // Seed Requests
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

        // Seed Parts Used
        $stmtJobPart = $pdo->prepare("INSERT INTO job_parts_used (job_id, part_id, quantity, notes) VALUES (?, ?, ?, ?)");
        $stmtJobPart->execute([3, 5, 2, 'Re-crimped ATS control sensor wiring']);

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
    }
}

/**
 * Sanitize string input
 */
function clean(string $data): string {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Format Kenyan phone number for WhatsApp deep-linking
 * Converts 0712345678 or +254712345678 to 254712345678
 */
function format_kenya_phone(string $phone): string {
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($cleaned, '0')) {
        $cleaned = '254' . substr($cleaned, 1);
    } elseif (str_starts_with($cleaned, '7') || str_starts_with($cleaned, '1')) {
        $cleaned = '254' . $cleaned;
    }
    return $cleaned;
}

/**
 * Generate Direct WhatsApp Link with pre-filled message
 */
function get_whatsapp_url(string $phone, string $message): string {
    $validPhone = format_kenya_phone($phone);
    return "https://api.whatsapp.com/send?phone=" . urlencode($validPhone) . "&text=" . rawurlencode($message);
}

/**
 * Format date for friendly display
 */
function format_date(?string $date): string {
    if (!$date) return 'N/A';
    return date('d M Y', strtotime($date));
}

/**
 * Format datetime
 */
function format_datetime(?string $datetime): string {
    if (!$datetime) return 'N/A';
    return date('d M Y, h:i A', strtotime($datetime));
}

/**
 * Human-readable relative time
 */
function time_ago(string $datetime): string {
    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;
    
    if ($difference < 60) return "just now";
    if ($difference < 3600) return floor($difference / 60) . " min ago";
    if ($difference < 86400) return floor($difference / 3600) . " hrs ago";
    if ($difference < 604800) return floor($difference / 86400) . " days ago";
    return date('M d, Y', $timestamp);
}

/**
 * HTML Status Badge generator for Job Cards & Requests
 */
function get_status_badge(string $status): string {
    $map = [
        'new'           => ['class' => 'badge-info',     'label' => 'New Request',    'icon' => 'sparkles'],
        'assigned'      => ['class' => 'badge-primary',  'label' => 'Assigned',       'icon' => 'user-check'],
        'scheduled'     => ['class' => 'badge-purple',   'label' => 'Scheduled',      'icon' => 'calendar'],
        'en_route'      => ['class' => 'badge-warning',  'label' => 'En Route',       'icon' => 'truck'],
        'in_progress'   => ['class' => 'badge-amber',    'label' => 'In Progress',    'icon' => 'wrench'],
        'pending_parts' => ['class' => 'badge-danger',   'label' => 'Pending Parts',  'icon' => 'package'],
        'completed'     => ['class' => 'badge-teal',     'label' => 'Completed',      'icon' => 'check-circle'],
        'signed_off'    => ['class' => 'badge-success',  'label' => 'Signed & Closed','icon' => 'shield-check'],
        'cancelled'     => ['class' => 'badge-muted',    'label' => 'Cancelled',      'icon' => 'x-circle'],
        // Tech status
        'available'     => ['class' => 'badge-success',  'label' => 'Available',      'icon' => 'check'],
        'on_job'        => ['class' => 'badge-amber',    'label' => 'On Job',         'icon' => 'activity'],
        'off_duty'      => ['class' => 'badge-muted',    'label' => 'Off Duty',       'icon' => 'moon']
    ];

    $cfg = $map[strtolower($status)] ?? ['class' => 'badge-secondary', 'label' => ucfirst(str_replace('_', ' ', $status)), 'icon' => 'circle'];
    return "<span class=\"badge {$cfg['class']}\"><i data-lucide=\"{$cfg['icon']}\" class=\"badge-icon\"></i> {$cfg['label']}</span>";
}

/**
 * HTML Priority Badge
 */
function get_priority_badge(string $priority): string {
    $map = [
        'low'       => ['class' => 'badge-subtle-secondary', 'label' => 'Low'],
        'normal'    => ['class' => 'badge-subtle-info',      'label' => 'Normal'],
        'urgent'    => ['class' => 'badge-subtle-warning',   'label' => 'Urgent'],
        'emergency' => ['class' => 'badge-subtle-danger',    'label' => '⚡ Emergency']
    ];
    $cfg = $map[strtolower($priority)] ?? ['class' => 'badge-secondary', 'label' => ucfirst($priority)];
    return "<span class=\"badge {$cfg['class']}\">{$cfg['label']}</span>";
}

/**
 * Trade Category Icons and colors
 */
function get_trade_badge(string $trade): string {
    $map = [
        'Electrical' => ['class' => 'trade-electric', 'icon' => 'zap'],
        'Solar'      => ['class' => 'trade-solar',    'icon' => 'sun'],
        'CCTV'       => ['class' => 'trade-cctv',     'icon' => 'video'],
        'Plumbing'   => ['class' => 'trade-plumb',    'icon' => 'droplet'],
        'Fibre'      => ['class' => 'trade-fibre',    'icon' => 'wifi'],
        'HVAC'       => ['class' => 'trade-hvac',     'icon' => 'wind'],
        'Appliance'  => ['class' => 'trade-appliance','icon' => 'tv'],
        'Computer'   => ['class' => 'trade-pc',       'icon' => 'monitor'],
        'Cleaning'   => ['class' => 'trade-cleaning', 'icon' => 'sparkles'],
        'Maintenance'=> ['class' => 'trade-maint',    'icon' => 'tool']
    ];
    $cfg = $map[$trade] ?? ['class' => 'trade-default', 'icon' => 'briefcase'];
    return "<span class=\"trade-tag {$cfg['class']}\"><i data-lucide=\"{$cfg['icon']}\" class=\"trade-icon\"></i> {$trade}</span>";
}

/**
 * Set flash alert message in session
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type, // success, error, warning, info
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 */
function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Log an activity to `job_logs`
 */
function log_job_activity(int $job_id, string $action, ?string $notes = null, ?int $user_id = null): void {
    try {
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO job_logs (job_id, user_id, action, notes) VALUES (?, ?, ?, ?)");
        $stmt->execute([$job_id, $user_id, $action, $notes]);
    } catch (Exception $e) {
        // Silently catch to not break primary flow
    }
}

/**
 * Create a system notification
 */
function create_notification(string $title, string $message, string $type = 'status_update', ?string $link = null, ?int $user_id = null): void {
    try {
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $type, $title, $message, $link]);
    } catch (Exception $e) {
        // Silently catch
    }
}

/**
 * Check if a user is currently logged in
 * Must have a valid integer user ID (not a simulation placeholder)
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user'])
        && isset($_SESSION['user']['id'])
        && is_int($_SESSION['user']['id'])
        && $_SESSION['user']['id'] > 0
        && !empty($_SESSION['user']['email']);
}

/**
 * Get current logged in user array
 */
function current_user(): ?array {
    return is_logged_in() ? $_SESSION['user'] : null;
}

/**
 * Enforce authentication — redirects to login if not logged in.
 * Ignores the check only for login/register/logout pages.
 */
function require_auth(): void {
    $publicPages = ['login.php', 'register.php', 'logout.php', 'setup.php', 'customer_portal.php', 'book_service.php'];
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
    if (in_array($currentPage, $publicPages)) return;

    if (!is_logged_in()) {
        // Store intended destination so we can redirect back after login
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

/**
 * Enforce a specific role — redirects to dashboard if user lacks required role.
 */
function require_role(string ...$roles): void {
    require_auth();
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles)) {
        set_flash('error', 'Access denied. You do not have permission to view that page.');
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

/**
 * Log out — completely destroy the session for security
 */
function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
    $_SESSION['_version'] = SESSION_VERSION;
}
