<?php
$pageTitle = 'Account Settings';
require_once __DIR__ . '/config/db.php';
require_role('admin', 'dispatcher', 'technician'); // any logged-in staff can edit their own account
$db = get_db();

$user    = current_user();
$userId  = $user['id'];

$errors   = [];
$success  = '';

// ─── Handle Profile Update ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name  = trim($_POST['name']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (empty($name))  $errors[] = 'Full name is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if (empty($phone)) $errors[] = 'Phone number is required.';

    // Check email is not taken by another user
    if (empty($errors)) {
        $checkEmail = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $checkEmail->execute([$email, $userId]);
        if ($checkEmail->fetch()) {
            $errors[] = 'That email address is already used by another account.';
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?");
        $stmt->execute([$name, $email, $phone, $userId]);

        // Refresh session
        $_SESSION['user']['name']  = $name;
        $_SESSION['user']['email'] = $email;
        $_SESSION['user']['phone'] = $phone;

        $success = 'profile';
        set_flash('success', 'Your profile has been updated successfully.');
        header('Location: admin_settings.php?tab=profile');
        exit;
    }
}

// ─── Handle Password Change ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $currentPw  = $_POST['current_password']  ?? '';
    $newPw      = $_POST['new_password']       ?? '';
    $confirmPw  = $_POST['confirm_password']   ?? '';

    // Fetch the real stored hash
    $row = $db->prepare("SELECT password FROM users WHERE id = ?");
    $row->execute([$userId]);
    $stored = $row->fetchColumn();

    if (!password_verify($currentPw, $stored)) {
        $errors[] = 'Your current password is incorrect.';
    }
    if (strlen($newPw) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($newPw !== $confirmPw) {
        $errors[] = 'New password and confirmation do not match.';
    }

    if (empty($errors)) {
        $newHash = password_hash($newPw, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")
           ->execute([$newHash, $userId]);
        set_flash('success', 'Password changed successfully! Please use your new password next time you sign in.');
        header('Location: admin_settings.php?tab=password');
        exit;
    }
}

// Re-fetch fresh user data from DB for form display
$freshUser = $db->prepare("SELECT * FROM users WHERE id = ?");
$freshUser->execute([$userId]);
$freshUser = $freshUser->fetch();

$activeTab = $_GET['tab'] ?? 'profile';

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Account Settings</h1>
        <p class="page-subtitle">Manage your personal details, login email, and account password</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="flash-alert flash-error" style="margin-bottom: 20px;">
        <i data-lucide="alert-circle"></i>
        <div>
            <?php foreach ($errors as $e): ?>
                <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Tab Switcher -->
<div style="display:flex; gap:8px; margin-bottom:24px; border-bottom:1px solid var(--border-color); padding-bottom:0;">
    <a href="admin_settings.php?tab=profile"
       style="padding:10px 20px; font-weight:600; font-size:13.5px; text-decoration:none; border-bottom:2px solid <?= $activeTab==='profile' ? 'var(--primary)' : 'transparent' ?>; color:<?= $activeTab==='profile' ? 'var(--primary)' : 'var(--text-muted)' ?>;">
        <i data-lucide="user" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;"></i>Profile
    </a>
    <a href="admin_settings.php?tab=password"
       style="padding:10px 20px; font-weight:600; font-size:13.5px; text-decoration:none; border-bottom:2px solid <?= $activeTab==='password' ? 'var(--primary)' : 'transparent' ?>; color:<?= $activeTab==='password' ? 'var(--primary)' : 'var(--text-muted)' ?>;">
        <i data-lucide="lock" style="width:14px;height:14px;vertical-align:middle;margin-right:6px;"></i>Change Password
    </a>
</div>

<div style="max-width:680px;">

<!-- ═══════════ PROFILE TAB ═══════════ -->
<?php if ($activeTab === 'profile'): ?>
<div class="card">
    <div class="card-header">
        <span class="card-title"><i data-lucide="user"></i> Personal Information</span>
    </div>
    <form method="POST" action="admin_settings.php?tab=profile">
        <div class="card-body">

            <!-- Avatar initials display -->
            <div style="display:flex;align-items:center;gap:18px;margin-bottom:28px;padding:18px;background:var(--bg-subtle);border-radius:12px;border:1px solid var(--border-color);">
                <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#0369a1);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff;font-family:'Outfit',sans-serif;flex-shrink:0;">
                    <?= strtoupper(substr($freshUser['name'], 0, 2)) ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:16px;"><?= htmlspecialchars($freshUser['name']) ?></div>
                    <div style="font-size:12.5px;color:var(--text-muted);margin-top:3px;"><?= ucfirst($freshUser['role']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($freshUser['email']) ?></div>
                    <div style="font-size:11.5px;color:var(--text-light);margin-top:2px;">Member since <?= date('M Y', strtotime($freshUser['created_at'])) ?></div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($freshUser['name']) ?>" required placeholder="Your full name">
                    <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px;">This name appears on job cards and reports.</div>
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Role</label>
                    <input type="text" class="form-control" value="<?= ucfirst(htmlspecialchars($freshUser['role'])) ?>" disabled style="opacity:0.6;cursor:not-allowed;">
                    <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px;">Role can only be changed by a super-admin.</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Login Email <span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($freshUser['email']) ?>" required placeholder="your@email.com">
                    <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px;">Used to sign in and receive system notifications.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone (WhatsApp) <span class="required">*</span></label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($freshUser['phone']) ?>" required placeholder="+254 7XX XXX XXX">
                    <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px;">Used for technician WhatsApp dispatch.</div>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" name="update_profile" class="btn btn-primary">
                <i data-lucide="save"></i> Save Profile
            </button>
        </div>
    </form>
</div>

<!-- ═══════════ PASSWORD TAB ═══════════ -->
<?php elseif ($activeTab === 'password'): ?>
<div class="card">
    <div class="card-header">
        <span class="card-title"><i data-lucide="shield"></i> Change Password</span>
    </div>
    <form method="POST" action="admin_settings.php?tab=password" id="pwForm">
        <div class="card-body">

            <div style="background:rgba(2,132,199,0.08);border:1px solid rgba(56,189,248,0.2);border-radius:10px;padding:14px 16px;margin-bottom:24px;font-size:13px;color:#bae6fd;display:flex;gap:10px;align-items:flex-start;">
                <i data-lucide="info" style="width:16px;height:16px;flex-shrink:0;margin-top:1px;"></i>
                <div>
                    <strong>Security tip:</strong> Use a strong password with at least 8 characters, mixing letters, numbers, and symbols.
                    After saving, your next login will require the new password.
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Current Password <span class="required">*</span></label>
                <div style="position:relative;">
                    <input type="password" name="current_password" id="currentPw" class="form-control" required placeholder="Enter your current password" style="padding-right:42px;">
                    <button type="button" onclick="togglePw('currentPw', this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);" title="Show/hide">
                        <i data-lucide="eye" style="width:16px;height:16px;"></i>
                    </button>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">New Password <span class="required">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="new_password" id="newPw" class="form-control" required placeholder="Min. 8 characters" minlength="8" style="padding-right:42px;" oninput="checkStrength(this.value)">
                        <button type="button" onclick="togglePw('newPw', this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);">
                            <i data-lucide="eye" style="width:16px;height:16px;"></i>
                        </button>
                    </div>
                    <!-- Strength bar -->
                    <div style="margin-top:6px;height:4px;background:var(--border-color);border-radius:4px;overflow:hidden;">
                        <div id="strengthBar" style="height:100%;width:0;background:#f43f5e;transition:all 0.3s ease;border-radius:4px;"></div>
                    </div>
                    <div id="strengthLabel" style="font-size:11px;color:var(--text-muted);margin-top:3px;"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password <span class="required">*</span></label>
                    <div style="position:relative;">
                        <input type="password" name="confirm_password" id="confirmPw" class="form-control" required placeholder="Repeat new password" style="padding-right:42px;">
                        <button type="button" onclick="togglePw('confirmPw', this)" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);">
                            <i data-lucide="eye" style="width:16px;height:16px;"></i>
                        </button>
                    </div>
                    <div id="matchLabel" style="font-size:11px;margin-top:3px;"></div>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button type="submit" name="update_password" class="btn btn-primary">
                <i data-lucide="lock"></i> Update Password
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

</div><!-- /max-width -->

<script>
function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isText = inp.type === 'text';
    inp.type = isText ? 'password' : 'text';
    btn.querySelector('i').setAttribute('data-lucide', isText ? 'eye' : 'eye-off');
    lucide.createIcons();
}

function checkStrength(val) {
    const bar = document.getElementById('strengthBar');
    const lbl = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8)  score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        {w:'0%',   c:'#f43f5e', t:''},
        {w:'25%',  c:'#f43f5e', t:'Weak'},
        {w:'50%',  c:'#f59e0b', t:'Fair'},
        {w:'75%',  c:'#0ea5e9', t:'Good'},
        {w:'100%', c:'#10b981', t:'Strong 💪'},
    ];
    const lvl = levels[score] || levels[0];
    bar.style.width = lvl.w;
    bar.style.background = lvl.c;
    lbl.textContent = lvl.t;
    lbl.style.color = lvl.c;
}

// Live match check
document.getElementById('confirmPw')?.addEventListener('input', function() {
    const match = document.getElementById('matchLabel');
    if (this.value === document.getElementById('newPw').value) {
        match.textContent = '✓ Passwords match';
        match.style.color = '#10b981';
    } else {
        match.textContent = '✗ Passwords do not match';
        match.style.color = '#f43f5e';
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
