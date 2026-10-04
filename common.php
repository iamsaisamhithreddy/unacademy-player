<?php
// ============================================================
// common.php — shared helpers
//   • continue_context(): pinned + last-watched lectures (same lecture = ONE entry)
//   • Telegram helpers: tg_api(), tg_send(), tg_secret(), status message builder
// Needs db.php ($conn, $botToken) to be included first by the calling script.
// ============================================================

/* ── "Continue where you left off" data ────────────────────────────────────── */

function _ctx_add(array &$ctx, $sid, $tid, $sname, $tname, $lid, $topic, $video, $pdf, $flag, $ts) {
    if (!isset($ctx[$sid])) {
        $ctx[$sid] = ['subject_name' => $sname, 'teacher_id' => $tid, 'teacher_name' => $tname, 'ts' => $ts, 'items' => []];
    }
    if ($ts > $ctx[$sid]['ts']) $ctx[$sid]['ts'] = $ts;
    if (!isset($ctx[$sid]['items'][$lid])) {
        $ctx[$sid]['items'][$lid] = ['lecture_id' => $lid, 'topic_name' => $topic, 'lecture_url' => $video,
                                     'pdf_url' => $pdf, 'pinned' => false, 'last' => false];
    }
    $ctx[$sid]['items'][$lid][$flag] = true;   // same lecture pinned AND last-watched = one entry, two flags
}

/** Returns [$ctx, $pin_by_subject, $last_by_subject] for one user. */
function continue_context(mysqli $conn, int $user_id): array {
    $ctx = []; $pin_by_subject = []; $last_by_subject = [];

    $stmt = $conn->prepare("
        SELECT p.subject_id, s.teacher_id, p.lecture_id, p.topic_name, p.pinned_at,
               s.subject_name, t.name, l.lecture_url, l.pdf_url
        FROM user_pins p
        JOIN subjects s ON s.id = p.subject_id
        JOIN teachers t ON t.id = s.teacher_id
        JOIN lectures l ON l.id = p.lecture_id
        WHERE p.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($sid, $tid, $lid, $topic, $ts, $sname, $tname, $video, $pdf);
    while ($stmt->fetch()) {
        $pin_by_subject[$sid] = $lid;
        _ctx_add($ctx, $sid, $tid, $sname, $tname, $lid, $topic, $video, $pdf, 'pinned', $ts);
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT w.subject_id, s.teacher_id, w.lecture_id, l.topic_name, w.watched_at,
               s.subject_name, t.name, l.lecture_url, l.pdf_url
        FROM user_last_watched w
        JOIN subjects s ON s.id = w.subject_id
        JOIN teachers t ON t.id = s.teacher_id
        JOIN lectures l ON l.id = w.lecture_id
        WHERE w.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($sid, $tid, $lid, $topic, $ts, $sname, $tname, $video, $pdf);
    while ($stmt->fetch()) {
        $last_by_subject[$sid] = $lid;
        _ctx_add($ctx, $sid, $tid, $sname, $tname, $lid, $topic, $video, $pdf, 'last', $ts);
    }
    $stmt->close();

    // Most recently touched subject first; inside a subject, the pinned lecture first
    uasort($ctx, fn($a, $b) => strcmp($b['ts'], $a['ts']));
    foreach ($ctx as &$cx) {
        uasort($cx['items'], fn($a, $b) => (int)$b['pinned'] <=> (int)$a['pinned']);
    }
    unset($cx);

    return [$ctx, $pin_by_subject, $last_by_subject];
}

/* ── Where this site lives (for links sent to Telegram) ────────────────────── */

function base_url(): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}

/* ── users table: Telegram columns (added automatically, once) ─────────────── */

function ensure_tg_columns(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $r = $conn->query("SHOW TABLES LIKE 'users'");
    if (!$r || $r->num_rows === 0) return;

    $have = [];
    $c = $conn->query("SHOW COLUMNS FROM users");
    while ($row = $c->fetch_assoc()) $have[$row['Field']] = true;

    if (empty($have['tg_chat_id']))      $conn->query("ALTER TABLE users ADD COLUMN tg_chat_id BIGINT NULL DEFAULT NULL, ADD UNIQUE KEY uq_tg_chat (tg_chat_id)");
    if (empty($have['tg_username']))     $conn->query("ALTER TABLE users ADD COLUMN tg_username VARCHAR(64) NULL DEFAULT NULL");
    if (empty($have['tg_link_token']))   $conn->query("ALTER TABLE users ADD COLUMN tg_link_token CHAR(64) NULL DEFAULT NULL");
    if (empty($have['tg_link_expires'])) $conn->query("ALTER TABLE users ADD COLUMN tg_link_expires DATETIME NULL DEFAULT NULL");
}

/* ── Telegram Bot API ──────────────────────────────────────────────────────── */

function tg_api(string $method, array $params = []): array {
    global $botToken;
    if (empty($botToken)) return ['ok' => false, 'description' => '$botToken is empty in db.php'];

    $base = getenv('TG_API_BASE') ?: 'https://api.telegram.org';
    $ch = curl_init("$base/bot{$botToken}/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false) return ['ok' => false, 'description' => 'curl: ' . $err];
    $j = json_decode($res, true);
    return is_array($j) ? $j : ['ok' => false, 'description' => 'Bad response from Telegram'];
}

/** Secret Telegram must send in the webhook header (derived from the bot token — nothing extra to configure). */
function tg_secret(): string {
    global $botToken;
    return substr(hash_hmac('sha256', 'lecture-portal-webhook', (string)$botToken), 0, 48);
}

function tg_send(int $chatId, string $html, ?array $keyboard = null): array {
    $p = [
        'chat_id'                  => $chatId,
        'text'                     => $html,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard) $p['reply_markup'] = ['inline_keyboard' => $keyboard];
    return tg_api('sendMessage', $p);
}

/** Builds the "where you left off" message + buttons. Returns [html, keyboard]. */
function tg_status_message(mysqli $conn, int $user_id, string $name): array {
    [$ctx] = continue_context($conn, $user_id);
    $base  = base_url();
    $e     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    if (empty($ctx)) {
        return [
            "👋 Hi <b>" . $e($name) . "</b>!\n\nYou haven't pinned or watched any lecture yet. Open the portal and start one — it will show up here.",
            [[['text' => '🌐 Open portal', 'url' => "$base/user.php"]]],
        ];
    }

    $text = "📍 <b>Continue where you left off</b>\n";
    $kb   = [];
    $n    = 0;
    $more = 0;

    foreach ($ctx as $c) {
        $text .= "\n<b>" . $e($c['subject_name']) . "</b> · " . $e($c['teacher_name']) . "\n";
        foreach ($c['items'] as $it) {
            if ($n >= 10) { $more++; continue; }
            $tags = [];
            if ($it['pinned']) $tags[] = '📍 Pinned';
            if ($it['last'])   $tags[] = '🕘 Last watched';
            $text .= "• " . $e($it['topic_name']) . " — " . implode(' · ', $tags) . "\n";

            $label = '▶ ' . mb_substr($it['topic_name'], 0, 40);
            // user.php?watch=ID records "last watched" and then opens the player
            $kb[]  = [['text' => $label, 'url' => "$base/user.php?watch=" . (int)$it['lecture_id']]];
            $n++;
        }
    }
    if ($more) $text .= "\n…and $more more on the portal.";
    $kb[] = [['text' => '🌐 Open portal', 'url' => "$base/user.php"]];

    return [$text, $kb];
}
