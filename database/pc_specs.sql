-- PixelCraft: real specs for every PC (run AFTER pc_products.sql).
-- Safe to re-run: adds missing columns, then inserts or updates one spec row per product.
-- Products are matched by name, so a PC listed in two categories (Core XII / Ultra / Elite)
-- gets a row for each copy. Copies in Editing PCs use pc_type 'editing'.
-- video_url is left untouched on re-run.

-- Extra component columns + the RAM/SSD sets offered for each PC ("|" separated)
ALTER TABLE product_specs
  ADD COLUMN IF NOT EXISTS motherboard    VARCHAR(255) AFTER gpu,
  ADD COLUMN IF NOT EXISTS psu            VARCHAR(100) AFTER motherboard,
  ADD COLUMN IF NOT EXISTS cooler         VARCHAR(100) AFTER psu,
  ADD COLUMN IF NOT EXISTS casing         VARCHAR(255) AFTER cooler,
  ADD COLUMN IF NOT EXISTS config_options VARCHAR(500) AFTER storage;

CREATE TEMPORARY TABLE spec_seed (
  name VARCHAR(255), cpu VARCHAR(255), gpu VARCHAR(255), motherboard VARCHAR(255), psu VARCHAR(100),
  cooler VARCHAR(100), casing VARCHAR(255), ram VARCHAR(100), storage VARCHAR(100),
  config_options VARCHAR(500), pc_type VARCHAR(20)
);

INSERT INTO spec_seed VALUES
-- Office
('PC Pixel Neo',   'Ryzen 3 3200G',  'Integrated Radeon Vega 8', 'A520M', '250W', 'Stock Cooler', 'Azure Millenia Air 1RGB',
 '8GB DDR4',        '256GB / 512GB / 1TB NVMe', '8GB DDR4 / 256GB NVMe|8GB DDR4 / 512GB NVMe|8GB DDR4 / 1TB NVMe', 'student'),
('PC Pixel Lite',  'Ryzen 5 5500GT', 'Integrated Radeon Vega 7', 'A520M', '250W', 'Stock Cooler', 'Aquaron Nemo',
 '8GB / 16GB DDR4', '256GB / 512GB / 1TB NVMe', '8GB DDR4 / 256GB NVMe|16GB DDR4 / 512GB NVMe|16GB DDR4 / 1TB NVMe', 'office'),
('PC Pixel Edge',  'Intel i5 12400', 'Intel UHD Graphics 730',   'H610M', '250W', 'Stock Cooler', 'Aquaron Nemo',
 '8GB / 16GB DDR4', '256GB / 512GB / 1TB NVMe', '8GB DDR4 / 256GB NVMe|16GB DDR4 / 512GB NVMe|16GB DDR4 / 1TB NVMe', 'office'),
('PC Pixel Azure', 'Ryzen 5 8500G',  'Integrated Radeon 760M',   'A620',  '250W', 'Stock Cooler', 'Aquaron Nemo',
 '8GB / 16GB DDR5', '256GB / 512GB / 1TB NVMe', '8GB DDR5 / 256GB NVMe|16GB DDR5 / 512GB NVMe|16GB DDR5 / 1TB NVMe', 'office'),

-- Gaming
('PC Pixel Blaze',      'Ryzen 5 5500', 'RTX 3050 / RTX 3060', 'A520', '550W', 'Stock Cooler', 'Pro 200',
 '8GB / 16GB DDR4', '256GB / 512GB / 1TB NVMe', '8GB DDR4 / 256GB NVMe|16GB DDR4 / 512GB NVMe|16GB DDR4 / 1TB NVMe', 'gaming'),
('PC Pixel Blaze Plus', 'Ryzen 5 5500', 'RTX 5050',            'A520', '550W', 'Stock Cooler', 'Pro 200',
 '8GB / 16GB DDR4', '256GB / 512GB / 1TB NVMe', '8GB DDR4 / 256GB NVMe|16GB DDR4 / 512GB NVMe|16GB DDR4 / 1TB NVMe', 'gaming'),
('PC Pixel Radiant (3 Years Warranty)', 'Ryzen 5 5500', 'RX 9050', 'A520', '550W', 'Stock Cooler', 'Segotep Brave W1',
 '8GB / 16GB DDR4', '256GB / 512GB / 1TB NVMe', '8GB DDR4 / 256GB NVMe|16GB DDR4 / 512GB NVMe|16GB DDR4 / 1TB NVMe', 'gaming'),
('PC Pixel Beast',   'Ryzen 5 7500F', 'RX 9060 XT',  'A620',          '650W', 'Dual Tower Cooler', 'Aquaron Giga',
 '8GB / 16GB DDR5', '256GB / 512GB / 1TB NVMe', '8GB DDR5 / 256GB NVMe|16GB DDR5 / 512GB NVMe|16GB DDR5 / 1TB NVMe', 'gaming'),
