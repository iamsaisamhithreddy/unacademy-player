<?php
/*
  tg_setup.php — run ONCE in your browser to point the bot at tg_webhook.php.
  Admin only: log in at login.php first (same admin login as index.php).

  If the bot already has a different webhook (another script uses it), this page
  shows it and does NOT overwrite it unless you add ?force=1.
*/

session_start();   // admin session (login.php uses the default session)


require_once __DIR__ . '/db.php';
require_once __DIR__ . '/common.php';

$e   = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/tg_webhook.php';

echo '<!doctype html><meta charset="utf-8"><title>Telegram setup</title>';
echo '<body style="font-family:Segoe UI,Arial;max-width:760px;margin:40px auto;line-height:1.6">';
echo '<h2>Telegram bot setup</h2>';

$me = tg_api('getMe');
if (empty($me['ok'])) {
    echo '<p style="color:#b00020">❌ Bot token not working: ' . $e($me['description'] ?? 'no response') . '<br>Check <code>$botToken</code> in db.php.</p>';
    exit;
}
echo '<p>✅ Bot: <b>@' . $e($me['result']['username']) . '</b></p>';

$info    = tg_api('getWebhookInfo');
$current = $info['result']['url'] ?? '';
echo '<p>Current webhook: <code>' . $e($current ?: '(none)') . '</code></p>';

if ($current !== '' && $current !== $url && empty($_GET['force'])) {
    echo '<p style="color:#b45309">⚠️ This bot already uses a different webhook. Replacing it will stop whatever uses it now.<br>'
       . 'If you are sure: <a href="?force=1">replace it with ' . $e($url) . '</a></p>';
    exit;
}

$r = tg_api('setWebhook', [
    'url'             => $url,
    'secret_token'    => tg_secret(),
    'allowed_updates' => ['message'],
]);

if (!empty($r['ok'])) {
    echo '<p style="color:#15803d">✅ Webhook set to <code>' . $e($url) . '</code></p>';
    echo '<p>Now open the portal (user.php), tap <b>Connect Telegram</b>, and press START in the bot.</p>';
} else {
    echo '<p style="color:#b00020">❌ ' . $e($r['description'] ?? 'setWebhook failed') . '</p>';
    echo '<p>Telegram only accepts HTTPS URLs — make sure SSL is active on this domain.</p>';
}
