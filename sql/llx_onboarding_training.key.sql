ALTER TABLE llx_onboarding_training ADD UNIQUE INDEX uk_onboarding_training_tx (gb_transaction_id, entity);
ALTER TABLE llx_onboarding_training ADD INDEX idx_onboarding_training_email (email);
ALTER TABLE llx_onboarding_training ADD COLUMN source varchar(10) DEFAULT 'paid' NOT NULL;
ALTER TABLE llx_onboarding_training ADD COLUMN fk_booking integer;
ALTER TABLE llx_onboarding_training ADD COLUMN fk_tool integer;
ALTER TABLE llx_onboarding_training ADD COLUMN free_reason varchar(255);
ALTER TABLE llx_onboarding_training ADD COLUMN fk_user integer;
ALTER TABLE llx_onboarding_training ADD COLUMN amount_net double(24,8);
