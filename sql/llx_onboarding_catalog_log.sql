-- Every change to the training catalog (tools, trainers, times, closed days),
-- numbered. The website reports the version it holds.
CREATE TABLE llx_onboarding_catalog_log(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	note varchar(255),
	fk_user integer,
	datec datetime
) ENGINE=innodb;
