-- A training registered on the website's /training/pay/ page, waiting for its
-- Givebutter payment. Givebutter cannot carry the tool and trainer, so the
-- payment is matched to this row by the email used at checkout.
--   status: waiting -> paid (fk_training set), or waiting -> expired
CREATE TABLE llx_onboarding_training_reg(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	email varchar(255) NOT NULL,
	firstname varchar(100),
	lastname varchar(100),
	tool_id varchar(100),
	tool varchar(150) NOT NULL,
	zone varchar(100) NOT NULL,
	fee double(24,8),
	trainer_id varchar(100),
	trainer_name varchar(150),
	trainer_member varchar(64),
	ip varchar(64),
	status varchar(20) DEFAULT 'waiting' NOT NULL,
	fk_training integer,
	datec datetime
) ENGINE=innodb;
