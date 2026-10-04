-- ============================================================================
-- OPTIONAL one-time migration: copy your OLD pins + notes (saved against random
-- session keys) into your NEW login account.
--
-- 1. Sign up / log in once at auth.php (this creates the `users`, `user_pins`
--    and `user_notes` tables).
-- 2. Put YOUR signup email below, then run this whole file in phpMyAdmin
--    (SQL tab) on database sairedd1_LECTURES.
-- Run it only ONCE, and only if you are the only person who used the old
-- pin/notes feature (it copies every old session's pins/notes to your account).
-- ============================================================================

SET @uid = (SELECT id FROM users WHERE email = 'PUT_YOUR_EMAIL_HERE' LIMIT 1);

-- Pins: oldest first, so the most recent pin per subject wins (one pin per subject).
-- (Pins whose lecture/subject no longer exist are skipped.)
INSERT INTO user_pins (user_id, subject_id, teacher_id, lecture_id, topic_name, pinned_at)
SELECT @uid, p.subject_id, p.teacher_id, p.lecture_id, p.topic_name, p.pinned_at
FROM pinned_lectures p
JOIN lectures l ON l.id = p.lecture_id
WHERE @uid IS NOT NULL
ORDER BY p.pinned_at ASC
ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id),
                        lecture_id = VALUES(lecture_id),
                        topic_name = VALUES(topic_name),
                        pinned_at  = VALUES(pinned_at);

-- Notes: one row per lecture (if several old sessions had notes for the same
-- lecture, the most recently updated one wins).
INSERT INTO user_notes (user_id, lecture_id, notes_json, updated_at)
SELECT @uid, n.lecture_id, n.notes_json, n.updated_at
FROM lecture_notes n
WHERE @uid IS NOT NULL
ORDER BY n.updated_at ASC
ON DUPLICATE KEY UPDATE notes_json = VALUES(notes_json), updated_at = VALUES(updated_at);

-- Check:
SELECT 'pins' AS what, COUNT(*) AS rows_for_you FROM user_pins  WHERE user_id = @uid
UNION ALL
SELECT 'notes',        COUNT(*)                 FROM user_notes WHERE user_id = @uid;
