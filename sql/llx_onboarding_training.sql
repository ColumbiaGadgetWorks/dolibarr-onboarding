-- One row per paid training, from the Givebutter training campaign. The trainee
-- is the person who paid; the trainer is matched to a member from the name
-- typed at checkout.
CREATE TABLE llx_onboarding_training(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	gb_transaction_id varchar(64) NOT NULL,
	email varchar(255),
	firstname varchar(100),
	lastname varchar(100),
	fk_adherent integer,
	zone varchar(100),
	tool varchar(150),
	trainer_name varchar(150),
	fk_trainer integer,
	amount double(24,8) DEFAULT 0,
	zone_amount double(24,8) DEFAULT 0,
	trainer_amount double(24,8) DEFAULT 0,
	transacted_at datetime,
	status varchar(20) DEFAULT 'recorded' NOT NULL,
	datec datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
