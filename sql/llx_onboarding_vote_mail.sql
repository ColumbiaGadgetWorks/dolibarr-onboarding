-- Emails to members waiting to go out (notice, discussion reminder, ballot,
-- non-voter reminder), sent right after the button press and by the 15-minute
-- job for any left over. One of each kind per member per vote.
CREATE TABLE llx_onboarding_vote_mail(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_vote integer NOT NULL,
	kind varchar(12) NOT NULL,
	fk_adherent integer NOT NULL,
	email varchar(255) NOT NULL,
	sent_at datetime,
	tries integer DEFAULT 0 NOT NULL
) ENGINE=innodb;
