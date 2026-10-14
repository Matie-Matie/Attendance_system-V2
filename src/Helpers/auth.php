<?php
/**
 * Authentication Helpers
 */

/**
 * Check if user is logged in
 */
function is_authenticated(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Require authentication (redirect if not logged in)
 */
function require_auth(): void
{
    if (!is_authenticated()) {
        $_SESSION['_flash']['error'] = 'Please login to continue.';
        redirect('/auth/login.php');
    }
}

/**
 * Get current user data
 */
function current_user(): ?array
{
    if (!is_authenticated()) return null;

    $stmt = db()->prepare("
        SELECT u.*, r.name AS role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = ? AND u.status = 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Check if user has a specific role
 */
function has_role(string $role): bool
{
    $user = current_user();
    return $user && $user['role_name'] === $role;
}

/**
 * Require a specific role
 */
function require_role(string $role): void
{
    require_auth();
    if (!has_role($role)) {
        http_response_code(403);
        die('Access denied: ' . $role . ' role required.');
    }
}

/**
 * Get the currently logged-in teacher's profile
 */
function current_teacher(): ?array
{
    require_auth();
    $stmt = db()->prepare("SELECT * FROM teachers WHERE user_id = ? AND status = 1");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Login a user by username and password
 */
function login(string $username, string $password): bool
{
    $stmt = db()->prepare("
        SELECT u.*, r.name AS role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.username = ? AND u.status = 1
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        // Failed sign-in is audited too (username + IP only, never passwords).
        try {
            $stmt = db()->prepare(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address)
                 VALUES (NULL, 'login_failed', 'user', NULL, ?, ?)"
            );
            $stmt->execute([
                json_encode(['username' => $username]),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('SCPLS audit log failed: ' . $e->getMessage());
        }
        return false;
    }

    // Regenerate session to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['user_id']  = (int) $user['id'];
    $_SESSION['user_role'] = $user['role_name'];
    $_SESSION['user_name'] = $user['full_name'];

    log_audit('login', 'user', (int) $user['id'], ['username' => $user['username']]);

    return true;
}

/**
 * Logout the current user
 */
function logout(): void
{
    // Audit the sign-out before the session is wiped (log_audit reads the session).
    if (!empty($_SESSION['user_id'])) {
        log_audit('logout', 'user', (int) $_SESSION['user_id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}
