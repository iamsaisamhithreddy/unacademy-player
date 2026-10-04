<?php
// ============================================================
// auth.php — Login + Signup on the same page (student users)
// ============================================================

$DEBUG = true; // set to false once everything works
error_reporting(E_ALL);
ini_set('display_errors', $DEBUG ? 1 : 0);

// --- SESSION (30-day login, shared helpers) ---
require_once __DIR__ . '/session.php';

// --- DATABASE CONNECTION (from db.php, same folder) ---
require_once __DIR__ . '/db.php';   // gives us $conn
$conn->set_charset("utf8mb4");

// --- AUTO-CREATE users TABLE (safe to run every time) ---
$conn->query("
    CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// --- WHERE TO GO AFTER LOGIN ---
$redirect_after_login = "user.php";   // student portal (index.php is the admin panel)

// Already logged in? Skip the form.
if (!empty($_SESSION['user_logged_in'])) {
    header("Location: $redirect_after_login");
    exit;
}

// --- CSRF TOKEN ---
$csrf = csrf_token();

$error   = "";
$success = "";
$mode    = "login";   // which tab is shown: login | signup
$old     = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        $error = "Session expired. Please refresh and try again.";
    } else {
        $action = $_POST['action'] ?? '';

        // ---------------- SIGNUP ----------------
        if ($action === 'signup') {
            $mode = "signup";
            $name     = trim($_POST['name'] ?? '');
            $email    = strtolower(trim($_POST['email'] ?? ''));
            $pass     = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm'] ?? '';
            $old = ['name' => $name, 'email' => $email];

            if ($name === '' || mb_strlen($name) > 100) {
                $error = "Please enter your name (max 100 characters).";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address.";
            } elseif (strlen($pass) < 8) {
                $error = "Password must be at least 8 characters.";
            } elseif ($pass !== $confirm) {
                $error = "Passwords do not match.";
            } else {
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();
                $exists = $stmt->num_rows > 0;
                $stmt->close();

                if ($exists) {
                    $error = "An account with this email already exists. Please log in.";
                } else {
                    $hash = password_hash($pass, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
                    $stmt->bind_param("sss", $name, $email, $hash);

                    if ($stmt->execute()) {
                        $stmt->close();
                        $mode = "login";
                        $success = "Account created! You can log in now.";
                        $old = ['name' => '', 'email' => $email];
                    } else {
                        $error = $DEBUG ? "DB error: " . $stmt->error : "Could not create account. Try again.";
                        $stmt->close();
                    }
                }
            }
        }

        // ---------------- LOGIN ----------------
        elseif ($action === 'login') {
            $mode = "login";
            $email = strtolower(trim($_POST['email'] ?? ''));
            $pass  = $_POST['password'] ?? '';
            $old['email'] = $email;

            $stmt = $conn->prepare("SELECT id, name, password_hash FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->bind_result($uid, $uname, $uhash);
            $found = $stmt->fetch();
            $stmt->close();

            if ($found && password_verify($pass, $uhash)) {
                session_regenerate_id(true);
                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id']        = $uid;
                $_SESSION['user_name']      = $uname;
                $_SESSION['user_email']     = $email;
                header("Location: $redirect_after_login");
                exit;
            } else {
                // Same message for both cases so emails can't be enumerated
                $error = "Invalid email or password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login / Sign Up</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body {
            display: flex; justify-content: center; align-items: center;
            min-height: 100vh; background: #f5f7fa; margin: 0; padding: 16px;
        }
        .box {
            background: #fff; padding: 32px; border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            width: 100%; max-width: 380px;
        }
        h2 { margin: 0 0 20px; color: #1e293b; font-weight: 600; text-align: center; }
        .tabs { display: flex; background: #f1f5f9; border-radius: 8px; padding: 4px; margin-bottom: 22px; }
        .tab {
            flex: 1; padding: 10px; border: none; background: transparent;
            border-radius: 6px; font-weight: 600; font-size: 14px; color: #64748b; cursor: pointer;
        }
        .tab.active { background: #fff; color: #1d4ed8; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .input-group { margin-bottom: 14px; }
        label { display: block; margin-bottom: 5px; font-size: 14px; color: #64748b; }
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%; padding: 12px; border: 1px solid #e2e8f0;
            border-radius: 8px; font-size: 16px; transition: border 0.2s;
        }
        input:focus { outline: none; border-color: #1d4ed8; }
        button.submit {
            width: 100%; padding: 12px; margin-top: 8px; background: #1d4ed8; color: #fff;
            border: none; border-radius: 8px; font-weight: 600; font-size: 16px; cursor: pointer;
            transition: background 0.2s;
        }
        button.submit:hover { background: #1e40af; }
        .msg { padding: 10px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .error   { color: #dc2626; background: #fef2f2; border: 1px solid #fee2e2; }
        .success { color: #15803d; background: #f0fdf4; border: 1px solid #bbf7d0; }
        .form { display: none; }
        .form.active { display: block; }
    </style>
</head>
<body>

<div class="box">
    <h2>📚 Lectures</h2>

    <div class="tabs">
        <button type="button" class="tab <?= $mode === 'login' ? 'active' : '' ?>" data-target="login">Log In</button>
        <button type="button" class="tab <?= $mode === 'signup' ? 'active' : '' ?>" data-target="signup">Sign Up</button>
    </div>

    <?php if ($error): ?>
        <div class="msg error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="msg success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- LOGIN FORM -->
    <form method="post" id="login" class="form <?= $mode === 'login' ? 'active' : '' ?>">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="login">
        <div class="input-group">
            <label>Email</label>
            <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($old['email']) ?>" required>
        </div>
        <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Enter password" required>
        </div>
        <button type="submit" class="submit">Log In</button>
    </form>

    <!-- SIGNUP FORM -->
    <form method="post" id="signup" class="form <?= $mode === 'signup' ? 'active' : '' ?>">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="signup">
        <div class="input-group">
            <label>Full Name</label>
            <input type="text" name="name" placeholder="Your name" value="<?= htmlspecialchars($old['name']) ?>" required>
        </div>
        <div class="input-group">
            <label>Email</label>
            <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($old['email']) ?>" required>
        </div>
        <div class="input-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Min 8 characters" minlength="8" required>
        </div>
        <div class="input-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm" placeholder="Repeat password" minlength="8" required>
        </div>
        <button type="submit" class="submit">Create Account</button>
    </form>
</div>

<script>
    const tabs = document.querySelectorAll('.tab');
    const forms = document.querySelectorAll('.form');
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.toggle('active', t === tab));
            forms.forEach(f => f.classList.toggle('active', f.id === tab.dataset.target));
        });
    });
</script>

</body>
</html>
