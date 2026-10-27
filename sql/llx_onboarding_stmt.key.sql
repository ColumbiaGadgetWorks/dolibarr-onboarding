ALTER TABLE llx_onboarding_stmt ADD UNIQUE INDEX uk_onboarding_stmt_hash (entity, hash);
ALTER TABLE llx_onboarding_stmt ADD INDEX idx_onboarding_stmt_account (fk_account, status, dateo);
ALTER TABLE llx_onboarding_stmt ADD COLUMN note varchar(12);
