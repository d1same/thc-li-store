CREATE TABLE IF NOT EXISTS customer_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    email_key TEXT,
    phone_key TEXT,
    name TEXT NOT NULL,
    email TEXT,
    phone TEXT,
    address1 TEXT,
    address2 TEXT,
    city TEXT,
    state TEXT NOT NULL DEFAULT 'NY',
    postal_code TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    marketing_opt_in INTEGER NOT NULL DEFAULT 0,
    private_notes TEXT,
    first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE orders ADD COLUMN customer_id INTEGER REFERENCES customer_profiles(id) ON DELETE SET NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_customer_profiles_user ON customer_profiles(user_id) WHERE user_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_customer_profiles_email_key ON customer_profiles(email_key) WHERE email_key IS NOT NULL AND email_key!='';
CREATE UNIQUE INDEX IF NOT EXISTS idx_customer_profiles_phone_key ON customer_profiles(phone_key) WHERE phone_key IS NOT NULL AND phone_key!='';
CREATE INDEX IF NOT EXISTS idx_customer_profiles_status_seen ON customer_profiles(status,last_seen_at DESC);
CREATE INDEX IF NOT EXISTS idx_customer_profiles_name ON customer_profiles(name COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_orders_customer_created ON orders(customer_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_reporting ON orders(status,payment_status,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_order_items_product ON order_items(product_id,variant_id,order_id);
