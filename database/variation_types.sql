-- Accessory variation types (Shopee-style): up to 3 types per product, e.g. Colour (Red, White, Grey) and
-- Fabric (PU Leather, PU). Every combination is a product_variants row with its own price and stock.
-- Run after accessory_variants.sql. Edited in admin/products.php; shown as one chip row per type on pages/product.php.

-- The product's types, in order: [{"name":"Colour","values":["Red","White","Grey"]}, {"name":"Fabric","values":[...]}]
ALTER TABLE products
    ADD COLUMN option_groups TEXT NULL DEFAULT NULL;

-- Each variant's value for every type, in the same order (["Red","PU Leather"]); option_name is its label ("Red · PU Leather")
ALTER TABLE product_variants
    MODIFY option_name VARCHAR(200) NULL DEFAULT NULL,
    ADD COLUMN option_values VARCHAR(500) NULL DEFAULT NULL AFTER option_name;

-- Variants added before types existed: one type called "Option" holding each variant's name
UPDATE product_variants
    SET option_values = JSON_ARRAY(option_name)
    WHERE option_name IS NOT NULL AND option_name <> '' AND option_values IS NULL;

UPDATE products p
    JOIN (SELECT product_id,
                 CONCAT('[{"name":"Option","values":[', GROUP_CONCAT(JSON_QUOTE(option_name) ORDER BY price, id SEPARATOR ','), ']}]') AS types
          FROM product_variants
          WHERE option_values IS NOT NULL
          GROUP BY product_id) v ON v.product_id = p.id
    SET p.option_groups = v.types
    WHERE p.option_groups IS NULL;
