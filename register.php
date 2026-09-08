<?php
require_once __DIR__ . '/config/db.php';

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = clean($_POST['name'] ?? '');
    $email = clean($_POST['email'] ?? '');
    $phone = clean($_POST['phone'] ?? '');
    $role = clean($_POST['role'] ?? 'member');
    $skills = clean($_POST['trade_skills'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        $error = 'Please fill in all mandatory fields (Name, Email, Phone, Password).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 4) {
        $error = 'Password must be at least 4 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $db = get_db();

        // Check if email already registered
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            $error = "An account with the email '{$email}' is already registered. Please sign in.";
        } else {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            // Default skills if left empty
            if (empty($skills)) {
                $skills = ($role === 'technician') ? 'General Maintenance' : 'Operations & Support';
            }

            $stmt = $db->prepare("
                INSERT INTO users (name, email, phone, password, role, trade_skills, status)
                VALUES (?, ?, ?, ?, ?, ?, 'available')
            ");
            $stmt->execute([$name, $email, $phone, $passwordHash, $role, $skills]);
            $newUserId = $db->lastInsertId();

            // Auto-login new member
            $_SESSION['user'] = [
                'id' => $newUserId,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'phone' => $phone,
                'trade_skills' => $skills,
                'avatar' => ''
            ];

            create_notification("New Account Registered", "User {$name} joined as {$role}.", "user", "technicians.php");

            set_flash('success', "Account created successfully! Welcome to FieldPulse Kenya, {$name}.");
            header("Location: " . BASE_URL . "/index.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Registration - FieldPulse Kenya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            background: radial-gradient(circle at top right, #0f172a, #020617);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: 'Inter', sans-serif;
            color: #f8fafc;
        }
        .auth-container {
            width: 100%;
            max-width: 520px;
        }
        .auth-brand {
            text-align: center;
            margin-bottom: 24px;
        }
        .brand-badge-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 10px 25px -5px rgba(2, 132, 199, 0.5);
            margin-bottom: 10px;
        }
        .auth-title {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 800;
            color: #fff;
        }
        .auth-subtitle {
            font-size: 13px;
            color: #94a3b8;
            margin-top: 4px;
        }
        .auth-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 30px 26px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
        }
        .auth-input-group {
            margin-bottom: 16px;
        }
        .auth-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        @media (max-width: 480px) {
            .auth-row { grid-template-columns: 1fr; }
        }
        .auth-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .auth-input {
            width: 100%;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            padding: 11px 13px;
            color: #fff;
            font-size: 13.5px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
        }
        .auth-input:focus {
            outline: none;
            border-color: #38bdf8;
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2);
            background: rgba(15, 23, 42, 1);
        }
        .btn-auth {
            width: 100%;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff;
            border: none;
            padding: 13px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.4);
            margin-top: 10px;
        }
        .btn-auth:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.55);
        }
        .auth-footer-links {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: #94a3b8;
        }
        .auth-footer-links a {
            color: #38bdf8;
            text-decoration: none;
            font-weight: 600;
        }
        .auth-footer-links a:hover {
            text-decoration: underline;
        }
        .error-alert {
            background: rgba(225, 29, 72, 0.15);
            border: 1px solid rgba(225, 29, 72, 0.3);
            color: #fca5a5;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
    </style>
</head>
<body>

<div class="auth-container">
    <div class="auth-brand">
        <div class="brand-badge-icon">
            <i data-lucide="user-plus" style="width: 26px; height: 26px;"></i>
        </div>
        <h1 class="auth-title">Member Registration</h1>
        <p class="auth-subtitle">Create your FieldPulse Kenya account</p>
    </div>

    <div class="auth-card">
        <?php if (!empty($error)): ?>
            <div class="error-alert">
                <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0; color: #f43f5e;"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <div class="auth-input-group">
                <label class="auth-label">Full Name <span style="color:#f43f5e;">*</span></label>
                <input type="text" name="name" class="auth-input" placeholder="e.g. Samuel Njuguna" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required autofocus>
            </div>

            <div class="auth-row">
                <div class="auth-input-group">
                    <label class="auth-label">Email Address <span style="color:#f43f5e;">*</span></label>
                    <input type="email" name="email" class="auth-input" placeholder="e.g. samuel@gmail.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>

                <div class="auth-input-group">
                    <label class="auth-label">Phone Number <span style="color:#f43f5e;">*</span></label>
                    <input type="text" name="phone" class="auth-input" placeholder="e.g. +254 712 345 678" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                </div>
            </div>

            <div class="auth-row">
                <div class="auth-input-group">
                    <label class="auth-label">Account Role <span style="color:#f43f5e;">*</span></label>
                    <select name="role" class="auth-input" style="appearance: none;">
                        <option value="member" <?= ($_POST['role'] ?? '') === 'member' ? 'selected' : '' ?>>Member / Operations User</option>
                        <option value="technician" <?= ($_POST['role'] ?? '') === 'technician' ? 'selected' : '' ?>>Field Technician</option>
                        <option value="dispatcher" <?= ($_POST['role'] ?? '') === 'dispatcher' ? 'selected' : '' ?>>Dispatch Coordinator</option>
                    </select>
                </div>

                <div class="auth-input-group">
                    <label class="auth-label">Trade Skills / Specialty</label>
                    <input type="text" name="trade_skills" class="auth-input" placeholder="e.g. Solar, CCTV, Electrical" value="<?= htmlspecialchars($_POST['trade_skills'] ?? '') ?>">
                </div>
            </div>

            <div class="auth-row">
                <div class="auth-input-group">
                    <label class="auth-label">Password <span style="color:#f43f5e;">*</span></label>
                    <input type="password" name="password" class="auth-input" placeholder="Create password" required>
                </div>

                <div class="auth-input-group">
                    <label class="auth-label">Confirm Password <span style="color:#f43f5e;">*</span></label>
                    <input type="password" name="confirm_password" class="auth-input" placeholder="Repeat password" required>
                </div>
            </div>

            <button type="submit" class="btn-auth">
                <i data-lucide="check" style="width: 18px; height: 18px;"></i> Complete Registration
            </button>
        </form>

        <div class="auth-footer-links">
            Already registered? <a href="login.php">Sign In Here</a>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();
</script>
</body>
</html>
