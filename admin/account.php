<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '_layout.php';

if (($_SESSION['admin_logged_in'] ?? false) !== true) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

const MIN_PASSWORD = 8;

// The logged-in admin (by id; sessions from before admin_id was stored fall back to the username)
$stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = ? OR (? = 0 AND username = ?) LIMIT 1');
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$stmt->execute([$admin_id, $admin_id, $_SESSION['admin_username'] ?? '']);
$me = $stmt->fetch();
if (!$me) {
    // Account removed while logged in
    $_SESSION = [];
    header('Location: login.php');
    exit;
}

// ── Change password ─────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Session expired. Please try again.';
    } else {
        $current = $_POST['current'] ?? '';
        $new     = $_POST['new'] ?? '';
        $confirm = $_POST['confirm'] ?? '';
        if (!password_verify($current, $me['password'])) {
            $errors[] = 'Your current password is not right.';
        }
        if (mb_strlen($new) < MIN_PASSWORD) {
            $errors[] = 'The new password must be at least ' . MIN_PASSWORD . ' characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'The two new passwords don’t match.';
        } elseif (password_verify($new, $me['password'])) {
            $errors[] = 'The new password is the same as the current one.';
        }
        if (!$errors) {
            $pdo->prepare('UPDATE admin_users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Your password was changed. Use the new one next time you log in.'];
            header('Location: account.php');
            exit;
        }
    }
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Activity ────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS n, MAX(synced_at) AS last FROM price_sync_log WHERE admin = ?');
    $stmt->execute([$me['username']]);
    $syncs = $stmt->fetch();
} catch (PDOException $e) {
    $syncs = null; // sync history not set up (database/price_sync_log.sql)
}

$when = fn(?string $ts) => $ts ? date('j M Y, g:i a', strtotime($ts)) : null;
$login_at = isset($_SESSION['admin_login_at']) ? date('j M Y, g:i a', $_SESSION['admin_login_at']) : null;
$previous = $when($_SESSION['admin_previous_login'] ?? null);

admin_open('Your account', 'account');
?>
        <header class="admin-page-head">
            <div>
                <h1>Your account</h1>
                <p class="admin-page-sub">Your details, sign-in history and password.</p>
            </div>
        </header>

        <?php if ($flash): ?>
        <div class="admin-flash is-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <div class="acct-grid">
            <section class="dash-card is-blue acct-profile">
                <span class="acct-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($me['username'], 0, 1))) ?></span>
                <div>
                    <h2><?= e($me['username']) ?></h2>
                    <p>Administrator · full access to products, prices and the store</p>
                </div>
                <dl class="acct-facts">
                    <div><dt>Username</dt><dd><?= e($me['username']) ?></dd></div>
                    <div><dt>Role</dt><dd>Administrator</dd></div>
                    <div><dt>Admin ID</dt><dd>#<?= (int)$me['id'] ?></dd></div>
                </dl>
            </section>

            <section class="dash-card is-green">
                <header class="dash-card-head">
                    <span class="dash-card-icon is-green"><?= admin_icon('clock') ?></span>
                    <div>
                        <h2>Sign-in</h2>
                        <p>This session and the one before</p>
                    </div>
                </header>
                <dl class="acct-facts">
                    <div><dt>Logged in since</dt><dd><?= e($login_at ?? 'Before login times were recorded') ?></dd></div>
                    <div><dt>Previous login</dt><dd><?= e($previous ?? ($login_at ? 'This is your first recorded login' : 'Shown from your next login')) ?></dd></div>
                </dl>
            </section>

            <section class="dash-card is-cyan">
                <header class="dash-card-head">
                    <span class="dash-card-icon is-cyan"><?= admin_icon('sync') ?></span>
                    <div>
                        <h2>Your activity</h2>
                        <p>Price syncs you have run</p>
                    </div>
                </header>
                <dl class="acct-facts">
                    <?php if ($syncs === null): ?>
                    <div><dt>Price syncs</dt><dd>Sync history isn’t set up yet</dd></div>
                    <?php else: ?>
                    <div><dt>Price syncs</dt><dd><?= (int)$syncs['n'] ?></dd></div>
                    <div><dt>Last one</dt><dd><?= e($when($syncs['last']) ?? 'None yet') ?></dd></div>
                    <?php endif; ?>
                </dl>
            </section>

            <section class="dash-card is-red acct-password" id="password">
                <header class="dash-card-head">
                    <span class="dash-card-icon is-red"><?= admin_icon('lock') ?></span>
                    <div>
                        <h2>Change password</h2>
                        <p>At least <?= MIN_PASSWORD ?> characters</p>
                    </div>
                </header>

                <?php if ($errors): ?>
                <ul class="admin-flash is-error ap-errors" role="alert">
                    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <form method="POST" action="account.php#password" class="ap-form acct-form">
                    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                    <label class="ap-wide">
                        <span>Current password</span>
                        <input type="password" name="current" autocomplete="current-password" required>
                    </label>
                    <label>
                        <span>New password</span>
                        <input type="password" name="new" minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password" required>
                    </label>
                    <label>
                        <span>Type it again</span>
                        <input type="password" name="confirm" minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password" required>
                    </label>
                    <div class="ap-form-actions ap-wide">
                        <button type="submit" class="btn btn-sm">Change password</button>
                    </div>
                </form>
            </section>
        </div>
<?php admin_close(); ?>
