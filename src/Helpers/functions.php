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

/**
 * Semesters are no longer split up in the UI (2026-10-22, sir Vic): the school
 * year is the only term the admin manages. The semesters table stays because
 * schedule_entries.semester_id is NOT NULL, so each school year quietly owns a
 * "Whole Year" term and new schedule entries are filed under it.
 *
 * Returns the semester id to file new schedule entries under: the current
 * school year's earliest term, created on demand. Null when no school year
 * exists at all.
 */
function current_term_id(): ?int
{
    $sy = db()->query("SELECT id, start_date, end_date FROM school_years ORDER BY is_current DESC, created_at DESC LIMIT 1")->fetch();
    if (!$sy) {
        return null;
    }
    $stmt = db()->prepare("SELECT id FROM semesters WHERE school_year_id = ? ORDER BY is_current DESC, start_date ASC, id ASC LIMIT 1");
    $stmt->execute([$sy['id']]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $ins = db()->prepare("INSERT INTO semesters (school_year_id, name, start_date, end_date, is_current) VALUES (?, 'Whole Year', ?, ?, 0)");
    $ins->execute([$sy['id'], $sy['start_date'], $sy['end_date']]);
    return (int) db()->lastInsertId();
}

/**
 * SQL fragment restricting schedule_entries (aliased $alias) to the current
 * school year — every term inside it counts. Empty string when no school year
 * is marked current, so callers fall back to "all entries".
 */
function current_school_year_filter(string $alias = 'se'): string
{
    $syId = db()->query("SELECT id FROM school_years WHERE is_current = 1 LIMIT 1")->fetchColumn();
    if (!$syId) {
        return '';
    }
    return " AND {$alias}.semester_id IN (SELECT id FROM semesters WHERE school_year_id = " . (int) $syId . ")";
}

/**
 * Default "From" date for the report pages: the whole current school year, so
 * the full attendance history shows instead of just today. Falls back to the
 * first recorded scan, then to 30 days ago.
 */
function report_default_from(): string
{
    $start = db()->query("SELECT start_date FROM school_years WHERE is_current = 1 AND start_date IS NOT NULL LIMIT 1")->fetchColumn();
    if ($start && $start <= today()) {
        return $start;
    }
    $first = db()->query("SELECT MIN(DATE(timestamp)) FROM attendance_records")->fetchColumn();
    return $first ?: date('Y-m-d', strtotime('-30 days'));
}

/**
 * Quick date ranges for the report filter: label => [from, to].
 */
function report_ranges(): array
{
    return [
        'Today'            => [today(), today()],
        'This week'        => [date('Y-m-d', strtotime('monday this week')), today()],
        'This month'       => [date('Y-m-01'), today()],
        'Whole school year' => [report_default_from(), today()],
    ];
}
