ALTER TABLE llx_onboarding_stmt_file ADD INDEX idx_onboarding_stmt_file_account (fk_account, source);
ALTER TABLE llx_onboarding_stmt_file ADD COLUMN begin_balance double(24,8);
ALTER TABLE llx_onboarding_stmt_file ADD COLUMN begin_date date;
