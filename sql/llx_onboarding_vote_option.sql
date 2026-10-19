-- The answers on the ballot, made when voting opens, with their running totals.
-- There is no table of ballots: a vote only adds one to the chosen answers, so
-- nothing links a member to how they voted.
--   kind  yes, no, abstain or candidate (fk_candidate set)
CREATE TABLE llx_onboarding_vote_option(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_question integer NOT NULL,
	position integer DEFAULT 0 NOT NULL,
	kind varchar(10) NOT NULL,
	fk_candidate integer,
	label varchar(255) NOT NULL,
	votes integer DEFAULT 0 NOT NULL
) ENGINE=innodb;
