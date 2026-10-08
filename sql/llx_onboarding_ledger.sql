-- Training money allocated to zone budgets and trainer credit. Append-only: a
-- balance is the sum of its rows. The money itself sits wherever Givebutter
-- pays out; these rows say whose share of it is whose.
--   account_type 'zone'    account_key = zone name, e.g. Machining
--   account_type 'trainer' account_key = member (adherent) id
--   kind: training (a fee's share), dues_credit (a month of dues refunded),
--         spend (zone budget spent), adjust (correction by hand)
CREATE TABLE llx_onboarding_ledger(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	account_type varchar(10) NOT NULL,
	account_key varchar(100) NOT NULL,
	amount double(24,8) NOT NULL,
	kind varchar(20) NOT NULL,
	fk_training integer,
	fk_credit integer,
	note varchar(255),
	fk_user integer,
	datec datetime
) ENGINE=innodb;
