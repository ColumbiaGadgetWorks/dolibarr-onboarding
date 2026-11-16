ALTER TABLE llx_onboarding_training ADD UNIQUE INDEX uk_onboarding_training_tx (gb_transaction_id, entity);
ALTER TABLE llx_onboarding_training ADD INDEX idx_onboarding_training_email (email);
