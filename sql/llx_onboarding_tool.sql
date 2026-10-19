-- A tool (or group of machines) that needs training before solo use. Edited on
-- Members, Onboarding, Training tools; copied to the website on every save.
--   ref       short id used by the website, e.g. 3d-printers. Never reused.
--   fee       base price; members pay fee * (100 - member_discount) / 100
--   trainer_share  percent of what arrives that goes to the trainer; NULL = the setting
--   seats     most people in one session
CREATE TABLE llx_onboarding_tool(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(64) NOT NULL,
	label varchar(150) NOT NULL,
	zone varchar(100) NOT NULL,
	fee double(24,8) DEFAULT 0 NOT NULL,
	member_discount double(24,8) DEFAULT 50 NOT NULL,
	trainer_share double(24,8),
	seats integer DEFAULT 1 NOT NULL,
	minutes integer DEFAULT 60 NOT NULL,
	covers varchar(255),
	wiki_url varchar(255),
	page_url varchar(255),
	bookable integer DEFAULT 1 NOT NULL,
	active integer DEFAULT 1 NOT NULL,
	datec datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
