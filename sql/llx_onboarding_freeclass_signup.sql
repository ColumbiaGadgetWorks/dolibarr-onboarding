-- Someone signed up for a free class on the website. Matched to their member
-- card or contact by email; a contact is made for someone new.
--   status  signed, or cancelled (they used the link in their email)
CREATE TABLE llx_onboarding_freeclass_signup(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_freeclass integer NOT NULL,
	email varchar(255) NOT NULL,
	firstname varchar(50),
	lastname varchar(50),
	fk_adherent integer,
	fk_socpeople integer,
	updates integer DEFAULT 0 NOT NULL,
	status varchar(10) DEFAULT 'signed' NOT NULL,
	attended integer DEFAULT 0 NOT NULL,
	ip varchar(64),
	datec datetime,
	date_cancel datetime
) ENGINE=innodb;
