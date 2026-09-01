<?php
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

$jobId = (int)($_GET['id'] ?? 0);
if ($jobId <= 0) die("Invalid Job ID");

$stmt = $db->prepare("
    SELECT j.*, 
           c.name as customer_name, c.phone as customer_phone, c.alternate_phone, c.email as customer_email,
           c.estate_area, c.address as customer_address, c.landmark,
           u.name as tech_name, u.phone as tech_phone, u.trade_skills as tech_skills,
           a.asset_name, a.brand as asset_brand, a.model_number as asset_model, a.serial_number as asset_serial
    FROM job_cards j
    JOIN customers c ON j.customer_id = c.id
    JOIN users u ON j.technician_id = u.id
    LEFT JOIN customer_assets a ON j.asset_id = a.id
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();
if (!$job) die("Job Card not found.");

// Parts Used
$partsUsed = $db->prepare("
    SELECT u.*, p.part_code, p.part_name, p.unit, p.category as part_category
    FROM job_parts_used u
    JOIN inventory_parts p ON u.part_id = p.id
    WHERE u.job_id = ?
");
$partsUsed->execute([$jobId]);
$parts = $partsUsed->fetchAll();

// Photos
$photos = $db->prepare("SELECT * FROM job_photos WHERE job_id = ?");
$photos->execute([$jobId]);
$jobPhotos = $photos->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Job Sheet - <?= htmlspecialchars($job['job_number']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            color: #0f172a;
            background: #fff;
            padding: 30px;
            font-size: 13px;
            line-height: 1.5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #e2e8f0;
            padding: 30px;
            border-radius: 8px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .brand h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 26px;
            color: #0284c7;
            font-weight: 800;
        }
        .brand p {
            color: #64748b;
            font-size: 12px;
        }
        .job-meta {
            text-align: right;
        }
        .job-number {
            font-family: 'JetBrains Mono', monospace;
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }
        .status-tag {
            display: inline-block;
            background: #0284c7;
            color: #fff;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 4px;
        }
        .section-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 14px;
        }
        .info-box-title {
            font-weight: 700;
            color: #0284c7;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 4px;
        }
        .info-row {
            margin-bottom: 4px;
            display: flex;
        }
        .info-label {
            width: 110px;
            color: #64748b;
            font-size: 12px;
        }
        .info-val {
            flex: 1;
            font-weight: 600;
        }
        .section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin: 20px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e2e8f0;
        }
        .work-box {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 14px;
            min-height: 50px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 12px;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 8px 12px;
            text-align: left;
        }
        th {
            background: #f1f5f9;
            font-weight: 700;
        }
        .signature-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed #cbd5e1;
        }
        .sig-block {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 14px;
            background: #fafafa;
            text-align: center;
        }
        .sig-img {
            max-height: 70px;
            max-width: 100%;
            display: block;
            margin: 8px auto;
        }
        @media print {
            body { padding: 0; }
            .container { border: none; padding: 0; max-width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="no-print" style="max-width: 800px; margin: 0 auto 16px; display: flex; justify-content: space-between; align-items: center;">
    <a href="job_view.php?id=<?= $job['id'] ?>" style="color: #0284c7; text-decoration: none; font-weight: 600;">← Back to Job View</a>
    <button onclick="window.print()" style="background: #0284c7; color: white; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 600; cursor: pointer;">
        🖨️ Print Job Sheet / Save as PDF
    </button>
</div>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="brand">
            <h1>FieldPulse Kenya</h1>
            <p>Field Service Operations & Maintenance Work Order</p>
            <p style="font-size: 11px; color: #94a3b8; margin-top: 2px;">Nairobi, Kenya • info@fieldpulse.co.ke</p>
        </div>
        <div class="job-meta">
            <div class="job-number"><?= htmlspecialchars($job['job_number']) ?></div>
            <div class="status-tag"><?= strtoupper(str_replace('_', ' ', $job['status'])) ?></div>
            <div style="font-size: 11.5px; color: #64748b; margin-top: 4px;">
                Date: <?= format_date($job['scheduled_date']) ?>
            </div>
        </div>
    </div>

    <!-- 2 Column Client & Tech Grid -->
    <div class="section-grid">
        <div class="info-box">
            <div class="info-box-title">Customer & Site Location</div>
            <div class="info-row">
                <span class="info-label">Customer:</span>
                <span class="info-val"><?= htmlspecialchars($job['customer_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Phone:</span>
                <span class="info-val"><?= htmlspecialchars($job['customer_phone']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Estate / Area:</span>
                <span class="info-val"><?= htmlspecialchars($job['estate_area']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Site Address:</span>
                <span class="info-val"><?= htmlspecialchars($job['customer_address']) ?></span>
            </div>
            <?php if ($job['landmark']): ?>
                <div class="info-row">
                    <span class="info-label">Landmark:</span>
                    <span class="info-val"><?= htmlspecialchars($job['landmark']) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="info-box">
            <div class="info-box-title">Job & Technician Details</div>
            <div class="info-row">
                <span class="info-label">Technician:</span>
                <span class="info-val"><?= htmlspecialchars($job['tech_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Tech Phone:</span>
                <span class="info-val"><?= htmlspecialchars($job['tech_phone']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Trade:</span>
                <span class="info-val"><?= htmlspecialchars($job['trade_category']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Scheduled:</span>
                <span class="info-val"><?= htmlspecialchars($job['scheduled_time']) ?></span>
            </div>
            <?php if ($job['asset_name']): ?>
                <div class="info-row">
                    <span class="info-label">Asset Serviced:</span>
                    <span class="info-val"><?= htmlspecialchars($job['asset_name']) ?> (<?= htmlspecialchars($job['asset_serial'] ?: 'N/A') ?>)</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Job Objective -->
    <div class="section-title">1. Job Objective & Initial Instructions</div>
    <div class="work-box">
        <strong><?= htmlspecialchars($job['title']) ?></strong><br>
        <?= nl2br(htmlspecialchars($job['job_description'] ?: 'None provided.')) ?>
    </div>

    <!-- On-Site Diagnosis & Work Done -->
    <div class="section-title">2. Technician Diagnosis & Work Performed</div>
    <div class="work-box">
        <div style="margin-bottom: 8px;">
            <strong style="color: #0284c7;">Diagnosis / Fault Found:</strong><br>
            <?= nl2br(htmlspecialchars($job['diagnosis'] ?: 'Diagnosis not recorded.')) ?>
        </div>
        <div>
            <strong style="color: #0284c7;">Corrective Work Done:</strong><br>
            <?= nl2br(htmlspecialchars($job['work_performed'] ?: 'Work performed not recorded.')) ?>
        </div>
    </div>

    <!-- Parts & Materials Used -->
    <div class="section-title">3. Parts & Materials Consumed on Job</div>
    <table>
        <thead>
            <tr>
                <th style="width: 120px;">Part Code</th>
                <th>Description</th>
                <th style="width: 80px;">Quantity</th>
                <th>Notes / Application</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($parts)): ?>
                <tr><td colspan="4" style="text-align: center; color: #64748b; padding: 12px;">No parts recorded for this job.</td></tr>
            <?php else: ?>
                <?php foreach ($parts as $p): ?>
                    <tr>
                        <td style="font-family: 'JetBrains Mono', monospace; font-weight: 600;"><?= htmlspecialchars($p['part_code']) ?></td>
                        <td><?= htmlspecialchars($p['part_name']) ?></td>
                        <td><?= $p['quantity'] ?> <?= htmlspecialchars($p['unit']) ?></td>
                        <td><?= htmlspecialchars($p['notes'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Signatures Block -->
    <div class="signature-section">
        <div class="sig-block">
            <div style="font-weight: 700; font-size: 11px; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Field Technician</div>
            <div style="height: 60px; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #0284c7;">
                <?= htmlspecialchars($job['tech_name']) ?>
            </div>
            <div style="border-top: 1px solid #cbd5e1; padding-top: 4px; font-size: 11.5px;">
                Technician Signature & Stamp
            </div>
        </div>

        <div class="sig-block">
            <div style="font-weight: 700; font-size: 11px; text-transform: uppercase; color: #64748b; margin-bottom: 6px;">Customer Confirmation & Sign-Off</div>
            <?php if ($job['signature_data']): ?>
                <img src="<?= $job['signature_data'] ?>" alt="Signature" class="sig-img">
                <div style="font-size: 11px; color: #0f172a; font-weight: 600;"><?= htmlspecialchars($job['signed_by_name']) ?></div>
            <?php else: ?>
                <div style="height: 60px; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-style: italic;">
                    Pending Customer Signature
                </div>
            <?php endif; ?>
            <div style="border-top: 1px solid #cbd5e1; padding-top: 4px; font-size: 11.5px;">
                Customer Signature & Date (<?= format_date($job['signed_at'] ?? $job['scheduled_date']) ?>)
            </div>
        </div>
    </div>
</div>

</body>
</html>
