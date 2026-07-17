ALTER TABLE users ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN password_changed_at TEXT;
ALTER TABLE users ADD COLUMN mfa_secret_encrypted TEXT;
ALTER TABLE users ADD COLUMN mfa_enabled_at TEXT;
ALTER TABLE users ADD COLUMN mfa_recovery_codes_json TEXT;
ALTER TABLE users ADD COLUMN last_login_at TEXT;

CREATE TABLE IF NOT EXISTS rate_limits (
    scope TEXT NOT NULL,
    key_hash TEXT NOT NULL,
    hits INTEGER NOT NULL DEFAULT 0,
    window_started_at INTEGER NOT NULL,
    blocked_until INTEGER,
    updated_at INTEGER NOT NULL,
    PRIMARY KEY (scope, key_hash)
);

CREATE INDEX IF NOT EXISTS idx_rate_limits_updated ON rate_limits(updated_at);
CREATE INDEX IF NOT EXISTS idx_audit_events_action_created ON audit_events(action, created_at DESC);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_single_owner ON users(role) WHERE role='owner';

INSERT OR IGNORE INTO settings (key,value,type) VALUES ('staff_mfa_required','0','bool');
