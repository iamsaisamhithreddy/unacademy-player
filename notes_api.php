<?php
/*
  notes_api.php — Player notes, saved per LOGGED-IN USER and per LECTURE
  (any lecture can have notes — no "one per subject" limit).

  Actions (POST JSON body):
    { "action": "load",   "lecture_id": INT }                       -> { ok, exists, notes }
    { "action": "save",   "lecture_id": INT, "notes": [ ... ] }
    { "action": "delete", "lecture_id": INT }
    { "action": "list" }                                            -> lecture ids that have notes

  Table user_notes is auto-created.
*/

header('Content-Type: application/json');
error_reporting(0);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

$uid = require_login_json();
session_write_close();   // don't hold the session lock during DB work

$conn->set_charset('utf8mb4');
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

$body      = json_decode(file_get_contents('php://input'), true) ?: [];
$action    = $body['action'] ?? '';
$lectureId = (int)($body['lecture_id'] ?? 0);

if ($action === 'list') {
    $stmt = $conn->prepare("SELECT lecture_id, notes_json FROM user_notes WHERE user_id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $stmt->bind_result($lid, $json);
    $ids = [];
    while ($stmt->fetch()) {
        if (trim($json) !== '' && trim($json) !== '[]') $ids[] = $lid;
    }
    $stmt->close();
    echo json_encode(['ok' => true, 'lecture_ids' => $ids]);
    exit;
}

if (!$lectureId) {
    echo json_encode(['ok' => false, 'error' => 'Missing lecture_id']);
    exit;
}

if ($action === 'load') {
    $stmt = $conn->prepare("SELECT notes_json FROM user_notes WHERE user_id = ? AND lecture_id = ? LIMIT 1");
    $stmt->bind_param("ii", $uid, $lectureId);
    $stmt->execute();
    $stmt->bind_result($json);
    $notes  = [];
    $exists = false;
    if ($stmt->fetch()) {
        $exists = true;
        $notes  = json_decode($json, true) ?: [];
    }
    $stmt->close();
    echo json_encode(['ok' => true, 'exists' => $exists, 'notes' => $notes]);

} elseif ($action === 'save') {
    $notesArr = $body['notes'] ?? null;
    if (!is_array($notesArr)) {
        echo json_encode(['ok' => false, 'error' => 'notes must be an array']);
        exit;
    }
    $notesJson = json_encode(array_values($notesArr), JSON_UNESCAPED_UNICODE);
    if ($notesJson === false || strlen($notesJson) > 4 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'Notes invalid or too large']);
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO user_notes (user_id, lecture_id, notes_json)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE notes_json = VALUES(notes_json), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param("iis", $uid, $lectureId, $notesJson);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok]);

} elseif ($action === 'delete') {
    $stmt = $conn->prepare("DELETE FROM user_notes WHERE user_id = ? AND lecture_id = ?");
    $stmt->bind_param("ii", $uid, $lectureId);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => $ok]);

} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
