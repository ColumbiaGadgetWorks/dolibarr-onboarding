-- A statement export uploaded on Reconcile bank accounts, or the automatic
-- PayPal reading (source api, one row per account, its range widened each run).
--   date_from, date_to: the days its transactions cover
--   begin_balance, begin_date: the balance it starts from (the end of the day before), if it has one
--   end_balance, end_date: the balance the statement gives at its last day, if it has one
CREATE TABLE llx_onboarding_stmt_file(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_account integer NOT NULL,
	source varchar(8) DEFAULT 'upload' NOT NULL,
	filename varchar(255),
	original varchar(255),
	date_from date,
	date_to date,
	begin_balance double(24,8),
	begin_date date,
	end_balance double(24,8),
	end_date date,
	nrows integer DEFAULT 0,
	who varchar(255),
	datec datetime
) ENGINE=innodb;
