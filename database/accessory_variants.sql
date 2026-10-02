-- Accessory variants: a free-text option (colour, edition, size…) with its own price and stock,
-- e.g. "Black" / "White" or "Luffy Edition". PC and laptop variants leave it NULL and use RAM / storage / set / GPU.
-- Edited in admin/products.php (Variants, on an accessory's edit form); shown as chips on pages/product.php.
ALTER TABLE product_variants
    ADD COLUMN option_name VARCHAR(60) NULL DEFAULT NULL AFTER product_id;
