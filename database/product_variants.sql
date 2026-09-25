-- PixelCraft: priced configurations for each PC (run after pc_products.sql).
-- One row per RAM/SSD + set + GPU + colour combination the customer can pick on the product page.
-- PCs without rows here fall back to product_specs.config_options (sets shown, no price change).
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS product_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  ram VARCHAR(50) NOT NULL,
  storage VARCHAR(50) NOT NULL,
  set_type ENUM('cpu_only','full_set') NOT NULL DEFAULT 'cpu_only',
  gpu_variant VARCHAR(100) DEFAULT NULL,
  color VARCHAR(50) DEFAULT 'Black',
  price DECIMAL(10,2) NOT NULL,
  jcl_1yr DECIMAL(10,2) DEFAULT NULL,
  jcl_2yr DECIMAL(10,2) DEFAULT NULL,
  jcl_3yr DECIMAL(10,2) DEFAULT NULL,
  stock INT DEFAULT 10,
  KEY idx_product (product_id, price),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- PC Pixel Lite
DELETE v FROM product_variants v JOIN products p ON p.id = v.product_id WHERE p.name = 'PC Pixel Lite';
INSERT INTO product_variants (product_id, ram, storage, set_type, color, price, jcl_1yr, jcl_2yr, jcl_3yr)
SELECT p.id, s.ram, s.storage, s.set_type, s.color, s.price, s.jcl_1yr, s.jcl_2yr, s.jcl_3yr
FROM products p
JOIN (
  SELECT '8GB DDR4' AS ram, '256GB NVMe' AS storage, 'cpu_only' AS set_type, 'Black' AS color,
         1759.00 AS price, 168.57 AS jcl_1yr, 95.28 AS jcl_2yr, 70.85 AS jcl_3yr
  UNION ALL SELECT '16GB DDR4', '512GB NVMe', 'cpu_only', 'Black', 2329.00, 223.20, 126.15,  93.81
  UNION ALL SELECT '16GB DDR4', '1TB NVMe',   'cpu_only', 'Black', 2689.00, 257.70, 145.65, 108.31
  UNION ALL SELECT '8GB DDR4',  '256GB NVMe', 'full_set', 'Black', 2099.00, 201.15, 113.70,  84.54
  UNION ALL SELECT '16GB DDR4', '512GB NVMe', 'full_set', 'Black', 2669.00, 255.78, 144.57, 107.50
  UNION ALL SELECT '16GB DDR4', '1TB NVMe',   'full_set', 'Black', 3029.00, 290.28, 164.07, 122.00
  UNION ALL SELECT '8GB DDR4',  '256GB NVMe', 'cpu_only', 'White', 1759.00, 168.57, 95.28,  70.85
  UNION ALL SELECT '16GB DDR4', '512GB NVMe', 'cpu_only', 'White', 2329.00, 223.20, 126.15,  93.81
  UNION ALL SELECT '16GB DDR4', '1TB NVMe',   'cpu_only', 'White', 2689.00, 257.70, 145.65, 108.31
  UNION ALL SELECT '8GB DDR4',  '256GB NVMe', 'full_set', 'White', 2099.00, 201.15, 113.70,  84.54
  UNION ALL SELECT '16GB DDR4', '512GB NVMe', 'full_set', 'White', 2669.00, 255.78, 144.57, 107.50
  UNION ALL SELECT '16GB DDR4', '1TB NVMe',   'full_set', 'White', 3029.00, 290.28, 164.07, 122.00
) s
WHERE p.name = 'PC Pixel Lite';

-- Listing cards show products.price, so keep it at the cheapest variant
UPDATE products p
JOIN (SELECT product_id, MIN(price) AS low FROM product_variants GROUP BY product_id) v ON v.product_id = p.id
SET p.price = v.low;
