<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!empty($_SESSION['admin'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'Sesi tamat. Sila cuba lagi.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            unset($_SESSION['csrf']);
            header('Location: index.php');
            exit;
        }
        $error = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Admin Login - PixelCraft</title>
    <link rel="stylesheet" href="<?= asset('../', 'assets/css/style.css') ?>">
</head>
<body class="admin-auth">

<main class="auth-card">
    <a href="../index.php" class="logo">Pixel<span>Craft</span></a>
    <p class="auth-sub">Admin Panel</p>

    <?php if ($error): ?>
    <div class="auth-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">

        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">

        <button type="submit" class="btn">Log Masuk</button>
    </form>

    <a href="../index.php" class="auth-back">← Kembali ke laman utama</a>
</main>

</body>
</html>
