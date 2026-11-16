ALTER TABLE llx_onboarding_applicant ADD UNIQUE INDEX uk_onboarding_applicant_email (email, entity);
ALTER TABLE llx_onboarding_applicant ADD INDEX idx_onboarding_applicant_token (token_hash);
