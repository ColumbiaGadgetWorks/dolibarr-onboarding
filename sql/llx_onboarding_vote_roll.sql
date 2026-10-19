-- Who may vote, taken when voting opens, and whether they have voted. voted_on
-- is a date, not a time, so it cannot be matched to a change in the totals.
CREATE TABLE llx_onboarding_vote_roll(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_vote integer NOT NULL,
	fk_adherent integer NOT NULL,
	email varchar(255) NOT NULL,
	voted_on date,
	resent_at datetime
) ENGINE=innodb;
