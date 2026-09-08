<?php
require_once __DIR__ . '/config/db.php';

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

$error = '';
$demoMode = filter_var(getenv('DEMO_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($loginInput) || empty($password)) {
        $error = 'Please enter your username/email and password.';
    } else {
        $db = get_db();

        // Find user by username "admin" or by email or phone
        $stmt = $db->prepare("
            SELECT * FROM users
            WHERE email = ? OR name = ? OR phone = ?
            LIMIT 1
        ");
        $stmt->execute([$loginInput, $loginInput, $loginInput]);
        $user = $stmt->fetch();

        // Check password
        $isValid = false;
        if ($user) {
            // Check bcrypt password or fallback to admin123 match if hash matches
            if (password_verify($password, $user['password']) || ($demoMode && $password === 'admin123' && ($loginInput === 'admin' || $user['role'] === 'admin' || str_starts_with($user['email'], 'admin')))) {
                $isValid = true;
            }
        } elseif ($demoMode && strtolower($loginInput) === 'admin' && $password === 'admin123') {
            // Find first admin user
            $user = $db->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch();
            if ($user) {
                $isValid = true;
            }
        }

        if ($isValid && $user) {
            // Regenerate session ID for security
            session_regenerate_id(true);

            $_SESSION['_version'] = SESSION_VERSION;
            $_SESSION['user'] = [
                'id'          => (int)$user['id'],
                'name'        => $user['name'],
                'email'       => $user['email'],
                'role'        => $user['role'],
                'phone'       => $user['phone'],
                'trade_skills'=> $user['trade_skills'] ?? '',
                'avatar'      => $user['avatar'] ?? ''
            ];

            set_flash('success', 'Welcome back, ' . htmlspecialchars($user['name']) . '!');

            // Redirect to originally-requested page if stored, else dashboard
            $dest = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);
            // Safety: only redirect to local paths
            if (!empty($dest) && str_starts_with($dest, '/')) {
                header('Location: ' . $dest);
            } else {
                header('Location: ' . BASE_URL . '/index.php');
            }
            exit;
        } else {
            $error = 'Invalid credentials. Please check your login details and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - FieldPulse Kenya</title>
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
            max-width: 440px;
        }
        .auth-brand {
            text-align: center;
            margin-bottom: 28px;
        }
        .brand-badge-icon {
            width: 54px;
            height: 54px;
            background: linear-gradient(135deg, #0284c7, #0369a1);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            box-shadow: 0 10px 25px -5px rgba(2, 132, 199, 0.5);
            margin-bottom: 12px;
        }
        .auth-title {
            font-family: 'Outfit', sans-serif;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #fff;
        }
        .auth-subtitle {
            font-size: 13.5px;
            color: #94a3b8;
            margin-top: 4px;
        }
        .auth-card {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 32px 28px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
        }
        .demo-credentials-box {
            background: rgba(2, 132, 199, 0.1);
            border: 1px solid rgba(56, 189, 248, 0.2);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 20px;
            font-size: 12.5px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: #bae6fd;
        }
        .demo-credentials-box code {
            font-family: 'JetBrains Mono', monospace;
            background: rgba(0, 0, 0, 0.3);
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            color: #38bdf8;
        }
        .auth-input-group {
            margin-bottom: 18px;
        }
        .auth-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .auth-input {
            width: 100%;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            padding: 12px 14px;
            color: #fff;
            font-size: 14px;
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
            padding: 14px;
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
            margin-top: 6px;
        }
        .btn-auth:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.55);
        }
        .auth-footer-links {
            text-align: center;
            margin-top: 22px;
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
            margin-bottom: 20px;
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
            <i data-lucide="zap" style="width: 28px; height: 28px;"></i>
        </div>
        <h1 class="auth-title">FieldPulse Kenya</h1>
        <p class="auth-subtitle">Field Service & Dispatch Management System</p>
    </div>

    <div class="auth-card">
        <?php if (!empty($error)): ?>
            <div class="error-alert">
                <i data-lucide="alert-circle" style="width: 18px; height: 18px; flex-shrink: 0; color: #f43f5e;"></i>
                <div><?= $error ?></div>
            </div>
        <?php endif; ?>

        <?php $flash = get_flash(); if ($flash): ?>
            <div class="flash-alert flash-<?= $flash['type'] ?>" style="margin-bottom: 20px;">
                <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle-2' : 'alert-circle' ?>"></i>
                <span><?= htmlspecialchars($flash['message']) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($demoMode): ?>
            <!-- Only show demo credentials when explicitly enabled. -->
            <div class="demo-credentials-box">
                <i data-lucide="key" style="width: 16px; height: 16px; flex-shrink: 0; margin-top: 2px;"></i>
                <div>
                    <strong>Demo Administrator Access:</strong><br>
                    Username: <code>admin</code> &nbsp;|&nbsp; Password: <code>admin123</code>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="auth-input-group">
                <label class="auth-label">Username / Email / Phone</label>
                <input type="text" name="login" id="login" class="auth-input" placeholder="admin  or  email@fieldpulse.co.ke" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" required autofocus>
            </div>

            <div class="auth-input-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label class="auth-label" style="margin-bottom: 0;">Password</label>
                </div>
                <input type="password" name="password" id="password" class="auth-input" placeholder="Enter your password" required>
            </div>

            <button type="submit" class="btn-auth">
                <i data-lucide="log-in" style="width: 18px; height: 18px;"></i> Sign In to Operations
            </button>
        </form>

        <div class="auth-footer-links">
            Don't have an account? <a href="register.php">Register Member Account</a>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();
</script>
</body>
</html>
