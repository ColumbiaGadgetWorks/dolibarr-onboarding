-- Every Givebutter dues transaction seen, so a repeated delivery is applied once
-- and payments that match nobody can be assigned by hand.
CREATE TABLE llx_onboarding_payment(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	gb_transaction_id varchar(64) NOT NULL,
	gb_plan_id varchar(64),
	gb_contact_id varchar(64),
	email varchar(255),
	firstname varchar(100),
	lastname varchar(100),
	amount double(24,8) DEFAULT 0,
	campaign_code varchar(64),
	transacted_at datetime,
	status varchar(20) DEFAULT 'unmatched' NOT NULL,
	fk_applicant integer,
	datec datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
