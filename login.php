<?php
require_once __DIR__ . '/config/db.php';

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$error = '';
$regError = '';
$regSuccess = '';
$activeTab = $_GET['tab'] ?? 'login'; // 'login' or 'register'

// ── HANDLE LOGIN ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $loginInput = trim($_POST['login'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (empty($loginInput) || empty($password)) {
        $error = 'Please enter your username/email and password.';
        $activeTab = 'login';
    } else {
        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE (email = ? OR name = ? OR phone = ?) LIMIT 1");
        $stmt->execute([$loginInput, $loginInput, $loginInput]);
        $user = $stmt->fetch();

        $isValid = false;
        if ($user && password_verify($password, $user['password'])) {
            $isValid = true;
        }

        if ($isValid && $user) {
            // Check account status
            $acctStatus = $user['account_status'] ?? 'active';
            if ($acctStatus === 'pending') {
                $error = 'Your account is pending admin approval. You will be notified once it is activated.';
                $activeTab = 'login';
            } elseif ($acctStatus === 'suspended') {
                $error = 'Your account has been suspended. Please contact the administrator.';
                $activeTab = 'login';
            } else {
                session_regenerate_id(true);
                $_SESSION['_version'] = SESSION_VERSION;
                $_SESSION['user'] = [
                    'id'           => (int)$user['id'],
                    'name'         => $user['name'],
                    'email'        => $user['email'],
                    'role'         => $user['role'],
                    'phone'        => $user['phone'],
                    'trade_skills' => $user['trade_skills'] ?? '',
                    'avatar'       => $user['avatar'] ?? ''
                ];
                set_flash('success', 'Welcome back, ' . htmlspecialchars($user['name']) . '!');
                $dest = $_SESSION['redirect_after_login'] ?? '';
                unset($_SESSION['redirect_after_login']);
                if (!empty($dest) && str_starts_with($dest, '/')) {
                    header('Location: ' . $dest);
                } else {
                    header('Location: ' . BASE_URL . '/index.php');
                }
                exit;
            }
        } else {
            $error = 'Invalid credentials. Please check your email/phone and password.';
            $activeTab = 'login';
        }
    }
}

// ── HANDLE CUSTOMER SELF-REGISTRATION ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_customer') {
    $activeTab = 'register';
    $name     = clean($_POST['reg_name'] ?? '');
    $email    = clean($_POST['reg_email'] ?? '');
    $phone    = clean($_POST['reg_phone'] ?? '');
    $address  = clean($_POST['reg_address'] ?? '');
    $password = $_POST['reg_password'] ?? '';
    $confirm  = $_POST['reg_confirm'] ?? '';

    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        $regError = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $regError = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $regError = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $regError = 'Passwords do not match.';
    } else {
        $db = get_db();
        // Ensure account_status column exists
        try { $db->exec("ALTER TABLE users ADD COLUMN account_status TEXT NOT NULL DEFAULT 'active'"); } catch(Exception $e){}

        $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            $regError = "An account with email '{$email}' already exists. Please sign in.";
        } else {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            try {
                $stmt = $db->prepare("INSERT INTO users (name, email, phone, password, role, trade_skills, status, account_status) VALUES (?, ?, ?, ?, 'member', 'Customer', 'available', 'pending')");
                $stmt->execute([$name, $email, $phone, $passwordHash]);
                $newUserId = (int)$db->lastInsertId();

                // Also create customer record
                try {
                    $stmtCust = $db->prepare("INSERT INTO customers (user_id, name, contact_person, phone, email, estate_area, address, customer_type) VALUES (?, ?, ?, ?, ?, ?, ?, 'residential')");
                    $stmtCust->execute([$newUserId, $name, $name, $phone, $email, $address ?: 'Not specified', $address ?: 'Pending update']);
                } catch(Exception $e) {}

                // Notify admin
                create_notification("New Customer Registration", "Customer '{$name}' ({$email}) has registered and is awaiting account approval.", 'user', 'admin_accounts.php');

                $regSuccess = "Registration successful! Your account is pending admin approval. You will receive confirmation once activated.";
            } catch(Exception $e) {
                $regError = "Registration failed. Please try again.";
            }
        }
    }
}

