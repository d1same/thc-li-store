UPDATE settings
SET value='receipts@thc-li.com',updated_at=CURRENT_TIMESTAMP
WHERE key IN ('email_from_address','email_reply_to') AND TRIM(value)='';
