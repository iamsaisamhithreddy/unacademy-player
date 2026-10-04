<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/session.php';
include 'db.php';
require_once __DIR__ . '/common.php';

// ── Must be logged in (students log in / sign up at auth.php) ────────────────
$user_id   = require_login_page();
$user_name = $_SESSION['user_name'] ?? 'Student';
$csrf      = csrf_token();
$conn->set_charset('utf8mb4');

/* ── Auto-create per-user tables if missing (same as the APIs) ──────────────── */
$conn->query("
  CREATE TABLE IF NOT EXISTS user_pins (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    lecture_id INT NOT NULL,
    topic_name VARCHAR(512) NOT NULL,
    pinned_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_subject (user_id, subject_id),
    KEY idx_user (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$conn->query("
  CREATE TABLE IF NOT EXISTS user_notes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    lecture_id INT NOT NULL,
    notes_json MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_lecture (user_id, lecture_id),
    KEY idx_user (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$conn->query("
  CREATE TABLE IF NOT EXISTS user_last_watched (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    subject_id INT NOT NULL,
    lecture_id INT NOT NULL,
    watched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_subject (user_id, subject_id),
    KEY idx_user (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

ensure_tg_columns($conn);   // adds the Telegram columns to `users` once

/* ── Opened from the Telegram bot: record "last watched", then open the player ── */
if (isset($_GET['watch'])) {
    $wl = (int)$_GET['watch'];
    $stmt = $conn->prepare("SELECT subject_id, lecture_url, pdf_url FROM lectures WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $wl);
    $stmt->execute();
    $stmt->bind_result($w_sid, $w_video, $w_pdf);
    $w_found = $stmt->fetch();
    $stmt->close();

    if ($w_found) {
        $stmt = $conn->prepare("
            INSERT INTO user_last_watched (user_id, subject_id, lecture_id) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE lecture_id = VALUES(lecture_id), watched_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param("iii", $user_id, $w_sid, $wl);
        $stmt->execute();
        $stmt->close();
        header('Location: ./player/player.html?video=' . urlencode($w_video) . '&pdf=' . urlencode((string)$w_pdf) . '&lecture_id=' . $wl);
        exit;
    }
}

/* ── SUBJECTS ────────────────────────────────────────────────────────────────── */
$subjects = $conn->query("
    SELECT s.id, s.subject_name, t.name AS teacher_name
    FROM subjects s
    JOIN teachers t ON s.teacher_id = t.id
    ORDER BY s.subject_name ASC
");

/* ── SELECTED ────────────────────────────────────────────────────────────────── */
$selected_subject = isset($_GET['subject']) ? (int)$_GET['subject'] : 0;
$selected_teacher = isset($_GET['teacher']) ? (int)$_GET['teacher'] : 0;

/* ── TEACHERS ────────────────────────────────────────────────────────────────── */
$teachers = [];
if ($selected_subject) {
    $stmt = $conn->prepare("
        SELECT t.id, t.name
        FROM teachers t
        JOIN subjects s ON s.teacher_id = t.id
        WHERE s.id = ?
    ");
    $stmt->bind_param("i", $selected_subject);
    $stmt->execute();
    $stmt->bind_result($tid, $tname);
    while ($stmt->fetch()) {
        $teachers[] = ["id" => $tid, "name" => $tname];
    }
    $stmt->close();
}

/* ── LECTURES ────────────────────────────────────────────────────────────────── */
$lectures = [];
if ($selected_subject && $selected_teacher) {
    $stmt = $conn->prepare("
        SELECT id, topic_name, lecture_url, pdf_url
        FROM lectures
        WHERE subject_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->bind_param("i", $selected_subject);
    $stmt->execute();
    $stmt->bind_result($lid, $ltopic, $lvideo, $lpdf);
    while ($stmt->fetch()) {
        $lectures[] = [
            "id"          => $lid,
            "topic_name"  => $ltopic,
            "lecture_url" => $lvideo,
            "pdf_url"     => $lpdf
        ];
    }
    $stmt->close();
}

/* ── MY PINS + LAST WATCHED (same lecture in both = shown once) ─────────────── */
[$ctx, $pin_by_subject, $last_by_subject] = continue_context($conn, $user_id);

/* ── TELEGRAM link status ────────────────────────────────────────────────────── */
$tg_chat = 0; $tg_user = '';
$stmt = $conn->prepare("SELECT tg_chat_id, tg_username FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($tg_c, $tg_u);
if ($stmt->fetch()) { $tg_chat = (int)$tg_c; $tg_user = (string)$tg_u; }
$stmt->close();

/* ── LECTURES THAT HAVE MY NOTES (for the 📝 badge) ───────────────────────────── */
$noted = [];
$stmt = $conn->prepare("SELECT lecture_id, notes_json FROM user_notes WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($n_lid, $n_json);
while ($stmt->fetch()) {
    $j = trim($n_json);
    if ($j !== '' && $j !== '[]') {
        $arr = json_decode($j, true);
        $noted[$n_lid] = is_array($arr) ? count($arr) : 1;
    }
}
$stmt->close();
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Lecture Portal</title>

<style>
body{
    font-family:Segoe UI,Arial;
    background:#f4f6f9;
    margin:0;
    padding:40px;
}
.container{
    max-width:1000px;
    margin:auto;
}
.card{
    background:#fff;
    padding:25px;
    border-radius:8px;
    box-shadow:0 2px 6px rgba(0,0,0,0.1);
    margin-bottom:20px;
}
h2{
    margin-top:0;
    color:#1a73e8;
}
select{
    width:100%;
    padding:12px;
    margin-top:10px;
    border:1px solid #ddd;
    border-radius:4px;
}
table{
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}
th,td{
    padding:12px;
    border-bottom:1px solid #eee;
}
th{
    background:#f8f9fa;
    text-align:left;
}
.open-btn{
    background:#28a745;
    color:#fff;
    padding:6px 12px;
    border-radius:4px;
    text-decoration:none;
    font-size:13px;
    display:inline-block;
}
.pdf-link {
    color:#d93025;
    text-decoration:none;
    font-weight:bold;
    font-size:13px;
    display:flex;
    align-items:center;
    gap:5px;
}
.pdf-link:hover { text-decoration:underline; }
.pdf-icon { width:20px; height:20px; }
.sno-col  { width:50px; text-align:center; }

/* ── Pin button ── */
.pin-btn {
    background:#f8f9fa;
    border:1px solid #ccc;
    color:#333;
    cursor:pointer;
    padding:6px 10px;
    border-radius:4px;
    font-size:13px;
    transition:background 0.2s;
}
.pin-btn:hover    { background:#e2e6ea; }
.pin-btn.pinned   { background:#d4edda; border-color:#28a745; color:#155724; }
.pin-btn.loading  { opacity:0.5; pointer-events:none; }

/* ── Pinned banner ── */
.pinned-card {
    border-left:5px solid #28a745;
    background:#eafaf1;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
}
.pinned-card h3   { margin:0 0 4px; color:#28a745; font-size:17px; }
.pinned-card p    { margin:0; color:#333; font-size:14px; }
.unpin-btn {
    background:none;
    border:1px solid #28a745;
    color:#28a745;
    padding:5px 10px;
    border-radius:4px;
    font-size:12px;
    cursor:pointer;
    white-space:nowrap;
    flex-shrink:0;
}
.unpin-btn:hover { background:#c3e6cb; }

/* ── Top bar ── */
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;color:#333;font-size:14px;}
.topbar form{margin:0;}
.logout-btn{background:#fff;border:1px solid #ccc;border-radius:4px;padding:6px 12px;cursor:pointer;font-size:13px;}
.logout-btn:hover{background:#e2e6ea;}

/* ── My pins ── */
.pin-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #d5eadc;flex-wrap:wrap;}
.pin-row:last-child{border-bottom:none;}
.pin-row .meta{font-size:12px;color:#555;}
.pin-row .actions{display:flex;gap:8px;align-items:center;}
.pin-row a.subject-link{font-size:12px;color:#1a73e8;text-decoration:none;}
.notes-badge{display:inline-block;margin-left:8px;font-size:12px;color:#555;background:#eef2f7;border-radius:10px;padding:1px 8px;}

.tg-card{border-left:5px solid #229ed9;}
.tg-card h2{color:#229ed9;margin-bottom:6px;}
.tg-btn{background:#229ed9;color:#fff;border:none;border-radius:4px;padding:8px 14px;font-size:13px;cursor:pointer;text-decoration:none;display:inline-block;margin-right:8px;}
.tg-btn.alt{background:#f8f9fa;color:#333;border:1px solid #ccc;}
.tg-btn:disabled{opacity:.5;}
.tag{display:inline-block;font-size:11px;border-radius:10px;padding:1px 8px;margin-left:6px;font-weight:600;vertical-align:middle;}
.tag-pin{background:#d4edda;color:#155724;}
.tag-last{background:#e0ecff;color:#1a56b0;}

/* ── Toast notification ── */
#toast {
    position:fixed;
    bottom:24px;
    left:50%;
    transform:translateX(-50%) translateY(20px);
    background:#333;
    color:#fff;
    padding:10px 20px;
    border-radius:6px;
    font-size:13px;
    opacity:0;
    pointer-events:none;
    transition:opacity 0.25s, transform 0.25s;
    z-index:9999;
}
#toast.show {
    opacity:1;
    transform:translateX(-50%) translateY(0);
}
</style>
</head>

<body>
<div class="container">

<!-- ── Top bar ── -->
<div class="topbar">
    <span>👋 Hi, <strong><?= htmlspecialchars($user_name) ?></strong></span>
    <form method="post" action="logout.php">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <button type="submit" class="logout-btn">Log out</button>
    </form>
</div>

<!-- ── Continue card: pinned + last watched (same lecture is shown only once) ── -->
<?php if (!empty($ctx)): ?>
<div class="card pinned-card" id="pinned-banner" style="display:block;">
    <h3>📍 Continue Where You Left Off</h3>
    <?php foreach ($ctx as $c_subject_id => $c): ?>
        <?php foreach ($c['items'] as $it): ?>
        <div class="pin-row">
            <div>
                <strong><?= htmlspecialchars($it['topic_name']) ?></strong>
                <?php if ($it['pinned']): ?><span class="tag tag-pin">📍 Pinned</span><?php endif; ?>
                <?php if ($it['last']): ?><span class="tag tag-last">🕘 Last watched</span><?php endif; ?>
                <div class="meta"><?= htmlspecialchars($c['subject_name']) ?> · <?= htmlspecialchars($c['teacher_name']) ?></div>
            </div>
            <div class="actions">
                <a class="open-btn" target="_blank" data-lecture="<?= (int)$it['lecture_id'] ?>"
                   href="./player/player.html?video=<?= urlencode($it['lecture_url']) ?>&pdf=<?= urlencode($it['pdf_url']) ?>&lecture_id=<?= (int)$it['lecture_id'] ?>">▶ Continue</a>
                <a class="subject-link" href="?subject=<?= (int)$c_subject_id ?>&teacher=<?= (int)$c['teacher_id'] ?>">Open subject</a>
                <?php if ($it['pinned']): ?>
                <button class="unpin-btn" onclick="unpinLecture(<?= (int)$c_subject_id ?>)">✕ Unpin</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Telegram ── -->
<div class="card tg-card" id="tg-card">
    <h2>📲 Telegram</h2>
    <?php if ($tg_chat): ?>
        <p id="tg-text">Connected<?= $tg_user ? ' as <strong>@' . htmlspecialchars($tg_user) . '</strong>' : '' ?>. Send <code>/continue</code> to the bot any time to see where you left off.</p>
        <button class="tg-btn" id="tg-send">📩 Send to Telegram now</button>
        <button class="tg-btn alt" id="tg-unlink">Disconnect</button>
    <?php else: ?>
        <p id="tg-text">Get "where you left off" in Telegram and continue from there too.</p>
        <button class="tg-btn" id="tg-connect">Connect Telegram</button>
    <?php endif; ?>
</div>

<!-- ── Subject selector ── -->
<div class="card">
<h2>Select Subject</h2>
<form method="GET">
<select name="subject" onchange="this.form.submit()">
<option value="">-- Choose Subject --</option>
<?php while($s = $subjects->fetch_assoc()): ?>
<option value="<?= $s['id'] ?>" <?= ($selected_subject == $s['id']) ? 'selected' : '' ?>>
    <?= htmlspecialchars($s['subject_name']) ?> (<?= htmlspecialchars($s['teacher_name']) ?>)
</option>
<?php endwhile; ?>
</select>
</form>
</div>

<!-- ── Teacher selector ── -->
<?php if (!empty($teachers)): ?>
<div class="card">
<h2>Select Faculty</h2>
<form method="GET">
<input type="hidden" name="subject" value="<?= $selected_subject ?>">
<select name="teacher" onchange="this.form.submit()">
<option value="">-- Choose Faculty --</option>
<?php foreach($teachers as $t): ?>
<option value="<?= $t['id'] ?>" <?= ($selected_teacher == $t['id']) ? 'selected' : '' ?>>
    <?= htmlspecialchars($t['name']) ?>
</option>
<?php endforeach; ?>
</select>
</form>
</div>
<?php endif; ?>

<!-- ── Lectures table ── -->
<?php if (!empty($lectures)): ?>
<div class="card">
<h2>Lectures</h2>
<table>
<tr>
    <th class="sno-col">S.No</th>
    <th>Topic</th>
    <th>Video Class</th>
    <th>PDF Notes</th>
    <th>Action</th>
</tr>
<?php
$counter = 1;
foreach($lectures as $l):
    $isPinned = (($pin_by_subject[$selected_subject] ?? 0) == $l['id']);
?>
<tr id="row-<?= $l['id'] ?>">
    <td class="sno-col"><?= $counter++ ?></td>
    <td>
        <?= htmlspecialchars($l['topic_name']) ?>
        <?php if (($last_by_subject[$selected_subject] ?? 0) == $l['id']): ?><span class="tag tag-last">🕘 Last watched</span><?php endif; ?>
        <?php if (!empty($noted[$l['id']])): ?><span class="notes-badge" title="You have notes on this lecture">📝 <?= (int)$noted[$l['id']] ?></span><?php endif; ?>
    </td>
    <td>
        <a class="open-btn"
           target="_blank" data-lecture="<?= (int)$l['id'] ?>"
           href="./player/player.html?video=<?= urlencode($l['lecture_url']) ?>&pdf=<?= urlencode($l['pdf_url']) ?>&lecture_id=<?= $l['id'] ?>">
            ▶ Watch Video
        </a>
    </td>
    <td>
        <?php if(!empty($l['pdf_url'])): ?>
        <a class="pdf-link" target="_blank" href="<?= htmlspecialchars($l['pdf_url']) ?>">
            <img src="https://cdn-icons-png.flaticon.com/512/337/337946.png" class="pdf-icon" alt="PDF">
            View Notes
        </a>
        <?php else: ?>
        <span style="color:#ccc;font-size:12px;">No Notes</span>
        <?php endif; ?>
    </td>
    <td>
        <button
            class="pin-btn <?= $isPinned ? 'pinned' : '' ?>"
            id="pin-btn-<?= $l['id'] ?>"
            onclick="pinLecture(<?= (int)$l['id'] ?>, <?= (int)$selected_subject ?>, <?= (int)$selected_teacher ?>)">
            <?= $isPinned ? '📍 Pinned' : '📌 Pin' ?>
        </button>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

</div><!-- /container -->

<div id="toast"></div>

<script>
// ── Current context (PHP-injected) ────────────────────────────────────────────
const CTX = {
    subject_id: <?= (int)$selected_subject ?>,
    teacher_id: <?= (int)$selected_teacher ?>
};

// ── Toast helper ──────────────────────────────────────────────────────────────
function showToast(msg, duration = 2500) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), duration);
}

// ── Call pin_api.php (redirects to login if the session has expired) ─────────
async function pinApi(payload) {
    const res = await fetch('pin_api.php', {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'Content-Type': 'application/json' },
        body:        JSON.stringify(payload)
    });
    if (res.status === 401) { window.location.href = 'auth.php'; throw new Error('auth'); }
    return res.json();
}

// ── Telegram card ─────────────────────────────────────────────────────────────
const CSRF = <?= json_encode($csrf) ?>;
async function tgCall(action) {
    const res = await fetch('tg_link.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ action })
    });
    if (res.status === 401) { window.location.href = 'auth.php'; throw new Error('auth'); }
    return res.json();
}
(function () {
    const connect = document.getElementById('tg-connect');
    const send    = document.getElementById('tg-send');
    const unlink  = document.getElementById('tg-unlink');

    if (connect) connect.addEventListener('click', async () => {
        connect.disabled = true;
        try {
            const d = await tgCall('link');
            if (!d.ok) { connect.disabled = false; showToast('⚠️ ' + (d.error || 'Could not start linking'), 5000); return; }
            document.getElementById('tg-text').textContent = 'Open Telegram and press START in the bot (link valid for 15 minutes). This page updates by itself.';
            const a = document.createElement('a');
            a.className = 'tg-btn'; a.href = d.url; a.target = '_blank'; a.rel = 'noopener';
            a.textContent = 'Open Telegram';
            connect.replaceWith(a);
            // wait for the bot to confirm the link
            let tries = 0;
            const t = setInterval(async () => {
                if (++tries > 100) { clearInterval(t); return; }
                try { const s = await tgCall('status'); if (s.linked) { clearInterval(t); window.location.reload(); } } catch (_) {}
            }, 3000);
        } catch (err) { connect.disabled = false; }
    });

    if (send) send.addEventListener('click', async () => {
        send.disabled = true;
        try {
            const d = await tgCall('send');
            showToast(d.ok ? '📩 Sent to Telegram' : '⚠️ ' + (d.error || 'Could not send'), 3500);
        } catch (_) {}
        send.disabled = false;
    });

    if (unlink) unlink.addEventListener('click', async () => {
        if (!confirm('Disconnect Telegram from your account?')) return;
        try { const d = await tgCall('unlink'); if (d.ok) window.location.reload(); } catch (_) {}
    });
})();

// ── Remember "last watched" when a lecture is opened from this page ──────────
let needRefresh = false;
function recordWatch(e) {
    const a = e.target.closest('a[data-lecture]');
    if (!a) return;
    needRefresh = true;
    fetch('pin_api.php', {
        method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'watch', lecture_id: parseInt(a.dataset.lecture, 10) })
    }).catch(() => {});
}
document.addEventListener('click', recordWatch);
document.addEventListener('auxclick', recordWatch);          // middle-click "open in new tab"
// When you come back from the player tab, refresh so the card shows the new last-watched lecture
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && needRefresh) window.location.reload();
});

// ── Pin a lecture (one pin per subject — re-pinning replaces the old one) ────
async function pinLecture(lectureId, subjectId, teacherId) {
    const btn = document.getElementById('pin-btn-' + lectureId);
    if (btn) btn.classList.add('loading');

    try {
        const data = await pinApi({
            action:     'save',
            lecture_id: lectureId,
            subject_id: subjectId,
            teacher_id: teacherId
        });

        if (data.ok) {
            showToast('📍 Pinned: ' + (data.topic_name || ''));
            setTimeout(() => window.location.reload(), 500);   // refresh "My Pins" + buttons
        } else {
            if (btn) btn.classList.remove('loading');
            showToast('⚠️ Could not save pin. Try again.');
        }
    } catch(err) {
        if (btn) btn.classList.remove('loading');
        if (err.message !== 'auth') showToast('⚠️ Network error saving pin.');
    }
}

// ── Unpin ─────────────────────────────────────────────────────────────────────
async function unpinLecture(subjectId) {
    try {
        const data = await pinApi({ action: 'delete', subject_id: subjectId });
        if (data.ok) {
            showToast('Pin removed.');
            setTimeout(() => window.location.reload(), 400);
        } else {
            showToast('⚠️ Could not remove pin.');
        }
    } catch(err) {
        if (err.message !== 'auth') showToast('⚠️ Could not remove pin.');
    }
}
</script>

</body>
</html>