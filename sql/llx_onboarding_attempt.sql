-- Failed sign-in attempts on the purchase form, kept for a day. A bucket is a
-- hash of an email address or IP address, or "all" for the whole form.
CREATE TABLE llx_onboarding_attempt(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	bucket varchar(64) NOT NULL,
	datec datetime NOT NULL
) ENGINE=innodb;
