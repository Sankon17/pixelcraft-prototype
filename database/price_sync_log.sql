-- PixelCraft: history of Price Sync uploads (admin/sync_handler.php writes a row per successful sync),
-- shown under "Recent price syncs" on the admin dashboard. Safe to re-run.

CREATE TABLE IF NOT EXISTS price_sync_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  filename VARCHAR(255) NOT NULL,
  admin VARCHAR(100) DEFAULT NULL,
  rows_updated INT NOT NULL DEFAULT 0,   -- sheet rows matched to a variant
  prices_changed INT NOT NULL DEFAULT 0, -- of those, how many had a different price
  skipped INT NOT NULL DEFAULT 0,
  not_found INT NOT NULL DEFAULT 0,
  products INT NOT NULL DEFAULT 0,       -- PCs whose "From" price was recalculated
  KEY idx_synced (synced_at)
) ENGINE=InnoDB;
