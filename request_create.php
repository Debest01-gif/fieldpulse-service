<?php
$pageTitle = 'New Service Request';
require_once __DIR__ . '/config/db.php';
require_auth();
$db = get_db();

$customers = $db->query("SELECT * FROM customers ORDER BY name ASC")->fetchAll();
$tradeCategories = ['Electrical', 'Solar', 'CCTV', 'Plumbing', 'Fibre', 'HVAC', 'Appliance', 'Computer', 'Cleaning', 'Maintenance'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $assetId = !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
    $tradeCategory = clean($_POST['trade_category'] ?? '');
    $title = clean($_POST['title'] ?? '');
    $description = clean($_POST['description'] ?? '');
    $priority = clean($_POST['priority'] ?? 'normal');
    $preferredDate = !empty($_POST['preferred_date']) ? clean($_POST['preferred_date']) : null;
    $preferredTimeSlot = clean($_POST['preferred_time_slot'] ?? '');

    // Form validation
    if (!$customerId || empty($tradeCategory) || empty($title) || empty($description)) {
        set_flash('error', 'Please fill in all required fields (Customer, Trade, Title, Description).');
    } else {
        // Generate Ticket Number
        $ticketNo = 'REQ-' . date('Y') . '-' . str_pad((string)rand(100, 999), 3, '0', STR_PAD_LEFT);

        $stmt = $db->prepare("
            INSERT INTO service_requests (ticket_no, customer_id, asset_id, trade_category, title, description, priority, status, preferred_date, preferred_time_slot, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?)
        ");
        $stmt->execute([$ticketNo, $customerId, $assetId, $tradeCategory, $title, $description, $priority, $preferredDate, $preferredTimeSlot, $_SESSION['user']['id'] ?? 1]);

        $newId = $db->lastInsertId();
        create_notification("New Service Request", "Request {$ticketNo} received for {$tradeCategory} service.", "request", "requests.php");

        set_flash('success', "Service Request {$ticketNo} logged successfully!");

        // If user wants immediate dispatch
        if (isset($_POST['dispatch_now'])) {
            header("Location: job_create.php?request_id={$newId}");
            exit;
        }

        header("Location: requests.php");
        exit;
    }
}

// Fetch all assets for dynamic client-side filtering
$allAssets = $db->query("SELECT id, customer_id, asset_name, category, brand, model_number FROM customer_assets WHERE status != 'decommissioned'")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Log New Service Request</h1>
        <p class="page-subtitle">Record incoming customer inquiries, service calls, and equipment breakdowns</p>
    </div>
    <div class="page-actions">
        <a href="requests.php" class="btn btn-secondary">
            <i data-lucide="arrow-left"></i> Back to Requests
        </a>
    </div>
</div>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="file-plus"></i> Service Request Details</span>
    </div>
    <form method="POST" action="request_create.php">
        <div class="card-body">
            <!-- Customer Picker -->
            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label class="form-label" style="margin-bottom: 0;">Select Customer <span class="required">*</span></label>
                    <a href="customer_create.php?return_to=request_create.php" style="font-size: 12px; font-weight: 600;">+ Add New Customer</a>
                </div>
                <select name="customer_id" id="customerId" class="form-control" required onchange="filterCustomerAssets()">
                    <option value="">-- Choose Customer --</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['estate_area']) ?> - <?= htmlspecialchars($c['phone']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Customer Installed Asset (Optional) -->
            <div class="form-group">
                <label class="form-label">Related Installed Asset / Equipment (Optional)</label>
                <select name="asset_id" id="assetSelect" class="form-control">
                    <option value="">-- General Site Service (No specific asset) --</option>
                </select>
                <span class="form-hint">Assets registered to the selected customer will populate automatically.</span>
            </div>

            <!-- Trade Category & Priority -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Trade / Service Category <span class="required">*</span></label>
                    <select name="trade_category" class="form-control" required>
                        <option value="">-- Select Trade --</option>
                        <?php foreach ($tradeCategories as $trade): ?>
                            <option value="<?= $trade ?>"><?= $trade ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Priority Level <span class="required">*</span></label>
                    <select name="priority" class="form-control" required>
                        <option value="normal" selected>Normal</option>
                        <option value="urgent">Urgent</option>
                        <option value="emergency">⚡ Emergency (Immediate)</option>
                        <option value="low">Low</option>
                    </select>
                </div>
            </div>

            <!-- Title / Brief Issue -->
            <div class="form-group">
                <label class="form-label">Issue Summary / Title <span class="required">*</span></label>
                <input type="text" name="title" class="form-control" placeholder="e.g. CCTV camera offline in warehouse / Inverter switchover delay" required>
            </div>

            <!-- Detailed Description -->
            <div class="form-group">
                <label class="form-label">Detailed Problem Description <span class="required">*</span></label>
                <textarea name="description" class="form-control" placeholder="Provide full details, symptoms, error codes, site instructions..." rows="4" required></textarea>
            </div>

            <!-- Preferred Schedule -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Preferred Date</label>
                    <input type="date" name="preferred_date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Preferred Time Slot</label>
                    <select name="preferred_time_slot" class="form-control">
                        <option value="Morning (08:00 - 12:00)">Morning (08:00 - 12:00)</option>
                        <option value="Afternoon (12:00 - 16:00)">Afternoon (12:00 - 16:00)</option>
                        <option value="Evening (16:00 - 19:00)">Evening (16:00 - 19:00)</option>
                        <option value="Immediate / Emergency">Immediate / Emergency</option>
                        <option value="Anytime">Anytime / Flexible</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <a href="requests.php" class="btn btn-secondary">Cancel</a>
            <div style="display: flex; gap: 10px;">
                <button type="submit" name="save_only" class="btn btn-secondary">
                    <i data-lucide="save"></i> Save to Queue
                </button>
                <button type="submit" name="dispatch_now" class="btn btn-primary">
                    <i data-lucide="send"></i> Save & Dispatch Technician
                </button>
            </div>
        </div>
    </form>
</div>

<script>
const allAssetsData = <?= json_encode($allAssets) ?>;

function filterCustomerAssets() {
    const customerId = document.getElementById('customerId').value;
    const assetSelect = document.getElementById('assetSelect');

    assetSelect.innerHTML = '<option value="">-- General Site Service (No specific asset) --</option>';

    if (!customerId) return;

    const filtered = allAssetsData.filter(a => a.customer_id == customerId);
    filtered.forEach(a => {
        const opt = document.createElement('option');
        opt.value = a.id;
        opt.textContent = `${a.asset_name} (${a.category} - ${a.brand || ''} ${a.model_number || ''})`;
        assetSelect.appendChild(opt);
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
