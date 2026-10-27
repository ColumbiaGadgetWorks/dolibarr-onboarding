-- A bank login connected through Plaid (Bank feeds). One login can hold several
-- accounts (llx_onboarding_plaid_account).
--   access_token: Plaid's key for the login, kept encrypted when Dolibarr can
--   cursor:       where the transactions sync got to
--   status:       ok, login (the bank wants the login again: Reconnect), error
CREATE TABLE llx_onboarding_plaid_item(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	item_id varchar(128) NOT NULL,
	access_token text NOT NULL,
	institution varchar(200),
	cursor_value text,
	status varchar(8) DEFAULT 'ok' NOT NULL,
	last_sync datetime,
	last_error varchar(500),
	datec datetime
) ENGINE=innodb;
