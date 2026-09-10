<?php
/**
 * Application Bootstrap
 * Loaded by all public-facing pages
 */

// Error reporting — production (log errors, don't display)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Load config
$dbConfig = require __DIR__ . '/config/database.php';
$appConfig = require __DIR__ . '/config/app.php';

// Set timezone
date_default_timezone_set($appConfig['timezone']);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => $appConfig['session_lifetime'],
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// Define base path
define('BASE_PATH', __DIR__);
define('APP_CONFIG', $appConfig);
define('DB_CONFIG', $dbConfig);

// Auto-load classes (simple PSR-4-like autoloader)
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Auto-load helpers
require_once __DIR__ . '/src/Helpers/functions.php';
require_once __DIR__ . '/src/Helpers/auth.php';
require_once __DIR__ . '/src/Helpers/validation.php';
require_once __DIR__ . '/src/Helpers/sms.php';

/* ------------------------------------------------------------------
 * Fatal-error safety net — never show a blank white page.
 * Any uncaught exception or fatal error is logged and replaced with a
 * plain, friendly message (JSON for /api/ requests, HTML otherwise).
 * ---------------------------------------------------------------- */
function scpls_fail(string $logMsg): void
{
    error_log('SCPLS fatal: ' . $logMsg);
    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    $isApi = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
    if ($isApi) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
        . '<div style="max-width:520px;margin:15vh auto;font-family:system-ui,sans-serif;text-align:center;color:#1A1410">'
        . '<h1 style="font-size:1.4rem">Something went wrong</h1>'
        . '<p style="color:#6B5F56">The page could not be loaded. Please go back and try again — if it keeps happening, contact the administrator.</p>'
        . '<p><a href="/" style="color:#C00000">Return home</a></p></div>';
}

set_exception_handler(function (\Throwable $e) {
    scpls_fail($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        scpls_fail($err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
    }
});

/* ------------------------------------------------------------------
 * CSRF — every state-changing request from a logged-in session must
 * carry a valid token. Public endpoints under /api/ are exempt (the
 * attendance kiosk has no session); GET is never checked.
 * ---------------------------------------------------------------- */
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && isset($_SESSION['user_id'])
    && !str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')
    && !verify_csrf($_POST['_csrf_token'] ?? '')
) {
    http_response_code(419);
    die('Your session expired or the form is invalid. Please go back, reload the page, and try again.');
}

/* ------------------------------------------------------------------
 * One-shot "old input" — survives exactly one redirect, then clears,
 * so a failed submit never bleeds values into an unrelated form later.
 * ---------------------------------------------------------------- */
$GLOBALS['_old_input'] = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);

// Self-applying schema upgrades (for hosts without phpMyAdmin access).
// Cheap after the first success: one cached SELECT; the ALTERs run once ever.
notify_ensure_schema();

// Background housekeeping — throttled absentee sweep (no cron needed).
// Self-throttles to once per 10 min and runs after the response is flushed.
notify_tick();
