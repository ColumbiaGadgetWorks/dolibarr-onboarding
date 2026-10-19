-- Every version of the waiver and the membership agreement ever offered for
-- signing. The text is kept in GitHub (the website repository) and copied here
-- nightly; a signature names its version, so the words signed are always here.
--   doc: waiver or agreement. version: first 16 hex of the text's SHA-256.
CREATE TABLE llx_onboarding_doc_version(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	doc varchar(20) NOT NULL,
	version varchar(64) NOT NULL,
	title varchar(200),
	text mediumtext NOT NULL,
	source varchar(255),
	datec datetime
) ENGINE=innodb;
