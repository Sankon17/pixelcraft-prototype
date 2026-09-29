-- PixelCraft: remember when each admin last logged in (shown on admin/account.php). Safe to re-run.

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER password;
