-- Days nobody can book a training: entered by hand, or found on the shop
-- calendar ("Open Hack Night cancelled") by the website.
--   source: hand or calendar
CREATE TABLE llx_onboarding_closed_date(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	day date NOT NULL,
	reason varchar(255),
	source varchar(10) DEFAULT 'hand' NOT NULL,
	datec datetime
) ENGINE=innodb;
