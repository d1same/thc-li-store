ALTER TABLE orders ADD COLUMN order_source TEXT NOT NULL DEFAULT 'online';
ALTER TABLE orders ADD COLUMN created_by_user_id INTEGER;
ALTER TABLE orders ADD COLUMN age_verified INTEGER NOT NULL DEFAULT 0;
ALTER TABLE orders ADD COLUMN discount_label TEXT;
ALTER TABLE orders ADD COLUMN receipt_email_sent INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS staff_permissions (
    user_id INTEGER NOT NULL,
    permission TEXT NOT NULL,
    allowed INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_orders_source_created ON orders(order_source, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_staff_permissions_user ON staff_permissions(user_id);

INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'pos.access' FROM users WHERE role IN ('staff','manager');
INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'pos.complete' FROM users WHERE role IN ('staff','manager');
INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'orders.view' FROM users WHERE role IN ('staff','manager');
INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'orders.manage' FROM users WHERE role='manager';
INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'products.view' FROM users WHERE role IN ('staff','manager');
INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'products.create' FROM users WHERE role='manager';
INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'products.edit' FROM users WHERE role='manager';
INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'products.archive' FROM users WHERE role='manager';
INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'promotions.manage' FROM users WHERE role='manager';
INSERT OR IGNORE INTO staff_permissions (user_id, permission)
SELECT id, 'settings.manage' FROM users WHERE role='manager';
