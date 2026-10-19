ALTER TABLE llx_onboarding_vote_mail ADD UNIQUE INDEX uk_onboarding_vote_mail (fk_vote, kind, fk_adherent);
