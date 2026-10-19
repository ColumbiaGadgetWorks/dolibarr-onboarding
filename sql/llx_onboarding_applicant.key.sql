ALTER TABLE llx_onboarding_applicant ADD UNIQUE INDEX uk_onboarding_applicant_email (email, entity);
ALTER TABLE llx_onboarding_applicant ADD INDEX idx_onboarding_applicant_token (token_hash);
ALTER TABLE llx_onboarding_applicant ADD COLUMN training_only integer DEFAULT 0 NOT NULL;
ALTER TABLE llx_onboarding_applicant ADD COLUMN birthdate date;
ALTER TABLE llx_onboarding_applicant ADD COLUMN guardian_name varchar(200);
ALTER TABLE llx_onboarding_applicant ADD COLUMN guardian_relation varchar(50);
ALTER TABLE llx_onboarding_applicant ADD COLUMN guardian_email varchar(255);