// Ensure account_status column exists on first load
try {
    $db = get_db();
    $db->exec("ALTER TABLE users ADD COLUMN account_status TEXT NOT NULL DEFAULT 'active'");
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FieldPulse Kenya — Sign In</title>
    <meta name="description" content="FieldPulse Kenya Field Service Management System. Sign in to manage field technicians, service bookings, and customer requests.">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <meta name="theme-color" content="#0284c7">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/icons/icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: #020617;
            min-height: 100vh;
            display: flex;
            font-family: 'Inter', sans-serif;
            color: #f8fafc;
            position: relative;
            overflow: hidden;
        }

        /* Animated background */
        .bg-orbs {
            position: fixed; inset: 0; pointer-events: none; z-index: 0;
        }
        .bg-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            animation: floatOrb 12s ease-in-out infinite;
        }
        .orb1 { width: 500px; height: 500px; background: #0284c7; top: -150px; right: -100px; animation-delay: 0s; }
        .orb2 { width: 350px; height: 350px; background: #7c3aed; bottom: -100px; left: -80px; animation-delay: 4s; }
        .orb3 { width: 280px; height: 280px; background: #0891b2; top: 50%; left: 40%; animation-delay: 8s; }
        @keyframes floatOrb {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-30px) scale(1.05); }
        }

        /* Layout */
        .auth-layout {
            display: flex;
            width: 100%;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        /* Left hero panel */
        .auth-hero {
            flex: 0 0 45%;
            background: linear-gradient(135deg, rgba(2,132,199,0.12), rgba(124,58,237,0.08));
            border-right: 1px solid rgba(255,255,255,0.06);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 50px;
            position: relative;
            overflow: hidden;
        }
        .auth-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%230284c7' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        .hero-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 48px;
        }
        .hero-logo {
            width: 52px; height: 52px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            box-shadow: 0 10px 30px rgba(2,132,199,0.4);
            flex-shrink: 0;
        }
        .hero-brand-text h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -0.5px;
        }
        .hero-brand-text span {
            font-size: 12px; color: #64748b; font-weight: 500;
        }
        .hero-title {
            font-family: 'Outfit', sans-serif;
            font-size: 36px; font-weight: 800; line-height: 1.2;
            color: #fff; letter-spacing: -1px; margin-bottom: 16px;
        }
        .hero-title span { color: #38bdf8; }
        .hero-desc {
            font-size: 14.5px; color: #94a3b8; line-height: 1.7;
            margin-bottom: 40px;
        }
        .hero-features { display: flex; flex-direction: column; gap: 14px; }
        .hero-feature {
            display: flex; align-items: center; gap: 12px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 10px; padding: 12px 14px;
            transition: all 0.2s;
        }
        .hero-feature:hover { background: rgba(255,255,255,0.07); }
        .hero-feature-icon {
            width: 34px; height: 34px;
            border-radius: 8px; display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .hero-feature-text { font-size: 13px; color: #cbd5e1; font-weight: 500; }

        /* Right auth panel */
        .auth-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 32px;
            overflow-y: auto;
        }
        .auth-box {
            width: 100%;
            max-width: 460px;
        }

        /* Tab switcher */
        .auth-tabs {
            display: flex;
            background: rgba(15,23,42,0.8);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            padding: 4px;
            margin-bottom: 24px;
        }
        .auth-tab-btn {
            flex: 1;
            padding: 10px 16px;
            border: none;
            background: transparent;
            color: #64748b;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex; align-items: center; justify-content: center; gap: 7px;
        }
        .auth-tab-btn.active {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff;
            box-shadow: 0 4px 12px rgba(2,132,199,0.35);
        }
        .auth-tab-btn:not(.active):hover { color: #cbd5e1; background: rgba(255,255,255,0.05); }

        /* Card */
        .auth-card {
            background: rgba(15,23,42,0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px;
            padding: 32px 28px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }
        .auth-card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 22px; font-weight: 800; color: #fff;
            margin-bottom: 4px;
        }
        .auth-card-sub {
            font-size: 13px; color: #64748b; margin-bottom: 24px;
        }

        /* Inputs */
        .form-group { margin-bottom: 16px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media(max-width: 400px) { .form-row { grid-template-columns: 1fr; } }
        .form-label {
            display: block; font-size: 11.5px; font-weight: 700;
            color: #94a3b8; margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .form-input {
            width: 100%;
            background: rgba(2,6,23,0.7);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 11px 13px;
            color: #f1f5f9;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
        }
        .form-input::placeholder { color: #334155; }
        .form-input:focus {
            outline: none;
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2,132,199,0.2);
            background: rgba(2,6,23,0.9);
        }
        .form-input option { background: #0f172a; color: #f1f5f9; }

        /* Alerts */
        .alert {
            padding: 12px 14px; border-radius: 10px; font-size: 13px;
            margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px;
        }
        .alert-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.25); color: #fca5a5; }
        .alert-success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.25); color: #6ee7b7; }
        .alert-info { background: rgba(2,132,199,0.1); border: 1px solid rgba(56,189,248,0.2); color: #bae6fd; }

        /* Buttons */
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff; border: none;
            padding: 13px 20px; border-radius: 10px;
            font-size: 15px; font-weight: 700;
            font-family: 'Outfit', sans-serif;
            cursor: pointer; transition: all 0.25s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 4px 15px rgba(2,132,199,0.4);
            margin-top: 8px;
        }
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 22px rgba(2,132,199,0.55);
        }
        .btn-submit:active { transform: translateY(0); }

        /* Tab content */
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Pending notice */
        .pending-notice {
            background: linear-gradient(135deg, rgba(245,158,11,0.1), rgba(234,179,8,0.05));
            border: 1px solid rgba(245,158,11,0.25);
            border-radius: 12px; padding: 14px 16px;
            font-size: 13px; color: #fde68a;
            display: flex; align-items: flex-start; gap: 10px;
            margin-bottom: 16px;
        }

        /* Responsive */
        @media(max-width: 900px) {
            .auth-hero { display: none; }
        }
        @media(max-width: 480px) {
            .auth-panel { padding: 24px 16px; }
            .auth-card { padding: 24px 20px; }
        }

        /* Install prompt */
        .install-banner {
            display: none;
            margin-top: 18px;
            background: rgba(124,58,237,0.12);
            border: 1px solid rgba(124,58,237,0.25);
            border-radius: 12px; padding: 12px 14px;
            font-size: 12.5px; color: #c4b5fd;
            align-items: center; gap: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .install-banner:hover { background: rgba(124,58,237,0.18); }
        .install-banner.show { display: flex; }
    </style>
</head>
<body>

<div class="bg-orbs">
    <div class="bg-orb orb1"></div>
    <div class="bg-orb orb2"></div>
    <div class="bg-orb orb3"></div>
</div>

<div class="auth-layout">
    <!-- ── Hero Panel ── -->
    <div class="auth-hero">
        <div class="hero-brand">
            <div class="hero-logo">
                <i data-lucide="zap" style="width:26px;height:26px;"></i>
            </div>
            <div class="hero-brand-text">
                <h1>FieldPulse</h1>
                <span>Kenya Field Service Management</span>
            </div>
        </div>

        <h2 class="hero-title">The Smart Way to <span>Dispatch & Track</span> Field Teams</h2>
        <p class="hero-desc">Connect customers with expert field technicians across Solar, Electrical, CCTV, Plumbing, HVAC, and Networking services — all in one secure platform.</p>

        <div class="hero-features">
            <div class="hero-feature">
                <div class="hero-feature-icon" style="background:rgba(2,132,199,0.15);">
                    <i data-lucide="calendar-plus" style="width:16px;height:16px;color:#38bdf8;"></i>
                </div>
                <span class="hero-feature-text">Book any field service online in minutes</span>
            </div>
            <div class="hero-feature">
                <div class="hero-feature-icon" style="background:rgba(16,185,129,0.15);">
                    <i data-lucide="map-pin" style="width:16px;height:16px;color:#34d399;"></i>
                </div>
                <span class="hero-feature-text">Track your assigned technician in real-time</span>
            </div>
            <div class="hero-feature">
                <div class="hero-feature-icon" style="background:rgba(245,158,11,0.15);">
                    <i data-lucide="shield-check" style="width:16px;height:16px;color:#fbbf24;"></i>
                </div>
                <span class="hero-feature-text">Secure digital job sign-off &amp; reports</span>
            </div>
            <div class="hero-feature">
                <div class="hero-feature-icon" style="background:rgba(124,58,237,0.15);">
                    <i data-lucide="smartphone" style="width:16px;height:16px;color:#a78bfa;"></i>
                </div>
                <span class="hero-feature-text">Installable mobile app — works offline</span>
            </div>
        </div>
    </div>

    <!-- ── Auth Panel ── -->
    <div class="auth-panel">
        <div class="auth-box">

            <!-- Tab switcher -->
            <div class="auth-tabs">
                <button class="auth-tab-btn <?= $activeTab === 'login' ? 'active' : '' ?>"
                        onclick="switchTab('login')" id="tab-login">
                    <i data-lucide="log-in" style="width:15px;height:15px;"></i> Sign In
                </button>
                <button class="auth-tab-btn <?= $activeTab === 'register' ? 'active' : '' ?>"
                        onclick="switchTab('register')" id="tab-register">
                    <i data-lucide="user-plus" style="width:15px;height:15px;"></i> Create Account
                </button>
            </div>

            <!-- ── LOGIN TAB ── -->
            <div class="tab-content <?= $activeTab === 'login' ? 'active' : '' ?>" id="panel-login">
                <div class="auth-card">
                    <div class="auth-card-title">Welcome Back</div>
                    <div class="auth-card-sub">Sign in to access your operations dashboard</div>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error">
                            <i data-lucide="alert-circle" style="width:16px;height:16px;flex-shrink:0;margin-top:1px;"></i>
                            <div><?= htmlspecialchars($error) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php $flash = get_flash(); if ($flash): ?>
                        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
                            <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle-2' : 'alert-circle' ?>" style="width:16px;height:16px;flex-shrink:0;"></i>
                            <span><?= htmlspecialchars($flash['message']) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <i data-lucide="info" style="width:15px;height:15px;flex-shrink:0;margin-top:1px;"></i>
                        <div>Sign in with your registered <strong>email, phone number,</strong> or <strong>username</strong> and password.</div>
                    </div>

                    <form method="POST" action="login.php">
                        <input type="hidden" name="action" value="login">
                        <div class="form-group">
                            <label class="form-label">Username / Email / Phone</label>
                            <input type="text" name="login" id="login_input" class="form-input"
                                   placeholder="admin  or  your@email.com"
                                   value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" required autofocus>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" id="login_password" class="form-input"
                                   placeholder="Enter your password" required>
                        </div>
                        <button type="submit" class="btn-submit">
                            <i data-lucide="log-in" style="width:18px;height:18px;"></i> Sign In to Operations
                        </button>
                    </form>

                    <div style="text-align:center;margin-top:18px;font-size:13px;color:#475569;">
                        New customer? <a href="javascript:void(0)" onclick="switchTab('register')"
                            style="color:#38bdf8;font-weight:600;text-decoration:none;">Create a free account</a>
                    </div>
                </div>
            </div>

            <!-- ── REGISTER TAB ── -->
            <div class="tab-content <?= $activeTab === 'register' ? 'active' : '' ?>" id="panel-register">
                <div class="auth-card">
                    <div class="auth-card-title">Create Customer Account</div>
                    <div class="auth-card-sub">Register to book field services and track your technicians</div>

                    <?php if (!empty($regError)): ?>
                        <div class="alert alert-error">
                            <i data-lucide="alert-circle" style="width:16px;height:16px;flex-shrink:0;margin-top:1px;"></i>
                            <div><?= htmlspecialchars($regError) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($regSuccess)): ?>
                        <div class="alert alert-success">
                            <i data-lucide="check-circle-2" style="width:16px;height:16px;flex-shrink:0;margin-top:1px;"></i>
                            <div><?= htmlspecialchars($regSuccess) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="pending-notice">
                        <i data-lucide="clock" style="width:16px;height:16px;flex-shrink:0;margin-top:1px;"></i>
                        <div>New accounts require <strong>admin approval</strong> before first login. You'll be notified promptly.</div>
                    </div>

                    <form method="POST" action="login.php">
                        <input type="hidden" name="action" value="register_customer">

                        <div class="form-group">
                            <label class="form-label">Full Name <span style="color:#f43f5e;">*</span></label>
                            <input type="text" name="reg_name" class="form-input"
                                   placeholder="e.g. Samuel Njuguna"
                                   value="<?= htmlspecialchars($_POST['reg_name'] ?? '') ?>" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Email Address <span style="color:#f43f5e;">*</span></label>
                                <input type="email" name="reg_email" class="form-input"
                                       placeholder="your@email.com"
                                       value="<?= htmlspecialchars($_POST['reg_email'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Phone Number <span style="color:#f43f5e;">*</span></label>
                                <input type="text" name="reg_phone" class="form-input"
                                       placeholder="+254 712 345 678"
                                       value="<?= htmlspecialchars($_POST['reg_phone'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Your Estate / Area / Address</label>
                            <input type="text" name="reg_address" class="form-input"
                                   placeholder="e.g. Karen, Miotoni Road, House 4"
                                   value="<?= htmlspecialchars($_POST['reg_address'] ?? '') ?>">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Password <span style="color:#f43f5e;">*</span></label>
                                <input type="password" name="reg_password" class="form-input"
                                       placeholder="Min 6 characters" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Confirm Password <span style="color:#f43f5e;">*</span></label>
                                <input type="password" name="reg_confirm" class="form-input"
                                       placeholder="Repeat password" required>
                            </div>
                        </div>

                        <div style="font-size:11.5px;color:#475569;margin-bottom:12px;line-height:1.6;">
                            By creating an account you agree to our terms of service. Your information is kept secure and private.
                        </div>

                        <button type="submit" class="btn-submit" style="background: linear-gradient(135deg, #059669, #047857);">
                            <i data-lucide="user-plus" style="width:18px;height:18px;"></i> Submit Registration
                        </button>
                    </form>

                    <div style="text-align:center;margin-top:18px;font-size:13px;color:#475569;">
                        Already have an account? <a href="javascript:void(0)" onclick="switchTab('login')"
                            style="color:#38bdf8;font-weight:600;text-decoration:none;">Sign In Here</a>
                    </div>
                </div>
            </div>

            <!-- PWA Install Banner -->
            <div class="install-banner" id="installBanner" onclick="installPWA()">
                <i data-lucide="download" style="width:18px;height:18px;flex-shrink:0;"></i>
                <div>
                    <strong>Install FieldPulse App</strong><br>
                    <span style="opacity:0.8;font-size:11.5px;">Add to home screen for quick access</span>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
lucide.createIcons();

// Tab switching
function switchTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.auth-tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('panel-' + tab).classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
}

// PWA Install
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    document.getElementById('installBanner').classList.add('show');
});
function installPWA() {
    if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(() => {
            document.getElementById('installBanner').classList.remove('show');
            deferredPrompt = null;
        });
    }
}

// Register service worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js').catch(() => {});
}
</script>
</body>
</html>
