<?php
/**
 * Global Helper Functions
 */

use App\Core\Database;

/**
 * Get PDO connection (shortcut)
 */
function db(): PDO
{
    return Database::connect();
}

/**
 * Escape HTML output
 */
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to a URL
 */
function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

/**
 * Get a config value
 */
function config(string $key, mixed $default = null): mixed
{
    static $appConfig = null;
    if ($appConfig === null) {
        $appConfig = APP_CONFIG;
    }

    $keys = explode('.', $key);
    $value = $appConfig;

    foreach ($keys as $segment) {
        if (is_array($value) && array_key_exists($segment, $value)) {
            $value = $value[$segment];
        } else {
            return $default;
        }
    }

    return $value;
}

/**
 * Generate a CSRF token
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Verify CSRF token
 */
function verify_csrf(string $token): bool
{
    $stored = $_SESSION['_csrf_token'] ?? '';
    // Reject when either side is empty — otherwise hash_equals('', '') passes
    // and an attacker just omits the token on a session with none yet.
    if ($stored === '' || $token === '') {
        return false;
    }
    return hash_equals($stored, $token);
}

/**
 * CSRF hidden input field
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . csrf_token() . '">';
}

/**
 * Flash message helper
 */
function flash(string $key, ?string $value = null): ?string
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }

    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $message;
}

/**
 * Old input helper (for form re-population). One-shot: the values are
 * snapshotted in bootstrap and cleared from the session, so they survive
 * exactly one redirect and never leak into a later, unrelated form.
 */
function old(string $key, mixed $default = ''): mixed
{
    return $GLOBALS['_old_input'][$key] ?? $default;
}

/**
 * Whether debug tooling (e.g. ?debug_time= scan-time override) is allowed.
 * Off in production — these switches can otherwise be used to forge attendance.
 */
function debug_enabled(): bool
{
    return config('debug') === true;
}

/**
 * Write an entry to the audit trail. Best-effort — never throws.
 */
function log_audit(string $action, ?string $entityType = null, ?int $entityId = null, ?array $newValues = null): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $entityType,
            $entityId,
            $newValues ? json_encode($newValues) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('SCPLS audit log failed: ' . $e->getMessage());
    }
}

/**
 * Format time in AM/PM
 */
function format_time(string $time): string
{
    return date('h:i A', strtotime($time));
}

/**
 * Format date
 */
function format_date(string $date, string $format = 'M d, Y'): string
{
    return date($format, strtotime($date));
}

/**
 * Current date/time helpers
 */
function today(): string
{
    return date('Y-m-d');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function current_time(): string
{
    return date('H:i:s');
}
