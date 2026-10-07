ALTER TABLE llx_onboarding_payment ADD UNIQUE INDEX uk_onboarding_payment_tx (gb_transaction_id, entity);
