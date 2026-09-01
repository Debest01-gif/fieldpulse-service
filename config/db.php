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


// Database configuration. Environment variables are used in production and
// local-development defaults are kept for the original XAMPP setup.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'field_service_db');
define('APP_NAME', 'FieldPulse');
define('APP_TAGLINE', 'Field Service Management System');
define('BASE_URL', rtrim((string) (getenv('BASE_URL') ?: ''), '/'));

/**
 * Returns the PDO database connection instance.
 * Automatically checks and initializes the database if not present.
 */
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // If database doesn't exist, connect to server without db and run setup
            if ($e->getCode() == 1049) {
                header("Location: " . BASE_URL . "/setup.php");
                exit;
            }
            die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
        }
    }
    return $pdo;
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
    $publicPages = ['login.php', 'register.php', 'logout.php', 'setup.php'];
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
