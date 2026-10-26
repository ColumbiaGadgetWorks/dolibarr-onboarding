ALTER TABLE llx_onboarding_paypal ADD UNIQUE INDEX uk_onboarding_paypal_tx (txid, entity);
ALTER TABLE llx_onboarding_paypal ADD INDEX idx_onboarding_paypal_payer (payer_id);
