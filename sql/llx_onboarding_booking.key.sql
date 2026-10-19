ALTER TABLE llx_onboarding_booking ADD INDEX idx_onboarding_booking_email (entity, email, status);
ALTER TABLE llx_onboarding_booking ADD INDEX idx_onboarding_booking_session (entity, fk_trainer, session_start);
ALTER TABLE llx_onboarding_booking ADD UNIQUE INDEX uk_onboarding_booking_tx (entity, gb_transaction_id);
