-- PixelCraft: real PC products + product_specs table.
-- Safe to re-run: clears Office/Editing/Gaming PCs before inserting.
-- Run database/pc_specs.sql afterwards to (re)load the specs — deleting products removes their specs.
-- PC categories: Laptops, Office, Editing, Gaming. (Student PCs was removed; PC Pixel Neo is in Office.)
-- Core XII, Core Ultra and Core Elite are listed in BOTH Editing and Gaming (separate rows).

START TRANSACTION;

-- Categories
INSERT IGNORE INTO categories (name, slug) VALUES ('Laptops', 'laptops');

SET @office  = (SELECT id FROM categories WHERE slug = 'office-pcs');
SET @editing = (SELECT id FROM categories WHERE slug = 'editing-pcs');
SET @gaming  = (SELECT id FROM categories WHERE slug = 'gaming-pcs');
SET @laptops = (SELECT id FROM categories WHERE slug = 'laptops');
SET @student = (SELECT id FROM categories WHERE slug = 'student-pcs');

-- Specs table (one row per product, removed with the product)
CREATE TABLE IF NOT EXISTS product_specs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  cpu VARCHAR(255),
  gpu VARCHAR(255),
  motherboard VARCHAR(255),
  psu VARCHAR(100),
  cooler VARCHAR(100),
  casing VARCHAR(255),
  ram VARCHAR(100),
  storage VARCHAR(100),
  config_options VARCHAR(500),
  color VARCHAR(100),
  video_url VARCHAR(500),
  pc_type ENUM('gaming','editing','office','student'),
  UNIQUE KEY uniq_product (product_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Homepage "What's New" spotlight: set is_featured = 1 on the product to show.
-- If none is featured, the newest Gaming PC is shown.
SET @has_featured = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_featured');
SET @sql = IF(@has_featured = 0, 'ALTER TABLE products ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Clear old PC products (and the retired Student PCs category)
DELETE FROM products WHERE category_id IN (@office, @editing, @gaming, @student);
DELETE FROM categories WHERE slug = 'student-pcs';

-- Office PCs
INSERT INTO products (category_id, name, description, price, stock, image) VALUES
  (@office, 'PC Pixel Neo',   'Specs coming soon', 1429.00, 10, 'placeholder.jpg'),
  (@office, 'PC Pixel Lite',  'Specs coming soon', 1849.00, 10, 'placeholder.jpg'),
  (@office, 'PC Pixel Edge',  'Specs coming soon', 2089.00, 10, 'placeholder.jpg'),
  (@office, 'PC Pixel Azure', 'Specs coming soon', 2409.00, 10, 'placeholder.jpg');

-- Editing PCs
INSERT INTO products (category_id, name, description, price, stock, image) VALUES
  (@editing, 'PC Pixel Core XII',                      'Specs coming soon', 4959.00, 10, 'placeholder.jpg'),
  (@editing, 'PC Pixel Core Ultra (3 Years Warranty)', 'Specs coming soon', 6679.00, 10, 'placeholder.jpg'),
  (@editing, 'Pixel Core Elite',                       'Specs coming soon', 9409.00, 10, 'placeholder.jpg');

-- Gaming PCs
INSERT INTO products (category_id, name, description, price, stock, image) VALUES
  (@gaming, 'PC Pixel Blaze',                             'Specs coming soon',  3299.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Radiant (3 Years Warranty)',        'Specs coming soon',  3299.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Blaze Plus',                        'Specs coming soon',  4149.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Nitro (3 Years Warranty)',          'Specs coming soon',  5199.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Atom Mini ITX (3 Years Warranty)',  'Specs coming soon',  5459.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Core XII',                          'Specs coming soon',  4959.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Core Ultra (3 Years Warranty)',     'Specs coming soon',  6679.00, 10, 'placeholder.jpg'),
  (@gaming, 'Pixel Core Elite',                           'Specs coming soon',  9409.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Beast',                             'Specs coming soon',  6089.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Frost',                             'Specs coming soon',  7907.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Hellion',                           'Specs coming soon',  9339.00, 10, 'placeholder.jpg'),
  (@gaming, 'PC Pixel Raptor',                            'Specs coming soon', 13269.00, 10, 'placeholder.jpg');

-- Laptops (already added; bring them in line with the same placeholders)
UPDATE products SET description = 'Specs coming soon', image = 'placeholder.jpg' WHERE category_id = @laptops;

COMMIT;
