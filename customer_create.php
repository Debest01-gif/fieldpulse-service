<?php
$pageTitle = 'Add Customer';
require_once __DIR__ . '/config/db.php';
$db = get_db();

$returnTo = clean($_GET['return_to'] ?? 'customers.php');

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
        set_flash('error', 'Please provide Name, Phone Number, Estate/Area, and Physical Address.');
    } else {
        $stmt = $db->prepare("
            INSERT INTO customers (name, contact_person, phone, alternate_phone, email, estate_area, address, landmark, gps_coords, customer_type, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $contactPerson, $phone, $altPhone, $email, $estateArea, $address, $landmark, $gpsCoords, $customerType, $notes]);
        $newCustomerId = $db->lastInsertId();

        set_flash('success', "Customer {$name} registered successfully!");
        if ($returnTo === 'request_create.php') {
            header("Location: request_create.php");
        } else {
            header("Location: customer_view.php?id={$newCustomerId}");
        }
        exit;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Add New Customer Profile</h1>
        <p class="page-subtitle">Register client details, gate access instructions, and estate location</p>
    </div>
    <div class="page-actions">
        <a href="<?= htmlspecialchars($returnTo) ?>" class="btn btn-secondary">
            <i data-lucide="arrow-left"></i> Back
        </a>
    </div>
</div>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div class="card-header">
        <span class="card-title"><i data-lucide="user-plus"></i> Customer Registration</span>
    </div>
    <form method="POST" action="customer_create.php?return_to=<?= urlencode($returnTo) ?>">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label class="form-label">Customer / Company Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Apex Towers Ltd / Eng. David Kiprop" required>
                </div>

                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Client Type <span class="required">*</span></label>
                    <select name="customer_type" class="form-control" required>
                        <option value="residential" selected>Residential</option>
                        <option value="commercial">Commercial</option>
                        <option value="industrial">Industrial</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Contact Person Name</label>
                    <input type="text" name="contact_person" class="form-control" placeholder="e.g. Facilities Manager / Esther">
                </div>

                <div class="form-group">
                    <label class="form-label">Primary Phone (WhatsApp) <span class="required">*</span></label>
                    <input type="text" name="phone" class="form-control" placeholder="e.g. +254 712 345 678" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Alternate Phone</label>
                    <input type="text" name="alternate_phone" class="form-control" placeholder="e.g. 0722 000 000">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="e.g. client@company.co.ke">
            </div>

            <!-- Location Details -->
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Estate / Town / Area <span class="required">*</span></label>
                    <input type="text" name="estate_area" class="form-control" placeholder="e.g. Kilimani, Argwings Kodhek Rd / Karen" required>
                </div>

                <div class="form-group">
                    <label class="form-label">GPS Coordinates / Google Pin (Optional)</label>
                    <input type="text" name="gps_coords" class="form-control" placeholder="e.g. -1.2921, 36.7856">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Physical Address / House Number / Building <span class="required">*</span></label>
                <textarea name="address" class="form-control" placeholder="e.g. Villa 4B, Acacia Court, James Gichuru Road" rows="2" required></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Landmarks & Gate Access Instructions</label>
                <input type="text" name="landmark" class="form-control" placeholder="e.g. Opposite Yaya Centre, tell security gate you are visiting Unit 12">
            </div>

            <div class="form-group">
                <label class="form-label">Internal Notes</label>
                <textarea name="notes" class="form-control" placeholder="e.g. Customer prefers morning visits only; guard dogs in yard." rows="2"></textarea>
            </div>
        </div>

        <div class="card-footer">
            <a href="<?= htmlspecialchars($returnTo) ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary btn-lg">
                <i data-lucide="save"></i> Save Customer Profile
            </button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
