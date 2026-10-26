-- PayPal dues payments seen by the hourly PayPal sync (Transaction search API).
-- Unmatched ones wait under Unmatched payments for someone to pick the member.
CREATE TABLE llx_onboarding_paypal(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	txid varchar(64) NOT NULL,
	payer_id varchar(64),
	email varchar(255),
	name varchar(200),
	amount double(24,8) DEFAULT 0,
	fee double(24,8) DEFAULT 0,
	event_code varchar(10),
	transacted_at datetime,
	status varchar(16) DEFAULT 'unmatched' NOT NULL,
	fk_adherent integer,
	note varchar(255),
	datec datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
