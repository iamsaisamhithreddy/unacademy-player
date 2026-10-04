<?php

session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}
include 'db.php';

// --- 1. HANDLE SAVING DATA ---

// Add Teacher
if (isset($_POST['add_teacher'])) {
    $name = $_POST['teacher_name'];
    $stmt = $conn->prepare("INSERT INTO teachers (name) VALUES (?)");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $stmt->close();
}

// Add Subject
if (isset($_POST['add_subject'])) {
    $sub_name = $_POST['subject_name'];
    $t_id = $_POST['teacher_id'];
    $stmt = $conn->prepare("INSERT INTO subjects (subject_name, teacher_id) VALUES (?, ?)");
    $stmt->bind_param("si", $sub_name, $t_id);
    $stmt->execute();
    $stmt->close();
}

// Add Lecture
if (isset($_POST['add_lecture'])) {
    $subject_id = $_POST['subject_id'];
    $topic_name = $_POST['topic_name'];
    $lecture_url = $_POST['lecture_url'];
    $pdf_url = $_POST['pdf_url'];
    $json_url = $_POST['json_url'];

    $stmt = $conn->prepare("INSERT INTO lectures (subject_id, topic_name, lecture_url, pdf_url, json_url) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $subject_id, $topic_name, $lecture_url, $pdf_url, $json_url);
    $stmt->execute();
    $stmt->close();
}

// Handle Delete Lecture
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    $conn->query("DELETE FROM lectures WHERE id = $del_id");
    header("Location: index.php"); 
    exit();
}

// --- 2. FETCH DATA ---
$teachers = $conn->query("SELECT * FROM teachers ORDER BY name ASC");
$subjects_dropdown = $conn->query("SELECT s.id, s.subject_name, t.name as teacher_name FROM subjects s JOIN teachers t ON s.teacher_id = t.id ORDER BY s.subject_name ASC");
$lectures_result = $conn->query("SELECT l.*, s.subject_name, t.name as teacher_name FROM lectures l JOIN subjects s ON l.subject_id = s.id JOIN teachers t ON s.teacher_id = t.id ORDER BY l.created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lecture Admin Panel</title>
<style>
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
.container { max-width: 1100px; margin: auto; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
.card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
h2, h3 { margin-top: 0; color: #1a73e8; border-bottom: 1px solid #eee; padding-bottom: 10px; }
input, select { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
button { width: 100%; padding: 12px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; transition: 0.3s; }
.btn-blue { background: #1a73e8; color: white; }
.btn-green { background: #28a745; color: white; }
.extract-box { background: #fff3cd; border: 1px solid #ffeeba; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
th, td { padding: 15px; text-align: left; border-bottom: 1px solid #eee; }
th { background: #f8f9fa; }
.badge { text-decoration: none; padding: 5px 10px; border-radius: 4px; font-size: 12px; color: white; margin-right: 5px; display: inline-block; }
.bg-red { background: #dc3545; }
.bg-blue { background: #007bff; }
.bg-dark { background: #343a40; }
.bg-open { background: #28a745; }
</style>
</head>
<body>

<div class="container">
<h2>Lecture Management System</h2>

<div class="grid">
<div class="card">
<h3>1. Add Teacher</h3>
<form method="POST">
<input type="text" name="teacher_name" placeholder="Teacher Name" required>
<button type="submit" name="add_teacher" class="btn-blue">Save Teacher</button>
</form>
</div>

<div class="card">
<h3>2. Add Subject</h3>
<form method="POST">
<input type="text" name="subject_name" placeholder="Subject (e.g. Physics)" required>
<select name="teacher_id" required>
<option value="">-- Assign Teacher --</option>
<?php while($t = $teachers->fetch_assoc()): ?>
<option value="<?= $t['id'] ?>"><?= $t['name'] ?></option>
<?php endwhile; ?>
</select>
<button type="submit" name="add_subject" class="btn-blue">Save Subject</button>
</form>
</div>
</div>

<div class="card">
<h3>3. Add Lecture Details</h3>

<div class="extract-box">
<strong>URL Auto-Extractor:</strong>
<input type="text" id="raw_url" placeholder="Paste studyuk.site URL here..." oninput="extractLinks()">
<small>This will automatically fill the Video, PDF, and JSON fields below.</small>
</div>

<form method="POST">
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
<select name="subject_id" required>
<option value="">-- Select Subject --</option>
<?php 
$subjects_dropdown->data_seek(0);
while($s = $subjects_dropdown->fetch_assoc()): ?>
<option value="<?= $s['id'] ?>"><?= $s['subject_name'] ?> (<?= $s['teacher_name'] ?>)</option>
<?php endwhile; ?>
</select>
<input type="text" name="topic_name" placeholder="Topic Name" required>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
<input type="url" name="lecture_url" id="f_video" placeholder="Video Link" required>
<input type="url" name="pdf_url" id="f_pdf" placeholder="PDF Link" required>
<input type="url" name="json_url" id="f_json" placeholder="JSON Link" required>
</div>
<button type="submit" name="add_lecture" class="btn-green">Publish Lecture</button>
</form>
</div>

<h3>Published Lectures</h3>
<table>
<thead>
<tr>
<th>Subject</th>
<th>Teacher</th>
<th>Topic</th>
<th>Links</th>
<th>Action</th>
</tr>
</thead>
<tbody>
<?php if ($lectures_result->num_rows > 0): ?>
<?php while($row = $lectures_result->fetch_assoc()): ?>
<tr>
<td><strong><?= htmlspecialchars($row['subject_name']) ?></strong></td>
<td><?= htmlspecialchars($row['teacher_name']) ?></td>
<td><?= htmlspecialchars($row['topic_name']) ?></td>
<td>
<a href="<?= $row['lecture_url'] ?>" target="_blank" class="badge bg-red">Video</a>
<a href="<?= $row['pdf_url'] ?>" target="_blank" class="badge bg-blue">PDF</a>
<a href="<?= $row['json_url'] ?>" target="_blank" class="badge bg-dark">JSON</a>

<!-- ✅ NEW: OPEN PLAYER -->
<a href="player.html?video=<?= urlencode($row['lecture_url']) ?>&pdf=<?= urlencode($row['pdf_url']) ?>" 
   target="_blank" 
   class="badge bg-open">Open</a>

</td>
<td>
<a href="index.php?delete_id=<?= $row['id'] ?>" style="color:red; font-size:12px;" onclick="return confirm('Delete?')">Delete</a>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="5" style="text-align:center;">No lectures found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<script>
function extractLinks() {
    const urlInput = document.getElementById('raw_url').value.trim();
    if(!urlInput) return;

    try {
        const urlObj = new URL(urlInput);
        const params = new URLSearchParams(urlObj.search);

        let videoUrl = params.get('playurl');
        if (videoUrl) {
            document.getElementById('f_video').value = videoUrl;
            document.getElementById('f_json').value = videoUrl.replace('output.webm', 'data.json');
        }

        let pdfUrl = params.get('pdf');
        if (pdfUrl) {
            document.getElementById('f_pdf').value = decodeURIComponent(pdfUrl);
        }
    } catch (e) {}
}
</script>

</body>
</html>
