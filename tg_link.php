<?php
/*
  tg_link.php — used by the Telegram card on user.php (logged-in students only).

  POST JSON  { "action": "link" }    -> { ok, url }   one-time t.me deep link (valid 15 min)
             { "action": "status" }  -> { ok, linked, username }
             { "action": "send" }    -> sends "where you left off" to the linked chat now
             { "action": "unlink" }  -> disconnects Telegram from this account
  Header X-CSRF-Token must match the page's token.
*/

header('Content-Type: application/json');
error_reporting(0);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/common.php';

$uid  = require_login_json();
$name = $_SESSION['user_name'] ?? 'Student';
$csrf = csrf_token();
session_write_close();

if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$conn->set_charset('utf8mb4');
ensure_tg_columns($conn);

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

function my_tg(mysqli $conn, int $uid): array {
    $stmt = $conn->prepare("SELECT tg_chat_id, tg_username FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->bind_result($chat, $uname);
    $row = $stmt->fetch() ? ['chat' => $chat ? (int)$chat : 0, 'username' => (string)$uname] : ['chat' => 0, 'username' => ''];
    $stmt->close();
    return $row;
}

if ($action === 'link') {
    $me = tg_api('getMe');
    $bot = $me['result']['username'] ?? '';
    if (!$bot) {
        echo json_encode(['ok' => false, 'error' => 'Bot not reachable — check $botToken in db.php (' . ($me['description'] ?? 'no response') . ')']);
        exit;
    }

    $token = bin2hex(random_bytes(16));          // goes in the link
    $hash  = hash('sha256', $token);             // only the hash is stored
    $stmt  = $conn->prepare("UPDATE users SET tg_link_token = ?, tg_link_expires = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?");
    $stmt->bind_param("si", $hash, $uid);
    $ok = $stmt->execute();
    $stmt->close();

    echo json_encode($ok ? ['ok' => true, 'url' => "https://t.me/$bot?start=$token"] : ['ok' => false, 'error' => 'DB error']);

} elseif ($action === 'status') {
    $t = my_tg($conn, $uid);
    echo json_encode(['ok' => true, 'linked' => $t['chat'] > 0, 'username' => $t['username']]);

} elseif ($action === 'send') {
    $t = my_tg($conn, $uid);
    if (!$t['chat']) {
        echo json_encode(['ok' => false, 'error' => 'Telegram is not connected']);
        exit;
    }
    [$text, $kb] = tg_status_message($conn, $uid, $name);
    $r = tg_send($t['chat'], $text, $kb);
    echo json_encode(['ok' => !empty($r['ok']), 'error' => $r['description'] ?? null]);

} elseif ($action === 'unlink') {
    $stmt = $conn->prepare("UPDATE users SET tg_chat_id = NULL, tg_username = NULL, tg_link_token = NULL, tg_link_expires = NULL WHERE id = ?");
    $stmt->bind_param("i", $uid);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
