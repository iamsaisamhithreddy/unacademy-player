<?php
// ============================================================
// session.php — shared session + login helpers
// Included by: auth.php, logout.php, user.php, pin_api.php, notes_api.php
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    $lifetime = 60 * 60 * 24 * 30;            // stay logged in for 30 days
    $dir      = __DIR__ . '/_sessions';        // private session folder (so shared-host cleanup can't wipe it early)

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        }
        ini_set('session.save_path', $dir);
    }

    ini_set('session.gc_maxlifetime', (string)$lifetime);
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    // Own cookie name so it never clashes with the admin login session
    session_name('LECSESS');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Logged-in student's id, or 0 if nobody is logged in. */
function current_user_id(): int {
    return !empty($_SESSION['user_logged_in']) ? (int)($_SESSION['user_id'] ?? 0) : 0;
}

/** For pages: redirect to auth.php when not logged in. Returns the user id. */
function require_login_page(): int {
    $uid = current_user_id();
    if (!$uid) {
        header('Location: auth.php');
        exit;
    }
    return $uid;
}

/** For JSON APIs: answer 401 when not logged in. Returns the user id. */
function require_login_json(): int {
    $uid = current_user_id();
    if (!$uid) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'auth']);
        exit;
    }
    return $uid;
}

/** CSRF token for normal HTML forms. */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
