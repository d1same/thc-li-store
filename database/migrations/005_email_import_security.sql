ALTER TABLE customer_profiles ADD COLUMN marketing_consent_at TEXT;
ALTER TABLE customer_profiles ADD COLUMN marketing_consent_source TEXT;
ALTER TABLE customer_profiles ADD COLUMN marketing_unsubscribed_at TEXT;

ALTER TABLE orders ADD COLUMN receipt_email_status TEXT NOT NULL DEFAULT 'not_requested';
ALTER TABLE orders ADD COLUMN receipt_email_last_sent_at TEXT;

CREATE TABLE IF NOT EXISTS email_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    subject TEXT NOT NULL,
    intro_text TEXT NOT NULL,
    product_ids_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','approved','sending','completed','cancelled')),
    recipient_count INTEGER NOT NULL DEFAULT 0,
    sent_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    approved_by_user_id INTEGER,
    approved_at TEXT,
    created_by_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS email_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_type TEXT NOT NULL CHECK (message_type IN ('order_confirmation','owner_notification','receipt','campaign','campaign_draft_notice')),
    recipient_email TEXT NOT NULL,
    recipient_name TEXT,
    subject TEXT NOT NULL,
    body_text TEXT NOT NULL,
    body_html TEXT,
    customer_id INTEGER,
    order_id INTEGER,
    campaign_id INTEGER,
    unsubscribe_token TEXT,
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','processing','sent','failed','cancelled')),
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    available_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at TEXT,
    sent_at TEXT,
    last_error TEXT,
    unique_key TEXT NOT NULL UNIQUE,
    created_by_user_id INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customer_profiles(id) ON DELETE SET NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS email_delivery_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    details TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (queue_id) REFERENCES email_queue(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS customer_import_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_filename TEXT NOT NULL,
    file_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'previewed' CHECK (status IN ('previewed','completed','cancelled','failed')),
    total_rows INTEGER NOT NULL DEFAULT 0,
    create_rows INTEGER NOT NULL DEFAULT 0,
    update_rows INTEGER NOT NULL DEFAULT 0,
    skipped_rows INTEGER NOT NULL DEFAULT 0,
    error_rows INTEGER NOT NULL DEFAULT 0,
    summary_json TEXT,
    created_by_user_id INTEGER,
    completed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS login_rate_limits (
    key_hash TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL DEFAULT 0,
    window_started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    blocked_until TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_email_queue_ready ON email_queue(status,available_at,id);
CREATE INDEX IF NOT EXISTS idx_email_queue_order ON email_queue(order_id,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_email_queue_campaign ON email_queue(campaign_id,status);
CREATE INDEX IF NOT EXISTS idx_email_campaigns_status_created ON email_campaigns(status,created_at DESC);
CREATE INDEX IF NOT EXISTS idx_customer_marketing ON customer_profiles(marketing_opt_in,status,email);
CREATE INDEX IF NOT EXISTS idx_login_rate_limits_blocked ON login_rate_limits(blocked_until);
