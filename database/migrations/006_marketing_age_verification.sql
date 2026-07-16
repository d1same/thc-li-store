ALTER TABLE customer_profiles ADD COLUMN marketing_age_verified_at TEXT;
ALTER TABLE customer_profiles ADD COLUMN marketing_age_verified_source TEXT;

UPDATE customer_profiles
SET marketing_age_verified_at=(SELECT MIN(o.created_at) FROM orders o WHERE o.customer_id=customer_profiles.id),
    marketing_age_verified_source='prior verified order'
WHERE EXISTS (SELECT 1 FROM orders o WHERE o.customer_id=customer_profiles.id);

CREATE INDEX IF NOT EXISTS idx_customer_campaign_eligibility
ON customer_profiles(status,marketing_opt_in,marketing_unsubscribed_at,marketing_age_verified_at);
