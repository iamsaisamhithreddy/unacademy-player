<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. Database Connection
$conn = new mysqli('localhost', 'sairedd1_sai', 'saireddyA1@#$', 'sairedd1_LECTURES');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* ---------------- SUBJECTS ---------------- */
$subjects = $conn->query("
    SELECT s.id, s.subject_name, t.name AS teacher_name
    FROM subjects s
    JOIN teachers t ON s.teacher_id = t.id
    ORDER BY s.subject_name ASC
");

/* ---------------- SELECTED ---------------- */
$selected_subject = isset($_GET['subject']) ? (int)$_GET['subject'] : 0;
$selected_teacher = isset($_GET['teacher']) ? (int)$_GET['teacher'] : 0;

/* ---------------- TEACHERS ---------------- */
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
        $teachers[] = ["id"=>$tid, "name"=>$tname];
    }
    $stmt->close();
}

/* ---------------- LECTURES ---------------- */
$lectures = [];
if ($selected_subject && $selected_teacher) {
    $stmt = $conn->prepare("
        SELECT topic_name, lecture_url, pdf_url
        FROM lectures
        WHERE subject_id = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $selected_subject);
    $stmt->execute();
    $stmt->bind_result($ltopic, $lvideo, $lpdf);

    while ($stmt->fetch()) {
        // Dynamic JSON URL generation by replacing output.webm with data.json
        $json_url = str_replace('output.webm', 'data.json', $lvideo);
        
        $lectures[] = [
            "topic_name" => $ltopic,
            "lecture_url" => $lvideo,
            "json_url" => $json_url,
            "pdf_url" => $lpdf
        ];
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Lecture Portal</title>
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #fff; padding: 40px; color: #000; }
    .container { max-width: 900px; margin: auto; }
    
    .card { border-bottom: 1px solid #eee; padding-bottom: 25px; margin-bottom: 25px; }
    h2 { margin-top: 0; font-size: 24px; font-weight: bold; }
    
    select { 
        width: 100%; 
        padding: 12px; 
        border: 1px solid #ddd; 
        border-radius: 4px; 
        font-size: 16px; 
        margin-top: 10px;
    }

    .lecture-item { padding: 20px 0; border-bottom: 1px solid #f0f0f0; }
    .lecture-item:last-child { border-bottom: none; }
    
    .topic-name { 
        font-size: 18px; 
        font-weight: 800; 
        text-transform: uppercase; 
        display: block; 
        margin-bottom: 8px; 
    }

    .links-container { display: flex; gap: 20px; align-items: center; }
    
    .dl-all { 
        color: #008000; 
        font-weight: bold; 
        text-decoration: underline; 
        cursor: pointer; 
        font-size: 16px;
    }
    
    .file-link { 
        color: #1a73e8; 
        text-decoration: none; 
        font-size: 16px;
    }
    .file-link:hover { text-decoration: underline; }
</style>
</head>

<body>
<div class="container">

    <div class="card">
        <h2>Select Lecture</h2>
        <form method="GET" id="filterForm">
            <select name="subject" onchange="document.getElementById('filterForm').submit()">
                <option value="">-- Select Subject --</option>
                <?php while($s = $subjects->fetch_assoc()): ?>
                    <option value="<?= $s['id'] ?>" <?= ($selected_subject==$s['id'])?'selected':'' ?>>
                        <?= htmlspecialchars($s['subject_name']) ?> (<?= htmlspecialchars($s['teacher_name']) ?>)
                    </option>
                <?php endwhile; ?>
            </select>

            <?php if (!empty($teachers)): ?>
            <select name="teacher" onchange="document.getElementById('filterForm').submit()">
                <option value="">-- Select Faculty --</option>
                <?php foreach($teachers as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= ($selected_teacher==$t['id'])?'selected':'' ?>>
                        <?= htmlspecialchars($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!empty($lectures)): ?>
    <div class="results">
        <?php foreach($lectures as $l): ?>
            <div class="lecture-item">
                <span class="topic-name"><?= htmlspecialchars($l['topic_name']) ?></span>
                <div class="links-container">
                    <span class="dl-all" onclick="multiDownload('<?= $l['lecture_url'] ?>', '<?= $l['json_url'] ?>', '<?= $l['pdf_url'] ?>')">
                        [Download All 3 Files]
                    </span>

                    <a href="<?= htmlspecialchars($l['lecture_url']) ?>" target="_blank" class="file-link">Video (.webm)</a>
                    <a href="<?= htmlspecialchars($l['json_url']) ?>" target="_blank" class="file-link">Data (.json)</a>
                    <?php if($l['pdf_url']): ?>
                        <a href="<?= htmlspecialchars($l['pdf_url']) ?>" target="_blank" class="file-link">Notes (.pdf)</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script>
/**
 * Triggers downloads for the Video, the correctly mapped JSON, and the PDF.
 */
function multiDownload(video, json, pdf) {
    const urls = [video, json];
    if (pdf && pdf.trim() !== "") {
        urls.push(pdf);
    }

    urls.forEach((url, i) => {
        setTimeout(() => {
            const a = document.createElement('a');
            a.href = url;
            a.target = '_blank';
            a.click();
        }, i * 600); // Staggered delay to prevent browser blocking
    });
}
</script>
</body>
</html>