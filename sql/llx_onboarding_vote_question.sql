-- One question on a member vote.
--   kind       bylaw (Yes / No / Abstain) or seat (a board seat)
--   threshold  twothirds or majority (bylaw questions)
--   decided    for a tied seat: the option the tiebreaker picked, and who
CREATE TABLE llx_onboarding_vote_question(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_vote integer NOT NULL,
	position integer DEFAULT 0 NOT NULL,
	kind varchar(10) NOT NULL,
	title varchar(255) NOT NULL,
	url varchar(255),
	threshold varchar(10) DEFAULT 'twothirds' NOT NULL,
	fk_decided integer,
	decided_by varchar(128)
) ENGINE=innodb;
