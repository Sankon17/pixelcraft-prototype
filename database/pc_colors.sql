-- PixelCraft: colour choice for the PCs (run after product_variants.sql).
-- Every Black variant gets a White copy, and Pixel Core XII also a Pink one: same RAM/SSD, set, GPU, price, JCL and stock.
-- Pixel Neo only comes in black, so it is left alone. The product page shows a Color chip row once a PC has 2+ colours.
-- Safe to re-run: a copy is only added where that colour of that configuration doesn't exist yet.

INSERT INTO product_variants (product_id, ram, storage, set_type, gpu_variant, color, price, jcl_1yr, jcl_2yr, jcl_3yr, stock)
SELECT v.product_id, v.ram, v.storage, v.set_type, v.gpu_variant, c.color, v.price, v.jcl_1yr, v.jcl_2yr, v.jcl_3yr, v.stock
FROM product_variants v
JOIN products p ON p.id = v.product_id
JOIN categories cat ON cat.id = p.category_id AND cat.slug IN ('office-pcs', 'editing-pcs', 'gaming-pcs')
JOIN (SELECT 'White' AS color UNION ALL SELECT 'Pink') c ON c.color = 'White' OR p.name = 'Pixel Core XII'
WHERE v.color = 'Black'
  AND p.name <> 'Pixel Neo'
  AND NOT EXISTS (
      SELECT 1 FROM product_variants x
      WHERE x.product_id = v.product_id AND x.ram = v.ram AND x.storage = v.storage
        AND x.set_type = v.set_type AND x.gpu_variant <=> v.gpu_variant AND x.color = c.color
  );
