ALTER TABLE llx_onboarding_vote_candidate ADD UNIQUE INDEX uk_onboarding_vote_candidate (fk_vote, fk_adherent);
