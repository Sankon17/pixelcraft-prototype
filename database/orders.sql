-- PixelCraft: customer orders from pages/checkout.php (written by pages/order_handler.php). Safe to re-run.
-- order_items keeps its own copy of the name, chosen option and price, so an order still reads correctly
-- after a product is renamed, repriced or deleted.

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(100) NOT NULL,
  phone VARCHAR(30) NOT NULL,
  email VARCHAR(255) NOT NULL,
  address TEXT NOT NULL,
  delivery_method VARCHAR(20) NOT NULL DEFAULT 'Postage',
  payment_method VARCHAR(50) NOT NULL,
  total_amount DECIMAL(10,2) NOT NULL,
  status ENUM('pending', 'confirmed', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at),
  KEY idx_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NULL,
  variant_id INT NULL,
  product_name VARCHAR(255) NOT NULL,
  variant_detail VARCHAR(255) DEFAULT NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  KEY idx_order (order_id),
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Added after the first version: Pick Up or Postage (for databases created before it)
ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_method VARCHAR(20) NOT NULL DEFAULT 'Postage' AFTER address;

-- Fulfilment statuses like Shoppego (October 2026): Awaiting processing → Processing → Packed → Shipped / Ready for pickup → Completed.
-- "confirmed" from the first version becomes "processing". Safe to re-run.
ALTER TABLE orders MODIFY status ENUM('pending', 'confirmed', 'processing', 'packed', 'shipped', 'ready_pickup', 'completed', 'cancelled') NOT NULL DEFAULT 'pending';
UPDATE orders SET status = 'processing' WHERE status = 'confirmed';
ALTER TABLE orders MODIFY status ENUM('pending', 'processing', 'packed', 'shipped', 'ready_pickup', 'completed', 'cancelled') NOT NULL DEFAULT 'pending';

-- Admin "Save filters" on admin/orders.php: a named set of order filters per admin
CREATE TABLE IF NOT EXISTS order_saved_filters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  name VARCHAR(60) NOT NULL,
  query VARCHAR(500) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin (admin_id)
) ENGINE=InnoDB;
