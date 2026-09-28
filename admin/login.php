<?php
session_start();
require_once '../includes/db.php';

const MAX_ATTEMPTS = 3;
const LOCKOUT_SECONDS = 60;

if (($_SESSION['admin_logged_in'] ?? false) === true) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Lockout expired: start counting again
if (!empty($_SESSION['login_locked_until']) && time() >= $_SESSION['login_locked_until']) {
    unset($_SESSION['login_locked_until']);
    $_SESSION['login_attempts'] = 0;
}
$locked = !empty($_SESSION['login_locked_until']);

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please try again.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until'], $_SESSION['csrf']);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            header('Location: index.php');
            exit;
        }

        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= MAX_ATTEMPTS) {
            $_SESSION['login_locked_until'] = time() + LOCKOUT_SECONDS;
            $locked = true;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

if ($locked) {
    $error = 'Too many attempts. Please wait.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Admin Login — PixelCraft</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            background: #0a0a0a;
            color: #eee;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #111;
            border: 1px solid #1f1f1f;
            border-top: 4px solid #cc0000;
            border-radius: 10px;
            padding: 40px 36px 28px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6);
        }

        .login-logo {
            text-align: center;
            font-size: 32px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #fff;
        }
        .login-logo span { color: #cc0000; }

        .login-sub {
            text-align: center;
            margin-top: 4px;
            font-size: 12px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #888;
        }

        .login-form { margin-top: 32px; }

        .login-form label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #bbb;
        }

        .login-form input {
            width: 100%;
            margin-bottom: 18px;
            padding: 12px 14px;
            background: #0a0a0a;
            border: 1px solid #2a2a2a;
            border-radius: 6px;
            color: #fff;
            font: inherit;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .login-form input::placeholder { color: #555; }
        .login-form input:focus {
            border-color: #cc0000;
            box-shadow: 0 0 0 3px rgba(204, 0, 0, 0.2);
        }

        .login-btn {
            width: 100%;
            margin-top: 6px;
            padding: 13px;
            background: #cc0000;
            border: 0;
            border-radius: 6px;
            color: #fff;
            font: inherit;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }
        .login-btn:hover,
        .login-btn:focus-visible { background: #aa0000; }
        .login-btn:disabled { background: #444; color: #888; cursor: not-allowed; }

        .login-error {
            margin-top: 16px;
            padding: 10px 12px;
            background: rgba(204, 0, 0, 0.1);
            border: 1px solid rgba(204, 0, 0, 0.4);
            border-radius: 6px;
            color: #ff4d4d;
            font-size: 14px;
            text-align: center;
        }

        .login-footer {
            margin-top: 28px;
            padding-top: 16px;
            border-top: 1px solid #1f1f1f;
            text-align: center;
            font-size: 12px;
            color: #666;
        }

        @media (max-width: 480px) {
            .login-card { padding: 32px 20px 22px; }
            .login-logo { font-size: 28px; }
        }
    </style>
</head>
<body>

<main class="login-card">
    <div class="login-logo">Pixel<span>Craft</span></div>
    <p class="login-sub">Admin Panel</p>

    <form method="POST" class="login-form" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">

        <label for="username">Username</label>
        <input type="text" id="username" name="username" placeholder="Enter username"
               value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>" required autofocus autocomplete="username">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Enter password"
               required autocomplete="current-password">

        <button type="submit" class="login-btn"<?= $locked ? ' disabled' : '' ?>>Login</button>

        <?php if ($error): ?>
        <p class="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </form>

    <p class="login-footer">&copy; 2026 Pixel Craft Sdn Bhd — Admin Access Only</p>
</main>

</body>
</html>
