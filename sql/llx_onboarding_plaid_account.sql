-- One account of a Plaid login, and the Dolibarr bank account it feeds (0: not fed).
--   balance: the bank's current balance at balance_at (for a card, what is owed)
CREATE TABLE llx_onboarding_plaid_account(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_item integer NOT NULL,
	account_id varchar(128) NOT NULL,
	name varchar(200),
	mask varchar(10),
	type varchar(20),
	subtype varchar(40),
	fk_bank_account integer DEFAULT 0 NOT NULL,
	balance double(24,8),
	balance_at datetime
) ENGINE=innodb;
