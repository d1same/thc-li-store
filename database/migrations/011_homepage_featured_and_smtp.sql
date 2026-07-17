ALTER TABLE products ADD COLUMN featured_at TEXT;

-- Preserve the eight products that were already visible under the previous
-- category/name ordering. New selections receive a real timestamp and rise
-- above this baseline.
UPDATE products
SET featured_at = '2000-01-01 00:00:00'
WHERE featured = 1;

WITH ranked AS (
    SELECT p.id,
           ROW_NUMBER() OVER (ORDER BY c.position, p.name, p.id) AS slot
    FROM products p
    JOIN categories c ON c.id = p.category_id
    WHERE p.featured = 1
)
UPDATE products
SET featured = 0,
    featured_at = NULL
WHERE id IN (SELECT id FROM ranked WHERE slot > 8);

CREATE INDEX IF NOT EXISTS idx_products_featured_slots
ON products(featured, featured_at DESC);

INSERT OR IGNORE INTO settings (key,value,type) VALUES ('email_transport','php_mail','string');
INSERT OR IGNORE INTO settings (key,value,type) VALUES ('smtp_host','mail.thc-li.com','string');
INSERT OR IGNORE INTO settings (key,value,type) VALUES ('smtp_port','465','int');
INSERT OR IGNORE INTO settings (key,value,type) VALUES ('smtp_encryption','ssl','string');
INSERT OR IGNORE INTO settings (key,value,type) VALUES ('smtp_username','receipts@thc-li.com','string');
INSERT OR IGNORE INTO settings (key,value,type) VALUES ('smtp_timeout','10','int');
