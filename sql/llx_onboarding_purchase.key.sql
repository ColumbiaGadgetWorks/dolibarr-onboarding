ALTER TABLE llx_onboarding_purchase ADD INDEX idx_onboarding_purchase_status (status, zone);
ALTER TABLE llx_onboarding_purchase ADD COLUMN needs varchar(100);
ALTER TABLE llx_onboarding_purchase ADD COLUMN items text;
ALTER TABLE llx_onboarding_purchase ADD COLUMN amount_final double(24,8);
