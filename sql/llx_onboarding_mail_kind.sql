-- Kinds of email the module has held, and the ones the approver said to always
-- allow ("Approve, and always allow this kind").
CREATE TABLE llx_onboarding_mail_kind(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	kind varchar(190) NOT NULL,
	example varchar(255),
	allowed integer DEFAULT 0 NOT NULL,
	allowed_by varchar(255),
	allowed_at datetime,
	datec datetime
) ENGINE=innodb;
