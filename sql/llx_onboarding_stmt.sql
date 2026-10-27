-- One transaction read from a statement, and the Dolibarr bank entry it matched.
--   kind:   main, or fee (a processor fee shown on the same line as its payment)
--   alt_amount: what the money came to after the fee, tried when amount finds nothing
--   status: open (no entry found yet), matched (fk_bank), added (an entry was made
--           for it), ignored (a person said so, or its fee was netted in the payment)
--   hash:   the same transaction read twice is kept once
CREATE TABLE llx_onboarding_stmt(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_account integer NOT NULL,
	fk_file integer,
	dateo date NOT NULL,
	amount double(24,8) NOT NULL,
	alt_amount double(24,8),
	label varchar(255),
	ref varchar(64),
	kind varchar(8) DEFAULT 'main' NOT NULL,
	fk_pair integer,
	fk_bank integer,
	status varchar(8) DEFAULT 'open' NOT NULL,
	hash varchar(40) NOT NULL,
	datec datetime
) ENGINE=innodb;
