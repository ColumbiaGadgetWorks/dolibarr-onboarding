-- A trainer whose credit reached the threshold: a month of dues to refund,
-- once someone approves it.
--   status: pending -> approved -> refunded, or pending -> rejected
CREATE TABLE llx_onboarding_credit(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_adherent integer NOT NULL,
	amount double(24,8) NOT NULL,
	status varchar(20) DEFAULT 'pending' NOT NULL,
	gb_transaction_id varchar(64),
	note varchar(255),
	fk_user_decided integer,
	date_decided datetime,
	datec datetime
) ENGINE=innodb;
