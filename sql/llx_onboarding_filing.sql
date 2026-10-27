-- One row per fiscal year's IRS and state filings (Yearly filings).
--   fy: the calendar year the fiscal year ends in (FY2027 = June 2026 to May 2027)
--   status: docs (the treasurer is asked for the statements) -> file (ready to
--           file, the IRS and state steps) -> done; or done straight away when the
--           year is recorded as filed outside Dolibarr
--   escalated: the escalation steps already emailed to the board, e.g. ,irs30,irs7,
--   receipts: gross receipts the treasurer reported when filing; later years'
--             three-year average uses it
CREATE TABLE llx_onboarding_filing(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fy integer NOT NULL,
	status varchar(8) DEFAULT 'docs' NOT NULL,
	docs_done_at datetime,
	docs_done_by varchar(255),
	docs_note varchar(500),
	irs_form varchar(10),
	receipts double(24,8),
	irs_filed_at date,
	irs_filed_by varchar(255),
	state_filed_at date,
	state_filed_by varchar(255),
	asks integer DEFAULT 0 NOT NULL,
	last_ask datetime,
	escalated varchar(100),
	note varchar(500),
	datec datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
