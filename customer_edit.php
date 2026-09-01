<?php
$pageTitle = 'Edit Customer Profile';
require_once __DIR__ . '/config/db.php';
$db = get_db();

$customerId = (int)($_GET['id'] ?? 0);
if ($customerId <= 0) {
    set_flash('error', 'Invalid Customer ID.');
    header('Location: customers.php');
    exit;
}

// Fetch Customer
$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();
if (!$customer) {
    set_flash('error', 'Customer not found.');
    header('Location: customers.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean($_POST['name'] ?? '');
    $contactPerson = clean($_POST['contact_person'] ?? '');
    $phone = clean($_POST['phone'] ?? '');
    $altPhone = clean($_POST['alternate_phone'] ?? '');
    $email = clean($_POST['email'] ?? '');
    $estateArea = clean($_POST['estate_area'] ?? '');
    $address = clean($_POST['address'] ?? '');
    $landmark = clean($_POST['landmark'] ?? '');
    $gpsCoords = clean($_POST['gps_coords'] ?? '');
    $customerType = clean($_POST['customer_type'] ?? 'residential');
    $notes = clean($_POST['notes'] ?? '');

    if (empty($name) || empty($phone) || empty($estateArea) || empty($address)) {
        set_flash('error', 'Please fill in Name, Phone, Estate/Area, and Physical Address.');
    } else {
        $stmtUpdate = $db->prepare("
            UPDATE customers 
            SET name = ?, contact_person = ?, phone = ?, alternate_phone = ?, email = ?, 
                estate_area = ?, address = ?, landmark = ?, gps_coords = ?, customer_type = ?, notes = ?
            WHERE id = ?
        ");
        $stmtUpdate->execute([$name, $contactPerson, $phone, $altPhone, $email, $estateArea, $address, $landmark, $gpsCoords, $customerType, $notes, $customerId]);

        set_flash('success', "Customer profile '{$name}' updated successfully.");
        header("Location: customer_view.php?id={$customerId}");
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Edit Customer Profile</h1>
        <p class="page-subtitle">Update contact details, site address, and gate access information</p>
    </div>
    <div class="page-actions">
        <a href="customer_view.php?id=<?= $customerId ?>" class="btn btn-secondary">
            <i data-lucide="arrow-left"></i> Cancel / Back to Profile
        </a>
    </div>
</div>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="edit"></i> Edit <?= htmlspecialchars($customer['name']) ?></span>
    </div>
    <form method="POST" action="customer_edit.php?id=<?= $customerId ?>">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label class="form-label">Customer / Company Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($customer['name']) ?>" required>
                </div>

                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Client Type <span class="required">*</span></label>
                    <select name="customer_type" class="form-control" required>
                        <option value="residential" <?= $customer['customer_type'] === 'residential' ? 'selected' : '' ?>>Residential</option>
                        <option value="commercial" <?= $customer['customer_type'] === 'commercial' ? 'selected' : '' ?>>Commercial</option>
                        <option value="industrial" <?= $customer['customer_type'] === 'industrial' ? 'selected' : '' ?>>Industrial</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Contact Person Name</label>
                    <input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($customer['contact_person'] ?? '') ?>" placeholder="e.g. Facilities Manager">
                </div>

                <div class="form-group">
                    <label class="form-label">Primary Phone (WhatsApp) <span class="required">*</span></label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone']) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Alternate Phone</label>
                    <input type="text" name="alternate_phone" class="form-control" value="<?= htmlspecialchars($customer['alternate_phone'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($customer['email'] ?? '') ?>">
            </div>

            <!-- Location Details -->
            <div style="background: var(--bg-subtle); padding: 18px; border-radius: var(--radius-md); margin-bottom: 20px; border: 1px solid var(--border-color);">
                <div style="font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="map-pin" style="width: 16px; height: 16px; color: var(--primary);"></i>
                    Site Location & Physical Address
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Estate / Neighborhood Area <span class="required">*</span></label>
                        <input type="text" name="estate_area" class="form-control" value="<?= htmlspecialchars($customer['estate_area']) ?>" required>
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">Prominent Landmark</label>
                        <input type="text" name="landmark" class="form-control" value="<?= htmlspecialchars($customer['landmark'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Detailed Physical Address (House/Floor/Gate) <span class="required">*</span></label>
                    <textarea name="address" class="form-control" rows="2" required><?= htmlspecialchars($customer['address']) ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">GPS Coordinates (Latitude, Longitude)</label>
                    <input type="text" name="gps_coords" class="form-control" value="<?= htmlspecialchars($customer['gps_coords'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Access Notes / Security Instructions</label>
                <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($customer['notes'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center;">
            <a href="customer_view.php?id=<?= $customerId ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <i data-lucide="save"></i> Update Customer Profile
            </button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
