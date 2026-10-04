<?php
require_once __DIR__ . '/session.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {

    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => 'Lax',
    ]);
    session_destroy();
}

header('Location: auth.php');
exit;
