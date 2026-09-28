-- PixelCraft: show/hide switch for products (admin/products.php "Active" column).
-- Hidden products (is_active = 0) are left out of the public listings and product pages.
-- Safe to re-run.
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER image;
