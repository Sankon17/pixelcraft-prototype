-- PixelCraft: product photo galleries (run after pc_products.sql).
-- Files live in assets/images/products/. products.image is the main photo used on listing cards;
-- product_images holds the full gallery shown on the product page, in sort_order.
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS product_images (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  KEY idx_product (product_id, sort_order),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- PC Pixel Lite
DELETE pi FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.name = 'PC Pixel Lite';
INSERT INTO product_images (product_id, filename, sort_order)
SELECT p.id, g.filename, g.sort_order
FROM products p
JOIN (
  SELECT 'pixel-lite-1.jpg' AS filename, 1 AS sort_order
  UNION ALL SELECT 'pixel-lite-2-specs.jpg', 2
  UNION ALL SELECT 'pixel-lite-3-freegifts.jpg', 3
) g
WHERE p.name = 'PC Pixel Lite';
UPDATE products SET image = 'pixel-lite-1.jpg' WHERE name = 'PC Pixel Lite';

-- PC Pixel Azure
DELETE pi FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.name = 'PC Pixel Azure';
INSERT INTO product_images (product_id, filename, sort_order)
SELECT p.id, g.filename, g.sort_order
FROM products p
JOIN (
  SELECT 'pixel-azure-1.jpg' AS filename, 1 AS sort_order
  UNION ALL SELECT 'pixel-azure-2-specs.jpg', 2
  UNION ALL SELECT 'pixel-azure-3-freegifts.jpg', 3
) g
WHERE p.name = 'PC Pixel Azure';
UPDATE products SET image = 'pixel-azure-1.jpg' WHERE name = 'PC Pixel Azure';

-- PC Pixel Radiant
DELETE pi FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE p.name = 'PC Pixel Radiant (3 Years Warranty)';
INSERT INTO product_images (product_id, filename, sort_order)
SELECT p.id, g.filename, g.sort_order
FROM products p
JOIN (
  SELECT 'pixel-radiant-1.jpg' AS filename, 1 AS sort_order
  UNION ALL SELECT 'pixel-radiant-2-specs.jpg', 2
  UNION ALL SELECT 'pixel-radiant-3-freegifts.jpg', 3
) g
WHERE p.name = 'PC Pixel Radiant (3 Years Warranty)';
UPDATE products SET image = 'pixel-radiant-1.jpg' WHERE name = 'PC Pixel Radiant (3 Years Warranty)';
