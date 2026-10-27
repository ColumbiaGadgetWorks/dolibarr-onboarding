-- What happened on a yearly filing: started, asked, uploads, steps done,
-- escalations, questions. Shown on the Yearly filings page.
CREATE TABLE llx_onboarding_filing_event(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_filing integer NOT NULL,
	kind varchar(20) NOT NULL,
	who varchar(255),
	note varchar(500),
	datec datetime
) ENGINE=innodb;
