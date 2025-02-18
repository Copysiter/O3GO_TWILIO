<?php
// Проверяем, что файл вызван напрямую
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    die('Direct access not permitted');
}

// Устанавливаем обработку ошибок
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('display_errors', 0);

// Создаем необходимые директории
$directories = [
    __DIR__ . '/logs',
    __DIR__ . '/cache',
    __DIR__ . '/sessions',
    __DIR__ . '/uploads'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Создаем .htaccess для защиты директорий
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order deny,allow\nDeny from all");
    }
}

// Устанавливаем параметры сессии до её старта
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']));
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.cookie_lifetime', 3600);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);

// Устанавливаем путь для сохранения сессий
session_save_path(__DIR__ . '/sessions');

// Старт сессии если еще не запущена
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Database configuration
define('DB_HOST', 'db');
define('DB_USER', 'o3go');
define('DB_PASS', 'bD1sV2mM5lfG4iW7');
define('DB_NAME', 'twilio');

// Application settings
define('APP_NAME', 'Twilio Manager');
define('APP_VERSION', '1.0.1');
define('TIMEZONE', 'Europe/Moscow');
define('DEBUG_MODE', false);

// Security settings
define('SESSION_LIFETIME', 3600); // 1 hour
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_TIMEOUT', 900); // 15 minutes
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_MIN_LENGTH', 8);
define('COOKIE_PREFIX', 'twm_');

// Twilio API settings
define('TWILIO_API_URL', 'https://api.twilio.com');
define('TWILIO_API_VERSION', '2010-04-01');
define('PROXY_ENABLED', true);
define('CURL_TIMEOUT', 30);
define('VERIFY_SSL', false);

// File paths
define('ROOT_PATH', __DIR__);
define('LOG_PATH', __DIR__ . '/logs');
define('CACHE_PATH', __DIR__ . '/cache');
define('UPLOAD_PATH', __DIR__ . '/uploads');
define('SESSION_PATH', __DIR__ . '/sessions');
// Additional security headers
$security_headers = [
    'X-Frame-Options' => 'DENY',
    'X-XSS-Protection' => '1; mode=block',
    'X-Content-Type-Options' => 'nosniff',
    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:;",
    'Referrer-Policy' => 'same-origin',
    'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()'
];

foreach ($security_headers as $header => $value) {
    header("$header: $value");
}

// Error handling and logging setup
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('error_log', LOG_PATH . '/debug.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('error_log', LOG_PATH . '/error.log');
}

// Set timezone
date_default_timezone_set(TIMEZONE);

// Set default character encoding
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');
// Initialize database connection function
function initDB() {
    static $db = null;
    
    if ($db === null) {
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($db->connect_error) {
                error_log("Database connection error: " . $db->connect_error);
                throw new Exception('Database connection failed');
            }
            
            // Установка параметров соединения
            $db->set_charset("utf8mb4");
            $db->query("SET time_zone = '" . $db->real_escape_string(date('P')) . "'");
            $db->query("SET sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'");
            
            return $db;
        } catch (Exception $e) {
            error_log("Database initialization error: " . $e->getMessage());
            throw new Exception('System configuration error');
        }
    }
    
    return $db;
}

// Clean old session files (1% chance to run)
if (rand(1, 100) === 1) {
    $files = glob(SESSION_PATH . '/sess_*');
    $now = time();
    
    foreach ($files as $file) {
        if (is_file($file) && $now - filemtime($file) > SESSION_LIFETIME) {
            @unlink($file);
        }
    }
}

// Clean old log files (1% chance to run)
if (rand(1, 100) === 1) {
    $old_logs = glob(LOG_PATH . '/*.log');
    $now = time();
    
    foreach ($old_logs as $log) {
        if (is_file($log) && $now - filemtime($log) > 86400 * 30) { // 30 дней
            @unlink($log);
        }
    }
}
function getApiKey($mysqli) {
    $stmt = $mysqli->prepare("SELECT value FROM settings WHERE name = 'api_key'");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['value'];
    }
    return null;
}