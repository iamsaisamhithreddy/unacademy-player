<?php
/*
  pin_api.php — Pinned lectures, saved per LOGGED-IN USER (one pin per subject).

  Actions (POST JSON body):
    { "action": "save",   "lecture_id": INT, "subject_id": INT, "teacher_id": INT }
    { "action": "load",   "subject_id": INT }
    { "action": "delete", "subject_id": INT }
    { "action": "list" }                       -> all of this user's pins (every subject)
    { "action": "watch",  "lecture_id": INT }  -> remember "last watched lecture" for that lecture's subject

  Table user_pins is auto-created. Re-pinning in the same subject replaces the old pin.
*/

header('Content-Type: application/json');
error_reporting(0);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$uid = require_login_json();
session_write_close();   // don't hold the session lock during DB work

$conn->set_charset('utf8mb4');
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

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

if ($action === 'save') {
    $lid = (int)($body['lecture_id'] ?? 0);
    $sid = (int)($body['subject_id'] ?? 0);
    $tid = (int)($body['teacher_id'] ?? 0);

    if (!$lid || !$sid || !$tid) {
        echo json_encode(['ok' => false, 'error' => 'Missing fields']);
        exit;
    }

    // Take the topic name from the DB (and make sure the lecture belongs to this subject)
    $stmt = $conn->prepare("SELECT topic_name FROM lectures WHERE id = ? AND subject_id = ? LIMIT 1");
    $stmt->bind_param("ii", $lid, $sid);
    $stmt->execute();
    $stmt->bind_result($topic);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        echo json_encode(['ok' => false, 'error' => 'Lecture not found']);
        exit;
    }
    $topic = mb_substr($topic, 0, 512);

    $stmt = $conn->prepare("
        INSERT INTO user_pins (user_id, subject_id, teacher_id, lecture_id, topic_name)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id),
                                lecture_id = VALUES(lecture_id),
                                topic_name = VALUES(topic_name),
                                pinned_at  = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("iiiis", $uid, $sid, $tid, $lid, $topic);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok, 'topic_name' => $topic]);

} elseif ($action === 'load') {
    $sid = (int)($body['subject_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT lecture_id, topic_name, pinned_at
        FROM user_pins
        WHERE user_id = ? AND subject_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $uid, $sid);
    $stmt->execute();
    $stmt->bind_result($lid, $topic, $at);
    $pin = null;
    if ($stmt->fetch()) {
        $pin = ['lecture_id' => $lid, 'topic_name' => $topic, 'pinned_at' => $at];
    }
    $stmt->close();
    echo json_encode(['ok' => true, 'pin' => $pin]);

} elseif ($action === 'delete') {
    $sid = (int)($body['subject_id'] ?? 0);

    $stmt = $conn->prepare("DELETE FROM user_pins WHERE user_id = ? AND subject_id = ?");
    $stmt->bind_param("ii", $uid, $sid);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok]);

} elseif ($action === 'list') {
    $stmt = $conn->prepare("
        SELECT subject_id, teacher_id, lecture_id, topic_name, pinned_at
        FROM user_pins
        WHERE user_id = ?
        ORDER BY pinned_at DESC
    ");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->bind_result($sid, $tid, $lid, $topic, $at);
    $pins = [];
    while ($stmt->fetch()) {
        $pins[] = [
            'subject_id' => $sid, 'teacher_id' => $tid, 'lecture_id' => $lid,
            'topic_name' => $topic, 'pinned_at' => $at,
        ];
    }
    $stmt->close();
    echo json_encode(['ok' => true, 'pins' => $pins]);

} elseif ($action === 'watch') {
    $lid = (int)($body['lecture_id'] ?? 0);

    // The subject comes from the DB, not from the client
    $stmt = $conn->prepare("SELECT subject_id FROM lectures WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $lid);
    $stmt->execute();
    $stmt->bind_result($sid);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        echo json_encode(['ok' => false, 'error' => 'Lecture not found']);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO user_last_watched (user_id, subject_id, lecture_id)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE lecture_id = VALUES(lecture_id), watched_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("iii", $uid, $sid, $lid);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}