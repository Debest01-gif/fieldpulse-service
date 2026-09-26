<?php
/**
 * FieldPulse Kenya - Member & Customer Field Service Booking Portal
 * Book for any field service (Solar, Electrical, CCTV, Plumbing, Fibre, HVAC, Appliance, IT, Cleaning, Maintenance)
 */
require_once __DIR__ . '/config/db.php';
$db = get_db();

$user = current_user();
$preselectedTrade = clean($_GET['trade'] ?? 'Solar');

// If user is logged in, find their customer details
$customerRecord = null;
if ($user) {
    $stmtC = $db->prepare("SELECT * FROM customers WHERE email = ? OR phone = ? OR user_id = ? LIMIT 1");
    $stmtC->execute([$user['email'], $user['phone'], $user['id']]);
    $customerRecord = $stmtC->fetch();
}

$error = '';
$success = '';

// Handle Booking Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean($_POST['name'] ?? ($user['name'] ?? ''));
    $phone = clean($_POST['phone'] ?? ($user['phone'] ?? ''));
    $email = clean($_POST['email'] ?? ($user['email'] ?? ''));
    $estateArea = clean($_POST['estate_area'] ?? '');
    $address = clean($_POST['address'] ?? '');
    $landmark = clean($_POST['landmark'] ?? '');
    $customerType = clean($_POST['customer_type'] ?? 'residential');
    
    $tradeCategory = clean($_POST['trade_category'] ?? 'General');
    $title = clean($_POST['title'] ?? '');
    $description = clean($_POST['description'] ?? '');
    $priority = clean($_POST['priority'] ?? 'normal');
    $preferredDate = !empty($_POST['preferred_date']) ? clean($_POST['preferred_date']) : date('Y-m-d');
    $preferredTimeSlot = clean($_POST['preferred_time_slot'] ?? 'Morning (08:00 - 12:00)');
    
    // Optional Equipment Asset
    $assetName = clean($_POST['asset_name'] ?? '');
    $assetBrand = clean($_POST['asset_brand'] ?? '');
    $assetModel = clean($_POST['asset_model'] ?? '');
    $assetSerial = clean($_POST['asset_serial'] ?? '');

    // Validation
    if (empty($name) || empty($phone) || empty($estateArea) || empty($address) || empty($tradeCategory) || empty($title) || empty($description)) {
        $error = 'Please fill in all mandatory fields (Name, Phone, Location/Estate, Physical Address, Service Category, Issue Title, Description).';
    } else {
        try {
            // 1. Find or create customer record
            $customerId = null;
            if ($customerRecord) {
                $customerId = $customerRecord['id'];
                // Update customer address and area if changed
                $stmtUpCust = $db->prepare("UPDATE customers SET name = ?, phone = ?, estate_area = ?, address = ?, landmark = ?, customer_type = ?, user_id = ? WHERE id = ?");
                $stmtUpCust->execute([$name, $phone, $estateArea, $address, $landmark, $customerType, $user['id'] ?? null, $customerId]);
            } else {
                $stmtFind = $db->prepare("SELECT id FROM customers WHERE phone = ? OR (email != '' AND email = ?) LIMIT 1");
                $stmtFind->execute([$phone, $email]);
                $existingCust = $stmtFind->fetch();

                if ($existingCust) {
                    $customerId = $existingCust['id'];
                    $stmtUpCust = $db->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, estate_area = ?, address = ?, landmark = ?, customer_type = ?, user_id = ? WHERE id = ?");
                    $stmtUpCust->execute([$name, $phone, $email, $estateArea, $address, $landmark, $customerType, $user['id'] ?? null, $customerId]);
                } else {
                    $stmtInsCust = $db->prepare("INSERT INTO customers (user_id, name, contact_person, phone, email, estate_area, address, landmark, customer_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmtInsCust->execute([$user['id'] ?? null, $name, $name, $phone, $email, $estateArea, $address, $landmark, $customerType]);
                    $customerId = $db->lastInsertId();
                }
            }

            // 2. Insert Asset if specified
            $assetId = null;
            if (!empty($assetName)) {
                $stmtAsset = $db->prepare("INSERT INTO customer_assets (customer_id, asset_name, category, brand, model_number, serial_number, install_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmtAsset->execute([$customerId, $assetName, $tradeCategory, $assetBrand, $assetModel, $assetSerial, date('Y-m-d')]);
                $assetId = $db->lastInsertId();
            }

            // 3. Generate Ticket Number
            $ticketNo = 'REQ-' . date('Y') . '-' . str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);

            // 4. Insert Service Request
            $stmtReq = $db->prepare("
                INSERT INTO service_requests (
                    ticket_no, customer_id, asset_id, trade_category, title, description,
                    priority, status, preferred_date, preferred_time_slot, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?)
            ");
            $stmtReq->execute([
                $ticketNo, $customerId, $assetId, $tradeCategory, $title, $description,
                $priority, $preferredDate, $preferredTimeSlot, $user['id'] ?? null
            ]);
            $newReqId = $db->lastInsertId();

            // 5. Send dispatch notifications
            create_notification(
                "New Service Request Booked",
                "Customer {$name} booked a {$tradeCategory} service ({$ticketNo}: {$title}) in {$estateArea}.",
                "request",
                "requests.php"
            );

            set_flash('success', "🎉 Your service booking has been confirmed! Tracking Ticket # {$ticketNo} generated.");
            header("Location: customer_portal.php?ticket={$ticketNo}");
            exit;

        } catch (Exception $e) {
            $error = "Error saving booking: " . $e->getMessage();
        }
    }
}

// Field Services List
$tradeOptions = [
    'Solar'       => ['name' => 'Solar & Power Inverters', 'icon' => 'sun', 'desc' => 'Deye, Growatt & Victron hybrid inverters, solar panels, battery banks & solar water heaters.'],
    'Electrical'  => ['name' => 'Electrical Engineering & Wiring', 'icon' => 'zap', 'desc' => 'House wiring, circuit breakers, generator ATS panels, surge protectors, lighting.'],
    'CCTV'        => ['name' => 'CCTV & Security Systems', 'icon' => 'video', 'desc' => 'IP cameras, NVR/DVR setups, access control, biometrics, alarms.'],
    'Plumbing'    => ['name' => 'Plumbing & Water Heaters', 'icon' => 'droplet', 'desc' => 'Booster pumps, solar water heating systems, pipe leak repairs, PPR piping.'],
    'Fibre'       => ['name' => 'Fibre, Mesh WiFi & Cabling', 'icon' => 'wifi', 'desc' => 'UniFi WiFi 6 mesh, optical fibre terminations, CAT6 structured cabling.'],
    'HVAC'        => ['name' => 'HVAC & Air Conditioning', 'icon' => 'wind', 'desc' => 'Server room AC, split units, refrigerant gas recharge (R410A), VRF chillers.'],
    'Appliance'   => ['name' => 'Appliance Repair', 'icon' => 'tv', 'desc' => 'Washing machines, commercial/domestic ovens, refrigerators, microwaves.'],
    'Computer'    => ['name' => 'Computer Hardware & IT', 'icon' => 'monitor', 'desc' => 'Desktop PCs, servers, POS systems, printers, network backups.'],
    'Cleaning'    => ['name' => 'Cleaning & Hygiene', 'icon' => 'sparkles', 'desc' => 'Deep cleaning, post-construction cleaning, carpet/sofa steam wash, fumigation.'],
    'Maintenance' => ['name' => 'Facility Maintenance & Handyman', 'icon' => 'wrench', 'desc' => 'Welding, locks, ceiling repair, painting, handyman services.']
];

// Kenyan Estates for suggestions
$kenyanEstates = [
    'Kilimani, Nairobi', 'Karen, Nairobi', 'Westlands, Nairobi', 'Lavington, Nairobi',
    'Runda, Nairobi', 'Kileleshwa, Nairobi', 'Upper Hill, Nairobi', 'Parklands, Nairobi',
    'South C, Nairobi', 'South B, Nairobi', 'Langata, Nairobi', 'Industrial Area, Nairobi',
    'Syokimau, Machakos', 'Kitengela, Kajiado', 'Ongata Rongai, Kajiado', 'Kiambu Road, Kiambu',
    'Ruiru, Kiambu', 'Thika Road, Nairobi', 'Mombasa Road, Nairobi', 'Gigiri, Nairobi',
    'Muthaiga, Nairobi', 'Spring Valley, Nairobi', 'Kitisuru, Nairobi', 'Ngong, Kajiado'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Field Service - FieldPulse Kenya</title>
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
        }

        body {
            background-color: var(--portal-bg);
            color: #f8fafc;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }

        .booking-nav {
            background: rgba(19, 29, 49, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--portal-border);
            position: sticky;
            top: 0;
            z-index: 100;
            padding: 14px 24px;
        }
        .booking-nav-inner {
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .booking-container {
            max-width: 920px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .booking-header {
            text-align: center;
            margin-bottom: 32px;
        }
        .booking-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(2, 132, 199, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.3);
            color: #38bdf8;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        .booking-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 8px;
        }
        .booking-header p {
            color: #94a3b8;
            font-size: 15px;
            max-width: 580px;
            margin: 0 auto;
        }

        .booking-form-card {
            background: var(--portal-card);
            border: 1px solid var(--portal-border);
            border-radius: 24px;
            padding: 32px 36px;
            box-shadow: 0 20px 50px -15px rgba(0, 0, 0, 0.5);
        }

        @media (max-width: 600px) {
            .booking-form-card { padding: 22px 18px; }
            .booking-header h2 { font-size: 26px; }
        }

        .form-section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 17px;
            font-weight: 800;
            color: #38bdf8;
            margin: 24px 0 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-section-title:first-of-type {
            margin-top: 0;
        }

        /* Trade Selector Grid */
        .trades-picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .trade-radio-card {
            position: relative;
            cursor: pointer;
        }
        .trade-radio-card input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .trade-card-content {
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            padding: 14px 12px;
            text-align: center;
            transition: all 0.2s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .trade-card-content i {
            color: #94a3b8;
            transition: all 0.2s ease;
        }
        .trade-card-content span {
            font-size: 13px;
            font-weight: 700;
            color: #cbd5e1;
        }
        .trade-radio-card:hover .trade-card-content {
            border-color: rgba(56, 189, 248, 0.4);
            background: rgba(15, 23, 42, 0.95);
        }
        .trade-radio-card input:checked + .trade-card-content {
            background: rgba(2, 132, 199, 0.18);
            border-color: #38bdf8;
            box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.3);
        }
        .trade-radio-card input:checked + .trade-card-content i {
            color: #38bdf8;
            transform: scale(1.15);
        }
        .trade-radio-card input:checked + .trade-card-content span {
            color: #fff;
        }

        /* Form Inputs */
        .portal-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #cbd5e1;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .portal-label .req {
            color: #f43f5e;
        }
        .portal-input {
            width: 100%;
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            padding: 12px 14px;
            color: #fff;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
        }
        .portal-input:focus {
            outline: none;
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
            background: #090d16;
        }
        .portal-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        @media (max-width: 600px) {
            .portal-form-row { grid-template-columns: 1fr; }
        }

        .btn-submit-booking {
            width: 100%;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff;
            border: none;
            padding: 16px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 800;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.45);
            margin-top: 24px;
        }
        .btn-submit-booking:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(2, 132, 199, 0.6);
        }

        .error-banner {
            background: rgba(225, 29, 72, 0.15);
            border: 1px solid rgba(225, 29, 72, 0.3);
            color: #fca5a5;
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 13.5px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
    </style>
</head>
<body>

<header class="booking-nav">
    <div class="booking-nav-inner">
        <a href="customer_portal.php" class="portal-brand">
            <div class="portal-brand-icon">
                <i data-lucide="zap" style="width: 22px; height: 22px;"></i>
            </div>
            <div class="portal-brand-text">
                <h1>FieldPulse</h1>
                <span>Operations Kenya</span>
            </div>
        </a>

        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="customer_portal.php" class="btn btn-secondary btn-sm" style="font-size: 12.5px;">
                <i data-lucide="activity" style="width: 14px; height: 14px;"></i> Track Request
            </a>
            <?php if ($user): ?>
                <a href="index.php" class="btn btn-secondary btn-sm" style="font-size: 12.5px;">
                    <i data-lucide="layout-dashboard" style="width: 14px; height: 14px;"></i> Dashboard
                </a>
            <?php else: ?>
                <a href="login.php" class="btn btn-secondary btn-sm" style="font-size: 12.5px;">
                    <i data-lucide="user" style="width: 14px; height: 14px;"></i> Sign In
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="booking-container">

    <div class="booking-header">
        <div class="booking-badge">
            <i data-lucide="sparkles" style="width: 14px; height: 14px;"></i> Field Service Dispatch
        </div>
        <h2>Book a Field Specialist</h2>
        <p>Submit your service request. Our dispatch desk connects a certified technician to your site with direct contact numbers and live arrival tracking.</p>
    </div>

    <div class="booking-form-card">

        <?php if (!empty($error)): ?>
            <div class="error-banner">
                <i data-lucide="alert-circle" style="width: 20px; height: 20px; flex-shrink: 0; color: #f43f5e;"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="book_service.php">

            <!-- 1. Service Category Selection -->
            <div class="form-section-title">
                <i data-lucide="briefcase"></i> 1. Select Field Service Category
            </div>

            <div class="trades-picker-grid">
                <?php foreach ($tradeOptions as $code => $tr): ?>
                    <label class="trade-radio-card">
                        <input type="radio" name="trade_category" value="<?= $code ?>" <?= ($preselectedTrade === $code || (empty($preselectedTrade) && $code === 'Solar')) ? 'checked' : '' ?> required>
                        <div class="trade-card-content">
                            <i data-lucide="<?= $tr['icon'] ?>" style="width: 26px; height: 26px;"></i>
                            <span><?= htmlspecialchars($code) ?></span>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>

            <!-- 2. Customer & Contact Details -->
            <div class="form-section-title">
                <i data-lucide="user"></i> 2. Contact & Site Details
            </div>

            <div class="portal-form-row">
                <div>
                    <label class="portal-label">Full Name / Company <span class="req">*</span></label>
                    <input type="text" name="name" class="portal-input" placeholder="e.g. Dr. Grace Kariuki or Apex Towers" value="<?= htmlspecialchars($_POST['name'] ?? ($customerRecord['name'] ?? ($user['name'] ?? ''))) ?>" required>
                </div>

                <div>
                    <label class="portal-label">Phone Number (WhatsApp) <span class="req">*</span></label>
                    <input type="text" name="phone" class="portal-input" placeholder="e.g. 0721445566 or +254 721 445 566" value="<?= htmlspecialchars($_POST['phone'] ?? ($customerRecord['phone'] ?? ($user['phone'] ?? ''))) ?>" required>
                </div>
            </div>

            <div class="portal-form-row">
                <div>
                    <label class="portal-label">Email Address</label>
                    <input type="email" name="email" class="portal-input" placeholder="e.g. client@gmail.com" value="<?= htmlspecialchars($_POST['email'] ?? ($customerRecord['email'] ?? ($user['email'] ?? ''))) ?>">
                </div>

                <div>
                    <label class="portal-label">Premises Type <span class="req">*</span></label>
                    <select name="customer_type" class="portal-input">
                        <option value="residential" <?= ($_POST['customer_type'] ?? ($customerRecord['customer_type'] ?? 'residential')) === 'residential' ? 'selected' : '' ?>>Residential (Home / Villa / Apartment)</option>
                        <option value="commercial" <?= ($_POST['customer_type'] ?? ($customerRecord['customer_type'] ?? '')) === 'commercial' ? 'selected' : '' ?>>Commercial (Office / Retail / Mall)</option>
                        <option value="industrial" <?= ($_POST['customer_type'] ?? ($customerRecord['customer_type'] ?? '')) === 'industrial' ? 'selected' : '' ?>>Industrial (Factory / Warehouse / Plant)</option>
                    </select>
                </div>
            </div>

            <div class="portal-form-row">
                <div>
                    <label class="portal-label">Estate / Area in Kenya <span class="req">*</span></label>
                    <input type="text" name="estate_area" list="estatesList" class="portal-input" placeholder="e.g. Karen, Miotoni Road / Kilimani" value="<?= htmlspecialchars($_POST['estate_area'] ?? ($customerRecord['estate_area'] ?? '')) ?>" required>
                    <datalist id="estatesList">
                        <?php foreach ($kenyanEstates as $est): ?>
                            <option value="<?= $est ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div>
                    <label class="portal-label">Gate / Landmark Access Notes</label>
                    <input type="text" name="landmark" class="portal-input" placeholder="e.g. Gate 4, opposite Yaya Centre, black gate" value="<?= htmlspecialchars($_POST['landmark'] ?? ($customerRecord['landmark'] ?? '')) ?>">
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label class="portal-label">Physical Address / House # / Building <span class="req">*</span></label>
                <input type="text" name="address" class="portal-input" placeholder="e.g. House 14, Miotoni Ridge Gated Estate or Apex Towers 5th Floor" value="<?= htmlspecialchars($_POST['address'] ?? ($customerRecord['address'] ?? '')) ?>" required>
            </div>

            <!-- 3. Service Issue & Problem Details -->
            <div class="form-section-title">
                <i data-lucide="wrench"></i> 3. Problem Description & Service Request
            </div>

            <div style="margin-bottom: 16px;">
                <label class="portal-label">Issue Summary / Title <span class="req">*</span></label>
                <input type="text" name="title" class="portal-input" placeholder="e.g. Solar inverter tripping error F58 / AC leaking water in server room" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
            </div>

            <div style="margin-bottom: 16px;">
                <label class="portal-label">Detailed Symptoms & Work Needed <span class="req">*</span></label>
                <textarea name="description" class="portal-input" rows="4" placeholder="Describe the fault, symptoms, error codes, when it happens, or what installation/servicing is required..." required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            </div>

            <!-- Optional Equipment Asset -->
            <details style="margin-bottom: 20px; background: rgba(0,0,0,0.25); padding: 14px 16px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.06);">
                <summary style="font-weight: 700; color: #38bdf8; font-size: 13.5px; cursor: pointer;">
                    + Specify Equipment / Appliance Details (Optional)
                </summary>
                <div style="margin-top: 14px;">
                    <div class="portal-form-row">
                        <div>
                            <label class="portal-label">Equipment / Appliance Name</label>
                            <input type="text" name="asset_name" class="portal-input" placeholder="e.g. Deye 10kW Hybrid Inverter / Samsung 14HP AC" value="<?= htmlspecialchars($_POST['asset_name'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="portal-label">Brand / Manufacturer</label>
                            <input type="text" name="asset_brand" class="portal-input" placeholder="e.g. Deye, Solahart, Hikvision, Carrier" value="<?= htmlspecialchars($_POST['asset_brand'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="portal-form-row" style="margin-bottom: 0;">
                        <div>
                            <label class="portal-label">Model Number</label>
                            <input type="text" name="asset_model" class="portal-input" placeholder="e.g. SUN-10K-SG04LP3" value="<?= htmlspecialchars($_POST['asset_model'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="portal-label">Serial Number</label>
                            <input type="text" name="asset_serial" class="portal-input" placeholder="e.g. SN-99882" value="<?= htmlspecialchars($_POST['asset_serial'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </details>

            <!-- 4. Schedule & Urgency -->
            <div class="form-section-title">
                <i data-lucide="calendar"></i> 4. Preferred Date & Urgency
            </div>

            <div class="portal-form-row">
                <div>
                    <label class="portal-label">Preferred Service Date <span class="req">*</span></label>
                    <input type="date" name="preferred_date" class="portal-input" min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['preferred_date'] ?? date('Y-m-d')) ?>" required>
                </div>

                <div>
                    <label class="portal-label">Preferred Time Slot <span class="req">*</span></label>
                    <select name="preferred_time_slot" class="portal-input">
                        <option value="Morning (08:00 - 12:00)">Morning (08:00 - 12:00)</option>
                        <option value="Afternoon (12:00 - 16:00)">Afternoon (12:00 - 16:00)</option>
                        <option value="Evening (16:00 - 19:00)">Evening (16:00 - 19:00)</option>
                        <option value="Immediate / Emergency">⚡ Immediate / Emergency Dispatch</option>
                        <option value="Flexible / Anytime">Flexible / Anytime Today</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label class="portal-label">Urgency Level <span class="req">*</span></label>
                <select name="priority" class="portal-input">
                    <option value="normal" selected>Normal (Standard Scheduled Service)</option>
                    <option value="urgent">Urgent (Service within 2-4 hours)</option>
                    <option value="emergency">⚡ Emergency (Immediate Critical Dispatch)</option>
                    <option value="low">Low (Routine maintenance check)</option>
                </select>
            </div>

            <button type="submit" class="btn-submit-booking">
                <i data-lucide="check-circle-2" style="width: 20px; height: 20px;"></i>
                Confirm Booking & Dispatch Technician
            </button>
        </form>

    </div>

</div>

<script>
    if (window.lucide) {
        lucide.createIcons();
    }
</script>
</body>
</html>
