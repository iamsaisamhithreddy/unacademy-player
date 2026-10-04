<?php
/*
  tg_webhook.php — Telegram calls this URL for every message sent to your bot.
  Register it once by opening tg_setup.php (admin login required).

  What the bot understands:
    /start <token>   link this Telegram chat to a portal account (button on user.php)
    /start /continue show where you left off (pinned + last watched)
    /unlink          disconnect this chat from the portal account
*/

error_reporting(0);
header('Content-Type: text/plain');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/common.php';

// Only Telegram knows this secret (it is sent in a header when we register the webhook)
$hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals(tg_secret(), $hdr)) {
    http_response_code(403);
    exit('forbidden');
}

$update = json_decode(file_get_contents('php://input'), true);
$msg    = $update['message'] ?? null;

// Only private chats (so a group can never get linked to an account)
if (!$msg || ($msg['chat']['type'] ?? '') !== 'private') {
    echo 'ok';
    exit;
}

$chatId = (int)$msg['chat']['id'];
$text   = trim($msg['text'] ?? '');
$tgUser = mb_substr($msg['from']['username'] ?? '', 0, 64);

$conn->set_charset('utf8mb4');
ensure_tg_columns($conn);

$cmd = ''; $arg = '';
if (preg_match('~^/(\w+)(?:@\w+)?(?:\s+(\S+))?~u', $text, $m)) {
    $cmd = strtolower($m[1]);
    $arg = $m[2] ?? '';
}

// Which portal account (if any) is this chat linked to?
function user_for_chat(mysqli $conn, int $chatId): ?array {
    $stmt = $conn->prepare("SELECT id, name FROM users WHERE tg_chat_id = ? LIMIT 1");
    $stmt->bind_param("i", $chatId);
    $stmt->execute();
    $stmt->bind_result($id, $name);
    $row = $stmt->fetch() ? ['id' => (int)$id, 'name' => $name] : null;
    $stmt->close();
    return $row;
}

$portal = base_url() . '/user.php';

if ($cmd === 'start' && $arg !== '') {
    // ── Link this chat to the account that generated the token ───────────────
    $hash = hash('sha256', $arg);
    $stmt = $conn->prepare("
        SELECT id, name FROM users
        WHERE tg_link_token = ? AND tg_link_expires > NOW()
        LIMIT 1
    ");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $stmt->bind_result($uid, $uname);
    $found = $stmt->fetch();
    $stmt->close();

    if ($found) {
        // A chat can belong to only one account
        $stmt = $conn->prepare("UPDATE users SET tg_chat_id = NULL, tg_username = NULL WHERE tg_chat_id = ? AND id <> ?");
        $stmt->bind_param("ii", $chatId, $uid);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE users
            SET tg_chat_id = ?, tg_username = ?, tg_link_token = NULL, tg_link_expires = NULL
            WHERE id = ?
        ");
        $stmt->bind_param("isi", $chatId, $tgUser, $uid);
        $stmt->execute();
        $stmt->close();

        tg_send($chatId, "✅ <b>Connected!</b> This chat is now linked to <b>" . htmlspecialchars($uname) . "</b>.\nSend /continue any time to see where you left off.");
        [$t, $kb] = tg_status_message($conn, (int)$uid, $uname);
        tg_send($chatId, $t, $kb);
    } else {
        tg_send($chatId, "⚠️ That link expired or was already used.\nOpen the portal and tap <b>Connect Telegram</b> again.", [[['text' => '🌐 Open portal', 'url' => $portal]]]);
    }

} elseif ($cmd === 'start' || $cmd === 'continue' || $cmd === 'status') {
    $u = user_for_chat($conn, $chatId);
    if ($u) {
        [$t, $kb] = tg_status_message($conn, $u['id'], $u['name']);
        tg_send($chatId, $t, $kb);
    } else {
        tg_send($chatId, "👋 Welcome! This chat isn't linked to a portal account yet.\n\nLog in to the portal and tap <b>Connect Telegram</b> — it opens this bot with a one-time link.", [[['text' => '🌐 Open portal', 'url' => $portal]]]);
    }

} elseif ($cmd === 'unlink') {
    $stmt = $conn->prepare("UPDATE users SET tg_chat_id = NULL, tg_username = NULL WHERE tg_chat_id = ?");
    $stmt->bind_param("i", $chatId);
    $stmt->execute();
    $had = $stmt->affected_rows > 0;
    $stmt->close();
    tg_send($chatId, $had ? "🔌 Disconnected. Use <b>Connect Telegram</b> on the portal to link again." : "This chat wasn't linked to any account.");

} else {
    tg_send($chatId, "Commands:\n/continue — where you left off\n/unlink — disconnect this chat\n\nTo connect, use <b>Connect Telegram</b> on the portal.");
}

echo 'ok';
