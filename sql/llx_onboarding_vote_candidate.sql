-- Someone running for a seat. One seat per person per vote.
CREATE TABLE llx_onboarding_vote_candidate(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_vote integer NOT NULL,
	fk_question integer NOT NULL,
	fk_adherent integer NOT NULL,
	name varchar(128) NOT NULL,
	statement varchar(400),
	source varchar(10) DEFAULT 'button' NOT NULL,
	datec datetime
) ENGINE=innodb;