('PC Pixel Frost',   'Ryzen 5 7500F', 'RTX 5060 Ti', 'A620',          '650W', 'AIO 360mm', 'The View 5',
 '16GB DDR5', '512GB / 1TB NVMe', '16GB DDR5 / 512GB NVMe|16GB DDR5 / 1TB NVMe', 'gaming'),
('PC Pixel Hellion', 'Ryzen 5 7500F', 'RTX 5070',    'B650M WiFi 6',  '750W', 'AIO 360mm', 'Aquaron X-Curve',
 '16GB DDR5', '512GB / 1TB NVMe', '16GB DDR5 / 512GB NVMe|16GB DDR5 / 1TB NVMe', 'gaming'),
('PC Pixel Core XII', 'Intel i5 14400F', 'RTX 5060', 'H610M', '650W', 'Tower Cooler', 'Aquaron',
 '8GB / 16GB DDR4', '256GB / 512GB / 1TB NVMe', '8GB DDR4 / 256GB NVMe|16GB DDR4 / 512GB NVMe|16GB DDR4 / 1TB NVMe', 'gaming'),
('PC Pixel Core Ultra (3 Years Warranty)', 'Intel U5 225F', 'RTX 5060', 'H810M WiFi 6', '650W', 'AIO 360mm', 'Invassion Zoom 7 Fan',
 '16GB DDR5', '512GB / 1TB NVMe', '16GB DDR5 / 512GB NVMe|16GB DDR5 / 1TB NVMe', 'gaming'),
('Pixel Core Elite', 'Intel U7 270K Plus', 'RTX 5060 Ti', 'B860 WiFi 6', '750W', 'AIO 360mm', 'Invassion Zoom 7 Fan',
 '16GB DDR5', '512GB / 1TB NVMe', '16GB DDR5 / 512GB NVMe|16GB DDR5 / 1TB NVMe', 'gaming'),
('PC Pixel Atom Mini ITX (3 Years Warranty)', 'Ryzen 5 5500', 'RTX 5050', 'B550I', '750W SFX', 'Stock Cooler', 'Deep Cool CH160',
 '8GB / 16GB DDR4', '256GB / 512GB / 1TB NVMe', '8GB DDR4 / 256GB NVMe|16GB DDR4 / 512GB NVMe|16GB DDR4 / 1TB NVMe', 'gaming'),
('PC Pixel Nitro (3 Years Warranty)', 'Ryzen 5 7500F', 'RTX 5060', 'A620', '650W', 'Tower Cooler (TC)', 'Pro 200',
 '8GB / 16GB DDR5', '256GB / 512GB / 1TB NVMe', '8GB DDR5 / 256GB NVMe|16GB DDR5 / 512GB NVMe|16GB DDR5 / 1TB NVMe', 'gaming'),
('PC Pixel Raptor', 'Ryzen 7 9850X3D', 'RTX 5070 Ti / RTX 5080', 'B850M WiFi 6', '1000W', 'AIO 360mm', 'Tecware VXN Glow',
 '16GB / 32GB DDR5', '512GB / 1TB NVMe', '16GB DDR5 / 512GB NVMe|32GB DDR5 / 512GB NVMe|16GB DDR5 / 1TB NVMe|32GB DDR5 / 1TB NVMe', 'gaming');

INSERT INTO product_specs (product_id, cpu, gpu, motherboard, psu, cooler, casing, ram, storage, config_options, pc_type)
SELECT p.id, s.cpu, s.gpu, s.motherboard, s.psu, s.cooler, s.casing, s.ram, s.storage, s.config_options,
       IF(c.slug = 'editing-pcs', 'editing', s.pc_type)
FROM spec_seed s
JOIN products p   ON p.name = s.name
JOIN categories c ON c.id = p.category_id
ON DUPLICATE KEY UPDATE
  cpu = VALUES(cpu), gpu = VALUES(gpu), motherboard = VALUES(motherboard), psu = VALUES(psu),
  cooler = VALUES(cooler), casing = VALUES(casing), ram = VALUES(ram), storage = VALUES(storage),
  config_options = VALUES(config_options), pc_type = VALUES(pc_type);

DROP TEMPORARY TABLE spec_seed;

-- Performance videos (YouTube links; any watch / youtu.be / shorts URL works)
UPDATE product_specs ps
JOIN products p ON p.id = ps.product_id
SET ps.video_url = CASE p.name
    WHEN 'PC Pixel Lite'  THEN 'https://youtu.be/A6Z2pwCDftY'
    WHEN 'PC Pixel Azure' THEN 'https://youtu.be/K056Oqz1n-k'
    WHEN 'PC Pixel Radiant (3 Years Warranty)' THEN 'https://youtu.be/jPgklvSo7Oo'
  END
WHERE p.name IN ('PC Pixel Lite', 'PC Pixel Azure', 'PC Pixel Radiant (3 Years Warranty)');
