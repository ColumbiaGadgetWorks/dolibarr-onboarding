ALTER TABLE llx_onboarding_ledger ADD INDEX idx_onboarding_ledger_account (entity, account_type, account_key);
ALTER TABLE llx_onboarding_ledger ADD UNIQUE INDEX uk_onboarding_ledger_training (fk_training, account_type);
