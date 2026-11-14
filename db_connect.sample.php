<?php
// db_connect.php — Veya Education Hub
// Copy this file to db_connect.php and set host/user/pass/name for your MySQL setup.

// ── SECURITY: Turn off error display, log to file instead ─────────────
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Logs go to: C:\xampp\htdocs\Veya\logs\error.log
ini_set('error_log', __DIR__ . '/logs/error.log');
error_reporting(E_ALL);

// ── SECURITY: HTTP Security Headers ──────────────────────────────────
// Only send headers if no output has been sent yet (avoid "headers already sent")
if (!headers_sent()) {
    // Prevent site being embedded in iframes (clickjacking protection)
    header("X-Frame-Options: DENY");
    // Prevent browser guessing content type (MIME sniffing attacks)
    header("X-Content-Type-Options: nosniff");
    // Control referrer info sent to other sites
    header("Referrer-Policy: strict-origin-when-cross-origin");
    // Legacy XSS protection for older browsers
    header("X-XSS-Protection: 1; mode=block");
    // Restrict access to browser features (camera, microphone, etc.)
    header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()");
    // Content Security Policy: allow inline styles/scripts (needed for current code),
    // Google Fonts, Unsplash images, CDN scripts
    header("Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
        "font-src 'self' https://fonts.gstatic.com; " .
        "img-src 'self' data: https://images.unsplash.com https://plus.unsplash.com; " .
        "connect-src 'self'; " .
        "frame-ancestors 'none';"
    );
}

// ── SECURITY: Session timeout (30 minutes of inactivity) ─────────────
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    $timeout = 1800; // 30 minutes in seconds
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        // Session expired — destroy and redirect to login
        $uid_before = $_SESSION['user_id']; // preserve for redirect message
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['timeout_msg'] = 'Your session expired. Please log in again.';
        header('Location: ' . (strpos($_SERVER['PHP_SELF'], '/admin/') !== false
            ? '../login.php?timeout=1'
            : 'login.php?timeout=1'));
        exit;
    }
    $_SESSION['last_activity'] = time();
}


$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "YOUR_MYSQL_PASSWORD";   // <-- set your own; never commit the real one
$DB_NAME = "veya_db";
$DB_PORT = 3306;

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);

if ($conn->connect_error) {
    // Show a friendly error instead of a blank page
    http_response_code(503);
    die("
    <style>body{font-family:sans-serif;text-align:center;padding:80px;background:#F8FAFF;}
    h2{color:#DC2626;}p{color:#6B7280;}a{color:#1A56DB;}</style>
    <h2>⚠️ Database connection failed</h2>
    <p>Make sure XAMPP MySQL is running and the database <strong>veya_db</strong> exists.</p>
    <p style='font-size:12px;color:#9CA3AF;'>" . htmlspecialchars($conn->connect_error) . "</p>
    <a href='javascript:history.back()'>← Go back</a>
    ");
}

$conn->set_charset("utf8mb4");

// Load shared helpers (currency, image, etc.)
require_once __DIR__ . "/includes/functions.php";
?>