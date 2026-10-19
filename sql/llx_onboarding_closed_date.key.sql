ALTER TABLE llx_onboarding_closed_date ADD UNIQUE INDEX uk_onboarding_closed_date (entity, day, source);
