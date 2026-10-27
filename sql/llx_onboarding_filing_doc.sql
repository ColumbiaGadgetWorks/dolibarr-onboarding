-- A statement or report uploaded for a yearly filing, or an item marked as not
-- needed. Files are under onboarding/filing/<fy>/ in Dolibarr's documents.
--   item:   acct:<bank account id>, y:<yearly item>, irs or state (the confirmations)
--   period: YYYY-MM for one month's statement, year for the whole year, empty when
--           a statement's month could not be told from its file name
--   kind:   file or none
CREATE TABLE llx_onboarding_filing_doc(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_filing integer NOT NULL,
	item varchar(64) NOT NULL,
	period varchar(7),
	kind varchar(8) DEFAULT 'file' NOT NULL,
	filename varchar(255),
	original varchar(255),
	size integer,
	note varchar(255),
	who varchar(255),
	datec datetime
) ENGINE=innodb;
